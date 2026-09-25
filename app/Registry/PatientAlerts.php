<?php

declare(strict_types=1);

namespace App\Registry;

use App\Models\Patient;

/**
 * The banner's alert strip.
 *
 * Auto-populated, never typed, and only ever showing alerts that apply — the
 * strip collapses when there is nothing in it rather than sitting there empty.
 *
 * Most of the alerts Brief 1 lists depend on data later slices collect: drugs
 * to avoid, falls and fractures, dysphagia, orthostatic hypotension, impulse
 * control, implanted devices. Each is added here as its source lands. What can
 * be answered honestly today is answered today.
 *
 * @return list<array{kind: string, severity: string, text: string}>
 */
final class PatientAlerts
{
    /**
     * @return list<array<string, string>>
     */
    public static function for(Patient $patient): array
    {
        $alerts = [];

        $overdue = $patient->visits()
            ->where('status', 'scheduled')
            ->whereDate('scheduled_for', '<', today())
            ->orderBy('scheduled_for')
            ->first();

        if ($overdue) {
            $days = (int) $overdue->scheduled_for->diffInDays(today());

            $alerts[] = [
                'kind' => 'overdue_visit',
                // A missed appointment becomes a different kind of problem once
                // it is months rather than weeks old.
                'severity' => $days > 90 ? 'high' : 'medium',
                'text' => "Visit overdue by {$days} days, scheduled for {$overdue->scheduled_for->toDateString()}",
            ];
        }

        if ($patient->status === 'deceased') {
            $alerts[] = [
                'kind' => 'deceased',
                'severity' => 'high',
                'text' => 'This record is read-only: the patient is recorded as deceased.',
            ];
        }

        return $alerts;
    }
}
