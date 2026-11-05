<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ActionHistoryController extends Controller
{
    public function table(Request $request): JsonResponse
    {
        $schoolId = $this->adminSchoolId();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'actor_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('school_id', $schoolId),
            ],
            'action_type' => ['nullable', 'string', 'max:48'],
            'entity_type' => ['nullable', 'string', 'max:64'],
            'year_id' => [
                'nullable',
                'integer',
                Rule::exists('academic_years', 'id')->where('school_id', $schoolId),
            ],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (
            isset($filters['date_from'], $filters['date_to'])
            && $filters['date_to'] < $filters['date_from']
        ) {
            throw ValidationException::withMessages([
                'date_to' => 'The end date must be on or after the start date.',
            ]);
        }

        $eventsQuery = DB::table('audit_events')
            ->where('school_id', $schoolId)
            ->when($filters['actor_id'] ?? null, fn ($query, $actorId) => $query->where('actor_user_id', $actorId))
            ->when($filters['action_type'] ?? null, fn ($query, $action) => $query->where('action_type', $action))
            ->when($filters['entity_type'] ?? null, fn ($query, $entity) => $query->where('entity_type', $entity))
            ->when($filters['year_id'] ?? null, fn ($query, $yearId) => $query->where('year_id', $yearId))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when(trim($filters['search'] ?? '') !== '', function ($query) use ($filters) {
                $search = '%'.trim($filters['search']).'%';
                $query->where(function ($query) use ($search) {
                    $query->where('actor_name', 'like', $search)
                        ->orWhere('action_type', 'like', $search)
                        ->orWhere('entity_type', 'like', $search)
                        ->orWhere('summary_key', 'like', $search)
                        ->orWhere('reason', 'like', $search)
                        ->orWhere('entity_id', 'like', $search);
                });
            });

        $events = $eventsQuery
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'year_id',
                'actor_user_id',
                'actor_name',
                'action_type',
                'entity_type',
                'entity_id',
                'summary_key',
                'reason',
                'created_at',
            ])
            ->map(fn ($event) => [
                'id' => (int) $event->id,
                'year_id' => $event->year_id === null ? null : (int) $event->year_id,
                'actor_user_id' => (int) $event->actor_user_id,
                'actor_name' => $event->actor_name,
                'action_type' => $event->action_type,
                'entity_type' => $event->entity_type,
                'entity_id' => $event->entity_id === null ? null : (int) $event->entity_id,
                'summary_key' => $event->summary_key,
                'reason' => $event->reason,
                'created_at' => $event->created_at,
            ])
            ->values();

        $actors = DB::table('audit_events')
            ->where('school_id', $schoolId)
            ->select('actor_user_id')
            ->selectRaw('MAX(actor_name) as actor_name')
            ->groupBy('actor_user_id')
            ->orderBy('actor_name')
            ->get()
            ->map(fn ($actor) => [
                'id' => (int) $actor->actor_user_id,
                'name' => $actor->actor_name,
            ])
            ->values();

        return response()->json([
            'events' => $events,
            'count' => $events->count(),
            'actors' => $actors,
            'actions' => DB::table('audit_events')
                ->where('school_id', $schoolId)
                ->distinct()
                ->orderBy('action_type')
                ->pluck('action_type')
                ->values(),
            'entities' => DB::table('audit_events')
                ->where('school_id', $schoolId)
                ->distinct()
                ->orderBy('entity_type')
                ->pluck('entity_type')
                ->values(),
            'years' => DB::table('academic_years')
                ->where('school_id', $schoolId)
                ->orderByDesc('start_date')
                ->orderByDesc('id')
                ->get(['id', 'year_label'])
                ->map(fn ($year) => [
                    'id' => (int) $year->id,
                    'label' => $year->year_label,
                ])
                ->values(),
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
