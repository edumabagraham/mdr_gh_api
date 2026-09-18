<?php

namespace App\Http\Controllers;

use App\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One request, four sections. This runs on every login, so it is a handful of
 * queries rather than a query per row.
 */
class DashboardController extends Controller
{
    private const RECENTLY_SEEN_LIMIT = 5;

    private const OVERDUE_LIMIT = 20;

    /**
     * No clinic session has this many patients in it. The cap exists so one
     * bulk-scheduled day cannot turn the login screen into a thousand-row
     * render; the count above the list stays truthful either way.
     */
    private const CLINIC_LIMIT = 100;

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'todays_clinic' => $this->todaysClinic(),
            'unfinished_work' => $this->unfinishedWork($request),
            'overdue_followup' => $this->overdueFollowup(),
            'recently_seen' => $this->recentlySeen($request),
        ]);
    }

    /**
     * Today's list belongs to the clinic, not to a clinician: whoever is in
     * that day sees all of it.
     *
     * @return array<string, mixed>
     */
    private function todaysClinic(): array
    {
        // A plain comparison, not whereDate: casting the column to a date
        // hides it from the (scheduled_for, status) index.
        $booked = Visit::query()
            ->where('scheduled_for', today())
            ->where('status', '!=', 'cancelled');

        $count = (clone $booked)->count();
        $waiting = (clone $booked)->where('status', 'arrived')->count();

        $visits = $booked
            ->with('patient')
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'arrived' THEN 1 WHEN 'scheduled' THEN 2 ELSE 3 END")
            ->orderBy('arrived_at')
            ->limit(self::CLINIC_LIMIT)
            ->get();

        return [
            'count' => $count,
            'waiting' => $waiting,
            'items' => $visits->map(fn (Visit $visit) => [
                'visit_id' => $visit->id,
                'patient' => [
                    'id' => $visit->patient->id,
                    'registry_no' => $visit->patient->registry_no,
                    'name' => $visit->patient->displayName(),
                    'age' => $visit->patient->age(),
                    'sex' => $visit->patient->sex,
                ],
                'type' => $visit->type,
                'status' => $visit->status,
                'arrived_at' => $visit->arrived_at?->toIso8601String(),
                'waiting_minutes' => $visit->status === 'arrived' ? $visit->waitingMinutes() : null,
            ])->values()->all(),
        ];
    }

    /**
     * Assessments this clinician started and left open.
     *
     * The instrument tables arrive in a later slice. Until then the panel
     * ships with a working empty state rather than a failing request.
     *
     * @return array<string, mixed>
     */
    private function unfinishedWork(Request $request): array
    {
        if (! Schema::hasTable('administrations')) {
            return ['count' => 0, 'items' => []];
        }

        $rows = DB::table('administrations')
            ->where('status', 'draft')
            ->where('administered_by', $request->user()?->getKey())
            ->get();

        return ['count' => $rows->count(), 'items' => $rows->all()];
    }

    /**
     * @return array<string, mixed>
     */
    private function overdueFollowup(): array
    {
        $overdue = Visit::query()
            ->where('status', 'scheduled')
            ->where('scheduled_for', '<', today());

        $count = (clone $overdue)->count();

        $visits = $overdue
            ->with('patient')
            ->orderBy('scheduled_for')
            ->limit(self::OVERDUE_LIMIT)
            ->get();

        return [
            'count' => $count,
            'items' => $visits->map(fn (Visit $visit) => [
                'patient' => [
                    'id' => $visit->patient->id,
                    'registry_no' => $visit->patient->registry_no,
                    'name' => $visit->patient->displayName(),
                ],
                'scheduled_for' => $visit->scheduled_for->toDateString(),
                'days_overdue' => (int) $visit->scheduled_for->diffInDays(today()),
                'date_last_seen' => $visit->patient->date_last_seen?->toDateString(),
                'contact_attempts' => $visit->contact_attempts,
            ])->values()->all(),
        ];
    }

    /**
     * A shortcut back into recent work, filtered to this clinician. A shortcut,
     * not a permission boundary — every record stays reachable through search.
     *
     * @return array<string, mixed>
     */
    private function recentlySeen(Request $request): array
    {
        $visits = Visit::with('patient')
            ->where('clinician_id', $request->user()?->getKey())
            ->where('status', 'complete')
            ->orderByDesc('completed_at')
            ->limit(self::RECENTLY_SEEN_LIMIT)
            ->get();

        return [
            'count' => $visits->count(),
            'items' => $visits->map(fn (Visit $visit) => [
                'patient' => [
                    'id' => $visit->patient->id,
                    'registry_no' => $visit->patient->registry_no,
                    'name' => $visit->patient->displayName(),
                ],
                'completed_at' => $visit->completed_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
