<?php

declare(strict_types=1);

use App\Domain\Accounting\SieImport\SieImportStatus;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SieImport;
use App\Models\User;
use App\Models\Voucher;
use App\Modules\Connect\Actions\PutSftpExport;
use App\Modules\Connect\Actions\SetSftpEnabled;
use App\Modules\Connect\ConnectModule;
use App\Modules\Connect\Livewire\SftpManager;
use App\Modules\Connect\Models\ConnectSftpAccount;
use App\Modules\Connect\Models\ConnectSftpKey;
use App\Modules\Connect\Models\ConnectSftpUpload;
use App\Modules\Connect\Models\ConnectWebhookDelivery;
use App\Modules\Connect\Sftp\SftpConfig;
use App\Modules\Connect\Sftp\SftpUploadNotice;
use App\Modules\Connect\Sftp\SshPublicKey;
use App\Modules\ModuleManager;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Bookkeeping;
use Tests\Support\Connect;

/*
 * SFTP for SIE files in NONOX Connect (ADR-032, "SFTP"): the hooks SFTPGo
 * calls (login and upload), the job that takes a file into the two-step SIE
 * import, the panel under Anslutningar, and the isolation between companies.
 */

const SFTP_SECRET = 'test-hook-secret-0123456789abcdef0123456789';

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    Http::fake();
    Connect::setUp();
    Connect::fakeDns(['hooks.example.com' => ['93.184.215.14']]);

    $this->sftpRoot = sys_get_temp_dir().'/nonox-sftp-test-'.Str::random(8);
    mkdir($this->sftpRoot);

    config([
        'services.connect.sftp.hook_secret' => SFTP_SECRET,
        'services.connect.sftp.host' => 'sftp.nonox.test',
        'services.connect.sftp.root' => $this->sftpRoot,
        'services.connect.sftp.hook_ips' => '',
    ]);
});

afterEach(function () {
    if (is_dir($this->sftpRoot)) {
        exec('rm -rf '.escapeshellarg($this->sftpRoot));
    }
});

/** An ed25519 public key line, as ssh-keygen writes it. */
function sftpEd25519Key(string $comment = 'kassa@example'): string
{
    $public = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
    $blob = pack('N', 11).'ssh-ed25519'.pack('N', 32).$public;

    return 'ssh-ed25519 '.base64_encode($blob).' '.$comment;
}

function sftpRsaKey(int $bits): string
{
    $details = openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]));
    $mpint = fn (string $bytes): string => pack('N', strlen(ord($bytes[0]) & 0x80 ? "\0".$bytes : $bytes)).(ord($bytes[0]) & 0x80 ? "\0".$bytes : $bytes);
    $blob = pack('N', 7).'ssh-rsa'.$mpint($details['rsa']['e']).$mpint($details['rsa']['n']);

    return 'ssh-rsa '.base64_encode($blob);
}

/**
 * A company with Connect and SFTP on and one key, made as the actions would
 * after the passkey step.
 *
 * @param  list<string>  $cidrs
 * @return array{0: User, 1: Company, 2: ConnectSftpAccount, 3: string}
 */
function sftpCompany(array $cidrs = []): array
{
    [$owner, $company] = Connect::company();
    $company->forceFill(['org_number' => '556334-3689'])->save();
    $account = app(SetSftpEnabled::class)($owner, $company, true);
    $line = sftpEd25519Key();
    $key = SshPublicKey::parse($line);

    CurrentCompany::run($company, function () use ($account, $key, $owner, $cidrs): void {
        $account->forceFill(['allowed_cidrs' => $cidrs])->save();
        ConnectSftpKey::create([
            'account_id' => $account->id,
            'name' => 'Kassan',
            'algorithm' => $key->algorithm,
            'bits' => $key->bits,
            'public_key' => $key->line(),
            'fingerprint' => $key->fingerprint(),
            'created_by' => $owner->id,
        ]);
    });

    return [$owner->fresh(), $company->fresh(), $account->fresh(), $line];
}

/** What SFTPGo's external auth hook sends for a public key login (SFTPGo v2.7). */
function sftpAuthBody(string $username, string $keyLine, array $overrides = []): array
{
    $key = SshPublicKey::parse($keyLine);

    return [
        'username' => $username,
        'ip' => '203.0.113.7',
        'protocol' => 'SSH',
        'password' => '',
        'public_key' => $key?->line()."\n",
        'keyboard_interactive' => '',
        'tls_cert' => '',
        ...$overrides,
    ];
}

/** What SFTPGo's upload action sends (SFTPGo v2.7, custom actions). */
function sftpUploadBody(string $username, string $name, int $size, array $overrides = []): array
{
    return [
        'action' => 'upload',
        'username' => $username,
        'path' => '/srv/sftpgo/users/'.$username.'/in/'.$name,
        'virtual_path' => '/in/'.$name,
        'file_size' => $size,
        'status' => 1,
        'protocol' => 'SSH',
        'ip' => '203.0.113.7',
        'session_id' => 'sess-1',
        'timestamp' => 1_793_000_000_000_000_000,
        ...$overrides,
    ];
}

function sftpHook(string $hook, array $body, string $secret = SFTP_SECRET): TestResponse
{
    return test()->postJson("/hooks/sftpgo/{$hook}", $body, ['Authorization' => "Bearer {$secret}"]);
}

function sftpSieFile(): string
{
    $lines = [
        '#FLAGGA 0',
        '#FORMAT PC8',
        '#SIETYP 4',
        '#ORGNR 556334-3689',
        '#RAR 0 20260101 20261231',
        '#KONTO 1930 "Företagskonto"',
        '#KONTO 3001 "Försäljning"',
        '#VER A 1 20260310 "Dagskassa"',
        '{',
        '#TRANS 1930 {} 1000.00',
        '#TRANS 3001 {} -1000.00',
        '}',
    ];

    return (string) iconv('UTF-8', 'CP437', implode("\r\n", $lines)."\r\n");
}

/** Puts a file in the account's /in folder, as SFTPGo would, and returns its size. */
function sftpDrop(ConnectSftpAccount $account, string $name, string $contents): int
{
    SftpConfig::ensureHome($account->username);
    file_put_contents(SftpConfig::inbox($account->username).'/'.$name, $contents);

    return strlen($contents);
}

/** @return list<string> */
function sftpAuditActions(Company $company): array
{
    return CompanyScope::bypass(fn () => AuditLog::query()->where('company_id', $company->id)->where('action', 'like', 'connect.sftp.%')->orderBy('id')->pluck('action')->all());
}

describe('SSH public keys', function () {
    it('accepts ed25519 and RSA of at least 3072 bits and computes the OpenSSH fingerprint', function () {
        $line = sftpEd25519Key();
        $key = SshPublicKey::parse($line);
        [, $encoded] = explode(' ', $line);

        expect($key)->not->toBeNull()
            ->and($key->algorithm)->toBe('ssh-ed25519')
            ->and($key->line())->toBe('ssh-ed25519 '.$encoded)
            ->and($key->fingerprint())->toBe('SHA256:'.rtrim(base64_encode(hash('sha256', base64_decode($encoded), true)), '='));

        $rsa = SshPublicKey::parse(sftpRsaKey(3072));
        expect($rsa?->algorithm)->toBe('ssh-rsa')->and($rsa?->bits)->toBe(3072);
    });

    it('refuses short RSA keys, other types, private keys and mismatched wire types', function () {
        $ed = sftpEd25519Key();
        [, $encoded] = explode(' ', $ed);

        expect(SshPublicKey::parse(sftpRsaKey(2048)))->toBeNull()
            ->and(SshPublicKey::parse('ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBEUWwDwEWhTbF0MqAsp/oXK1HR2cElhM8oo1uVmL3ZeDKDiTm4ljMr92wfTgIGDqIoxmVqgYIkAOAhuykAVWBzc='))->toBeNull()
            ->and(SshPublicKey::parse('ssh-rsa '.$encoded))->toBeNull()
            ->and(SshPublicKey::parse('-----BEGIN OPENSSH PRIVATE KEY-----'))->toBeNull()
            ->and(SshPublicKey::parse('ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 31).str_repeat('a', 31))))->toBeNull()
            ->and(SshPublicKey::parse('command="x" '.$ed))->toBeNull()
            ->and(SshPublicKey::refusal(sftpRsaKey(2048)))->toContain('3072')
            ->and(SshPublicKey::refusal('-----BEGIN OPENSSH PRIVATE KEY-----'))->toContain('privat');
    });
});

describe('the login hook', function () {
    it('lets a known key in and answers with the virtual user SFTPGo expects', function () {
        [, $company, $account, $line] = sftpCompany(['198.51.100.0/24', '203.0.113.7']);

        $response = sftpHook('auth', sftpAuthBody($account->username, $line))->assertOk();

        expect($response->json('username'))->toBe($account->username)
            ->and($response->json('status'))->toBe(1)
            ->and($response->json('home_dir'))->toBe($this->sftpRoot.'/'.$account->username)
            ->and($response->json('public_keys'))->toBe([SshPublicKey::parse($line)->line()])
            ->and($response->json('permissions'))->toBe(['/' => ['list'], '/in' => ['list', 'upload'], '/ut' => ['list', 'download']])
            ->and($response->json('filters.allowed_ip'))->toBe(['198.51.100.0/24', '203.0.113.7/32'])
            ->and($response->json('filters.denied_login_methods'))->toContain('password', 'keyboard-interactive', 'publickey+password')
            ->and($response->json('filters.denied_protocols'))->toBe(['FTP', 'DAV', 'HTTP'])
            ->and($response->json('filters.external_auth_cache_time'))->toBe(0)
            ->and($response->json('filters.max_upload_file_size'))->toBe(SftpConfig::maxFileBytes())
            ->and(is_dir(SftpConfig::inbox($account->username)))->toBeTrue()
            ->and(is_dir(SftpConfig::outbox($account->username)))->toBeTrue()
            ->and($response->headers->get('Cache-Control'))->toContain('no-store');

        $key = CurrentCompany::run($company, fn () => ConnectSftpKey::query()->sole());
        expect($key->last_used_ip)->toBe('203.0.113.7')
            ->and($account->fresh()->last_login_at)->not->toBeNull()
            ->and(sftpAuditActions($company))->toContain('connect.sftp.login');
    });

    it('refuses a wrong key, passwords, other protocols and unknown users', function () {
        [, $company, $account, $line] = sftpCompany();

        sftpHook('auth', sftpAuthBody($account->username, sftpEd25519Key()))->assertForbidden();
        sftpHook('auth', sftpAuthBody($account->username, $line, ['password' => 'hemligt']))->assertForbidden();
        sftpHook('auth', sftpAuthBody($account->username, $line, ['keyboard_interactive' => '1']))->assertForbidden();
        sftpHook('auth', sftpAuthBody($account->username, $line, ['protocol' => 'FTP']))->assertForbidden();
        sftpHook('auth', sftpAuthBody($account->username, $line, ['public_key' => '']))->assertForbidden();
        sftpHook('auth', sftpAuthBody('nnx'.str_repeat('a', 13), $line))->assertForbidden();

        expect(sftpAuditActions($company))->toContain('connect.sftp.login_refused');
    });

    it('refuses an address outside the allowlist', function () {
        [, , $account, $line] = sftpCompany(['198.51.100.0/24']);

        sftpHook('auth', sftpAuthBody($account->username, $line))->assertForbidden();
        sftpHook('auth', sftpAuthBody($account->username, $line, ['ip' => '198.51.100.20']))->assertOk();
    });

    it('refuses when SFTP is off, Connect is off, the key is removed or the responsible person lost the right', function () {
        [$owner, $company, $account, $line] = sftpCompany();
        $body = sftpAuthBody($account->username, $line);

        app(SetSftpEnabled::class)($owner, $company, false);
        sftpHook('auth', $body)->assertForbidden();

        app(SetSftpEnabled::class)($owner, $company, true);
        sftpHook('auth', $body)->assertOk();

        $admin = Bookkeeping::member($company, 'admin');
        app(SetSftpEnabled::class)($admin, $company, true);
        sftpHook('auth', $body)->assertOk();
        $company->users()->updateExistingPivot($admin->id, ['role' => 'bookkeeper']);
        sftpHook('auth', $body)->assertForbidden();

        app(SetSftpEnabled::class)($owner, $company, true);
        sftpHook('auth', $body)->assertOk();

        app(ModuleManager::class)->disable(ConnectModule::KEY, $company, $owner, true);
        sftpHook('auth', $body)->assertForbidden();

        $keys = CompanyScope::bypass(fn () => ConnectSftpKey::query()->where('company_id', $company->id)->get());
        expect($keys->every(fn (ConnectSftpKey $key) => $key->revoked_at !== null))->toBeTrue()
            ->and($account->fresh()->is_enabled)->toBeFalse();
    });

    it('never lets one company\'s key into another company\'s home', function () {
        [, , $accountA, $lineA] = sftpCompany();
        [, , $accountB, $lineB] = sftpCompany();

        sftpHook('auth', sftpAuthBody($accountB->username, $lineA))->assertForbidden();
        sftpHook('auth', sftpAuthBody($accountA->username, $lineB))->assertForbidden();

        $response = sftpHook('auth', sftpAuthBody($accountA->username, $lineA))->assertOk();
        expect($response->json('home_dir'))->toEndWith('/'.$accountA->username)
            ->and($response->json('home_dir'))->not->toContain($accountB->username);
    });

    it('accepts only the configured secret, fails closed without one and can be limited to addresses', function () {
        [, , $account, $line] = sftpCompany();
        $body = sftpAuthBody($account->username, $line);

        sftpHook('auth', $body, 'wrong-secret-0123456789abcdef0123456789')->assertUnauthorized();
        test()->postJson('/hooks/sftpgo/auth', $body)->assertUnauthorized();

        config(['services.connect.sftp.hook_ips' => '192.0.2.1']);
        sftpHook('auth', $body)->assertNotFound();

        config(['services.connect.sftp.hook_ips' => '127.0.0.1', 'services.connect.sftp.hook_secret' => 'too-short']);
        sftpHook('auth', $body, 'too-short')->assertNotFound();

        config(['services.connect.sftp.hook_secret' => null]);
        sftpHook('auth', $body)->assertNotFound();
        sftpHook('upload', sftpUploadBody($account->username, 'a.se', 10))->assertNotFound();
    });
});

describe('the upload hook and the job', function () {
    it('takes an uploaded SIE file into a pending SIE import, removes it from the drop and tells the company', function () {
        [$owner, $company, $account] = sftpCompany();
        Connect::webhook($owner, $company, ['sie.uploaded']);
        $size = sftpDrop($account, 'kassa.se', sftpSieFile());

        sftpHook('upload', sftpUploadBody($account->username, 'kassa.se', $size))->assertOk()->assertJsonPath('ok', true);

        $upload = CurrentCompany::run($company, fn () => ConnectSftpUpload::query()->sole());
        $import = CurrentCompany::run($company, fn () => SieImport::query()->sole());

        expect($upload->status)->toBe(ConnectSftpUpload::ANALYSED)
            ->and($upload->sie_import_id)->toBe($import->id)
            ->and($upload->sha256)->toBe(hash('sha256', sftpSieFile()))
            ->and($import->status)->toBe(SieImportStatus::Analysed)
            ->and($import->user_id)->toBe($owner->id)
            ->and($import->original_name)->toBe('kassa.se')
            ->and(file_exists(SftpConfig::inbox($account->username).'/kassa.se'))->toBeFalse()
            ->and(CurrentCompany::run($company, fn () => Voucher::query()->where('source', 'IMPORT_SIE')->count()))->toBe(0)
            ->and(sftpAuditActions($company))->toContain('connect.sftp.upload_received', 'connect.sftp.upload_analysed')
            ->and($owner->notifications()->where('type', SftpUploadNotice::class)->count())->toBe(1);

        $delivery = CompanyScope::bypass(fn () => ConnectWebhookDelivery::query()->where('company_id', $company->id)->sole());
        $event = json_decode($delivery->body, true);
        expect($event['type'])->toBe('sie.uploaded')
            ->and($event['data']['upload_id'])->toBe($upload->id)
            ->and($event['data']['sie_import_id'])->toBe($import->id)
            ->and($event['data'])->not->toHaveKey('file_name');
    });

    it('counts the same notice twice as one upload', function () {
        [, $company, $account] = sftpCompany();
        $size = sftpDrop($account, 'kassa.se', sftpSieFile());
        $body = sftpUploadBody($account->username, 'kassa.se', $size);

        sftpHook('upload', $body)->assertOk();
        sftpHook('upload', $body)->assertOk()->assertJsonPath('duplicate', true);

        expect(CurrentCompany::run($company, fn () => ConnectSftpUpload::query()->count()))->toBe(1);
    });

    it('refuses paths outside /in, other endings and sizes over the limit, so SFTPGo removes the file', function () {
        [, $company, $account] = sftpCompany();

        sftpHook('upload', sftpUploadBody($account->username, 'x.se', 10, ['virtual_path' => '/ut/x.se']))->assertStatus(422);
        sftpHook('upload', sftpUploadBody($account->username, 'x.se', 10, ['virtual_path' => '/in/../x.se']))->assertStatus(422);
        sftpHook('upload', sftpUploadBody($account->username, 'x.txt', 10))->assertStatus(422);
        sftpHook('upload', sftpUploadBody($account->username, 'x.se', SftpConfig::maxFileBytes() + 1))->assertStatus(422);
        sftpHook('upload', sftpUploadBody($account->username, 'x.se', 10, ['ip' => '192.0.2.1']))->assertOk();

        CurrentCompany::run($company, fn () => ConnectSftpAccount::query()->sole()->forceFill(['allowed_cidrs' => ['198.51.100.0/24']])->save());
        sftpHook('upload', sftpUploadBody($account->username, 'y.se', 10))->assertForbidden();

        expect(sftpAuditActions($company))->toContain('connect.sftp.upload_refused');
    });

    it('ignores notices that are not finished uploads', function () {
        [, $company, $account] = sftpCompany();

        sftpHook('upload', sftpUploadBody($account->username, 'x.se', 10, ['action' => 'download']))->assertOk()->assertJsonPath('ignored', true);
        sftpHook('upload', sftpUploadBody($account->username, 'x.se', 10, ['status' => 2]))->assertOk()->assertJsonPath('ignored', true);

        expect(CurrentCompany::run($company, fn () => ConnectSftpUpload::query()->count()))->toBe(0);
    });

    it('rejects a file that is not SIE and still removes it', function () {
        [$owner, $company, $account] = sftpCompany();
        $size = sftpDrop($account, 'bild.se', "\x89PNG\r\n\x1a\n\0\0\0");

        sftpHook('upload', sftpUploadBody($account->username, 'bild.se', $size))->assertOk();

        $upload = CurrentCompany::run($company, fn () => ConnectSftpUpload::query()->sole());
        expect($upload->status)->toBe(ConnectSftpUpload::REJECTED)
            ->and($upload->reason)->toContain('textfil')
            ->and(CurrentCompany::run($company, fn () => SieImport::query()->count()))->toBe(0)
            ->and(file_exists(SftpConfig::inbox($account->username).'/bild.se'))->toBeFalse()
            ->and(sftpAuditActions($company))->toContain('connect.sftp.upload_rejected')
            ->and($owner->notifications()->count())->toBe(1);
    });

    it('rejects a file without #FLAGGA first', function () {
        [, $company, $account] = sftpCompany();
        $size = sftpDrop($account, 'text.se', "Hej\r\n#FLAGGA 0\r\n");

        sftpHook('upload', sftpUploadBody($account->username, 'text.se', $size))->assertOk();

        expect(CurrentCompany::run($company, fn () => ConnectSftpUpload::query()->sole())->reason)->toContain('#FLAGGA');
    });
});

describe('the panel under Anslutningar', function () {
    it('adds a key only with a fresh passkey and refuses weak keys', function () {
        [$owner, $company] = Connect::company();
        $authenticator = Connect::passkey($owner);

        $component = Livewire::actingAs($owner)->test(SftpManager::class)
            ->call('enable')->assertHasNoErrors()->assertDispatched('toast');

        $account = CurrentCompany::run($company, fn () => ConnectSftpAccount::query()->sole());
        expect($account->username)->toMatch(SftpConfig::USERNAME_PATTERN)
            ->and($account->username)->not->toContain(preg_replace('/\D/', '', (string) $company->org_number));

        $component->set('keyName', 'Kassan')->set('publicKey', sftpEd25519Key())->call('addKey', null)->assertHasErrors('passkey');

        $passkey = function () use ($component, $authenticator, $owner): array {
            $options = null;
            $component->call('passkeyOptions')->assertReturned(function (mixed $data) use (&$options): bool {
                $options = $data;

                return true;
            });

            return $authenticator->authenticate((array) $options, $owner->getPasskeyUserHandle());
        };

        $component->set('publicKey', sftpRsaKey(2048))->call('addKey', $passkey())->assertHasErrors('public_key');
        $component->set('publicKey', sftpEd25519Key())->call('addKey', $passkey())->assertHasNoErrors()->assertDispatched('toast');

        $key = CurrentCompany::run($company, fn () => ConnectSftpKey::query()->sole());
        expect($key->name)->toBe('Kassan')->and($key->fingerprint)->toStartWith('SHA256:');

        $component->set('allowlist', '0.0.0.0/0')->call('saveAllowlist', $passkey())->assertHasErrors('allowlist');
        $component->set('allowlist', "198.51.100.0/24\n203.0.113.7")->call('saveAllowlist', $passkey())->assertHasNoErrors();
        expect($account->fresh()->allowed_cidrs)->toBe(['198.51.100.0/24', '203.0.113.7']);

        $component->call('removeKey', $key->id)->assertHasNoErrors();
        expect($key->fresh()->revoked_at)->not->toBeNull()
            ->and(sftpAuditActions($company))->toBe([
                'connect.sftp.enabled',
                'connect.sftp.key_added',
                'connect.sftp.allowlist_changed',
                'connect.sftp.key_removed',
            ]);
    });

    it('lets only owners and administrators who may import SIE turn it on', function () {
        [, $company] = Connect::company();
        $accountant = Bookkeeping::member($company, 'accountant');

        expect(fn () => app(SetSftpEnabled::class)($accountant, $company, true))->toThrow(AuthorizationException::class);

        config(['services.connect.sftp.hook_secret' => null]);
        $owner = User::query()->findOrFail($company->user_id);
        expect(fn () => app(SetSftpEnabled::class)($owner, $company, true))->toThrow(ValidationException::class);
    });

    it('shows the address, user name and upload log', function () {
        [, $company, $account] = sftpCompany();
        $admin = Bookkeeping::member($company, 'admin');

        Livewire::actingAs($admin)->test(SftpManager::class)
            ->assertSee($account->username)
            ->assertSee('sftp.nonox.test')
            ->assertSee('Inga filer ännu.');
    });
});

describe('the download folder', function () {
    it('puts a SIE export of the current year in /ut and prunes it after a week', function () {
        $this->travelTo(CarbonImmutable::parse('2026-06-20 10:00:00'));
        [$owner, $company, $account] = sftpCompany();

        $name = app(PutSftpExport::class)($owner, $company);
        $path = SftpConfig::outbox($account->username).'/'.$name;

        expect($name)->toEndWith('.se')
            ->and(file_get_contents($path))->toStartWith('#FLAGGA')
            ->and(sftpAuditActions($company))->toContain('connect.sftp.export_published');

        touch($path, now()->subDays(8)->getTimestamp());
        $this->artisan('connect:prune')->assertSuccessful();

        expect(file_exists($path))->toBeFalse();
    });
});
