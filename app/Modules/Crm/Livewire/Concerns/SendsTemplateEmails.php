<?php

declare(strict_types=1);

namespace App\Modules\Crm\Livewire\Concerns;

use App\Modules\Crm\Actions\SendTemplateEmail;
use App\Modules\Crm\Models\EmailTemplate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * "Mejla med mall" on the account card and the person page (ADR-048): pick
 * a template, adjust subject and text, send through SendTemplateEmail. The
 * merge fields are filled in when the mail is sent, for the chosen person.
 *
 * @mixin Component
 */
trait SendsTemplateEmails
{
    /** @var array{person_id: string, template_id: string, subject: string, body: string, not_marketing: bool} */
    public array $mail = ['person_id' => '', 'template_id' => '', 'subject' => '', 'body' => '', 'not_marketing' => false];

    /** The account the mail is logged on. */
    abstract protected function mailAccountId(): ?int;

    public function startTemplateMail(?int $personId = null): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'crm:manage'), 403);

        $template = EmailTemplate::query()->orderBy('name')->first();
        $this->mail = [
            'person_id' => (string) ($personId ?? ''),
            'template_id' => (string) ($template->id ?? ''),
            'subject' => $template->subject ?? '',
            'body' => $template->body ?? '',
            'not_marketing' => false,
        ];
        $this->panel = 'mall';
        $this->resetErrorBag();
    }

    public function updatedMailTemplateId(): void
    {
        $template = $this->mail['template_id'] === '' ? null : EmailTemplate::query()->find((int) $this->mail['template_id']);

        if ($template instanceof EmailTemplate) {
            $this->mail['subject'] = $template->subject;
            $this->mail['body'] = $template->body;
        }
    }

    public function sendTemplateMail(SendTemplateEmail $send): void
    {
        abort_unless($this->user()->hasTeamPermission($this->company(), 'crm:manage'), 403);

        try {
            $send($this->user(), $this->company(), [
                'account_id' => $this->mailAccountId(),
                'person_id' => $this->mail['person_id'] === '' ? null : (int) $this->mail['person_id'],
                'template_id' => $this->mail['template_id'] === '' ? null : (int) $this->mail['template_id'],
                'subject' => $this->mail['subject'],
                'body' => $this->mail['body'],
                'not_marketing' => (bool) $this->mail['not_marketing'],
            ]);
        } catch (ValidationException $exception) {
            $this->setErrorBag(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ["mail.{$key}" => $messages])->all());

            return;
        }

        $this->reset('mail');
        $this->closePanel();
        $this->toast(__('Mejlet är skickat och loggat.'));
    }
}
