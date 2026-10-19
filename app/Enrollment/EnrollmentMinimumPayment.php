<?php

declare(strict_types=1);

namespace App\Enrollment;

use App\Data\Enrollment\RuleResult;
use App\Models\PaymentAllocation;
use App\Models\StudentEnrollment;
use InvalidArgumentException;

/** Tuition-only verified receipts; expected downpayments and caller facts never count. */
final readonly class EnrollmentMinimumPayment
{
    /** @param array<string, mixed> $configuration */
    public function required(StudentEnrollment $enrollment, array $configuration): float
    {
        $type = $configuration['type'] ?? 'none';
        $value = $configuration['value'] ?? 0;
        if (! in_array($type, ['none', 'fixed', 'percentage'], true)
            || ! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0
            || ($type === 'percentage' && (float) $value > 100)) {
            throw new InvalidArgumentException('Minimum payment configuration is invalid.');
        }
        if ($type === 'none') {
            return 0.0;
        }
        if ($type === 'fixed') {
            return round((float) $value, 2);
        }
        $tuition = $enrollment->studentTuition()->first();
        if (! $tuition) {
            throw new InvalidArgumentException('An assessment is required for a percentage minimum payment.');
        }

        return round(max(0.0, (float) $tuition->overall_tuition) * (float) $value / 100, 2);
    }

    public function received(StudentEnrollment $enrollment): float
    {
        $tuitionId = $enrollment->studentTuition()->value('id');
        $allocations = PaymentAllocation::query()->where('student_tuition_id', $tuitionId)->where('target_type', 'assessment')->where('amount', '>', 0);
        $allocatedTransactionIds = $tuitionId === null ? [] : (clone $allocations)->pluck('transaction_id')->all();
        $paid = $tuitionId === null ? 0.0 : (float) (clone $allocations)
            ->whereHas('transaction', fn ($query) => $query->whereIn('status', ['Paid', 'Completed', 'paid', 'completed']))->sum('amount');
        $links = $enrollment->enrollmentTransactions()
            ->whereNotIn('transaction_id', $allocatedTransactionIds)
            ->whereIn('status', ['Paid', 'Completed', 'paid', 'completed'])
            ->whereHas('transaction', fn ($query) => $query->whereIn('status', ['Paid', 'Completed', 'paid', 'completed']))
            ->with('transaction')->get();
        foreach ($links as $link) {
            $amount = $link->transaction?->settlements['tuition_fee'] ?? 0;
            if (is_numeric($amount) && is_finite((float) $amount) && (float) $amount > 0) {
                $paid += (float) $amount;
            }
        }

        return round($paid, 2);
    }

    /** @param array<string, mixed> $configuration */
    public function evaluate(StudentEnrollment $enrollment, array $configuration, float $incoming = 0.0): RuleResult
    {
        try {
            $required = $this->required($enrollment, $configuration);
        } catch (InvalidArgumentException $exception) {
            return RuleResult::fail($exception->getMessage());
        }
        if (! is_finite($incoming) || $incoming < 0) {
            return RuleResult::fail('Incoming tuition payment must be finite and non-negative.');
        }
        $paid = round($this->received($enrollment) + $incoming, 2);
        $metadata = ['paid' => $paid, 'required' => $required, 'source' => 'verified_tuition_receipts'];

        return $paid >= $required
            ? RuleResult::pass($metadata)
            : RuleResult::fail('The configured minimum payment has not been received.', $metadata);
    }
}
