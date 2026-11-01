<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SchedulesController extends Controller
{
    public function table(Request $request): JsonResponse
    {
        [$user, $schoolId] = $this->staffContext();
        $yearId = $this->resolveYearId($request, $schoolId);

        $schedulesQuery = DB::table('schedules')
            ->join('rooms', function ($join) {
                $join->on('rooms.id', '=', 'schedules.room_id')
                    ->on('rooms.school_id', '=', 'schedules.school_id')
                    ->on('rooms.year_id', '=', 'schedules.year_id');
            })
            ->join('users', function ($join) {
                $join->on('users.id', '=', 'schedules.teacher_id')
                    ->on('users.school_id', '=', 'schedules.school_id');
            })
            ->where('schedules.school_id', $schoolId)
            ->where('schedules.year_id', $yearId);

        if ($user->role === 'teacher') {
            $schedulesQuery->where('schedules.teacher_id', $user->id);
        }

        $schedules = $schedulesQuery
            ->orderBy('schedules.day_of_week')
            ->orderBy('schedules.starts_at')
            ->orderBy('rooms.name')
            ->get([
                'schedules.id',
                'schedules.room_id',
                'schedules.teacher_id',
                'schedules.day_of_week',
                'schedules.starts_at',
                'schedules.ends_at',
                'schedules.valid_from',
                'schedules.valid_until',
                'schedules.label',
                'rooms.name as room_name',
                'users.full_name as teacher_name',
            ])
            ->map(fn ($schedule) => [
                'id' => (int) $schedule->id,
                'room_id' => (int) $schedule->room_id,
                'room_name' => $schedule->room_name,
                'teacher_id' => (int) $schedule->teacher_id,
                'teacher_name' => $schedule->teacher_name,
                'day_of_week' => (int) $schedule->day_of_week,
                'starts_at' => substr($schedule->starts_at, 0, 5),
                'ends_at' => substr($schedule->ends_at, 0, 5),
                'valid_from' => $schedule->valid_from,
                'valid_until' => $schedule->valid_until,
                'label' => $schedule->label,
            ])
            ->values();

        $response = [
            'year_id' => $yearId,
            'schedules' => $schedules,
            'can_manage' => $user->role === 'admin',
        ];

        if ($user->role === 'admin') {
            $response['year_is_active'] = (bool) DB::table('academic_years')
                ->where('id', $yearId)
                ->where('school_id', $schoolId)
                ->value('is_active');
            $response['rooms'] = DB::table('rooms')
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($room) => ['id' => (int) $room->id, 'name' => $room->name])
                ->values();
            $response['teachers'] = DB::table('users')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->where('users.school_id', $schoolId)
                ->where('users.is_active', true)
                ->where('roles.role_key', 'teacher')
                ->orderBy('users.full_name')
                ->get(['users.id', 'users.full_name'])
                ->map(fn ($teacher) => ['id' => (int) $teacher->id, 'name' => $teacher->full_name])
                ->values();
        }

        return response()->json($response);
    }

    public function store(Request $request): JsonResponse
    {
        [, $schoolId] = $this->adminContext();
        $yearId = $this->validateYearId($request, $schoolId);
        $this->ensureYearIsEditable($schoolId, $yearId);
        $validated = $this->validatedSchedule($request);

        $scheduleId = DB::transaction(function () use ($validated, $schoolId, $yearId) {
            $this->lockScheduleResources($validated, $schoolId, $yearId);
            $this->ensureNoScheduleOverlap($validated, $schoolId, $yearId);

            return DB::table('schedules')->insertGetId([
                ...$validated,
                'school_id' => $schoolId,
                'year_id' => $yearId,
                'created_at' => now(),
            ]);
        });

        return response()->json(['schedule_id' => $scheduleId], 201);
    }

    public function update(Request $request, int $scheduleId): JsonResponse
    {
        [, $schoolId] = $this->adminContext();
        $yearId = $this->validateYearId($request, $schoolId);
        $this->ensureYearIsEditable($schoolId, $yearId);
        $validated = $this->validatedSchedule($request);

        DB::transaction(function () use ($validated, $scheduleId, $schoolId, $yearId) {
            $schedule = DB::table('schedules')
                ->where('id', $scheduleId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->lockForUpdate()
                ->first(['id']);
            abort_unless($schedule, 404);

            $this->lockScheduleResources($validated, $schoolId, $yearId);
            $this->ensureNoScheduleOverlap($validated, $schoolId, $yearId, $scheduleId);

            DB::table('schedules')
                ->where('id', $scheduleId)
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->update($validated);
        });

        return response()->json(['schedule_id' => $scheduleId]);
    }

    /**
     * @return array{room_id: int, teacher_id: int, day_of_week: int, starts_at: string, ends_at: string, valid_from: string, valid_until: ?string, label: string}
     */
    private function validatedSchedule(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'room_id' => ['required', 'integer'],
            'teacher_id' => ['required', 'integer'],
            'day_of_week' => ['required', 'integer', 'between:1,7'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);
        $validator->after(function ($validator) use ($request) {
            if (
                ! $validator->errors()->hasAny(['starts_at', 'ends_at'])
                && $request->input('ends_at') <= $request->input('starts_at')
            ) {
                $validator->errors()->add('ends_at', 'The end time must be after the start time.');
            }
        });
        $validated = $validator->validate();

        return [
            'room_id' => (int) $validated['room_id'],
            'teacher_id' => (int) $validated['teacher_id'],
            'day_of_week' => (int) $validated['day_of_week'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'valid_from' => $validated['valid_from'],
            'valid_until' => $validated['valid_until'] ?? null,
            'label' => ($validated['label'] ?? null) ?: 'Hifz',
        ];
    }

    /**
     * @param  array{room_id: int, teacher_id: int}  $validated
     */
    private function lockScheduleResources(array $validated, int $schoolId, int $yearId): void
    {
        $room = DB::table('rooms')
            ->where('id', $validated['room_id'])
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first(['id']);
        if (! $room) {
            throw ValidationException::withMessages([
                'room_id' => 'The selected room is not active in this academic year.',
            ]);
        }

        $teacher = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('users.id', $validated['teacher_id'])
            ->where('users.school_id', $schoolId)
            ->where('users.is_active', true)
            ->where('roles.role_key', 'teacher')
            ->lockForUpdate()
            ->first(['users.id']);
        if (! $teacher) {
            throw ValidationException::withMessages([
                'teacher_id' => 'The selected user is not an active teacher in this school.',
            ]);
        }
    }

    /**
     * @param  array{room_id: int, teacher_id: int, day_of_week: int, starts_at: string, ends_at: string, valid_from: string, valid_until: ?string}  $validated
     */
    private function ensureNoScheduleOverlap(
        array $validated,
        int $schoolId,
        int $yearId,
        ?int $scheduleId = null,
    ): void {
        $overlaps = DB::table('schedules')
            ->where('school_id', $schoolId)
            ->where('year_id', $yearId)
            ->where('day_of_week', $validated['day_of_week'])
            ->where(function ($resources) use ($validated) {
                $resources->where('room_id', $validated['room_id'])
                    ->orWhere('teacher_id', $validated['teacher_id']);
            })
            ->where('valid_from', '<=', $validated['valid_until'] ?? '9999-12-31')
            ->where(function ($dates) use ($validated) {
                $dates->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $validated['valid_from']);
            })
            ->where('starts_at', '<', $validated['ends_at'])
            ->where('ends_at', '>', $validated['starts_at']);

        if ($scheduleId !== null) {
            $overlaps->where('id', '!=', $scheduleId);
        }

        if ($overlaps->exists()) {
            throw ValidationException::withMessages([
                'schedule' => 'The room or teacher already has an overlapping schedule.',
            ]);
        }
    }

    private function staffContext(): array
    {
        $user = Auth::user();
        $schoolId = data_get($user, 'school_id');
        abort_unless(
            $user && in_array(data_get($user, 'role'), ['admin', 'teacher', 'secretary'], true) && $schoolId,
            403,
        );

        return [$user, (int) $schoolId];
    }

    private function adminContext(): array
    {
        [$user, $schoolId] = $this->staffContext();
        abort_unless($user->role === 'admin', 403);

        return [$user, $schoolId];
    }

    private function resolveYearId(Request $request, int $schoolId): int
    {
        $yearId = $request->input('year_id');
        $query = DB::table('academic_years')->where('school_id', $schoolId);

        if ($yearId !== null) {
            $query->where('id', $yearId);
        } else {
            $query->where('is_current', true)->where('is_active', true);
        }

        $year = $query->orderByDesc('start_date')->first(['id']);
        abort_unless($year, 404);

        return (int) $year->id;
    }

    private function validateYearId(Request $request, int $schoolId): int
    {
        $validated = $request->validate([
            'year_id' => [
                'required',
                'integer',
                Rule::exists('academic_years', 'id')->where('school_id', $schoolId),
            ],
        ]);

        return (int) $validated['year_id'];
    }

    private function ensureYearIsEditable(int $schoolId, int $yearId): void
    {
        $isActive = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('id', $yearId)
            ->value('is_active');

        if (! $isActive) {
            throw ValidationException::withMessages([
                'year_id' => 'Schedules in a closed academic year cannot be changed.',
            ]);
        }
    }
}
