<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user && in_array(data_get($user, 'role'), ['admin', 'teacher', 'secretary'], true), 403);

        return $this->profileResponse((int) $user->id, (int) $user->school_id);
    }

    public function update(Request $request): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user && in_array(data_get($user, 'role'), ['admin', 'teacher', 'secretary'], true), 403);

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'language' => ['required', Rule::in(['fr', 'ar'])],
        ]);

        DB::table('users')
            ->where('id', (int) $user->id)
            ->where('school_id', (int) $user->school_id)
            ->update([
                'full_name' => $validated['full_name'],
                'phone' => $validated['phone'] ?? null,
                'language' => $validated['language'],
                'updated_at' => now(),
            ]);

        return $this->profileResponse((int) $user->id, (int) $user->school_id);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = Auth::user();
        abort_unless($user && in_array(data_get($user, 'role'), ['admin', 'teacher', 'secretary'], true), 403);

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed', 'different:current_password'],
        ]);

        $userQuery = DB::table('users')
            ->where('id', (int) $user->id)
            ->where('school_id', (int) $user->school_id);
        $passwordHash = $userQuery->value('password_hash');

        abort_unless($passwordHash, 404);

        if (! Hash::check($validated['current_password'], $passwordHash)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $updated = $userQuery->update([
            'password_hash' => Hash::make($validated['password']),
            'updated_at' => now(),
        ]);
        abort_unless($updated > 0, 404);

        return response()->json(['updated' => true]);
    }

    private function profileResponse(int $userId, int $schoolId): JsonResponse
    {
        $profile = DB::table('users')
            ->where('id', $userId)
            ->where('school_id', $schoolId)
            ->first(['id', 'full_name', 'email', 'phone', 'language']);
        abort_unless($profile, 404);

        return response()->json([
            'profile' => [
                'id' => (int) $profile->id,
                'full_name' => $profile->full_name,
                'email' => $profile->email,
                'phone' => $profile->phone,
                'language' => $profile->language,
            ],
        ]);
    }
}
