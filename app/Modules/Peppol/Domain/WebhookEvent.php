<?php

declare(strict_types=1);

namespace App\Modules\Peppol\Domain;

/**
 * One verified event from the access point's webhook: a new incoming
 * document for an account, or a delivery update for a document sent.
 * Only ids and a status; the document is fetched with the API.
 */
final readonly class WebhookEvent
{
    public const string INCOMING = 'incoming';

    public const string DELIVERY = 'delivery';

    public function __construct(
        public string $type,
        public string $account,
        public string $reference,
        public string $documentType = 'Invoice',
        public ?TransmissionStatus $status = null,
        public ?string $error = null,
    ) {}
}
