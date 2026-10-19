<?php

declare(strict_types=1);

namespace App\Enrollment;

final class EnrollmentRuleTiming
{
    public static function appliesAtEntry(string $handler): bool
    {
        return ! self::appliesAtCompletion($handler);
    }

    public static function appliesAtCompletion(string $handler): bool
    {
        return $handler === 'billing.minimum_payment' || self::usesSavedRecordsAtCompletion($handler);
    }

    public static function usesSavedRecordsAtCompletion(string $handler): bool
    {
        return in_array($handler, ['academics.maximum_assigned_units', 'billing.maximum_other_assessment_balance', 'academics.verified_schedule', 'academics.verified_prerequisites'], true);
    }
}
