<?php

declare(strict_types=1);

namespace App\Enrollment;

use App\Models\StudentEnrollment;

final readonly class EnrollmentFeePolicy
{
    /** @return array<string, mixed> */
    public function forEnrollment(StudentEnrollment $enrollment): array
    {
        $enrollment->loadMissing(['course', 'policySnapshot']);
        $configuration = $enrollment->workflow_runtime === StudentEnrollment::WorkflowRuntimePolicyV1
            ? data_get($enrollment->policySnapshot?->configuration, 'billing.configuration', [])
            : [];

        return [
            ...EnrollmentFeeCalculator::Defaults,
            'course_lecture_rate_per_unit' => (float) ($enrollment->course?->lec_per_unit ?? 0),
            'course_laboratory_rate_per_unit' => (float) ($enrollment->course?->lab_per_unit ?? 0),
            ...array_filter($configuration, static fn (mixed $value): bool => $value !== null),
        ];
    }
}
