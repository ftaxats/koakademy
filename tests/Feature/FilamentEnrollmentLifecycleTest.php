<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Features\DynamicEnrollmentPolicies;
use App\Filament\Resources\StudentEnrollments\Pages\EditStudentEnrollment;
use App\Models\Course;
use App\Models\GeneralSetting;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\StudentTuition;
use App\Models\Subject;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

it('opens edits and saves modular enrollment fees through Livewire', function (bool $toggle, float $expectedLab, bool $pinned): void {
    config(['activitylog.enabled' => false]);
    GeneralSetting::factory()->create(['semester' => 1, 'school_starting_date' => '2035-08-01', 'school_ending_date' => '2036-05-31']);
    $actor = User::factory()->create(['role' => UserRole::Admin]);
    foreach (['View:StudentEnrollment', 'Update:StudentEnrollment', 'ViewAny:StudentEnrollment'] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($actor);
    Feature::for($actor)->deactivate(DynamicEnrollmentPolicies::class);
    Filament::setCurrentPanel('admin');
    $course = Course::factory()->create(['lec_per_unit' => 100, 'lab_per_unit' => 2000, 'miscelaneous' => 800]);
    $student = Student::factory()->create(['course_id' => $course->id, 'academic_year' => 1]);
    $enrollment = StudentEnrollment::factory()->legacyWorkflow()->create(['student_id' => $student->id, 'course_id' => $course->id, 'academic_year' => 1, 'semester' => 1, 'school_year' => '2035 - 2036']);
    if ($pinned) {
        $configuration = App\Enrollment\EnrollmentPolicyPreset::standard();
        $configuration['billing']['configuration'] = [...$configuration['billing']['configuration'],
            'modular_laboratory_multiplier' => 0.25, 'modular_fee' => 700,
            'course_lecture_rate_per_unit' => 100, 'course_laboratory_rate_per_unit' => 2000,
        ];
        $snapshot = App\Models\EnrollmentPolicySnapshot::factory()->create(['configuration' => $configuration]);
        $enrollment->forceFill(['workflow_runtime' => StudentEnrollment::WorkflowRuntimePolicyV1, 'enrollment_policy_snapshot_id' => $snapshot->id])->save();
    }
    $subject = Subject::factory()->create(['course_id' => $course->id, 'lecture' => 3, 'laboratory' => 1, 'code' => 'BIO-101']);
    $class = App\Models\Classes::factory()->create(['subject_id' => $subject->id, 'subject_code' => $subject->code, 'subject_ids' => [$subject->id], 'course_codes' => [(string) $course->id], 'semester' => 1, 'school_year' => $enrollment->school_year, 'academic_year' => 1]);
    $row = $enrollment->subjectsEnrolled()->create(['student_id' => $student->id, 'subject_id' => $subject->id, 'class_id' => $class->id, 'academic_year' => 1, 'school_year' => $enrollment->school_year, 'semester' => 1, 'lecture_fee' => 400, 'laboratory_fee' => 1000, 'is_modular' => true]);
    $tuition = StudentTuition::query()->create(['enrollment_id' => $enrollment->id, 'student_id' => $student->id, 'academic_year' => 1, 'school_year' => $enrollment->school_year, 'semester' => 1, 'total_lectures' => 400, 'total_laboratory' => 1000, 'total_tuition' => 3800, 'total_miscelaneous_fees' => 800, 'overall_tuition' => 4600, 'total_balance' => 4600]);

    $page = Livewire::test(EditStudentEnrollment::class, ['record' => $enrollment->id]);
    expect((float) $row->refresh()->laboratory_fee)->toBe(1000.0);
    if ($toggle) {
        $page->set('data.subjectsEnrolled.record-'.$row->id.'.is_modular', false);
        $page->set('data.subjectsEnrolled.record-'.$row->id.'.is_modular', true);
    }
    $page->call('save')->assertHasNoFormErrors();

    expect((float) $row->refresh()->laboratory_fee)->toBe($expectedLab);
    expect((float) $tuition->refresh()->total_laboratory)->toBe($expectedLab);
})->with(['preserve stored fee' => [false, 1000.0, false], 'toggle recalculates once' => [true, 2000.0, false], 'pinned multiplier' => [true, 500.0, true]]);

it('creates a modular enrollment through the actual Filament form', function (bool $dynamic): void {
    config(['activitylog.enabled' => false]);
    Illuminate\Support\Facades\Queue::fake();
    GeneralSetting::factory()->create(['semester' => 1, 'school_starting_date' => '2035-08-01', 'school_ending_date' => '2036-05-31']);
    $actor = User::factory()->create(['role' => UserRole::Admin]);
    foreach (['Create:StudentEnrollment', 'View:StudentEnrollment', 'ViewAny:StudentEnrollment'] as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($actor);
    Feature::for($actor)->deactivate(DynamicEnrollmentPolicies::class);
    Filament::setCurrentPanel('admin');
    $course = Course::factory()->create(['lec_per_unit' => 100, 'lab_per_unit' => 2000, 'miscelaneous' => 800]);
    $student = Student::factory()->create(['course_id' => $course->id, 'academic_year' => 1]);
    if ($dynamic) {
        $configuration = App\Enrollment\EnrollmentPolicyPreset::standard();
        $configuration['billing']['configuration']['modular_fee'] = 700;
        $manager = app(App\Enrollment\EnrollmentPolicyManager::class);
        $policy = $manager->create(['name' => 'Livewire policy', 'course_id' => $course->id, 'configuration' => $configuration], $actor);
        $manager->publish($policy, $policy->versions()->firstOrFail(), $actor);
        Feature::for($actor)->activate(DynamicEnrollmentPolicies::class);
    }
    $subject = Subject::factory()->create(['course_id' => $course->id, 'lecture' => 3, 'laboratory' => 1, 'code' => 'BIO-101', 'academic_year' => 1, 'semester' => 1]);
    $class = App\Models\Classes::factory()->create(['subject_id' => $subject->id, 'subject_code' => $subject->code, 'subject_ids' => [$subject->id], 'course_codes' => [(string) $course->id], 'semester' => 1, 'school_year' => '2035 - 2036', 'academic_year' => 1]);

    Livewire::test(App\Filament\Resources\StudentEnrollments\Pages\CreateStudentEnrollment::class)
        ->fillForm([
            'student_id' => $student->id, 'course_id' => $course->id, 'academic_year' => 1, 'semester' => 1,
            'subjectsEnrolled' => [[
                'subject_id' => $subject->id, 'class_id' => $class->id, 'section' => $class->id,
                'is_modular' => true, 'lecture' => 400, 'laboratory' => 2000,
                'enrolled_lecture_units' => 3, 'enrolled_laboratory_units' => 1,
            ]],
            'discount' => 0, 'miscellaneous' => 800, 'downpayment' => 0,
            'total_lectures' => 400, 'total_laboratory' => 2000, 'Total_Tuition' => 4800,
            'overall_total' => 5600, 'total_balance' => 5600, 'is_overall_manually_modified' => false,
        ])->call('create')->assertHasNoFormErrors();

    $enrollment = StudentEnrollment::query()->where('student_id', $student->id)->sole();
    expect((float) $enrollment->subjectsEnrolled()->sole()->laboratory_fee)->toBe(2000.0);
    expect((float) $enrollment->studentTuition()->sole()->overall_tuition)->toBe($dynamic ? 3900.0 : 5600.0);
})->with(['legacy create' => false, 'policy create' => true]);
