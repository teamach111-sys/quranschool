<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminUsersController extends Controller
{
    public function table(): JsonResponse
    {
        $schoolId = $this->adminSchoolId();

        $users = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('users.school_id', $schoolId)
            ->orderBy('users.full_name')
            ->orderBy('users.id')
            ->get([
                'users.id',
                'users.full_name',
                'users.email',
                'users.phone',
                'users.language',
                'users.role_id',
                'users.is_active',
                'roles.name_fr as role_name_fr',
                'roles.name_ar as role_name_ar',
            ])
            ->map(fn ($user) => [
                'id' => (int) $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'language' => $user->language,
                'role_id' => (int) $user->role_id,
                'role_name_fr' => $user->role_name_fr,
                'role_name_ar' => $user->role_name_ar,
                'is_active' => (bool) $user->is_active,
                'is_self' => (int) $user->id === (int) Auth::id(),
            ])
            ->values();

        $roles = DB::table('roles')
            ->orderBy('id')
            ->get(['id', 'role_key', 'name_fr', 'name_ar'])
            ->map(fn ($role) => [
                'id' => (int) $role->id,
                'role_key' => $role->role_key,
                'name_fr' => $role->name_fr,
                'name_ar' => $role->name_ar,
            ])
            ->values();

        return response()->json([
            'users' => $users,
            'roles' => $roles,
        ]);
    }

    public function updateStatus(Request $request, int $userId): JsonResponse
    {
        $schoolId = $this->adminSchoolId();
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);
        $isActive = (bool) $validated['is_active'];

        DB::transaction(function () use ($schoolId, $userId, $isActive) {
            $activeAdminIds = $this->lockActiveAdminIds($schoolId);
            $user = DB::table('users')
                ->where('school_id', $schoolId)
                ->where('id', $userId)
                ->lockForUpdate()
                ->first(['id', 'role_id', 'is_active']);
            abort_unless($user, 404);

            if ((bool) $user->is_active === $isActive) {
                return;
            }

            if (! $isActive) {
                abort_if((int) $user->id === (int) Auth::id(), 422, 'Administrators cannot deactivate their own account.');

                if ($this->isAdminRole((int) $user->role_id)) {
                    $this->ensureAnotherActiveAdminExists($activeAdminIds, (int) $user->id);
                }
            }

            DB::table('users')
                ->where('school_id', $schoolId)
                ->where('id', $userId)
                ->update([
                    'is_active' => $isActive,
                    'updated_at' => now(),
                ]);

            DB::table('role_change_history')->insert([
                'school_id' => $schoolId,
                'user_id' => $userId,
                'changed_by' => (int) Auth::id(),
                'old_role_id' => (int) $user->role_id,
                'new_role_id' => (int) $user->role_id,
                'reason' => $isActive ? 'Account reactivated.' : 'Account deactivated.',
                'created_at' => now(),
            ]);
        });

        return response()->json(['updated' => true]);
    }

    public function updateRole(Request $request, int $userId): JsonResponse
    {
        $schoolId = $this->adminSchoolId();
        $validated = $request->validate([
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'reason' => ['required', 'string', 'max:255'],
        ]);
        $roleId = (int) $validated['role_id'];

        DB::transaction(function () use ($schoolId, $userId, $roleId, $validated) {
            $activeAdminIds = $this->lockActiveAdminIds($schoolId);
            $user = DB::table('users')
                ->where('school_id', $schoolId)
                ->where('id', $userId)
                ->lockForUpdate()
                ->first(['id', 'role_id', 'is_active']);
            abort_unless($user, 404);

            if ((int) $user->role_id === $roleId) {
                return;
            }

            abort_if(
                (int) $user->id === (int) Auth::id(),
                422,
                'Administrators cannot change their own role.',
            );

            if (
                (bool) $user->is_active
                && $this->isAdminRole((int) $user->role_id)
                && ! $this->isAdminRole($roleId)
            ) {
                $this->ensureAnotherActiveAdminExists($activeAdminIds, (int) $user->id);
            }

            DB::table('users')
                ->where('school_id', $schoolId)
                ->where('id', $userId)
                ->update([
                    'role_id' => $roleId,
                    'updated_at' => now(),
                ]);

            DB::table('role_change_history')->insert([
                'school_id' => $schoolId,
                'user_id' => $userId,
                'changed_by' => (int) Auth::id(),
                'old_role_id' => (int) $user->role_id,
                'new_role_id' => $roleId,
                'reason' => $validated['reason'],
                'created_at' => now(),
            ]);
        });

        return response()->json(['updated' => true]);
    }

    public function resetAccess(Request $request, int $userId): JsonResponse
    {
        $schoolId = $this->adminSchoolId();
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);

        $updated = DB::table('users')
            ->where('school_id', $schoolId)
            ->where('id', $userId)
            ->update([
                'password_hash' => Hash::make($validated['password']),
                'updated_at' => now(),
            ]);

        abort_unless($updated > 0, 404);

        return response()->json(['updated' => true]);
    }

    private function adminSchoolId(): int
    {
        $user = Auth::user();
        $schoolId = data_get($user, 'school_id');

        abort_unless(data_get($user, 'role') === 'admin' && $schoolId, 403);

        return (int) $schoolId;
    }

    private function isAdminRole(int $roleId): bool
    {
        return DB::table('roles')
            ->where('id', $roleId)
            ->where('role_key', 'admin')
            ->exists();
    }

    private function lockActiveAdminIds(int $schoolId): Collection
    {
        return DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('users.school_id', $schoolId)
            ->where('roles.role_key', 'admin')
            ->where('users.is_active', true)
            ->orderBy('users.id')
            ->lockForUpdate()
            ->pluck('users.id');
    }

    private function ensureAnotherActiveAdminExists(Collection $activeAdminIds, int $excludedUserId): void
    {
        if ($activeAdminIds->filter(fn ($id) => (int) $id !== $excludedUserId)->isEmpty()) {
            throw ValidationException::withMessages([
                'user' => 'At least one other active administrator must remain.',
            ]);
        }
    }
}
