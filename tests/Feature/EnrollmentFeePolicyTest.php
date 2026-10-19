<?php

declare(strict_types=1);

use App\Data\Enrollment\EnrollmentContext;
use App\Enrollment\EnrollmentPolicyPreset;
use App\Enrollment\EnrollmentTuitionService;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\EnrollmentPolicySnapshot;
use App\Models\GeneralSetting;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\User;
use App\Services\AssessmentCalculationService;
use App\Services\AssessmentFormDataService;
use App\Services\EnrollmentBillingService;

it('keeps quoted modular fees consistent through saving editing recalculation and preview', function (float $multiplier, float $expectedLab): void {
    config(['activitylog.enabled' => false]);
    GeneralSetting::factory()->create(['school_starting_date' => '2026-06-01', 'school_ending_date' => '2027-03-31', 'semester' => 1]);
    $actor = User::factory()->create(['role' => UserRole::Admin]);
    $course = Course::factory()->create(['lec_per_unit' => 100, 'lab_per_unit' => 2000, 'miscelaneous' => 800]);
    $student = Student::factory()->create(['course_id' => $course->id]);
    $subject = Subject::factory()->create(['course_id' => $course->id, 'code' => 'BIO-101', 'lecture' => 3, 'laboratory' => 1]);
    $configuration = EnrollmentPolicyPreset::standard();
    $configuration['billing']['configuration']['modular_laboratory_multiplier'] = $multiplier;
    $configuration['billing']['configuration']['modular_fee'] = 700;
    $snapshot = EnrollmentPolicySnapshot::factory()->create(['configuration' => $configuration]);
    $enrollment = StudentEnrollment::withoutEvents(fn () => StudentEnrollment::factory()->create([
        'student_id' => $student->id, 'course_id' => $course->id,
        'workflow_runtime' => StudentEnrollment::WorkflowRuntimePolicyV1,
        'enrollment_policy_snapshot_id' => $snapshot->id,
        'school_year' => '2026 - 2027', 'semester' => 1, 'academic_year' => 1,
    ]));
    $this->actingAs($actor);
    $quote = $this->getJson(portalUrlForAdministrators('/administrators/enrollments/api/calculate-fees').'?'.http_build_query([
        'subject_id' => $subject->id, 'enrollment_id' => $enrollment->id, 'is_modular' => 1,
    ]))->assertSuccessful()->assertJsonPath('laboratory_fee', (int) $expectedLab)->assertJsonPath('modular_fee', 700)->json();
    $subjectData = [
        'subject_id' => $subject->id, 'class_id' => null, 'is_modular' => true, 'exclude_from_tuition' => false,
        'lecture_fee' => $quote['lecture_fee'], 'laboratory_fee' => $quote['laboratory_fee'],
        'enrolled_lecture_units' => 3, 'enrolled_laboratory_units' => 1,
    ];
    $enrollment->subjectsEnrolled()->create([...$subjectData, 'student_id' => $student->id, 'semester' => 1, 'academic_year' => 1, 'school_year' => $enrollment->school_year]);
    $context = EnrollmentContext::fromEnrollment($enrollment);
    $service = app(EnrollmentTuitionService::class);
    $service->persist($context, $configuration['billing']['configuration'], 'fee-regression');
    $tuition = $enrollment->studentTuition()->firstOrFail();
    expect((float) $tuition->total_laboratory)->toBe($expectedLab);

    $payload = ['student_id' => $student->id, 'semester' => 1, 'academic_year' => 1, 'subjects' => [$subjectData], 'discount' => 0, 'miscellaneous_fee' => 800, 'downpayment' => 0];
    $this->put(portalUrlForAdministrators('/administrators/enrollments/'.$enrollment->id), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $this->put(portalUrlForAdministrators('/administrators/enrollments/'.$enrollment->id), $payload)->assertRedirect()->assertSessionHasNoErrors();
    $course->update(['lab_per_unit' => 9000]);
    app(EnrollmentBillingService::class)->recalculateEnrollmentTuition($enrollment->fresh());
    $preview = app(AssessmentFormDataService::class)->build($enrollment->fresh());
    $calculation = app(AssessmentCalculationService::class)->calculateFromEnrolledSubjects($enrollment->fresh());
    $simulation = $service->quote(EnrollmentContext::fromEnrollment($enrollment->fresh()), $configuration['billing']['configuration']);

    expect((float) $tuition->refresh()->total_laboratory)->toBe($expectedLab);
    expect((float) $tuition->total_tuition)->toBe(1100.0 + $expectedLab);
    expect($preview['subjects'][0]['laboratory_fee'])->toBe($expectedLab);
    expect($preview['totals']['modular_fee_per_subject'])->toBe(700.0);
    expect($calculation['laboratory'])->toBe($expectedLab);
    expect($simulation['estimate']['laboratory'])->toBe($expectedLab);
})->with(['full fee' => [1.0, 2000.0], 'half fee' => [0.5, 1000.0], 'waived fee' => [0.0, 0.0]]);
