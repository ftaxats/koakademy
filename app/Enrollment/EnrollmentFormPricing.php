<?php

declare(strict_types=1);

namespace App\Enrollment;

use App\Data\Enrollment\EnrollmentContext;
use App\Features\DynamicEnrollmentPolicies;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Services\GeneralSettingsService;
use Laravel\Pennant\Feature;

/** Bridge legacy form state to the same fee policy used by typed enrollment. */
final readonly class EnrollmentFormPricing
{
    public function __construct(
        private EnrollmentFeeCalculator $calculator,
        private EnrollmentFeePolicy $policies,
        private EnrollmentPolicyResolver $resolver,
        private GeneralSettingsService $settings,
    ) {}

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public function configuration(array $state, ?StudentEnrollment $enrollment = null): array
    {
        if ($enrollment?->exists) {
            $configuration = $this->policies->forEnrollment($enrollment);
            $configuration['course_miscellaneous_fee'] ??= $configuration['miscellaneous_fee_override'] ?? $enrollment->course?->getMiscellaneousFee() ?? $configuration['miscellaneous_fee_fallback'];

            return $configuration;
        }
        $student = Student::query()->with('Course')->find($state['student_id'] ?? null);
        $configuration = EnrollmentFeeCalculator::Defaults;
        if ($student && Feature::active(DynamicEnrollmentPolicies::class)) {
            $context = new EnrollmentContext(
                schoolId: $student->school_id, studentType: $student->student_type?->value,
                courseId: $student->course_id,
                schoolYear: (string) ($state['school_year'] ?? $this->settings->getCurrentSchoolYearString()),
                semester: (int) ($state['semester'] ?? $this->settings->getCurrentSemester()),
                yearLevel: isset($state['academic_year']) ? (int) $state['academic_year'] : $student->academic_year,
            );
            $configuration = data_get($this->resolver->resolve($context)->configuration, 'billing.configuration', []);
        }

        return [
            ...EnrollmentFeeCalculator::Defaults,
            ...array_filter($configuration, static fn (mixed $value): bool => $value !== null),
            'course_lecture_rate_per_unit' => (float) ($student?->Course?->lec_per_unit ?? 0),
            'course_laboratory_rate_per_unit' => (float) ($student?->Course?->lab_per_unit ?? 0),
            'course_miscellaneous_fee' => (float) ($configuration['miscellaneous_fee_override'] ?? $student?->Course?->getMiscellaneousFee() ?? EnrollmentFeeCalculator::Defaults['miscellaneous_fee_fallback']),
        ];
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $overrides @return array{lecture_fee: float, laboratory_fee: float, modular_fee: float} */
    public function subject(Subject $subject, bool $modular, array $state, ?StudentEnrollment $enrollment = null, array $overrides = []): array
    {
        $configuration = $this->configuration($state, $enrollment);
        // Without a selected student, preview the subject's own program rates.
        if (! $enrollment && empty($state['student_id'])) {
            $subject->loadMissing('course');
            $configuration['course_lecture_rate_per_unit'] = (float) ($subject->course?->lec_per_unit ?? 0);
            $configuration['course_laboratory_rate_per_unit'] = (float) ($subject->course?->lab_per_unit ?? 0);
        }

        return $this->calculator->subject($subject->toArray(), $configuration, $modular, $overrides);
    }
}
