function normalizedWeekCount(value) {
    return Math.max(0, Number(value || 0));
}

export function protocolPhaseRanges(phases) {
    const ranges = [];
    let latestEnd = 0;

    phases.forEach((phase, index) => {
        const weekCount = normalizedWeekCount(phase.week_count);
        const previousRange = ranges[index - 1];
        const completedPreviousWeeks = phase.start_after_previous_phase_weeks;
        let start = 1;

        if (index > 0) {
            start = completedPreviousWeeks !== null && previousRange?.start !== null
                ? previousRange.start + Number(completedPreviousWeeks)
                : (previousRange?.end ?? latestEnd) + 1;
        }

        const range = weekCount > 0
            ? { start, end: start + weekCount - 1 }
            : { start: null, end: null };

        ranges.push(range);
        latestEnd = Math.max(latestEnd, range.end ?? 0);
    });

    return ranges;
}

export function totalProtocolWeeks(phases) {
    return protocolPhaseRanges(phases).reduce(
        (latestEnd, range) => Math.max(latestEnd, range.end ?? 0),
        0,
    );
}
