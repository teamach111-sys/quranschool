<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StudentsController extends Controller
{
    public function index(Request $request)
    {
        return $this->table($request);
    }

    public function table(Request $request)
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $schoolId = data_get($user, 'school_id');
        abort_unless($schoolId, 403);

        $role = $user->role ?? null;
        abort_unless(in_array($role, ['admin', 'teacher', 'secretary'], true), 403);

        $yearId = $this->resolveYearId($request, (int) $schoolId);
        $query = $this->baseStudentQuery($request, $user, (int) $schoolId, $yearId);

        $rows = $query
            ->select([
                'students.id',
                'students.student_number',
                'students.full_name',
                'students.status',
                'students.birth_date',
                'students.guardian_name',
                'students.guardian_phone',
                'students.guardian_email',
                'students.enrollment_date',
                'students.notes',
                'students.room_id',
                'rooms.name as room_name',
            ])
            ->orderBy('students.full_name')
            ->get();

        $students = $rows->map(function ($row) use ($role) {
            return [
                'id' => (int) $row->id,
                'student_number' => $row->student_number,
                'full_name' => $row->full_name,
                'status' => $row->status,
                'birth_date' => $row->birth_date,
                'guardian_name' => $row->guardian_name,
                'guardian_phone' => $row->guardian_phone,
                'guardian_email' => $row->guardian_email,
                'enrollment_date' => $row->enrollment_date,
                'notes' => $row->notes,
                'room_id' => $row->room_id ? (int) $row->room_id : null,
                'room_name' => $row->room_name,
                'is_read_only' => ! in_array($role, ['admin', 'secretary'], true),
            ];
        });

        return response()->json([
            'year_id' => $yearId,
            'students' => $students,
            'can_manage' => in_array($role, ['admin', 'secretary'], true),
            'is_teacher_view' => $role === 'teacher',
        ]);
    }

    private function resolveYearId(Request $request, int $schoolId): int
    {
        $yearId = $request->input('year_id');

        if ($yearId !== null) {
            $year = DB::table('academic_years')
                ->where('id', $yearId)
                ->where('school_id', $schoolId)
                ->first(['id']);

            abort_unless($year, 404);

            return (int) $year->id;
        }

        $year = DB::table('academic_years')
            ->where('school_id', $schoolId)
            ->where('is_current', true)
            ->where('is_active', true)
            ->orderByDesc('start_date')
            ->first(['id']);

        abort_unless($year, 404);

        return (int) $year->id;
    }

    private function baseStudentQuery(Request $request, object $user, int $schoolId, int $yearId)
    {
        $role = $user->role ?? null;
        $query = DB::table('students')
            ->leftJoin('rooms', 'rooms.id', '=', 'students.room_id')
            ->where('students.school_id', $schoolId)
            ->where('students.year_id', $yearId);

        if ($role === 'teacher') {
            $roomIds = DB::table('room_teachers')
                ->where('school_id', $schoolId)
                ->where('year_id', $yearId)
                ->where('teacher_id', $user->id)
                ->where('assigned_from', '<=', now()->toDateString())
                ->where(function ($query) {
                    $query->whereNull('assigned_until')
                        ->orWhere('assigned_until', '>=', now()->toDateString());
                })
                ->pluck('room_id')
                ->all();

            $query->whereIn('students.room_id', $roomIds);

            if (! $request->has('status')) {
                $query->whereIn('students.status', ['active', 'paused']);
            } elseif ($request->input('status') !== 'all') {
                $query->where('students.status', $request->input('status'));
            }
        } else {
            if ($request->filled('status') && $request->input('status') !== 'all') {
                $query->where('students.status', $request->input('status'));
            }
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            if ($search !== '') {
                $searchTerm = '%'.$search.'%';
                $query->where(function ($query) use ($searchTerm) {
                    $query->where('students.full_name', 'like', $searchTerm)
                        ->orWhere('students.student_number', 'like', $searchTerm)
                        ->orWhere('rooms.name', 'like', $searchTerm);
                });
            }
        }

        return $query;
    }
}
