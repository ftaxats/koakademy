<?php

declare(strict_types=1);

use App\Enrollment\EnrollmentPolicyManager;
use App\Enrollment\EnrollmentPolicyPreset;
use App\Enums\UserRole;
use App\Features\DynamicEnrollmentPolicies;
use App\Models\Course;
use App\Models\GeneralSetting;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use Laravel\Pennant\Feature;

it('quotes published program billing rules for a new administrator enrollment', function (): void {
    GeneralSetting::factory()->create(['school_starting_date' => '2026-06-01', 'school_ending_date' => '2027-03-31', 'semester' => 1]);
    $actor = User::factory()->create(['role' => UserRole::Admin]);
    $school = School::factory()->create();
    $course = Course::factory()->create(['school_id' => $school->id, 'lec_per_unit' => 100, 'lab_per_unit' => 2000]);
    $student = Student::factory()->create(['school_id' => $school->id, 'course_id' => $course->id]);
    $subject = Subject::factory()->create(['course_id' => $course->id, 'lecture' => 3, 'laboratory' => 2]);
    $configuration = EnrollmentPolicyPreset::standard();
    $configuration['billing']['configuration']['modular_laboratory_multiplier'] = 0.25;
    $configuration['billing']['configuration']['modular_fee'] = 300;
    $configuration['billing']['configuration']['lecture_fee_basis'] = 'lecture_units';
    $configuration['billing']['configuration']['laboratory_fee_basis'] = 'lab_units';
    $configuration['billing']['configuration']['default_downpayment'] = 0;
    $configuration['billing']['configuration']['miscellaneous_fee_override'] = 0;
    $manager = app(EnrollmentPolicyManager::class);
    $policy = $manager->create(['name' => 'Program pricing', 'school_id' => $school->id, 'course_id' => $course->id, 'configuration' => $configuration], $actor);
    $manager->publish($policy, $policy->versions()->firstOrFail(), $actor);
    $this->actingAs($actor);
    Feature::for($actor)->activate(DynamicEnrollmentPolicies::class);

    $this->getJson(portalUrlForAdministrators('/administrators/enrollments/api/calculate-fees').'?'.http_build_query([
        'subject_id' => $subject->id, 'student_id' => $student->id, 'semester' => 1, 'is_modular' => 1,
    ]))->assertSuccessful()->assertJsonPath('lecture_fee', 300)->assertJsonPath('laboratory_fee', 1000)->assertJsonPath('modular_fee', 300);

    $this->getJson(portalUrlForAdministrators('/administrators/enrollments/api/student-details').'?student_id='.$student->id)
        ->assertSuccessful()->assertJsonPath('billing_configuration.default_downpayment', 0)->assertJsonPath('miscellaneous_fee', 0);
});

it('keeps full modular laboratory fee in legacy quotes and saved tuition', function (): void {
    config(['activitylog.enabled' => false]);
    GeneralSetting::factory()->create(['school_starting_date' => '2026-06-01', 'school_ending_date' => '2027-03-31', 'semester' => 1]);
    $actor = User::factory()->create(['role' => UserRole::Admin]);
    $course = Course::factory()->create(['lec_per_unit' => 100, 'lab_per_unit' => 2000]);
    $student = Student::factory()->create(['course_id' => $course->id]);
    $subject = Subject::factory()->create(['course_id' => $course->id, 'code' => 'BIO-101', 'lecture' => 3, 'laboratory' => 1]);
    $this->actingAs($actor);
    Feature::for($actor)->deactivate(DynamicEnrollmentPolicies::class);
    $quote = $this->getJson(portalUrlForAdministrators('/administrators/enrollments/api/calculate-fees').'?'.http_build_query([
        'subject_id' => $subject->id, 'student_id' => $student->id, 'semester' => 1, 'is_modular' => 1,
    ]))->assertSuccessful()->assertJsonPath('laboratory_fee', 2000)->json();

    $this->post(portalUrlForAdministrators('/administrators/enrollments'), [
        'student_id' => $student->id, 'semester' => 1, 'academic_year' => 1,
        'subjects' => [[
            'subject_id' => $subject->id, 'is_modular' => true,
            'lecture_fee' => $quote['lecture_fee'], 'laboratory_fee' => $quote['laboratory_fee'],
            'enrolled_lecture_units' => 3, 'enrolled_laboratory_units' => 1,
        ]],
        'discount' => 0, 'miscellaneous_fee' => 800, 'downpayment' => 0,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $tuition = App\Models\StudentTuition::query()->where('student_id', $student->id)->firstOrFail();
    expect((float) $tuition->total_laboratory)->toBe(2000.0);
    expect((float) $tuition->total_tuition)->toBe(4800.0);
});
