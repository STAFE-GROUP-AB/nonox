<?php

declare(strict_types=1);

namespace App\Modules\Inbox\Support;

use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\VoucherAttachment;
use App\Modules\Inbox\Domain\MessageStatus;
use App\Modules\Inbox\Models\AllowedSender;
use App\Modules\Inbox\Models\InboxAttachment;
use App\Modules\Inbox\Models\InboxMessage;
use App\Support\Money\Money;

/**
 * The warnings that keep a message from being booked automatically: a
 * look-alike sender domain, changed payment details at a known supplier,
 * and duplicates. A warning never stops a person; it makes them look.
 * Must run inside the message's company.
 */
final class InboxChecks
{
    /** Warnings found when the message arrives, kept when it is read again. */
    public const array RECEIPT_WARNINGS = ['LOOKALIKE_DOMAIN'];

    /** Whether a warning belongs to the message itself (its arrival or release) rather than to a reading of it. */
    public static function keepsOnRereading(string $code): bool
    {
        return in_array($code, self::RECEIPT_WARNINGS, true) || str_starts_with($code, 'RELEASED_');
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    public static function lookalike(string $fromEmail): array
    {
        $at = strrpos($fromEmail, '@');
        $domain = $at === false ? '' : substr($fromEmail, $at + 1);

        $known = AllowedSender::query()->pluck('pattern')
            ->merge(Supplier::query()->whereNotNull('email')->pluck('email'))
            ->map(fn (mixed $address): string => mb_strtolower(substr((string) $address, (int) strrpos((string) $address, '@') + 1)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $imitated = LookalikeDomain::imitates($domain, array_values($known));

        return $imitated === null ? [] : [[
            'code' => 'LOOKALIKE_DOMAIN',
            'message' => __('Avsändarens domän :domain liknar :known, som du känner, men är inte samma. Kontrollera att det inte är ett bedrägeriförsök.', ['domain' => $domain, 'known' => $imitated]),
        ]];
    }

    /**
     * Payment details on an invoice that differ from the supplier register.
     * A bankgiro, plusgiro or IBAN is compared with the same kind in the
     * register; a kind the register does not have, while it has another,
     * counts as a change too.
     *
     * @return list<array{code: string, message: string}>
     */
    public static function bankDetails(Supplier $supplier, ?string $bankgiro, ?string $plusgiro, ?string $iban): array
    {
        $digits = fn (?string $value): string => strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $value));
        $invoice = array_filter(['bankgiro' => $digits($bankgiro), 'plusgiro' => $digits($plusgiro), 'iban' => $digits($iban)]);
        $register = array_filter(['bankgiro' => $digits($supplier->bankgiro), 'plusgiro' => $digits($supplier->plusgiro), 'iban' => $digits($supplier->iban)]);
        $labels = ['bankgiro' => __('bankgiro'), 'plusgiro' => __('plusgiro'), 'iban' => 'IBAN'];
        $warnings = [];

        foreach ($invoice as $kind => $value) {
            $changed = isset($register[$kind]) ? $register[$kind] !== $value : $register !== [];

            if ($changed) {
                $warnings[] = [
                    'code' => 'BANK_DETAILS_CHANGED',
                    'message' => __('Fakturan anger :kind :value, men :supplier har :registered i leverantörsregistret. Ring leverantören på ett nummer du redan har innan du betalar.', [
                        'kind' => $labels[$kind],
                        'value' => $value,
                        'supplier' => $supplier->name,
                        'registered' => isset($register[$kind]) ? "{$labels[$kind]} {$register[$kind]}" : __('andra betalningsuppgifter'),
                    ]),
                ];
            }
        }

        return $warnings;
    }

    /**
     * The same attachment already received, already underlag on a voucher or
     * already a registered supplier invoice.
     *
     * @return list<array{code: string, message: string}>
     */
    public static function duplicateAttachments(InboxMessage $message): array
    {
        $hashes = $message->attachments->reject(fn (InboxAttachment $attachment): bool => $attachment->isBlocked())->pluck('sha256')->unique()->values()->all();

        if ($hashes === []) {
            return [];
        }

        $inInbox = InboxAttachment::query()
            ->whereIn('sha256', $hashes)
            ->where('inbox_message_id', '<>', $message->id)
            ->whereHas('message', fn ($query) => $query->where('status', '<>', MessageStatus::Rejected->value))
            ->pluck('sha256');
        $asUnderlag = VoucherAttachment::query()->whereIn('sha256', $hashes)->pluck('sha256');
        $asInvoice = SupplierInvoice::query()->whereIn('file_sha256', $hashes)->pluck('file_sha256');
        $found = $inInbox->merge($asUnderlag)->merge($asInvoice)->unique();

        $warnings = [];

        foreach ($message->attachments as $attachment) {
            if ($found->contains($attachment->sha256)) {
                $warnings[] = ['code' => 'DUPLICATE_ATTACHMENT', 'message' => self::duplicateMessage($attachment->filename)];
            }
        }

        return $warnings;
    }

    /**
     * Files the text names but the mail does not carry. A forward from Mail
     * on an iPhone or iPad leaves "<Faktura.pdf>" in the text when the
     * attachment was not downloaded to the device, and sends no file.
     *
     * @return list<array{code: string, message: string}>
     */
    public static function missingAttachments(InboxMessage $message): array
    {
        if ($message->attachments->isNotEmpty() || $message->body_text === null) {
            return [];
        }

        preg_match_all('#<([^<>\r\n/]{1,120}\.(?:pdf|jpe?g|png|heic|xlsx|csv|txt|xml|se|si|sie))>#iu', $message->body_text, $matches);
        $names = array_values(array_unique($matches[1]));

        if ($names === []) {
            return [];
        }

        return [['code' => 'MISSING_ATTACHMENT', 'message' => __('Mejlet nämner :names men filen följde inte med. Skicka mejlet igen med bilagan. På iPhone och iPad: öppna bilagan först så att den laddas ner, eller välj att ta med bilagor när du vidarebefordrar.', ['names' => implode(', ', array_slice($names, 0, 3))])]];
    }

    private static function duplicateMessage(string $filename): string
    {
        return __('Bilagan :name har kommit in förut. Kontrollera att den inte bokförs två gånger.', ['name' => $filename]);
    }

    /**
     * @return list<array{code: string, message: string}>
     */
    public static function duplicateInvoice(int $supplierId, ?string $invoiceNumber, int $totalOre): array
    {
        if ($invoiceNumber === null || $invoiceNumber === '') {
            return [];
        }

        $exists = SupplierInvoice::query()
            ->where('supplier_id', $supplierId)
            ->where('invoice_number', $invoiceNumber)
            ->where('total_ore', $totalOre)
            ->exists();

        return $exists ? [[
            'code' => 'DUPLICATE_INVOICE',
            'message' => __('Faktura :number på :amount från samma leverantör finns redan. Kontrollera att den inte betalas två gånger.', ['number' => $invoiceNumber, 'amount' => Money::ofOre($totalOre)->format()]),
        ]] : [];
    }

    /**
     * Whether the sender is on the company's allowlist, by address or domain.
     */
    public static function isAllowed(string $fromEmail): bool
    {
        return AllowedSender::query()->whereIn('pattern', AllowedSender::patternsFor($fromEmail))->exists();
    }
}
