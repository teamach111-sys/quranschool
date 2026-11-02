<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TeacherDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = Auth::user();
        $schoolId = data_get($user, 'school_id');
        abort_unless(data_get($user, 'role') === 'teacher' && $schoolId, 403);

        $validated = $request->validate([
            'year_id' => ['sometimes', 'required', 'integer', 'min:1'],
        ]);
        $schoolId = (int) $schoolId;
        $yearQuery = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_current', true)
            ->where('is_active', true);

        if (isset($validated['year_id'])) {
            $yearQuery->where('id', (int) $validated['year_id']);
        }

        $year = $yearQuery->orderByDesc('start_date')->first(['id', 'year_label']);
        abort_unless($year, 404);

        $teacherId = (int) $user->id;
        $yearId = (int) $year->id;
        $now = now();
        $today = $now->toDateString();
        $todayWeekday = (int) $now->dayOfWeekIso;

        $rooms = DB::table('room_teachers as assignments')
            ->join('rooms', function (JoinClause $join) {
                $join->on('rooms.id', '=', 'assignments.room_id')
                    ->on('rooms.school_id', '=', 'assignments.school_id')
                    ->on('rooms.year_id', '=', 'assignments.year_id');
            })
            ->leftJoin('students', function (JoinClause $join) {
                $join->on('students.room_id', '=', 'rooms.id')
                    ->on('students.school_id', '=', 'rooms.school_id')
                    ->on('students.year_id', '=', 'rooms.year_id')
                    ->where('students.status', 'active');
            })
            ->where('assignments.school_id', $schoolId)
            ->where('assignments.year_id', $yearId)
            ->where('assignments.teacher_id', $teacherId)
            ->where('assignments.assigned_from', '<=', $today)
            ->where(function (Builder $dates) use ($today) {
                $dates->whereNull('assignments.assigned_until')
                    ->orWhere('assignments.assigned_until', '>=', $today);
            })
            ->where('rooms.is_active', true)
            ->groupBy('rooms.id', 'rooms.name')
            ->orderBy('rooms.name')
            ->get([
                'rooms.id',
                'rooms.name',
                DB::raw('COUNT(DISTINCT students.id) AS active_student_count'),
            ])
            ->map(fn ($room) => [
                'id' => (int) $room->id,
                'name' => $room->name,
                'active_student_count' => (int) $room->active_student_count,
            ])
            ->values();
        $roomIds = $rooms->pluck('id')->all();

        $todayLessons = $roomIds === []
            ? collect()
            : $this->scheduleQuery($schoolId, $yearId, $teacherId, $roomIds)
                ->where('schedules.day_of_week', $todayWeekday)
                ->where('schedules.valid_from', '<=', $today)
                ->where(function (Builder $dates) use ($today) {
                    $dates->whereNull('schedules.valid_until')
                        ->orWhere('schedules.valid_until', '>=', $today);
                })
                ->orderBy('schedules.starts_at')
                ->orderBy('rooms.name')
                ->get([
                    'schedules.id',
                    'schedules.room_id',
                    'schedules.day_of_week',
                    'schedules.starts_at',
                    'schedules.ends_at',
                    'schedules.label',
                    'rooms.name as room_name',
                ])
                ->map(fn ($lesson) => $this->formatLesson($lesson))
                ->values();

        $todayLessonRoomIds = $todayLessons->pluck('room_id')->unique()->all();
        $todayLessonStudents = $todayLessonRoomIds === []
            ? collect()
            : DB::table('students')
                ->leftJoin('attendance', function (JoinClause $join) use ($schoolId, $yearId, $today) {
                    $join->on('attendance.student_id', '=', 'students.id')
                        ->where('attendance.school_id', $schoolId)
                        ->where('attendance.year_id', $yearId)
                        ->where('attendance.attendance_date', $today);
                })
                ->where('students.school_id', $schoolId)
                ->where('students.year_id', $yearId)
                ->whereIn('students.room_id', $todayLessonRoomIds)
                ->where('students.status', 'active')
                ->orderBy('students.full_name')
                ->get([
                    'students.id as student_id',
                    'students.full_name as student_name',
                    'students.student_number',
                    'students.room_id',
                    'attendance.id as attendance_id',
                    'attendance.room_id as attendance_room_id',
                    'attendance.status as attendance_status',
                    'attendance.note as attendance_note',
                    'attendance.recorded_by',
                ])
                ->map(fn ($student) => [
                    'student_id' => (int) $student->student_id,
                    'student_name' => $student->student_name,
                    'student_number' => $student->student_number,
                    'status' => $student->attendance_status,
                    'note' => $student->attendance_note,
                    'attendance_id' => $student->attendance_id === null
                        ? null
                        : (int) $student->attendance_id,
                    'can_edit' => $student->attendance_id === null
                        || (
                            (int) $student->recorded_by === $teacherId
                            && (int) $student->attendance_room_id === (int) $student->room_id
                        ),
                    'room_id' => (int) $student->room_id,
                ])
                ->groupBy('room_id');

        $todayLessons = $todayLessons
            ->map(fn (array $lesson) => [
                ...$lesson,
                'students' => $todayLessonStudents->get($lesson['room_id'], collect())->values(),
                'late_suggestion' => $lesson['ends_at'] <= $now->format('H:i'),
            ])
            ->values();

        $todayAttendanceRoomIds = $todayLessonRoomIds === []
            ? []
            : DB::table('attendance')
                ->join('students', function (JoinClause $join) {
                    $join->on('students.id', '=', 'attendance.student_id')
                        ->on('students.school_id', '=', 'attendance.school_id')
                        ->on('students.year_id', '=', 'attendance.year_id');
                })
                ->where('attendance.school_id', $schoolId)
                ->where('attendance.year_id', $yearId)
                ->where('attendance.attendance_date', $today)
                ->whereIn(DB::raw('COALESCE(attendance.room_id, students.room_id)'), $todayLessonRoomIds)
                ->distinct()
                ->get([
                    DB::raw('COALESCE(attendance.room_id, students.room_id) AS room_id'),
                ])
                ->map(fn ($attendance) => (int) $attendance->room_id)
                ->all();
        $pendingAttendanceSlots = $todayLessons
            ->reject(fn (array $lesson) => in_array($lesson['room_id'], $todayAttendanceRoomIds, true))
            ->map(fn (array $lesson) => [
                'id' => $lesson['id'],
                'room_id' => $lesson['room_id'],
                'room_name' => $lesson['room_name'],
                'label' => $lesson['label'],
                'starts_at' => $lesson['starts_at'],
                'ends_at' => $lesson['ends_at'],
            ])
            ->values();

        $currentTime = $now->format('H:i');
        $attendanceFocusLesson = $todayLessons->first(
            fn (array $lesson) => $lesson['starts_at'] <= $currentTime && $lesson['ends_at'] > $currentTime,
        ) ?? $todayLessons->first(
            fn (array $lesson) => $lesson['starts_at'] > $currentTime,
        ) ?? $todayLessons->first(
            fn (array $lesson) => collect($lesson['students'])->contains(
                fn (array $student) => $student['status'] === null && $student['can_edit'],
            ),
        );

        $upcomingStart = $now->copy()->addDay();
        $upcomingEnd = $now->copy()->addDays(7);
        $upcomingDates = [];

        for ($date = $upcomingStart->copy(); $date->lte($upcomingEnd); $date->addDay()) {
            $upcomingDates[] = [
                'date' => $date->toDateString(),
                'weekday' => (int) $date->dayOfWeekIso,
            ];
        }

        $upcomingLessons = [];

        if ($roomIds !== []) {
            $upcomingAssignments = DB::table('room_teachers')
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->where('teacher_id', $teacherId)
                ->whereIn('room_id', $roomIds)
                ->where('assigned_from', '<=', $upcomingEnd->toDateString())
                ->where(function (Builder $dates) use ($upcomingStart) {
                    $dates->whereNull('assigned_until')
                        ->orWhere('assigned_until', '>=', $upcomingStart->toDateString());
                })
                ->get(['room_id', 'assigned_from', 'assigned_until']);

            $scheduleRows = $this->scheduleQuery($schoolId, $yearId, $teacherId, $roomIds)
                ->whereIn('schedules.day_of_week', array_column($upcomingDates, 'weekday'))
                ->where('schedules.valid_from', '<=', $upcomingEnd->toDateString())
                ->where(function (Builder $dates) use ($upcomingStart) {
                    $dates->whereNull('schedules.valid_until')
                        ->orWhere('schedules.valid_until', '>=', $upcomingStart->toDateString());
                })
                ->orderBy('schedules.starts_at')
                ->orderBy('rooms.name')
                ->get([
                    'schedules.id',
                    'schedules.room_id',
                    'schedules.day_of_week',
                    'schedules.starts_at',
                    'schedules.ends_at',
                    'schedules.valid_from',
                    'schedules.valid_until',
                    'schedules.label',
                    'rooms.name as room_name',
                ]);

            foreach ($upcomingDates as $lessonDate) {
                foreach ($scheduleRows as $schedule) {
                    if (
                        (int) $schedule->day_of_week !== $lessonDate['weekday']
                        || $schedule->valid_from > $lessonDate['date']
                        || ($schedule->valid_until !== null && $schedule->valid_until < $lessonDate['date'])
                    ) {
                        continue;
                    }

                    $hasActiveAssignment = $upcomingAssignments->contains(fn ($assignment) => (int) $assignment->room_id === (int) $schedule->room_id
                        && $assignment->assigned_from <= $lessonDate['date']
                        && ($assignment->assigned_until === null || $assignment->assigned_until >= $lessonDate['date']));

                    if (! $hasActiveAssignment) {
                        continue;
                    }

                    $upcomingLessons[] = [
                        ...$this->formatLesson($schedule),
                        'lesson_date' => $lessonDate['date'],
                    ];
                }
            }
        }

        $activeStudentsQuery = DB::table('students')
            ->join('rooms', function (JoinClause $join) {
                $join->on('rooms.id', '=', 'students.room_id')
                    ->on('rooms.school_id', '=', 'students.school_id')
                    ->on('rooms.year_id', '=', 'students.year_id');
            })
            ->where('students.school_id', $schoolId)
            ->where('students.year_id', $yearId)
            ->where('students.status', 'active')
            ->where('rooms.is_active', true)
            ->whereIn('students.room_id', $roomIds)
            ->whereNotExists(function (Builder $attendance) use ($schoolId, $yearId, $today) {
                $attendance->selectRaw('1')
                    ->from('attendance')
                    ->whereColumn('attendance.student_id', 'students.id')
                    ->where('attendance.school_id', $schoolId)
                    ->where('attendance.year_id', $yearId)
                    ->where('attendance.attendance_date', $today);
            });
        $pendingAttendanceCount = (clone $activeStudentsQuery)->count('students.id');
        $attendanceTasks = $activeStudentsQuery
            ->orderBy('rooms.name')
            ->orderBy('students.full_name')
            ->limit(10)
            ->get([
                'students.id',
                'students.full_name as student_name',
                'students.student_number',
                'rooms.id as room_id',
                'rooms.name as room_name',
            ])
            ->map(fn ($student) => [
                'student_id' => (int) $student->id,
                'student_name' => $student->student_name,
                'student_number' => $student->student_number,
                'room_id' => (int) $student->room_id,
                'room_name' => $student->room_name,
            ])
            ->values();

        $recentSessions = DB::table('memorization_sessions')
            ->join('students', function (JoinClause $join) {
                $join->on('students.id', '=', 'memorization_sessions.student_id')
                    ->on('students.school_id', '=', 'memorization_sessions.school_id')
                    ->on('students.year_id', '=', 'memorization_sessions.year_id');
            })
            ->leftJoin('rooms', function (JoinClause $join) {
                $join->on('rooms.id', '=', 'memorization_sessions.room_id')
                    ->on('rooms.school_id', '=', 'memorization_sessions.school_id')
                    ->on('rooms.year_id', '=', 'memorization_sessions.year_id');
            })
            ->where('memorization_sessions.school_id', $schoolId)
            ->where('memorization_sessions.year_id', $yearId)
            ->where('memorization_sessions.teacher_id', $teacherId)
            ->orderByDesc('memorization_sessions.session_date')
            ->orderByDesc('memorization_sessions.created_at')
            ->orderByDesc('memorization_sessions.id')
            ->limit(5)
            ->get([
                'memorization_sessions.id',
                'memorization_sessions.session_date',
                'memorization_sessions.portion_label',
                'memorization_sessions.evaluation',
                'students.full_name as student_name',
                'rooms.name as room_name',
            ])
            ->map(fn ($session) => [
                'id' => (int) $session->id,
                'session_date' => $session->session_date,
                'student_name' => $session->student_name,
                'room_name' => $session->room_name,
                'portion_label' => $session->portion_label,
                'evaluation' => $session->evaluation,
            ])
            ->values();

        return response()->json([
            'year_id' => $yearId,
            'year_label' => $year->year_label,
            'today' => $today,
            'groups' => $rooms,
            'today_lessons' => $todayLessons,
            'today_focus_lesson_id' => $attendanceFocusLesson['id'] ?? null,
            'upcoming_lessons' => $upcomingLessons,
            'attendance_tasks' => [
                'count' => $pendingAttendanceCount,
                'students' => $attendanceTasks,
                'slots' => $pendingAttendanceSlots,
            ],
            'recent_sessions' => $recentSessions,
        ]);
    }

    /**
     * @param  array<int, int>  $roomIds
     */
    private function scheduleQuery(int $schoolId, int $yearId, int $teacherId, array $roomIds): Builder
    {
        return DB::table('schedules')
            ->join('rooms', function (JoinClause $join) {
                $join->on('rooms.id', '=', 'schedules.room_id')
                    ->on('rooms.school_id', '=', 'schedules.school_id')
                    ->on('rooms.year_id', '=', 'schedules.year_id');
            })
            ->where('schedules.school_id', $schoolId)
            ->where('schedules.year_id', $yearId)
            ->where('schedules.teacher_id', $teacherId)
            ->whereIn('schedules.room_id', $roomIds)
            ->where('rooms.is_active', true);
    }

    private function formatLesson(object $lesson): array
    {
        return [
            'id' => (int) $lesson->id,
            'room_id' => (int) $lesson->room_id,
            'room_name' => $lesson->room_name,
            'day_of_week' => (int) $lesson->day_of_week,
            'starts_at' => substr($lesson->starts_at, 0, 5),
            'ends_at' => substr($lesson->ends_at, 0, 5),
            'label' => $lesson->label,
        ];
    }
}
