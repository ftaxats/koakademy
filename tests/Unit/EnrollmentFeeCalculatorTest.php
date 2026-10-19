<?php

declare(strict_types=1);

use App\Enrollment\EnrollmentFeeCalculator;

it('charges configurable modular laboratory percentage once', function (float $multiplier, float $expected): void {
    $fees = (new EnrollmentFeeCalculator)->subject(
        ['code' => 'BIO-101', 'lecture' => 3, 'laboratory' => 1],
        ['course_lecture_rate_per_unit' => 100, 'course_laboratory_rate_per_unit' => 2000, 'modular_laboratory_multiplier' => $multiplier, 'modular_fee' => 700],
        true,
    );

    expect($fees)->toBe(['lecture_fee' => 400.0, 'laboratory_fee' => $expected, 'modular_fee' => 700.0]);
})->with(['full' => [1.0, 2000.0], 'half' => [0.5, 1000.0], 'waived' => [0.0, 0.0]]);

it('keeps full laboratory fee by default', function (): void {
    $fees = (new EnrollmentFeeCalculator)->subject(['laboratory' => 1], ['course_laboratory_rate_per_unit' => 2000], true);

    expect($fees['laboratory_fee'])->toBe(2000.0);
});

it('preserves final custom amounts including zero on repeated calculations', function (float $amount): void {
    $calculator = new EnrollmentFeeCalculator;
    $configuration = ['course_laboratory_rate_per_unit' => 2000, 'modular_laboratory_multiplier' => 0.5, 'modular_fee' => 0];
    $subject = ['code' => 'NSTP-1', 'lecture' => 3, 'laboratory' => 1];
    $fees = $calculator->subject($subject, $configuration, true, ['lecture_fee' => 0, 'laboratory_fee' => $amount]);

    expect($calculator->subject($subject, $configuration, true, $fees))->toBe([
        'lecture_fee' => 0.0, 'laboratory_fee' => $amount, 'modular_fee' => 0.0,
    ]);
})->with(['zero override' => 0.0, 'already reduced' => 1000.0, 'custom override' => 1234.56]);

it('calculates configurable lecture and lab bases', function (string $lectureBasis, string $labBasis, float $lecture, float $lab): void {
    $fees = (new EnrollmentFeeCalculator)->subject(['code' => 'BIO-1', 'lecture' => 3, 'laboratory' => 2], [
        'lecture_fee_basis' => $lectureBasis, 'laboratory_fee_basis' => $labBasis,
        'course_lecture_rate_per_unit' => 100, 'course_laboratory_rate_per_unit' => 2000,
    ]);

    expect($fees)->toBe(['lecture_fee' => $lecture, 'laboratory_fee' => $lab, 'modular_fee' => 0.0]);
})->with([
    'compatibility' => ['combined_units', 'flat_subject', 500.0, 2000.0],
    'lecture only' => ['lecture_units', 'flat_subject', 300.0, 2000.0],
    'per lab unit' => ['combined_units', 'lab_units', 500.0, 4000.0],
    'both custom' => ['lecture_units', 'lab_units', 300.0, 4000.0],
]);

it('uses configurable code matching or disables it', function (string $marker, float $expected): void {
    $fees = (new EnrollmentFeeCalculator)->subject(['code' => 'COMMUNITY-101', 'lecture' => 3], [
        'nstp_code_marker' => $marker, 'nstp_lecture_multiplier' => 0.25, 'course_lecture_rate_per_unit' => 100,
    ]);

    expect($fees['lecture_fee'])->toBe($expected);
})->with(['custom marker' => ['community', 75.0], 'disabled' => ['', 300.0], 'nonmatching' => ['NSTP', 300.0]]);
