<?php

declare(strict_types=1);

namespace App\Modules\Connect\Mail;

use App\Modules\Connect\Support\ConnectScopes;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells the company's owner (and the creator) that an API key was created,
 * so a key made by someone else does not go unseen (ADR-032). Never
 * contains the key itself.
 */
class ApiKeyCreatedMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly string $companyName,
        public readonly string $creatorName,
        public readonly string $keyName,
        public readonly string $prefix,
        public readonly array $scopes,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('En API-nyckel har skapats för :company', ['company' => $this->companyName]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'connect::mail.api-key-created', with: [
            'companyName' => $this->companyName,
            'creatorName' => $this->creatorName,
            'keyName' => $this->keyName,
            'prefix' => $this->prefix,
            'scopes' => array_map(fn (string $scope): string => ConnectScopes::label($scope), $this->scopes),
            'expiresOn' => $this->expiresAt->timezone('Europe/Stockholm')->toDateString(),
        ]);
    }
}
