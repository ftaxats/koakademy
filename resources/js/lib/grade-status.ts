import { gradeOutcomeStatusLabel, isPassingGrade, isPassingOutcome, parseNumericGrade, resolveItemBand, type GradingConfig } from "@/lib/gwa";

export type GradeStatusVariant = "default" | "destructive" | "secondary" | "outline";

export interface GradeStatusBadge {
    label: string;
    variant: GradeStatusVariant;
    className: string;
    isPassed: boolean;
}

export interface GradeStatusSubject {
    grade: number | string | null;
    grade_outcome?: string | null;
    classification?: string | null;
    status?: string | null;
}

const PASSED_CLASS = "bg-green-600 hover:bg-green-700";

/**
 * Resolve the status badge for a checklist subject.
 *
 * The outcome may come from two places: the `grade_outcome` snapshot stored
 * on the enrollment when the grade was entered, or a live lookup of the
 * grade against the school's current grading bands. Both paths are normalized
 * to the same wording so a subject reads identically whether or not it has a
 * stored snapshot.
 */
export function resolveGradeStatusBadge(subject: GradeStatusSubject, config: GradingConfig): GradeStatusBadge {
    const zeroIsDropped = config.zero_is_dropped ?? false;
    const numeric = parseNumericGrade(subject.grade);
    const outcome = subject.grade_outcome;

    if (outcome === "withdrawn" || outcome === "dropped") {
        return { label: gradeOutcomeStatusLabel(outcome), variant: "destructive", className: "", isPassed: false };
    }

    if (zeroIsDropped && numeric !== null && numeric === 0) {
        return { label: gradeOutcomeStatusLabel("dropped"), variant: "destructive", className: "", isPassed: false };
    }

    if (isPassingOutcome(outcome)) {
        return { label: gradeOutcomeStatusLabel(outcome), variant: "default", className: PASSED_CLASS, isPassed: true };
    }
    if (outcome === "fail" || outcome === "failed") {
        return { label: gradeOutcomeStatusLabel(outcome), variant: "destructive", className: "", isPassed: false };
    }
    if (outcome === "incomplete") {
        return { label: gradeOutcomeStatusLabel(outcome), variant: "secondary", className: "", isPassed: false };
    }

    const band = resolveItemBand(subject.grade, config, subject.classification);
    if (band) {
        const isPassed = band.outcome === "pass";
        const isFailOrDropped = band.outcome === "fail" || band.outcome === "withdrawn";
        const variant = isPassed ? "default" : isFailOrDropped ? "destructive" : band.outcome === "incomplete" ? "secondary" : "outline";
        const className = isPassed ? PASSED_CLASS : "";
        return {
            label: gradeOutcomeStatusLabel(band.outcome, band.label),
            variant,
            className,
            isPassed,
        };
    }

    if (numeric === null) {
        if (subject.status === "Completed") {
            return { label: gradeOutcomeStatusLabel("pass"), variant: "default", className: PASSED_CLASS, isPassed: true };
        }
        const isProg = subject.status === "In Progress";
        return {
            label: subject.status ?? "Not Completed",
            variant: isProg ? "secondary" : "outline",
            className: "",
            isPassed: false,
        };
    }

    const isPassed = isPassingGrade(numeric, config, subject.classification);
    return {
        label: gradeOutcomeStatusLabel(isPassed ? "pass" : "fail"),
        variant: isPassed ? "default" : "destructive",
        className: isPassed ? PASSED_CLASS : "",
        isPassed,
    };
}
