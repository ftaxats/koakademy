<?php

declare(strict_types=1);

namespace App\Enrollment;

/** Monetary subject fields are final assessed amounts, not pre-multiplier base fees. */
final readonly class EnrollmentFeeCalculator
{
    public const array Defaults = [
        'nstp_lecture_multiplier' => 0.5,
        'modular_laboratory_multiplier' => 1.0,
        'modular_fee' => 2400.0,
        'miscellaneous_fee_fallback' => 3500.0,
        'lecture_fee_basis' => 'combined_units',
        'laboratory_fee_basis' => 'flat_subject',
        'nstp_code_marker' => 'NSTP',
        'default_downpayment' => 3500.0,
        'miscellaneous_fee_override' => null,
    ];

    /**
     * Missing amounts are calculated independently. Explicit zero is a valid override.
     *
     * @param  array<string, mixed>  $subject
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $assessedFees
     * @return array{lecture_fee: float, laboratory_fee: float, modular_fee: float}
     */
    public function subject(array $subject, array $configuration = [], bool $modular = false, array $assessedFees = []): array
    {
        $configuration = [...self::Defaults, ...$configuration];
        $lectureUnits = (float) ($subject['lecture'] ?? 0);
        $laboratoryUnits = (float) ($subject['laboratory'] ?? 0);
        $lecture = $lectureUnits > 0
            ? ($configuration['lecture_fee_basis'] === 'lecture_units' ? $lectureUnits : $lectureUnits + $laboratoryUnits) * (float) ($configuration['course_lecture_rate_per_unit'] ?? 0)
            : 0.0;
        $marker = mb_strtoupper(mb_trim((string) $configuration['nstp_code_marker']));
        if ($marker !== '' && str_contains(mb_strtoupper((string) ($subject['code'] ?? '')), $marker)) {
            $lecture *= (float) $configuration['nstp_lecture_multiplier'];
        }
        $laboratory = $laboratoryUnits > 0
            ? ($configuration['laboratory_fee_basis'] === 'lab_units' ? $laboratoryUnits : 1) * (float) ($configuration['course_laboratory_rate_per_unit'] ?? 0)
            : 0.0;
        if ($modular) {
            $laboratory *= (float) $configuration['modular_laboratory_multiplier'];
        }

        return [
            'lecture_fee' => round((float) ($assessedFees['lecture_fee'] ?? $lecture), 2),
            'laboratory_fee' => round((float) ($assessedFees['laboratory_fee'] ?? $laboratory), 2),
            'modular_fee' => $modular ? round((float) $configuration['modular_fee'], 2) : 0.0,
        ];
    }
}
