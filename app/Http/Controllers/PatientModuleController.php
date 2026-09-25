<?php

namespace App\Http\Controllers;

use App\Models\AuditEntry;
use App\Models\Patient;
use App\Models\PatientModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PatientModuleController extends Controller
{
    /**
     * Closing a module is a clinical statement, never a side effect of a
     * diagnosis changing. The row stays, with the reason on it: a module that
     * was open and is now closed is information, one that vanishes is a gap.
     */
    public function close(Request $request, Patient $patient, string $module): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $open = $this->openModule($patient, $module);

        $open->forceFill([
            'closed_at' => now(),
            'closed_reason' => $data['reason'],
            'is_primary' => false,
        ])->save();

        AuditEntry::record($request, 'module.closed', 'patient', $patient->getKey(), [
            'module' => $module,
            'reason' => $data['reason'],
        ]);

        return response()->json(['module' => $module, 'closed_at' => $open->closed_at->toIso8601String()]);
    }

    /** Choose which module the banner stages from. */
    public function primary(Request $request, Patient $patient, string $module): JsonResponse
    {
        $target = $this->openModule($patient, $module);

        DB::transaction(function () use ($patient, $target): void {
            // Cleared first: the database allows only one primary per patient,
            // and it is right that it does.
            $patient->modules()->open()->where('is_primary', true)->update(['is_primary' => false]);

            $target->forceFill(['is_primary' => true])->save();
        });

        AuditEntry::record($request, 'module.primary_changed', 'patient', $patient->getKey(), [
            'module' => $module,
        ]);

        return response()->json(['module' => $module, 'is_primary' => true]);
    }

    private function openModule(Patient $patient, string $module): PatientModule
    {
        $open = $patient->modules()->open()->where('module', $module)->first();

        if (! $open) {
            throw ValidationException::withMessages([
                'module' => "This patient has no open {$module} module.",
            ]);
        }

        return $open;
    }
}
