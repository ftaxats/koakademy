<?php

declare(strict_types=1);

use App\Enrollment\EnrollmentFormPricing;
use App\Enrollment\EnrollmentPolicyPreset;
use App\Models\Course;
use App\Models\EnrollmentPolicySnapshot;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Providers\EnrollmentServiceProvider;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

it('preserves pinned Filament subject pricing and explicit zero overrides', function (): void {
    $course = Course::factory()->create(['lec_per_unit' => 100, 'lab_per_unit' => 2000]);
    $student = Student::factory()->create(['course_id' => $course->id]);
    $configuration = EnrollmentPolicyPreset::standard();
    $configuration['billing']['configuration'] = [...$configuration['billing']['configuration'],
        'modular_laboratory_multiplier' => 0.25, 'modular_fee' => 700,
        'course_lecture_rate_per_unit' => 100, 'course_laboratory_rate_per_unit' => 2000,
    ];
    $snapshot = EnrollmentPolicySnapshot::factory()->create(['configuration' => $configuration]);
    $enrollment = StudentEnrollment::withoutEvents(fn () => StudentEnrollment::factory()->create([
        'student_id' => $student->id, 'course_id' => $course->id,
        'workflow_runtime' => StudentEnrollment::WorkflowRuntimePolicyV1, 'enrollment_policy_snapshot_id' => $snapshot->id,
    ]));
    $subject = Subject::factory()->create(['course_id' => $course->id, 'code' => 'BIO-1', 'lecture' => 3, 'laboratory' => 1]);
    $course->update(['lab_per_unit' => 9000]);
    $pricing = app(EnrollmentFormPricing::class);

    $fees = $pricing->subject($subject, true, [], $enrollment);
    $waived = $pricing->subject($subject, true, [], $enrollment, ['lecture_fee' => 0, 'laboratory_fee' => 0]);

    expect($fees)->toBe(['lecture_fee' => 400.0, 'laboratory_fee' => 500.0, 'modular_fee' => 700.0]);
    expect($waived['lecture_fee'])->toBe(0.0);
    expect($waived['laboratory_fee'])->toBe(0.0);
});

it('calculates Filament totals with a single modular surcharge and preserves zero miscellaneous', function (): void {
    $course = Course::factory()->create(['miscelaneous' => 800]);
    $student = Student::factory()->create(['course_id' => $course->id]);
    $state = [
        'student_id' => $student->id, 'miscellaneous' => 0, 'discount' => 0, 'downpayment' => 3500,
        'subjectsEnrolled' => [
            ['subject_id' => 1, 'lecture' => 400, 'laboratory' => 2000, 'is_modular' => true],
            ['subject_id' => 2, 'lecture' => 9000, 'laboratory' => 9000, 'is_modular' => true, 'exclude_from_tuition' => true],
        ],
        'additionalFees' => [],
    ];
    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->andReturnUsing(fn ($key) => $state[$key] ?? null);
    $result = [];
    $set = Mockery::mock(Set::class);
    $set->shouldReceive('__invoke')->andReturnUsing(function ($key, $value) use (&$result): void {
        $result[$key] = $value;
    });

    EnrollmentServiceProvider::updateTotals($get, $set);

    expect($result['Total_Tuition'])->toBe('4800.00');
    expect($result['overall_total'])->toBe('4800.00');
    expect($result['miscellaneous'])->toBe('0.00');
    expect($result['total_balance'])->toBe('4800.00');
});

it('uses published program billing rules before a Filament enrollment exists', function (): void {
    $actor = App\Models\User::factory()->create();
    $this->actingAs($actor);
    $course = Course::factory()->create(['lec_per_unit' => 100, 'lab_per_unit' => 2000]);
    $student = Student::factory()->create(['course_id' => $course->id]);
    $configuration = EnrollmentPolicyPreset::standard();
    $configuration['billing']['configuration'] = [...$configuration['billing']['configuration'], 'modular_fee' => 700, 'modular_laboratory_multiplier' => 0.5, 'miscellaneous_fee_override' => 0, 'default_downpayment' => 0];
    $manager = app(App\Enrollment\EnrollmentPolicyManager::class);
    $policy = $manager->create(['name' => 'Filament pricing', 'course_id' => $course->id, 'configuration' => $configuration], $actor);
    $manager->publish($policy, $policy->versions()->firstOrFail(), $actor);
    Laravel\Pennant\Feature::for($actor)->activate(App\Features\DynamicEnrollmentPolicies::class);
    $subject = Subject::factory()->create(['course_id' => $course->id, 'code' => 'BIO-1', 'lecture' => 3, 'laboratory' => 1]);
    $pricing = app(EnrollmentFormPricing::class);

    $fees = $pricing->subject($subject, true, ['student_id' => $student->id]);
    $defaults = $pricing->configuration(['student_id' => $student->id]);

    expect($fees)->toBe(['lecture_fee' => 400.0, 'laboratory_fee' => 1000.0, 'modular_fee' => 700.0]);
    expect($defaults['course_miscellaneous_fee'])->toBe(0.0);
    expect($defaults['default_downpayment'])->toBe(0);
});

it('recalculates Filament aggregate overrides with the same modular tuition convention', function (): void {
    $state = ['original_lecture_amount' => 400, 'total_laboratory' => 2000, 'discount' => 100, 'miscellaneous' => 0,
        'subjectsEnrolled' => [['subject_id' => 1, 'is_modular' => true]], 'additionalFees' => [], 'downpayment' => 0];
    $get = Mockery::mock(Get::class);
    $get->shouldReceive('__invoke')->andReturnUsing(fn ($key) => $state[$key] ?? null);
    $result = [];
    $set = Mockery::mock(Set::class);
    $set->shouldReceive('__invoke')->andReturnUsing(function ($key, $value) use (&$result): void {
        $result[$key] = $value;
    });

    EnrollmentServiceProvider::recalculateTotals($get, $set);

    expect($result['Total_Tuition'])->toBe('4400.00');
    expect($result['overall_total'])->toBe('4400.00');
    expect($result['total_lectures'])->toBe('0.00');
});
