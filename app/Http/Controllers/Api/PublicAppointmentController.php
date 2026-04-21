<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PublicAppointmentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $hasGuests = $request->has('guests')
            && is_array($request->input('guests'))
            && count($request->input('guests')) > 0;

        if ($hasGuests) {
            $validated = $request->validate([
                'customer_name' => ['required', 'string', 'max:255'],
                'customer_email' => ['required', 'email', 'max:255'],
                'customer_phone' => ['required', 'string', 'max:64'],
                'guests' => ['required', 'array', 'min:1', 'max:20'],
                'guests.*.label' => ['nullable', 'string', 'max:120'],
                'guests.*.services' => ['required', 'array', 'min:1'],
                'guests.*.services.*.name' => ['required', 'string', 'max:255'],
                'guests.*.services.*.category' => ['required', 'string', 'max:255'],
                'guests.*.services.*.price' => ['nullable', 'numeric', 'min:0'],
                'quoted_total' => ['required', 'numeric', 'min:0'],
                'appointment_date' => ['required', 'date'],
                'preferred_time' => ['required', 'string', 'max:191'],
                'customer_notes' => ['nullable', 'string', 'max:5000'],
            ]);

            $flat = Appointment::flattenGuestServicesIntoList($validated['guests']);

            if ($flat === []) {
                throw ValidationException::withMessages([
                    'guests' => ['Each guest must have at least one service.'],
                ]);
            }

            $validated['services'] = $flat;
            $validated['number_of_people'] = count($validated['guests']);
        } else {
            $validated = $request->validate([
                'customer_name' => ['required', 'string', 'max:255'],
                'customer_email' => ['required', 'email', 'max:255'],
                'customer_phone' => ['required', 'string', 'max:64'],
                'services' => ['required', 'array', 'min:1'],
                'services.*.name' => ['required', 'string', 'max:255'],
                'services.*.category' => ['required', 'string', 'max:255'],
                'services.*.price' => ['nullable', 'numeric', 'min:0'],
                'number_of_people' => ['required', 'integer', 'min:1', 'max:20'],
                'quoted_total' => ['required', 'numeric', 'min:0'],
                'appointment_date' => ['required', 'date'],
                'preferred_time' => ['required', 'string', 'max:191'],
                'customer_notes' => ['nullable', 'string', 'max:5000'],
            ]);

            $validated['guests'] = null;
        }

        $startsAt = $this->resolveStartsAt(
            $validated['appointment_date'],
            $validated['preferred_time']
        );

        $appointment = Appointment::create([
            'user_id' => $request->user()?->id,
            'customer_name' => $validated['customer_name'],
            'customer_email' => $validated['customer_email'],
            'customer_phone' => $validated['customer_phone'],
            'services' => $validated['services'],
            'guests' => $validated['guests'] ?? null,
            'number_of_people' => $validated['number_of_people'],
            'quoted_total' => $validated['quoted_total'],
            'appointment_date' => $validated['appointment_date'],
            'time_slot' => $validated['preferred_time'],
            'starts_at' => $startsAt,
            'status' => Appointment::STATUS_PENDING,
            'customer_notes' => $validated['customer_notes'] ?? null,
        ]);

        return response()->json($appointment->toApiArray(), 201);
    }

    public function currentService(): JsonResponse
    {
        $appointments = Appointment::query()
            ->where('status', Appointment::STATUS_ONGOING)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        if ($appointments->isEmpty()) {
            return response()->json([
                'count' => 0,
                'customers' => [],
                'message' => 'No customer is being served right now.',
            ]);
        }

        $customers = $appointments->map(function ($appointment) {
            return [
                'id' => $appointment->id,
                'customer_name' => $appointment->customer_name,
                'number_of_people' => max((int) $appointment->number_of_people, 1),
                'status' => $appointment->status,
                'quoted_total' => (float) $appointment->quoted_total,
            ];
        })->values();

        return response()->json([
            'count' => $customers->count(),
            'customers' => $customers,
            'message' => $customers->count() === 1
                ? '1 active booking is currently being served.'
                : $customers->count() . ' active bookings are currently being served.',
        ]);
    }

    public function upcomingAppointment(Request $request): JsonResponse
    {
        $user = $request->user();

        $appointment = Appointment::query()
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhere('customer_email', $user->email);
            })
            ->whereIn('status', [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_ONGOING,
            ])
            ->where(function ($query) {
                $query->whereDate('appointment_date', '>=', now()->toDateString())
                    ->orWhere('starts_at', '>=', now());
            })
            ->orderByRaw('COALESCE(starts_at, appointment_date) asc')
            ->first();

        if (! $appointment) {
            return response()->json([
                'data' => null,
                'message' => 'No upcoming appointment yet.',
            ]);
        }

        return response()->json([
            'data' => $appointment->toApiArray(),
            'message' => 'Upcoming appointment loaded.',
        ]);
    }

    private function resolveStartsAt(string $date, string $timeSlot): Carbon
    {
        $combined = trim($date . ' ' . $timeSlot);

        try {
            return Carbon::parse($combined);
        } catch (\Throwable) {
            try {
                return Carbon::parse($date)->setTime(12, 0);
            } catch (\Throwable) {
                throw ValidationException::withMessages([
                    'preferred_time' => ['Could not understand the preferred time. Try a format like 2:00 PM.'],
                ]);
            }
        }
    }
}