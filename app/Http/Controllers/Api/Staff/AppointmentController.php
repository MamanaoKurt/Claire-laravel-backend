<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : now()->startOfWeek();
        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : now()->endOfWeek();

        $staffId = (int) $request->user()->id;

        $items = Appointment::query()
            ->whereBetween('starts_at', [$from, $to])
            ->where(function ($q) use ($staffId) {
                $q->where('assigned_staff_id', $staffId)
                    ->orWhere(function ($q2) {
                        $q2->whereNull('assigned_staff_id')
                            ->whereIn('status', [
                                Appointment::STATUS_PENDING,
                                Appointment::STATUS_CONFIRMED,
                            ]);
                    });
            })
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'data' => $items->map(function (Appointment $a) use ($staffId) {
                $row = $a->toApiArray();
                $row['viewer_is_assigned'] = (int) $a->assigned_staff_id === $staffId;

                return $row;
            }),
        ]);
    }

    public function updateStatus(Request $request, Appointment $appointment): JsonResponse
    {
        $this->ensureAssigned($request, $appointment);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([
                Appointment::STATUS_ONGOING,
                Appointment::STATUS_COMPLETED,
                Appointment::STATUS_NO_SHOW,
            ])],
        ]);

        $next = $validated['status'];
        $current = $appointment->status;

        $allowed = match ($next) {
            Appointment::STATUS_ONGOING => in_array($current, [
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_PENDING,
            ], true),
            Appointment::STATUS_COMPLETED => in_array($current, [
                Appointment::STATUS_ONGOING,
                Appointment::STATUS_CONFIRMED,
            ], true),
            Appointment::STATUS_NO_SHOW => in_array($current, [
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_PENDING,
                Appointment::STATUS_ONGOING,
            ], true),
            default => false,
        };

        if (! $allowed) {
            throw ValidationException::withMessages([
                'status' => ['This status change is not allowed from the current state.'],
            ]);
        }

        $appointment->status = $next;
        $appointment->save();

        return response()->json($appointment->fresh()->toApiArray());
    }

    public function addNotes(Request $request, Appointment $appointment): JsonResponse
    {
        $this->ensureAssigned($request, $appointment);

        $validated = $request->validate([
            'staff_notes' => ['required', 'string', 'max:5000'],
        ]);

        $stamp = now()->format('Y-m-d H:i');
        $block = "[{$stamp}]\n".$validated['staff_notes'];
        $existing = trim((string) $appointment->staff_notes);
        $appointment->staff_notes = $existing === '' ? $block : $existing."\n\n".$block;
        $appointment->save();

        return response()->json($appointment->fresh()->toApiArray());
    }

    public function requestReschedule(Request $request, Appointment $appointment): JsonResponse
    {
        $this->ensureAssigned($request, $appointment);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'proposed_starts_at' => ['nullable', 'date'],
        ]);

        $appointment->reschedule_requested_at = now();
        $appointment->reschedule_note = $validated['note'] ?? null;
        if (! empty($validated['proposed_starts_at'])) {
            $appointment->reschedule_proposed_starts_at = Carbon::parse($validated['proposed_starts_at']);
        }
        $appointment->save();

        return response()->json($appointment->fresh()->toApiArray());
    }

    private function ensureAssigned(Request $request, Appointment $appointment): void
    {
        if ((int) $appointment->assigned_staff_id !== (int) $request->user()->id) {
            abort(403, 'This appointment is not assigned to you.');
        }
    }
}
