<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * Where a purchase requisition is (ADR-081 gap 3, E9): written and still
 * changeable (DRAFT), waiting for approval (SUBMITTED), approved and waiting for
 * a buyer (APPROVED), turned into purchase order rows (CONVERTED), sent back
 * to the requester (REJECTED) or withdrawn for good (CANCELLED).
 */
enum PurchaseRequisitionStatus: string
{
    case Draft = 'DRAFT';
    case Submitted = 'SUBMITTED';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Converted = 'CONVERTED';
    case Cancelled = 'CANCELLED';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Utkast'),
            self::Submitted => __('Väntar på attest'),
            self::Approved => __('Attesterad'),
            self::Rejected => __('Avvisad'),
            self::Converted => __('Omvandlad till order'),
            self::Cancelled => __('Makulerad'),
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Draft, self::Cancelled => 'neutral',
            self::Submitted => 'warning',
            self::Approved => 'accent',
            self::Rejected => 'danger',
            self::Converted => 'success',
        };
    }

    /** The requester (or a buyer) may still change it: nobody has been asked to decide on it. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    /** It still asks for something: not converted and not withdrawn. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::Approved, self::Rejected], true);
    }
}
