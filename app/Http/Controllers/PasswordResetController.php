<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'recovery_key' => ['required', 'string', 'regex:/^[A-Fa-f0-9]{64}$/'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ], [
            'email.required' => 'Veuillez saisir votre adresse e-mail.',
            'email.email' => 'Veuillez saisir une adresse e-mail valide.',
            'email.max' => 'L’adresse e-mail ne peut pas dépasser 190 caractères.',
            'recovery_key.required' => 'Veuillez saisir votre clé de récupération.',
            'recovery_key.string' => 'La clé de récupération doit être du texte.',
            'recovery_key.regex' => 'Le format de la clé de récupération est invalide. Vérifiez la clé téléchargée.',
            'password.required' => 'Veuillez saisir un nouveau mot de passe.',
            'password.string' => 'Le mot de passe doit être du texte.',
            'password.min' => 'Le mot de passe doit contenir au moins 12 caractères.',
            'password.max' => 'Le mot de passe ne peut pas dépasser 255 caractères.',
            'password.confirmed' => 'La confirmation du mot de passe ne correspond pas.',
        ]);

        $reset = DB::transaction(function () use ($validated): bool {
            $user = DB::table('users')
                ->join('roles', 'roles.id', '=', 'users.role_id')
                ->where('email', $validated['email'])
                ->where('users.is_active', true)
                ->whereIn('roles.role_key', ['admin', 'teacher', 'secretary'])
                ->lockForUpdate()
                ->first(['users.id']);
            if (! $user) {
                return false;
            }

            $recoveryKey = DB::table('password_recovery_keys')
                ->where('user_id', (int) $user->id)
                ->lockForUpdate()
                ->first(['key_hash']);
            if (! $recoveryKey || ! Hash::check($validated['recovery_key'], $recoveryKey->key_hash)) {
                return false;
            }

            $updates = [
                'password_hash' => Hash::make($validated['password']),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('users', 'remember_token')) {
                $updates['remember_token'] = Str::random(60);
            }

            DB::table('users')
                ->where('id', (int) $user->id)
                ->update($updates);
            DB::table('password_recovery_keys')
                ->where('user_id', (int) $user->id)
                ->delete();

            return true;
        });

        if (! $reset) {
            return response()->json([
                'message' => 'Adresse e-mail ou clé de récupération invalide.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Mot de passe réinitialisé. Vous pouvez maintenant vous connecter.',
        ]);
    }
}
