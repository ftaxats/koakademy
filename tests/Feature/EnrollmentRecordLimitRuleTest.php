<?php

declare(strict_types=1);

use App\Data\Enrollment\EnrollmentContext;
use App\Enrollment\EnrollmentPolicyRegistry;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentTuition;
use App\Models\Subject;

it('checks curriculum units rather than forged request facts or zero payload units', function (float $maximum, bool $passed): void {
    $course = Course::factory()->create();
    $student = Student::factory()->create(['course_id' => $course->id]);
    $enrollment = StudentEnrollment::factory()->create(['student_id' => $student->id, 'course_id' => $course->id]);
    $subject = Subject::factory()->create(['course_id' => $course->id, 'lecture' => 3, 'laboratory' => 2]);
    $enrollment->subjectsEnrolled()->create([
        'student_id' => $student->id, 'subject_id' => $subject->id,
        'school_year' => $enrollment->school_year, 'semester' => $enrollment->semester, 'academic_year' => $enrollment->academic_year,
        'enrolled_lecture_units' => 0, 'enrolled_laboratory_units' => 0, 'exclude_from_tuition' => true,
    ]);
    $context = EnrollmentContext::fromEnrollment($enrollment, facts: ['units' => 0, 'academics_maximum_assigned_units' => true]);

    $result = app(EnrollmentPolicyRegistry::class)->rule('academics.maximum_assigned_units')->evaluate($context, ['maximum' => $maximum]);

    expect($result->passed)->toBe($passed);
    expect($result->metadata['actual'])->toBe(5.0);
})->with(['over limit' => [4.0, false], 'at limit' => [5.0, true]]);

it('uses ledger balances and ignores the current assessment and cancelled assessments', function (): void {
    $student = Student::factory()->create();
    $enrollment = StudentEnrollment::factory()->create(['student_id' => $student->id]);
    $other = StudentEnrollment::factory()->create(['student_id' => $student->id, 'school_id' => $enrollment->school_id]);
    $cancelled = StudentEnrollment::factory()->create(['student_id' => $student->id, 'school_id' => $enrollment->school_id, 'terminal_outcome' => 'cancelled']);
    foreach ([[$enrollment, 9000], [$other, 1000], [$cancelled, 8000]] as [$record, $amount]) {
        StudentTuition::query()->create([
            'student_id' => $student->id, 'enrollment_id' => $record->id,
            'school_year' => $record->school_year, 'semester' => $record->semester, 'academic_year' => $record->academic_year,
            'overall_tuition' => $amount, 'total_balance' => 0, 'paid' => 0,
        ]);
    }
    $rule = app(EnrollmentPolicyRegistry::class)->rule('billing.maximum_other_assessment_balance');
    $context = EnrollmentContext::fromEnrollment($enrollment, facts: ['outstanding_balance' => 0]);

    $blocked = $rule->evaluate($context, ['maximum' => 999]);
    $allowed = $rule->evaluate($context, ['maximum' => 1000]);

    expect($blocked->passed)->toBeFalse();
    expect($blocked->metadata['actual'])->toBe(1000.0);
    expect($allowed->passed)->toBeTrue();
});

it('fails closed without saved subjects or a valid maximum', function (): void {
    $enrollment = StudentEnrollment::factory()->create();
    $rule = app(EnrollmentPolicyRegistry::class)->rule('academics.maximum_assigned_units');
    $context = EnrollmentContext::fromEnrollment($enrollment);

    expect($rule->evaluate($context, ['maximum' => 20])->passed)->toBeFalse();
    expect($rule->evaluate($context, ['maximum' => -1])->passed)->toBeFalse();
    expect($rule->evaluate($context, [])->passed)->toBeFalse();
});

it('simulates verified record limits for saved enrollments and defers hypothetical samples', function (): void {
    $enrollment = StudentEnrollment::factory()->create();
    $configuration = App\Enrollment\EnrollmentPolicyPreset::standard();
    $configuration['rules'] = [[
        'key' => 'verified_units', 'handler' => 'academics.maximum_assigned_units', 'configuration' => ['maximum' => 24],
    ]];
    $compiled = app(App\Enrollment\EnrollmentPolicyCompiler::class)->compile([['version_id' => 1, 'configuration' => $configuration]]);
    $simulation = app(App\Enrollment\EnrollmentPolicySimulationService::class);

    $saved = $simulation->simulateCompiled($compiled, EnrollmentContext::fromEnrollment($enrollment));
    $hypothetical = $simulation->simulateCompiled($compiled, new EnrollmentContext(null, null, null, null, null));

    expect($saved['blockers'])->toHaveCount(1);
    expect($saved['blockers'][0]['message'])->toBe('Assign subjects before checking the unit limit.');
    expect($hypothetical['eligibility'][0]['metadata']['deferred'])->toBeTrue();
    expect($hypothetical['writes_performed'])->toBeFalse();
});
