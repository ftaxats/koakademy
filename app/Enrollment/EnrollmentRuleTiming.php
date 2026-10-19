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
        return in_array($handler, ['billing.minimum_payment', 'academics.maximum_assigned_units', 'billing.maximum_other_assessment_balance'], true);
    }
}
