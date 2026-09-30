export function templateDosage(supplement, horseWeightKg) {
    if (!supplement || supplement.dosis === null || supplement.dosis === undefined || !supplement.dosis_type || !supplement.unit) {
        return '';
    }

    let dose = Number(supplement.dosis);
    if (!Number.isFinite(dose)) return '';

    if (supplement.dosis_type === 'per_kg' || supplement.dosis_type === 'per_600_kg') {
        const weight = Number(horseWeightKg);
        if (!Number.isFinite(weight) || weight <= 0) return '';
        dose *= weight;
        if (supplement.dosis_type === 'per_600_kg') dose /= 600;
    }

    // Round the final gram dose only; frequency never changes amount per administration.
    if (supplement.unit === 'g' && ['per_kg', 'per_600_kg'].includes(supplement.dosis_type)) {
        const step = dose < 10 ? 2.5 : 5;
        dose = Math.ceil(Number((dose / step).toFixed(12))) * step;
    }
    return `${Number(dose.toFixed(12))} ${supplement.unit}`;
}

export function defaultProtocolSupplementDosage(storedDosage, supplement, horseWeightKg) {
    if (storedDosage !== null && storedDosage !== undefined && String(storedDosage).trim() !== '') {
        return String(storedDosage);
    }

    return templateDosage(supplement, horseWeightKg);
}

export function defaultProtocolSupplementInstructions(storedInstructions, supplement) {
    if (storedInstructions !== null && storedInstructions !== undefined && String(storedInstructions).trim() !== '') {
        return String(storedInstructions);
    }

    return supplement?.instructions ?? '';
}

export function recalculateProtocolDosages(phases, weight) {
    return phases.map((phase) => ({ ...phase, supplements: phase.supplements.map((selection) =>
        selection.dosage_mode === 'automatic' && ['per_kg', 'per_600_kg'].includes(selection.dosis_type)
            ? { ...selection, dosage: templateDosage(selection, weight) }
            : selection,
    ) }));
}
