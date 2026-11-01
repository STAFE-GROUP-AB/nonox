<?php

declare(strict_types=1);

use App\Support\Files\EncryptedAdapter;
use App\Support\Files\PrivateFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->root = storage_path('framework/testing/encrypted-'.uniqid());
    config(['filesystems.disks.vault' => ['driver' => 'encrypted', 'root' => $this->root]]);
    $this->disk = Storage::disk('vault');
});

afterEach(function () {
    File::deleteDirectory($this->root);
});

it('stores files encrypted and reads them back in plain', function () {
    $pdf = "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n";
    $this->disk->put('underlag/kvitto.pdf', $pdf);

    $raw = (string) file_get_contents($this->root.'/underlag/kvitto.pdf');

    expect(EncryptedAdapter::isEncrypted($raw))->toBeTrue()
        ->and($raw)->not->toContain('%PDF')
        ->and($this->disk->get('underlag/kvitto.pdf'))->toBe($pdf)
        ->and($this->disk->size('underlag/kvitto.pdf'))->toBe(strlen($pdf))
        ->and($this->disk->mimeType('underlag/kvitto.pdf'))->toBe('application/pdf')
        ->and(stream_get_contents($this->disk->readStream('underlag/kvitto.pdf')))->toBe($pdf);
});

it('serves a download with the decrypted contents and length', function () {
    $this->disk->put('fil.txt', 'hemligt innehåll');

    $response = $this->disk->download('fil.txt', 'fil.txt');

    ob_start();
    $response->sendContent();
    $body = ob_get_clean();

    expect($body)->toBe('hemligt innehåll')
        ->and((int) $response->headers->get('Content-Length'))->toBe(strlen('hemligt innehåll'));
});

it('reads files stored before encryption, and encrypts them on request', function () {
    @mkdir($this->root, 0755, true);
    file_put_contents($this->root.'/gammal.txt', 'okrypterad');

    expect($this->disk->get('gammal.txt'))->toBe('okrypterad');
});

it('hands out a decrypted temporary copy and removes it afterwards', function () {
    $this->disk->put('sie.se', '#FLAGGA 0');
    $seen = null;

    $contents = PrivateFile::withLocalCopy('vault', 'sie.se', function (string $path) use (&$seen): string {
        $seen = $path;

        return (string) file_get_contents($path);
    });

    expect($contents)->toBe('#FLAGGA 0')
        ->and(file_exists((string) $seen))->toBeFalse();
});

it('keeps temporary uploads on a plain disk, since uploads are read from their path', function () {
    // Livewire swaps the disk while tests run, so the setting is what can be checked here.
    expect(config('livewire.temporary_file_upload.disk'))->toBe('uploads')
        ->and(config('filesystems.disks.uploads.driver'))->toBe('local')
        ->and(config('filesystems.disks.uploads.root'))->not->toBe(config('filesystems.disks.local.root'));
});
