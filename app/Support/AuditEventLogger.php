<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AuditEventLogger
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function record(
        int $schoolId,
        ?int $yearId,
        string $entityType,
        ?int $entityId,
        string $action,
        string $summaryKey,
        array $before = [],
        array $after = [],
        ?string $reason = null,
    ): void {
        $actorId = (int) Auth::id();
        $requestId = request()->header('X-Request-ID');
        $actorName = DB::table('users')
            ->where('school_id', $schoolId)
            ->where('id', $actorId)
            ->value('full_name');

        if ($actorId < 1 || ! is_string($actorName) || $actorName === '') {
            throw new \RuntimeException('Unable to resolve the authenticated actor for the audit event.');
        }

        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            if (in_array(strtolower((string) $field), [
                'password',
                'password_hash',
                'remember_token',
                'token',
                'secret',
            ], true)) {
                continue;
            }

            $oldValue = $before[$field] ?? null;
            $newValue = $after[$field] ?? null;
            if ($oldValue !== $newValue) {
                $changes[$field] = [
                    'before' => $oldValue,
                    'after' => $newValue,
                ];
            }
        }

        DB::table('audit_events')->insert([
            'school_id' => $schoolId,
            'year_id' => $yearId,
            'actor_user_id' => $actorId,
            'actor_name' => $actorName,
            'action_type' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'summary_key' => $summaryKey,
            'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'request_id' => is_string($requestId) && $requestId !== ''
                ? substr($requestId, 0, 100)
                : null,
            'created_at' => now(),
        ]);
    }
}
