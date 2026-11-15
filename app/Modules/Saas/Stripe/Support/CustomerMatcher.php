<?php

declare(strict_types=1);

namespace App\Modules\Saas\Stripe\Support;

use App\Domain\Company\OrganisationNumber;
use App\Models\Customer;
use App\Modules\Saas\Stripe\Models\StripeCustomerLink;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Matches Stripe customers to the company's customers without duplicates
 * (ADR-080 §7.5), in this order:
 *
 * 1. `metadata.nonox_customer_number` naming an existing customer number;
 * 2. a VAT number in the Stripe customer's tax ids equal to a customer's
 *    VAT number, or, for a Swedish one (SE + ten digits + 01, Luhn-checked),
 *    to a customer's organisation number;
 * 3. an exact e-mail address shared with exactly one customer;
 * 4. the same name and country as exactly one customer.
 *
 * Rules 1 and 2 link at once and are listed for review; 3 and 4 only suggest,
 * and a person decides. A rule that finds two or more customers decides
 * nothing. Only Stripe customers that no person has decided about are
 * matched (UNLINKED or SUGGESTED without a decision). Five queries for any
 * number of Stripe customers.
 */
final class CustomerMatcher
{
    /**
     * @param  Collection<int, StripeCustomerLink>  $links
     * @return list<StripeCustomerLink> the links that were linked by rule 1 or 2
     */
    public function match(Collection $links): array
    {
        $open = $links->filter(fn (StripeCustomerLink $link): bool => $link->status !== StripeCustomerLink::LINKED && $link->confirmed_at === null && ! $link->deleted_in_stripe)->values();

        if ($open->isEmpty()) {
            return [];
        }

        $numbers = $open->map(fn (StripeCustomerLink $link): ?int => self::number($link->nonox_customer_number))->filter()->unique()->values()->all();
        $vats = [];
        $orgs = [];

        foreach ($open as $link) {
            foreach (self::taxNumbers($link) as [$vat, $org]) {
                $vats[] = $vat;

                if ($org !== null) {
                    $orgs[] = $org;
                }
            }
        }

        $emails = $open->map(fn (StripeCustomerLink $link): string => self::email($link->email))->filter()->unique()->values()->all();
        $names = $open->map(fn (StripeCustomerLink $link): string => self::name($link->name))->filter()->unique()->values()->all();

        $byNumber = $numbers === [] ? collect() : Customer::query()->whereIn('number', $numbers)->get(['id', 'number'])->groupBy('number');
        $byVat = $vats === [] ? collect() : Customer::query()->whereIn(DB::raw("upper(replace(vat_number, ' ', ''))"), array_values(array_unique($vats)))->get(['id', 'vat_number'])->groupBy(fn (Customer $customer): string => self::vat((string) $customer->vat_number));
        $byOrg = $orgs === [] ? collect() : Customer::query()->whereIn('org_number', array_values(array_unique(array_map(fn (string $digits): string => substr($digits, 0, 6).'-'.substr($digits, 6), $orgs))))->get(['id', 'org_number'])->groupBy(fn (Customer $customer): string => (string) preg_replace('/\D/', '', (string) $customer->org_number));
        $byEmail = $emails === [] ? collect() : Customer::query()->whereIn(DB::raw('lower(trim(email))'), $emails)->get(['id', 'email'])->groupBy(fn (Customer $customer): string => self::email($customer->email));
        $byName = $names === [] ? collect() : Customer::query()->whereIn(DB::raw('lower(trim(name))'), $names)->get(['id', 'name', 'country'])->groupBy(fn (Customer $customer): string => self::name($customer->name));

        $linked = [];

        foreach ($open as $link) {
            $one = fn (?Collection $candidates): ?int => $candidates !== null && $candidates->unique('id')->count() === 1 ? (int) $candidates->first()->id : null;

            $number = self::number($link->nonox_customer_number);
            $byRule1 = $number === null ? null : $one($byNumber->get($number));
            $byRule2 = null;

            foreach (self::taxNumbers($link) as [$vat, $org]) {
                $candidates = $byVat->get($vat, collect())->merge($org === null ? [] : $byOrg->get($org, collect()));
                $byRule2 ??= $one($candidates);
            }

            $customer = $byRule1 ?? $byRule2;

            if ($customer !== null) {
                $link->forceFill([
                    'status' => StripeCustomerLink::LINKED,
                    'customer_id' => $customer,
                    'method' => $byRule1 !== null ? StripeCustomerLink::BY_METADATA : StripeCustomerLink::BY_TAX_ID,
                    'suggested_customer_id' => null,
                    'suggestion_method' => null,
                ])->save();
                $linked[] = $link;

                continue;
            }

            $email = self::email($link->email);
            $suggestion = $email === '' ? null : $one($byEmail->get($email));
            $method = $suggestion === null ? null : StripeCustomerLink::BY_EMAIL;

            if ($suggestion === null && self::name($link->name) !== '') {
                $sameCountry = $byName->get(self::name($link->name), collect())
                    ->filter(fn (Customer $candidate): bool => strtoupper((string) $candidate->country) === strtoupper((string) ($link->country ?? 'SE')));
                $suggestion = $one($sameCountry);
                $method = $suggestion === null ? null : StripeCustomerLink::BY_NAME;
            }

            $link->forceFill([
                'status' => $suggestion === null ? StripeCustomerLink::UNLINKED : StripeCustomerLink::SUGGESTED,
                'suggested_customer_id' => $suggestion,
                'suggestion_method' => $method,
            ])->save();
        }

        return $linked;
    }

    /**
     * The VAT numbers in the tax ids, each with the organisation number when
     * it is a valid Swedish one.
     *
     * @return list<array{0: string, 1: ?string}>
     */
    private static function taxNumbers(StripeCustomerLink $link): array
    {
        $numbers = [];

        foreach ((array) $link->tax_ids as $taxId) {
            if (! in_array($taxId['type'], ['eu_vat', 'gb_vat', 'no_vat', 'ch_vat'], true)) {
                continue;
            }

            $vat = self::vat($taxId['value']);

            if ($vat === '') {
                continue;
            }

            $org = preg_match('/^SE(\d{10})01$/', $vat, $match) === 1 ? OrganisationNumber::tryFrom($match[1])?->digits : null;
            $numbers[] = [$vat, $org];
        }

        return $numbers;
    }

    private static function number(?string $value): ?int
    {
        $value = trim((string) $value);

        return $value !== '' && ctype_digit($value) && strlen($value) <= 9 ? (int) $value : null;
    }

    private static function vat(string $value): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    private static function email(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private static function name(?string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
    }
}
