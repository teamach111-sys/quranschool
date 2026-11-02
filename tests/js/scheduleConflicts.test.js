import assert from 'node:assert/strict';
import test from 'node:test';
import { findScheduleConflict } from '../../resources/js/lib/scheduleConflicts.js';

const existingSchedule = {
    id: 1,
    room_id: 4,
    teacher_id: 8,
    day_of_week: 2,
    starts_at: '09:00',
    ends_at: '10:00',
    valid_from: '2026-09-01',
    valid_until: null,
    room_name: 'Alpha',
    teacher_name: 'Teacher One',
};

const draft = {
    room_id: '4',
    teacher_id: '9',
    day_of_week: '2',
    starts_at: '09:30',
    ends_at: '10:30',
    valid_from: '2026-10-01',
    valid_until: '',
};

test('finds an overlapping schedule for the same room', () => {
    const conflict = findScheduleConflict([existingSchedule], draft);

    assert.equal(conflict.schedule, existingSchedule);
    assert.equal(conflict.roomConflict, true);
    assert.equal(conflict.teacherConflict, false);
});

test('finds an overlapping schedule for the same teacher in another room', () => {
    const conflict = findScheduleConflict([existingSchedule], { ...draft, room_id: '5', teacher_id: '8' });

    assert.equal(conflict.schedule, existingSchedule);
    assert.equal(conflict.roomConflict, false);
    assert.equal(conflict.teacherConflict, true);
});

test('ignores slots on a different day, outside overlapping dates, or touching at the time boundary', () => {
    assert.equal(findScheduleConflict([existingSchedule], { ...draft, day_of_week: '3' }), null);
    assert.equal(findScheduleConflict([{
        ...existingSchedule,
        valid_until: '2026-09-30',
    }], draft), null);
    assert.equal(findScheduleConflict([existingSchedule], {
        ...draft,
        starts_at: '10:00',
        ends_at: '11:00',
    }), null);
});

test('treats the validity end date as inclusive', () => {
    const datedSchedule = {
        ...existingSchedule,
        valid_until: '2026-10-01',
    };
    const conflict = findScheduleConflict([datedSchedule], draft);

    assert.equal(conflict.schedule, datedSchedule);
});

test('does not report the slot being edited as a conflict', () => {
    assert.equal(findScheduleConflict([existingSchedule], {
        ...draft,
        room_id: '4',
        teacher_id: '8',
        starts_at: '09:00',
        ends_at: '10:00',
    }, existingSchedule.id), null);
});
