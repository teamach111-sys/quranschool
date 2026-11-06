<?php

namespace App\Http\Controllers;

use App\Support\AuditEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SchoolSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $school = DB::table('schools')
            ->where('id', $this->schoolId())
            ->first(['name_fr', 'name_ar', 'address', 'phone', 'email', 'currency', 'monthly_fee']);

        abort_unless($school, 404);

        if ($school->monthly_fee !== null) {
            $school->monthly_fee = number_format((float) $school->monthly_fee, 2, '.', '');
        }

        return response()->json(['school' => $school]);
    }

    public function update(Request $request): JsonResponse
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
            'monthly_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ]);

        $schoolId = $this->schoolId();
        DB::transaction(function () use ($validated, $schoolId) {
            $before = DB::table('schools')
                ->where('id', $schoolId)
                ->lockForUpdate()
                ->first(['name_fr', 'name_ar', 'address', 'phone', 'email', 'currency', 'monthly_fee']);
            abort_unless($before, 404);

            $beforeValues = (array) $before;
            $beforeValues['monthly_fee'] = $before->monthly_fee === null
                ? null
                : number_format((float) $before->monthly_fee, 2, '.', '');

            DB::table('schools')
                ->where('id', $schoolId)
                ->update([...$validated, 'updated_at' => now()]);
            $after = DB::table('schools')
                ->where('id', $schoolId)
                ->first(['name_fr', 'name_ar', 'address', 'phone', 'email', 'currency', 'monthly_fee']);
            abort_unless($after, 404);
            $afterValues = (array) $after;
            $afterValues['monthly_fee'] = $after->monthly_fee === null
                ? null
                : number_format((float) $after->monthly_fee, 2, '.', '');

            AuditEventLogger::record(
                $schoolId,
                null,
                'school',
                $schoolId,
                'updated',
                'school.settings_updated',
                $beforeValues,
                $afterValues,
            );
        });

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
