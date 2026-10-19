<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Features\DynamicEnrollmentPolicies;
use App\Filament\Resources\StudentEnrollments\Api\Handlers\CreateHandler;
use App\Filament\Resources\StudentEnrollments\Api\Requests\CreateStudentEnrollmentRequest;
use App\Models\Course;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\User;
use Laravel\Pennant\Feature;

it('creates API assessments through the selected runtime and replays submissions', function (bool $dynamic): void {
    config(['activitylog.enabled' => false]);
    $actor = User::factory()->create(['role' => UserRole::Admin]);
    $this->actingAs($actor);
    Feature::for($actor)->deactivate(DynamicEnrollmentPolicies::class);
    $course = Course::factory()->create(['lec_per_unit' => 100, 'lab_per_unit' => 2000, 'miscelaneous' => 800]);
    $student = Student::factory()->create(['course_id' => $course->id]);
    if ($dynamic) {
        $configuration = App\Enrollment\EnrollmentPolicyPreset::standard();
        $configuration['billing']['configuration']['modular_fee'] = 700;
        $manager = app(App\Enrollment\EnrollmentPolicyManager::class);
        $policy = $manager->create(['name' => 'API assessment rules', 'course_id' => $course->id, 'configuration' => $configuration], $actor);
        $manager->publish($policy, $policy->versions()->firstOrFail(), $actor);
        Feature::for($actor)->activate(DynamicEnrollmentPolicies::class);
    }
    $subject = Subject::factory()->create(['course_id' => $course->id, 'lecture' => 3, 'laboratory' => 1, 'code' => 'BIO-101']);
    $data = [
        'student_id' => $student->id, 'course_id' => $course->id, 'academic_year' => 1, 'semester' => 1,
        'subjectsEnrolled' => [['subject_id' => $subject->id, 'is_modular' => true]],
        'miscellaneous' => 800, 'downpayment' => 0,
    ];
    // Exercise the handler boundary with an already validated request; HTTP validation remains owned by the form request.
    $request = CreateStudentEnrollmentRequest::create('/api/student-enrollments', 'POST', $data);
    $request->setValidator(Illuminate\Support\Facades\Validator::make($data, array_fill_keys(array_keys($data), ['present'])));
    $request->setUserResolver(fn () => $actor);
    $request->headers->set('Idempotency-Key', 'filament-api-fees');

    (new CreateHandler)->handler($request);
    (new CreateHandler)->handler($request);

    $enrollment = StudentEnrollment::query()->where('student_id', $student->id)->sole();
    $tuition = $enrollment->studentTuition()->sole();
    expect((float) $tuition->total_laboratory)->toBe(2000.0);
    expect((float) $tuition->total_tuition)->toBe($dynamic ? 3100.0 : 4800.0);
    expect((float) $tuition->overall_tuition)->toBe($dynamic ? 3900.0 : 5600.0);
    expect($enrollment->subjectsEnrolled()->count())->toBe(1);
    expect($tuition->school_year)->toBe($enrollment->school_year);
})->with(['legacy' => false, 'policy' => true]);
