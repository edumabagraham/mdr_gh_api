<?php

namespace App\Http\Controllers;

use App\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VisitController extends Controller
{
    /**
     * Move a visit through the clinic: scheduled → arrived → in_progress →
     * complete, with missed and cancelled as exits.
     *
     * Transitions are checked rather than trusted. A tablet replaying a queued
     * update out of order would otherwise walk a finished visit backwards.
     */
    public function update(Request $request, Visit $visit): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Visit::TRANSITIONS))],
        ]);

        if (! $visit->canTransitionTo($data['status'])) {
            throw ValidationException::withMessages([
                'status' => "A visit that is {$visit->status} cannot become {$data['status']}.",
            ]);
        }

        $visit->status = $data['status'];

        match ($data['status']) {
            'arrived' => $visit->arrived_at ??= now(),
            'in_progress' => $visit->started_at ??= now(),
            'complete' => $visit->completed_at ??= now(),
            default => null,
        };

        if ($data['status'] === 'in_progress' && $visit->clinician_id === null) {
            $visit->clinician_id = $request->user()?->getKey();
        }

        $visit->save();

        // The patient's last-seen date is what the overdue list reads from.
        if ($data['status'] === 'complete') {
            $visit->patient()->update(['date_last_seen' => today()]);
        }

        return response()->json([
            'id' => $visit->id,
            'status' => $visit->status,
            'arrived_at' => $visit->arrived_at?->toIso8601String(),
            'started_at' => $visit->started_at?->toIso8601String(),
            'completed_at' => $visit->completed_at?->toIso8601String(),
        ]);
    }
}
