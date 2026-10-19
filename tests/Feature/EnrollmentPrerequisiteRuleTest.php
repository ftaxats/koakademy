<?php

declare(strict_types=1);

use App\Data\Enrollment\EnrollmentContext;
use App\Enrollment\EnrollmentPolicyRegistry;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;

it('requires a passing prerequisite in a completed prior period', function (?string $outcome, string $year, string $state, bool $passed): void {
    $course = Course::factory()->create(['school_id' => App\Models\School::factory()->create()->id]);
    $student = Student::factory()->create(['course_id' => $course->id]);
    $enrollment = StudentEnrollment::factory()->create(['student_id' => $student->id, 'course_id' => $course->id, 'school_id' => $course->school_id, 'school_year' => '2035 - 2036', 'semester' => 1]);
    $prerequisite = Subject::factory()->create(['course_id' => $course->id]);
    $subject = Subject::factory()->create(['course_id' => $course->id, 'pre_riquisite' => [$prerequisite->id]]);
    $enrollment->subjectsEnrolled()->create(['student_id' => $student->id, 'subject_id' => $subject->id, 'school_year' => $enrollment->school_year, 'semester' => 1, 'academic_year' => 1]);
    $prior = StudentEnrollment::factory()->create(['student_id' => $student->id, 'course_id' => $course->id, 'school_id' => $enrollment->school_id, 'school_year' => $year, 'semester' => 1, 'terminal_outcome' => $state]);
    $prior->subjectsEnrolled()->create(['student_id' => $student->id, 'subject_id' => $prerequisite->id, 'school_year' => $year, 'semester' => 1, 'academic_year' => 1, 'grade' => 99, 'grade_outcome' => $outcome]);

    $result = app(EnrollmentPolicyRegistry::class)->rule('academics.verified_prerequisites')->evaluate(EnrollmentContext::fromEnrollment($enrollment, facts: ['prerequisites_complete' => true]), []);

    expect($result->passed)->toBe($passed);
})->with([
    'passed earlier' => ['pass', '2034 - 2035', 'completed', true],
    'failed' => ['fail', '2034 - 2035', 'completed', false],
    'unevaluated legacy grade' => [null, '2034 - 2035', 'completed', false],
    'cancelled' => ['pass', '2034 - 2035', 'cancelled', false],
    'same normalized period' => ['pass', '2035-2036', 'completed', false],
    'future period' => ['pass', '2036 - 2037', 'completed', false],
]);

it('accepts mapped credited prerequisites only when configured', function (bool $accept): void {
    $course = Course::factory()->create(['school_id' => App\Models\School::factory()->create()->id]);
    $student = Student::factory()->create(['course_id' => $course->id]);
    $enrollment = StudentEnrollment::factory()->create(['student_id' => $student->id, 'school_id' => $course->school_id, 'school_year' => '2035 - 2036', 'semester' => 1]);
    $prerequisite = Subject::factory()->create(['course_id' => $course->id]);
    $subject = Subject::factory()->create(['course_id' => $course->id, 'pre_riquisite' => [$prerequisite->id]]);
    $enrollment->subjectsEnrolled()->create(['student_id' => $student->id, 'subject_id' => $subject->id, 'school_year' => $enrollment->school_year, 'semester' => 1, 'academic_year' => 1]);
    $prior = StudentEnrollment::factory()->create(['student_id' => $student->id, 'school_id' => $course->school_id, 'school_year' => '2034 - 2035', 'semester' => 1, 'terminal_outcome' => 'completed']);
    $prior->subjectsEnrolled()->create(['student_id' => $student->id, 'subject_id' => Subject::factory()->create(['course_id' => $course->id])->id, 'credited_subject_id' => $prerequisite->id, 'is_credited' => true, 'school_year' => $prior->school_year, 'semester' => 1, 'academic_year' => 1]);

    $result = app(EnrollmentPolicyRegistry::class)->rule('academics.verified_prerequisites')->evaluate(EnrollmentContext::fromEnrollment($enrollment), ['accept_credited' => $accept]);

    expect($result->passed)->toBe($accept);
})->with(['no automatic credit' => false, 'approved credits allowed' => true]);

it('evaluates grades using their pinned policy instead of hardcoded passing thresholds', function (string $type, mixed $grade, ?string $symbol, bool $passed): void {
    $course = Course::factory()->create(['school_id' => App\Models\School::factory()->create()->id]);
    $student = Student::factory()->create(['course_id' => $course->id]);
    $enrollment = StudentEnrollment::factory()->create(['student_id' => $student->id, 'school_id' => $course->school_id, 'school_year' => '2035 - 2036', 'semester' => 1]);
    $prerequisite = Subject::factory()->create(['course_id' => $course->id]);
    $subject = Subject::factory()->create(['course_id' => $course->id, 'pre_riquisite' => [$prerequisite->id]]);
    $enrollment->subjectsEnrolled()->create(['student_id' => $student->id, 'subject_id' => $subject->id, 'school_year' => $enrollment->school_year, 'semester' => 1, 'academic_year' => 1]);
    $prior = StudentEnrollment::factory()->create(['student_id' => $student->id, 'school_id' => $course->school_id, 'school_year' => '2034 - 2035', 'semester' => 1, 'terminal_outcome' => 'completed']);
    $policy = App\Models\GradingPolicy::query()->create(['school_id' => $course->school_id, 'name' => 'Prerequisite grading']);
    $version = $policy->versions()->create(['version' => 1, 'state' => 'published', 'configuration' => [
        'input_type' => $type, 'numeric_max' => 5, 'bands' => [
            ['min' => 1, 'max' => 2, 'symbol' => 'P', 'outcome' => 'pass'],
            ['min' => 2.01, 'max' => 5, 'symbol' => 'F', 'outcome' => 'fail'],
        ],
    ]]);
    $prior->subjectsEnrolled()->create(['student_id' => $student->id, 'subject_id' => $prerequisite->id, 'school_year' => $prior->school_year, 'semester' => 1, 'academic_year' => 1,
        'grade' => $grade, 'grade_symbol' => $symbol, 'grading_policy_version_id' => $version->id]);

    $result = app(EnrollmentPolicyRegistry::class)->rule('academics.verified_prerequisites')->evaluate(EnrollmentContext::fromEnrollment($enrollment), []);

    expect($result->passed)->toBe($passed);
})->with([
    'point scale pass' => ['numeric', 1.5, null, true],
    'point scale fail' => ['numeric', 3.0, null, false],
    'symbol pass' => ['symbol', null, 'P', true],
    'symbol fail' => ['symbol', null, 'F', false],
]);

it('fails closed for invalid or cross-school prerequisite curriculum', function (string $kind): void {
    $course = Course::factory()->create(['school_id' => App\Models\School::factory()->create()->id]);
    $enrollment = StudentEnrollment::factory()->create(['school_id' => $course->school_id, 'course_id' => $course->id]);
    $foreignCourse = Course::factory()->create(['school_id' => App\Models\School::factory()->create()->id]);
    $foreign = Subject::factory()->create(['course_id' => $foreignCourse->id]);
    $ids = match ($kind) {
        'foreign' => [$foreign->id],
        'invalid' => ['BIO-101'],
        'self' => [],
    };
    $subject = Subject::factory()->create(['course_id' => $course->id, 'pre_riquisite' => $ids]);
    if ($kind === 'self') {
        $subject->update(['pre_riquisite' => [$subject->id]]);
    }
    $enrollment->subjectsEnrolled()->create(['student_id' => $enrollment->student_id, 'subject_id' => $subject->id, 'school_year' => $enrollment->school_year, 'semester' => 1, 'academic_year' => 1]);

    $result = app(EnrollmentPolicyRegistry::class)->rule('academics.verified_prerequisites')->evaluate(EnrollmentContext::fromEnrollment($enrollment), []);

    expect($result->passed)->toBeFalse();
})->with(['foreign', 'invalid', 'self']);

it('passes a subject without prerequisites without writing grade outcomes', function (): void {
    $course = Course::factory()->create(['school_id' => App\Models\School::factory()->create()->id]);
    $enrollment = StudentEnrollment::factory()->create(['school_id' => $course->school_id, 'course_id' => $course->id]);
    $subject = Subject::factory()->create(['course_id' => $course->id, 'pre_riquisite' => []]);
    $row = $enrollment->subjectsEnrolled()->create(['student_id' => $enrollment->student_id, 'subject_id' => $subject->id, 'school_year' => $enrollment->school_year, 'semester' => 1, 'academic_year' => 1]);

    $result = app(EnrollmentPolicyRegistry::class)->rule('academics.verified_prerequisites')->evaluate(EnrollmentContext::fromEnrollment($enrollment), []);

    expect($result->passed)->toBeTrue();
    expect($row->refresh()->grade_outcome)->toBeNull();
});

it('reports missing prerequisites in saved-record simulation but defers hypothetical samples', function (): void {
    $course = Course::factory()->create(['school_id' => App\Models\School::factory()->create()->id]);
    $enrollment = StudentEnrollment::factory()->create(['school_id' => $course->school_id, 'course_id' => $course->id]);
    $configuration = App\Enrollment\EnrollmentPolicyPreset::standard();
    $configuration['rules'] = [['key' => 'prerequisites', 'handler' => 'academics.verified_prerequisites', 'configuration' => []]];
    $compiled = app(App\Enrollment\EnrollmentPolicyCompiler::class)->compile([['version_id' => 1, 'configuration' => $configuration]]);
    $simulation = app(App\Enrollment\EnrollmentPolicySimulationService::class);

    $saved = $simulation->simulateCompiled($compiled, EnrollmentContext::fromEnrollment($enrollment));
    $sample = $simulation->simulateCompiled($compiled, new EnrollmentContext(null, null, null, null, null));

    expect($saved['blockers'])->toHaveCount(1);
    expect($sample['eligibility'][0]['metadata']['deferred'])->toBeTrue();
});

it('rejects nonboolean credit acceptance before publishing', function (): void {
    $configuration = App\Enrollment\EnrollmentPolicyPreset::standard();
    $configuration['rules'][] = ['key' => 'prerequisites', 'handler' => 'academics.verified_prerequisites', 'configuration' => ['accept_credited' => 'yes']];

    expect(fn () => app(App\Enrollment\EnrollmentPolicyCompiler::class)->compile([['version_id' => 1, 'configuration' => $configuration]]))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});
