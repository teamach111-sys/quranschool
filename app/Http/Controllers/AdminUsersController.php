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

    public function store(Request $request): JsonResponse
    {
        $schoolId = $this->adminSchoolId();
        $validated = $this->validatedUser($request, null, true);

        $userId = DB::transaction(function () use ($validated, $schoolId) {
            $userId = DB::table('users')->insertGetId([
                'school_id' => $schoolId,
                'role_id' => $validated['role_id'],
                'full_name' => $validated['full_name'],
                'email' => $validated['email'],
                'password_hash' => Hash::make($validated['password']),
                'phone' => $validated['phone'],
                'language' => $validated['language'],
                'is_active' => $validated['is_active'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('role_change_history')->insert([
                'school_id' => $schoolId,
                'user_id' => $userId,
                'changed_by' => (int) Auth::id(),
                'old_role_id' => null,
                'new_role_id' => $validated['role_id'],
                'reason' => $validated['reason'],
                'created_at' => now(),
            ]);

            return $userId;
        });

        return response()->json(['user_id' => $userId], 201);
    }

    public function update(Request $request, int $userId): JsonResponse
    {
        $schoolId = $this->adminSchoolId();
        $validated = $this->validatedUser($request, $userId, false);

        DB::transaction(function () use ($validated, $schoolId, $userId) {
            $activeAdminIds = $this->lockActiveAdminIds($schoolId);
            $user = DB::table('users')
                ->where('school_id', $schoolId)
                ->where('id', $userId)
                ->lockForUpdate()
                ->first(['id', 'role_id', 'is_active']);
            abort_unless($user, 404);

            $roleChanged = (int) $user->role_id !== $validated['role_id'];
            $statusChanged = (bool) $user->is_active !== $validated['is_active'];
            if ($roleChanged && (int) $user->id === (int) Auth::id()) {
                throw ValidationException::withMessages([
                    'role_id' => 'Administrators cannot change their own role.',
                ]);
            }

            if ($roleChanged && blank($validated['reason'])) {
                throw ValidationException::withMessages([
                    'reason' => 'A reason is required when changing a user role.',
                ]);
            }

            $willRemainActiveAdmin = $validated['is_active']
                && $this->isAdminRole($validated['role_id']);
            $isCurrentlyActiveAdmin = (bool) $user->is_active
                && $this->isAdminRole((int) $user->role_id);

            if ($isCurrentlyActiveAdmin && ! $willRemainActiveAdmin) {
                abort_if(
                    (int) $user->id === (int) Auth::id(),
                    422,
                    'Administrators cannot deactivate their own account.',
                );
                $this->ensureAnotherActiveAdminExists($activeAdminIds, (int) $user->id);
            } elseif (! $validated['is_active'] && (int) $user->id === (int) Auth::id()) {
                abort(422, 'Administrators cannot deactivate their own account.');
            }

            $updates = [
                'role_id' => $validated['role_id'],
                'full_name' => $validated['full_name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'language' => $validated['language'],
                'is_active' => $validated['is_active'],
                'updated_at' => now(),
            ];
            if ($validated['password'] !== null) {
                $updates['password_hash'] = Hash::make($validated['password']);
            }

            DB::table('users')
                ->where('school_id', $schoolId)
                ->where('id', $userId)
                ->update($updates);

            if ($roleChanged) {
                DB::table('role_change_history')->insert([
                    'school_id' => $schoolId,
                    'user_id' => $userId,
                    'changed_by' => (int) Auth::id(),
                    'old_role_id' => (int) $user->role_id,
                    'new_role_id' => $validated['role_id'],
                    'reason' => $validated['reason'],
                    'created_at' => now(),
                ]);
            }

            if ($statusChanged) {
                DB::table('role_change_history')->insert([
                    'school_id' => $schoolId,
                    'user_id' => $userId,
                    'changed_by' => (int) Auth::id(),
                    'old_role_id' => $validated['role_id'],
                    'new_role_id' => $validated['role_id'],
                    'reason' => $validated['is_active'] ? 'Account reactivated.' : 'Account deactivated.',
                    'created_at' => now(),
                ]);
            }
        });

        return response()->json(['user_id' => $userId]);
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

    /**
     * @return array{
     *     full_name: string,
     *     email: string,
     *     password: ?string,
     *     phone: ?string,
     *     language: string,
     *     role_id: int,
     *     is_active: bool,
     *     reason: ?string
     * }
     */
    private function validatedUser(Request $request, ?int $userId, bool $isCreating): array
    {
        $emailRule = Rule::unique('users', 'email');
        if ($userId !== null) {
            $emailRule->ignore($userId);
        }

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'email' => [
                'required',
                'email',
                'max:190',
                $emailRule,
            ],
            'password' => $isCreating
                ? ['required', 'string', 'min:12', 'max:255', 'confirmed']
                : ['sometimes', 'nullable', 'string', 'min:12', 'max:255', 'confirmed'],
            'password_confirmation' => $isCreating
                ? ['required', 'string']
                : ['required_with:password', 'nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:30'],
            'language' => ['required', Rule::in(['fr', 'ar'])],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'is_active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'full_name' => $validated['full_name'],
            'email' => $validated['email'],
            'password' => $validated['password'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'language' => $validated['language'],
            'role_id' => (int) $validated['role_id'],
            'is_active' => (bool) $validated['is_active'],
            'reason' => $validated['reason'] ?? null,
        ];
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
