function minutesSinceMidnight(time) {
    const [hours, minutes] = String(time ?? '').slice(0, 5).split(':').map(Number);
    if (!Number.isInteger(hours) || !Number.isInteger(minutes) || hours < 0 || hours > 23 || minutes < 0 || minutes > 59) {
        return null;
    }

    return hours * 60 + minutes;
}

export function findScheduleConflict(schedules, draft, excludedScheduleId = null) {
    const startMinutes = minutesSinceMidnight(draft.starts_at);
    const endMinutes = minutesSinceMidnight(draft.ends_at);
    const dayOfWeek = Number(draft.day_of_week);

    if (
        !draft.room_id
        || !draft.teacher_id
        || !draft.valid_from
        || !Number.isInteger(dayOfWeek)
        || dayOfWeek < 1
        || dayOfWeek > 7
        || startMinutes === null
        || endMinutes === null
        || endMinutes <= startMinutes
        || (draft.valid_until && draft.valid_until < draft.valid_from)
    ) {
        return null;
    }

    const validUntil = draft.valid_until || '9999-12-31';

    for (const schedule of schedules) {
        const sameSchedule = excludedScheduleId !== null
            && String(schedule.id) === String(excludedScheduleId);
        const sameDay = Number(schedule.day_of_week) === dayOfWeek;
        const sameRoom = Number(schedule.room_id) === Number(draft.room_id);
        const sameTeacher = Number(schedule.teacher_id) === Number(draft.teacher_id);
        const datesOverlap = schedule.valid_from <= validUntil
            && (!schedule.valid_until || schedule.valid_until >= draft.valid_from);
        const existingStart = minutesSinceMidnight(schedule.starts_at);
        const existingEnd = minutesSinceMidnight(schedule.ends_at);
        const timesOverlap = existingStart !== null
            && existingEnd !== null
            && existingStart < endMinutes
            && existingEnd > startMinutes;

        if (!sameSchedule && sameDay && (sameRoom || sameTeacher) && datesOverlap && timesOverlap) {
            return {
                schedule,
                roomConflict: sameRoom,
                teacherConflict: sameTeacher,
            };
        }
    }

    return null;
}
