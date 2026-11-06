<?php

namespace App\Http\Controllers;

use App\Support\AuditEventLogger;
use App\Support\PaymentMethods;
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
            ->first(['name_fr', 'name_ar', 'address', 'phone', 'email', 'currency', 'monthly_fee', 'payment_methods']);

        abort_unless($school, 404);

        $school->payment_methods = PaymentMethods::fromJson($school->payment_methods);
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
        if (is_array($request->input('payment_methods'))) {
            $request->merge([
                'payment_methods' => array_map('trim', $request->input('payment_methods')),
            ]);
        }

        $validated = $request->validate([
            'name_fr' => ['required', 'string', 'max:150'],
            'name_ar' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:190'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'monthly_fee' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'payment_methods' => ['sometimes', 'required', 'array', 'min:1', 'max:20'],
            'payment_methods.*' => ['required', 'string', 'max:40', 'distinct'],
        ]);
        if (isset($validated['payment_methods'])) {
            $validated['payment_methods'] = json_encode(
                array_values(array_map('trim', $validated['payment_methods'])),
                JSON_THROW_ON_ERROR,
            );
        }

        $schoolId = $this->schoolId();
        DB::transaction(function () use ($validated, $schoolId) {
            $before = DB::table('schools')
                ->where('id', $schoolId)
                ->lockForUpdate()
                ->first(['name_fr', 'name_ar', 'address', 'phone', 'email', 'currency', 'monthly_fee', 'payment_methods']);
            abort_unless($before, 404);

            $beforeValues = (array) $before;
            $beforeValues['payment_methods'] = PaymentMethods::fromJson($before->payment_methods);
            $beforeValues['monthly_fee'] = $before->monthly_fee === null
                ? null
                : number_format((float) $before->monthly_fee, 2, '.', '');

            DB::table('schools')
                ->where('id', $schoolId)
                ->update([...$validated, 'updated_at' => now()]);
            $after = DB::table('schools')
                ->where('id', $schoolId)
                ->first(['name_fr', 'name_ar', 'address', 'phone', 'email', 'currency', 'monthly_fee', 'payment_methods']);
            abort_unless($after, 404);
            $afterValues = (array) $after;
            $afterValues['payment_methods'] = PaymentMethods::fromJson($after->payment_methods);
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
