<?php

declare(strict_types=1);

use App\Data\Enrollment\EnrollmentContext;
use App\Enrollment\EnrollmentMinimumPayment;
use App\Enrollment\EnrollmentPaymentService;
use App\Enrollment\EnrollmentPolicyRegistry;
use App\Models\PaymentAllocation;
use App\Models\StudentEnrollment;
use App\Models\StudentTransaction;
use App\Models\StudentTuition;
use App\Models\Transaction;
use App\Models\User;

it('accepts cumulative tuition receipts and does not double count idempotent retries', function (): void {
    $actor = User::factory()->create();
    $enrollment = StudentEnrollment::factory()->create();
    $tuition = StudentTuition::query()->create([
        'enrollment_id' => $enrollment->id, 'student_id' => $enrollment->student_id,
        'semester' => $enrollment->semester, 'school_year' => $enrollment->school_year,
        'academic_year' => 1, 'overall_tuition' => 1000,
    ]);
    $prior = Transaction::query()->create(['description' => 'Enrollment payment test', 'payment_method' => 'Cash', 'transaction_date' => now(), 'user_id' => $actor->id, 'status' => 'Paid', 'settlements' => ['tuition_fee' => 300, 'others' => 900]]);
    $link = StudentTransaction::query()->create([
        'student_id' => $enrollment->student_id, 'student_enrollment_id' => $enrollment->id,
        'transaction_id' => $prior->id, 'status' => 'Paid', 'amount' => 1200,
    ]);
    PaymentAllocation::query()->create([
        'student_id' => $enrollment->student_id, 'student_enrollment_id' => $enrollment->id,
        'student_tuition_id' => $tuition->id, 'transaction_id' => $prior->id,
        'student_transaction_id' => $link->id, 'amount' => 300, 'target_type' => 'assessment',
    ]);
    $context = EnrollmentContext::fromEnrollment($enrollment, actor: $actor);
    $context = new EnrollmentContext(
        $context->schoolId, $context->studentType, $context->courseId, $context->schoolYear, $context->semester,
        enrollment: $enrollment, actor: $actor,
        pinnedPolicyConfiguration: ['billing' => ['configuration' => ['minimum_payment' => ['type' => 'fixed', 'value' => 500]]]],
    );
    $service = app(EnrollmentPaymentService::class);
    $payload = ['runtime_payload' => ['invoicenumber' => 'CUMULATIVE-1', 'settlements' => ['tuition_fee' => 200]]];

    $first = $service->record($context, $payload, 'cumulative-topup');
    $retry = $service->record($context, $payload, 'cumulative-topup');
    $rule = app(EnrollmentPolicyRegistry::class)->rule('billing.minimum_payment')->evaluate($context, ['type' => 'fixed', 'value' => 500]);

    expect($first->successful)->toBeTrue();
    expect($retry->successful)->toBeTrue();
    expect($retry->metadata['already_exists'])->toBeTrue();
    expect((new EnrollmentMinimumPayment)->received($enrollment))->toBe(500.0);
    expect($rule->passed)->toBeTrue();
    expect($enrollment->enrollmentTransactions()->count())->toBe(2);

    $prior->update(['status' => 'Reversed']);
    expect((new EnrollmentMinimumPayment)->received($enrollment))->toBe(200.0);
    expect((new EnrollmentMinimumPayment)->evaluate($enrollment, ['type' => 'fixed', 'value' => 500])->passed)->toBeFalse();
});

it('ignores non tuition and unverified payments and caller payment facts', function (): void {
    $actor = User::factory()->create();
    $enrollment = StudentEnrollment::factory()->create();
    foreach ([['Paid', ['others' => 1000]], ['Pending', ['tuition_fee' => 1000]]] as [$status, $settlements]) {
        $transaction = Transaction::query()->create(['description' => 'Enrollment payment test', 'payment_method' => 'Cash', 'transaction_date' => now(), 'user_id' => $actor->id, 'status' => $status, 'settlements' => $settlements]);
        StudentTransaction::query()->create([
            'student_id' => $enrollment->student_id, 'student_enrollment_id' => $enrollment->id,
            'transaction_id' => $transaction->id, 'status' => $status, 'amount' => 1000,
        ]);
    }
    $context = EnrollmentContext::fromEnrollment($enrollment, facts: ['payment_amount' => 10000]);

    $result = app(EnrollmentPolicyRegistry::class)->rule('billing.minimum_payment')->evaluate($context, ['type' => 'fixed', 'value' => 500]);

    expect($result->passed)->toBeFalse();
    expect($result->metadata['paid'])->toBe(0.0);
    expect((new EnrollmentMinimumPayment)->evaluate($enrollment, ['type' => 'percentage', 'value' => 50])->passed)->toBeFalse();
});

it('rejects negative settlements without creating ledger entries', function (): void {
    $actor = User::factory()->create();
    $enrollment = StudentEnrollment::factory()->create();
    $context = EnrollmentContext::fromEnrollment($enrollment, actor: $actor);

    $result = app(EnrollmentPaymentService::class)->record($context, [
        'runtime_payload' => ['invoicenumber' => 'INVALID-NEGATIVE', 'settlements' => ['tuition_fee' => -50]],
    ], 'invalid-negative');

    expect($result->successful)->toBeFalse();
    expect($enrollment->enrollmentTransactions()->count())->toBe(0);
});

it('does not bypass a configured minimum through no receipt verification', function (): void {
    $actor = User::factory()->create();
    $enrollment = StudentEnrollment::factory()->create();
    $base = EnrollmentContext::fromEnrollment($enrollment, actor: $actor);
    $context = new EnrollmentContext(
        $base->schoolId, $base->studentType, $base->courseId, $base->schoolYear, $base->semester,
        enrollment: $enrollment, actor: $actor,
        pinnedPolicyConfiguration: ['billing' => ['configuration' => ['minimum_payment' => ['type' => 'fixed', 'value' => 500]]]],
    );

    $result = app(EnrollmentPaymentService::class)->record($context, [
        'receipt_mode' => 'none', 'record_transaction' => false,
        'runtime_payload' => ['reason' => 'Administrative review', 'settlements' => ['tuition_fee' => 1000]],
    ], 'no-receipt-minimum');

    expect($result->successful)->toBeFalse();
    expect($enrollment->enrollmentTransactions()->count())->toBe(0);
});
