<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SchoolSettingsController extends Controller
{
    public function show()
    {
        $school = DB::table('schools')
            ->where('id', $this->schoolId())
            ->first(['name_fr', 'name_ar', 'address', 'phone', 'email', 'currency']);

        abort_unless($school, 404);

        return response()->json(['school' => $school]);
    }

    public function update(Request $request)
    {
        $request->merge([
            'currency' => strtoupper((string) $request->input('currency')),
        ]);

        $validated = $request->validate([
            'name_fr' => ['required', 'string', 'max:150'],
            'name_ar' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
        ]);

        DB::table('schools')
            ->where('id', $this->schoolId())
            ->update([...$validated, 'updated_at' => now()]);

        return $this->show();
    }

    private function schoolId(): int
    {
        $user = Auth::user();
        $schoolId = data_get($user, 'school_id');

        abort_unless(data_get($user, 'role') === 'admin' && $schoolId, 403);

        return (int) $schoolId;
    }
}
