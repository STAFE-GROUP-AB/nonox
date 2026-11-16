<?php

declare(strict_types=1);

namespace App\Support\CompanyRegistry;

/**
 * What SCB's size classes (RegistryCodes::EMPLOYEE_CLASSES and
 * TURNOVER_CLASSES) say about a new company's setup (ADR-035 addendum).
 * Only suggestions: the classes are from the register's last import and
 * the user decides.
 */
final class CompanySize
{
    /** SCB's employee class for "0 anställda"; 0 means unknown and is stored as null. */
    private const int NO_EMPLOYEES = 1;

    /** 100–199 employees: certainly more than the 50 of ÅRL 1 kap. 3 § (class 6, 50–99, straddles the limit). */
    private const int EMPLOYEES_CERTAINLY_OVER_LIMIT = 7;

    /** 100–499,9 Mkr: certainly more than the 80 Mkr limit (class 7, 50–99,9 Mkr, straddles it). */
    private const int TURNOVER_CERTAINLY_OVER_LIMIT = 8;

    /**
     * Whether the company has employees, for "Registrerad arbetsgivare":
     * none in class 1, some in every other class, nothing when unknown.
     */
    public static function hasEmployees(?int $employeeClass): ?bool
    {
        if ($employeeClass === null || ! array_key_exists($employeeClass, RegistryCodes::EMPLOYEE_CLASSES)) {
            return null;
        }

        return $employeeClass !== self::NO_EMPLOYEES;
    }

    /**
     * Whether the classes say that two of the three size limits for a smaller
     * company are exceeded, which makes K2 unavailable: more than 50
     * employees on average and a net turnover over 80 million kronor. The
     * balance sheet total (over 40 million kronor) is not in the register,
     * so both of the others must be certainly over. Conservative: the
     * limits apply to each of the two latest fiscal years, and the register's
     * turnover comes from the VAT returns.
     *
     * TODO(legal-verify): confirm the limits in ÅRL (1999:1078) 1 kap. 3 §
     * against the law text; the repository's ReportingFrameworkRules quotes
     * them from BFNAR 2016:10.
     */
    public static function likelyAboveK2Limits(?int $employeeClass, ?int $turnoverClass): bool
    {
        return $employeeClass !== null
            && $turnoverClass !== null
            && $employeeClass >= self::EMPLOYEES_CERTAINLY_OVER_LIMIT
            && $turnoverClass >= self::TURNOVER_CERTAINLY_OVER_LIMIT;
    }
}
