import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { createRequire, Module } from "node:module";
import { test } from "node:test";
import { fileURLToPath } from "node:url";
import ts from "typescript";

function loadTs(url) {
    const filename = fileURLToPath(url);
    const { outputText } = ts.transpileModule(readFileSync(url, "utf8"), {
        fileName: filename,
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    });
    const mod = new Module(filename);
    mod.require = createRequire(url);
    mod._compile(outputText, filename);
    return mod.exports;
}

const gwaUrl = new URL("../../resources/js/lib/gwa.ts", import.meta.url);
const gwa = loadTs(gwaUrl);
const { DEFAULT_GRADING_CONFIG, GRADE_OUTCOME_STATUS_LABELS, gradeOutcomeStatusLabel, isPassingOutcome, resolveItemBand } = gwa;

// lib/grade-status.ts imports "@/lib/gwa"; resolve that alias to the loaded module.
const gradeStatusUrl = new URL("../../resources/js/lib/grade-status.ts", import.meta.url);
const gradeStatusFilename = fileURLToPath(gradeStatusUrl);
const { outputText } = ts.transpileModule(readFileSync(gradeStatusUrl, "utf8"), {
    fileName: gradeStatusFilename,
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
});
const gradeStatusModule = new Module(gradeStatusFilename);
gradeStatusModule.require = (id) => (id === "@/lib/gwa" ? gwa : createRequire(gradeStatusUrl)(id));
gradeStatusModule._compile(outputText, gradeStatusFilename);
const { resolveGradeStatusBadge } = gradeStatusModule.exports;

const config = DEFAULT_GRADING_CONFIG;

test("the default policy names its bands after ranges, not results", () => {
    assert.strictEqual(config.bands.find((band) => band.outcome === "pass").label, "Passing");
    assert.strictEqual(config.bands.find((band) => band.outcome === "fail").label, "Failing");
});

test("canonical result wording never reuses the policy band wording", () => {
    for (const label of Object.values(GRADE_OUTCOME_STATUS_LABELS)) {
        assert.notStrictEqual(label, "Passing");
        assert.notStrictEqual(label, "Failing");
    }
});

test("a stored outcome snapshot and a live band lookup resolve to the same label", () => {
    for (const grade of [75, 80, 88, 100]) {
        const band = resolveItemBand(grade, config, "internal");
        assert.strictEqual(band.outcome, "pass");

        // Live band lookup (row has no stored grade_outcome).
        assert.strictEqual(gradeOutcomeStatusLabel(band.outcome, band.label), "Passed");
        // Stored snapshot (row has grade_outcome = "pass").
        assert.strictEqual(gradeOutcomeStatusLabel("pass"), "Passed");
    }
});

test("failing grades read as Failed rather than the policy band name", () => {
    const band = resolveItemBand(60, config, "internal");
    assert.strictEqual(band.label, "Failing");
    assert.strictEqual(gradeOutcomeStatusLabel(band.outcome, band.label), "Failed");
    assert.strictEqual(gradeOutcomeStatusLabel("fail"), "Failed");
});

test("outcome aliases resolve to the same canonical wording", () => {
    assert.strictEqual(gradeOutcomeStatusLabel("passed"), "Passed");
    assert.strictEqual(gradeOutcomeStatusLabel("failed"), "Failed");
    assert.strictEqual(gradeOutcomeStatusLabel("dropped"), "Dropped");
    assert.strictEqual(gradeOutcomeStatusLabel("withdrawn"), "Dropped");
    assert.strictEqual(gradeOutcomeStatusLabel("incomplete"), "Incomplete");
});

test("only pass outcomes count as passing", () => {
    assert.strictEqual(isPassingOutcome("pass"), true);
    assert.strictEqual(isPassingOutcome("passed"), true);
    assert.strictEqual(isPassingOutcome("fail"), false);
    assert.strictEqual(isPassingOutcome("failed"), false);
    assert.strictEqual(isPassingOutcome("withdrawn"), false);
    assert.strictEqual(isPassingOutcome("dropped"), false);
    assert.strictEqual(isPassingOutcome("incomplete"), false);
    assert.strictEqual(isPassingOutcome(null), false);
});

test("outcomes without canonical wording keep the policy band label", () => {
    assert.strictEqual(gradeOutcomeStatusLabel("non_credit", "Audit"), "Audit");
    assert.strictEqual(gradeOutcomeStatusLabel(null, "Audit"), "Audit");
    assert.strictEqual(gradeOutcomeStatusLabel(undefined, "Audit"), "Audit");
    assert.strictEqual(gradeOutcomeStatusLabel("non_credit"), "");
});

test("a legacy row without a stored outcome matches the same row with one", () => {
    for (const [grade, outcome] of [
        [88, "pass"],
        [60, "fail"],
    ]) {
        const legacy = resolveGradeStatusBadge({ grade, classification: "internal", status: "Completed" }, config);
        const withSnapshot = resolveGradeStatusBadge({ grade, grade_outcome: outcome, classification: "internal", status: "Completed" }, config);

        assert.deepStrictEqual(legacy, withSnapshot);
    }
});

test("the checklist status never leaks the policy band wording", () => {
    const statuses = [
        { grade: 88, classification: "internal", status: "Completed" },
        { grade: 60, classification: "internal", status: "Failed" },
        { grade: 88, grade_outcome: "pass", classification: "internal", status: "Completed" },
        { grade: 60, grade_outcome: "fail", classification: "internal", status: "Failed" },
    ];

    for (const subject of statuses) {
        const { label } = resolveGradeStatusBadge(subject, config);
        assert.notStrictEqual(label, "Passing");
        assert.notStrictEqual(label, "Failing");
    }
});

test("passing subjects resolve to a green Passed badge", () => {
    const badge = resolveGradeStatusBadge({ grade: 88, classification: "internal", status: "Completed" }, config);

    assert.strictEqual(badge.label, "Passed");
    assert.strictEqual(badge.variant, "default");
    assert.strictEqual(badge.isPassed, true);
    assert.match(badge.className, /green/);
});

test("failed, dropped and incomplete subjects keep their own wording", () => {
    const failed = resolveGradeStatusBadge({ grade: 60, grade_outcome: "fail", classification: "internal", status: "Failed" }, config);
    assert.strictEqual(failed.label, "Failed");
    assert.strictEqual(failed.variant, "destructive");
    assert.strictEqual(failed.isPassed, false);

    const dropped = resolveGradeStatusBadge({ grade: null, grade_outcome: "withdrawn", classification: "internal", status: "Dropped" }, config);
    assert.strictEqual(dropped.label, "Dropped");
    assert.strictEqual(dropped.variant, "destructive");

    const incomplete = resolveGradeStatusBadge({ grade: 80, grade_outcome: "incomplete", classification: "internal", status: "In Progress" }, config);
    assert.strictEqual(incomplete.label, "Incomplete");
    assert.strictEqual(incomplete.variant, "secondary");
});

test("subjects without a grade fall back to their enrollment status", () => {
    const inProgress = resolveGradeStatusBadge({ grade: null, classification: "internal", status: "In Progress" }, config);
    assert.strictEqual(inProgress.label, "In Progress");
    assert.strictEqual(inProgress.variant, "secondary");

    const notCompleted = resolveGradeStatusBadge({ grade: null, classification: "internal", status: "Not Completed" }, config);
    assert.strictEqual(notCompleted.label, "Not Completed");
    assert.strictEqual(notCompleted.variant, "outline");
});

test("a zero grade is dropped only when the policy says so", () => {
    const withoutPolicy = resolveGradeStatusBadge({ grade: 0, classification: "internal", status: "Failed" }, config);
    assert.strictEqual(withoutPolicy.label, "Failed");

    const withPolicy = resolveGradeStatusBadge(
        { grade: 0, classification: "internal", status: "Failed" },
        {
            ...config,
            zero_is_dropped: true,
        },
    );
    assert.strictEqual(withPolicy.label, "Dropped");
    assert.strictEqual(withPolicy.variant, "destructive");
});

test("the passed alias reads the same as pass", () => {
    const alias = resolveGradeStatusBadge({ grade: 88, grade_outcome: "passed", classification: "internal", status: "Completed" }, config);
    const canonical = resolveGradeStatusBadge({ grade: 88, grade_outcome: "pass", classification: "internal", status: "Completed" }, config);

    assert.deepStrictEqual(alias, canonical);
    assert.strictEqual(alias.label, "Passed");
    assert.strictEqual(alias.isPassed, true);
});
