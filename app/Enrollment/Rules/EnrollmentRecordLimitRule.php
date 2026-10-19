<?php

declare(strict_types=1);

namespace App\Enrollment\Rules;

use App\Contracts\Enrollment\EnrollmentOperatorSchemaProvider;
use App\Contracts\Enrollment\EnrollmentRuleHandler;
use App\Data\Enrollment\EnrollmentContext;
use App\Data\Enrollment\RuleResult;
use App\Models\StudentTuition;
use App\Services\EnrollmentBillingService;

/** Trusted record checks: request facts never supply the measured value. */
final readonly class EnrollmentRecordLimitRule implements EnrollmentOperatorSchemaProvider, EnrollmentRuleHandler
{
    public function __construct(private string $handlerKey, private EnrollmentBillingService $billing) {}

    public function key(): string
    {
        return $this->handlerKey;
    }

    public function metadata(): array
    {
        return [
            'key' => $this->handlerKey,
            'label' => $this->isUnits() ? 'Maximum assigned units (verified records)' : 'Maximum other-assessment balance (ledger)',
            'category' => $this->isUnits() ? 'academics' : 'billing',
        ];
    }

    public function configurationSchema(): array
    {
        return $this->operatorSchema();
    }

    public function operatorSchema(): array
    {
        return [
            'description' => $this->isUnits()
                ? 'At completion, count current curriculum lecture and lab units for saved subject enrollments. Includes tuition-excluded subjects. Does not trust request facts.'
                : 'At completion, sum positive balances on this student\'s other non-cancelled assessments in the current school. Credits on another assessment do not offset debt. Does not trust request facts.',
            'fields' => [[
                'key' => 'maximum', 'label' => $this->isUnits() ? 'Maximum units' : 'Maximum outstanding amount',
                'control' => $this->isUnits() ? 'number' : 'money',
                'required' => true, 'minimum' => 0, 'step' => 0.01,
            ]],
        ];
    }

    public function evaluate(EnrollmentContext $context, array $configuration): RuleResult
    {
        $maximum = $configuration['maximum'] ?? null;
        if (! is_numeric($maximum) || ! is_finite((float) $maximum) || (float) $maximum < 0) {
            return RuleResult::fail('A non-negative maximum is required.');
        }
        $enrollment = $context->enrollment;
        if (! $enrollment?->exists) {
            return RuleResult::fail('A saved enrollment is required for this record check.');
        }
        if ($this->isUnits()) {
            $subjects = $enrollment->subjectsEnrolled()->with('subject')->get();
            if ($subjects->isEmpty()) {
                return RuleResult::fail('Assign subjects before checking the unit limit.');
            }
            $actual = 0.0;
            foreach ($subjects as $row) {
                if (! $row->subject) {
                    return RuleResult::fail('Every assigned subject requires a valid subject record for the unit check.');
                }
                // Curriculum units are authoritative; editable fee payload units cannot bypass the gate.
                $actual += (float) $row->subject->lecture + (float) $row->subject->laboratory;
            }
        } else {
            $assessments = StudentTuition::query()
                ->where('student_id', $enrollment->student_id)
                ->where('enrollment_id', '!=', $enrollment->id)
                ->with('enrollment')
                ->whereHas('enrollment', function ($query) use ($enrollment): void {
                    $query->where('school_id', $enrollment->school_id)
                        ->where(fn ($query) => $query->whereNull('terminal_outcome')->orWhere('terminal_outcome', '!=', 'cancelled'));
                })->get();
            $actual = 0.0;
            foreach ($assessments as $assessment) {
                $actual += $this->billing->balanceDue($assessment);
            }
        }
        $actual = round($actual, 2);
        $metadata = ['actual' => $actual, 'maximum' => (float) $maximum, 'source' => 'server_records'];

        return $actual <= (float) $maximum
            ? RuleResult::pass($metadata)
            : RuleResult::fail($this->isUnits() ? 'Assigned units exceed the configured maximum.' : 'Other assessment balances exceed the configured maximum.', $metadata);
    }

    private function isUnits(): bool
    {
        return $this->handlerKey === 'academics.maximum_assigned_units';
    }
}
