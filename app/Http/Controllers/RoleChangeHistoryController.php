<?php

namespace App\Http\Controllers;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RoleChangeHistoryController extends Controller
{
    public function table(): JsonResponse
    {
        $schoolId = $this->adminSchoolId();

        $historyQuery = DB::table('role_change_history')
            ->join('users as affected_users', function (JoinClause $join) {
                $join->on('affected_users.id', '=', 'role_change_history.user_id')
                    ->on('affected_users.school_id', '=', 'role_change_history.school_id');
            })
            ->join('users as actors', function (JoinClause $join) {
                $join->on('actors.id', '=', 'role_change_history.changed_by')
                    ->on('actors.school_id', '=', 'role_change_history.school_id');
            })
            ->leftJoin('roles as old_roles', 'old_roles.id', '=', 'role_change_history.old_role_id')
            ->join('roles as new_roles', 'new_roles.id', '=', 'role_change_history.new_role_id')
            ->where('role_change_history.school_id', $schoolId);

        $history = $historyQuery
            ->orderByDesc('role_change_history.created_at')
            ->orderByDesc('role_change_history.id')
            ->get([
                'role_change_history.id',
                'role_change_history.user_id',
                'affected_users.full_name as user_name',
                'role_change_history.changed_by',
                'actors.full_name as changed_by_name',
                'role_change_history.old_role_id',
                'old_roles.name_fr as old_role_name_fr',
                'old_roles.name_ar as old_role_name_ar',
                'role_change_history.new_role_id',
                'new_roles.name_fr as new_role_name_fr',
                'new_roles.name_ar as new_role_name_ar',
                'role_change_history.reason',
                'role_change_history.created_at',
            ])
            ->map(fn ($entry) => [
                'id' => (int) $entry->id,
                'user_id' => (int) $entry->user_id,
                'user_name' => $entry->user_name,
                'changed_by' => (int) $entry->changed_by,
                'changed_by_name' => $entry->changed_by_name,
                'old_role_id' => $entry->old_role_id === null ? null : (int) $entry->old_role_id,
                'old_role_name_fr' => $entry->old_role_name_fr,
                'old_role_name_ar' => $entry->old_role_name_ar,
                'new_role_id' => (int) $entry->new_role_id,
                'new_role_name_fr' => $entry->new_role_name_fr,
                'new_role_name_ar' => $entry->new_role_name_ar,
                'reason' => $entry->reason,
                'created_at' => $entry->created_at,
            ])
            ->values();

        $users = DB::table('users')
            ->where('school_id', $schoolId)
            ->orderBy('full_name')
            ->orderBy('id')
            ->get(['id', 'full_name'])
            ->map(fn ($user) => [
                'id' => (int) $user->id,
                'name' => $user->full_name,
            ])
            ->values();

        return response()->json([
            'history' => $history,
            'users' => $users,
        ]);
    }

    private function adminSchoolId(): int
    {
        $user = Auth::user();
        $schoolId = data_get($user, 'school_id');

        abort_unless(data_get($user, 'role') === 'admin' && $schoolId, 403);

        return (int) $schoolId;
    }
}
