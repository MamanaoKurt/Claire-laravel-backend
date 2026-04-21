<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    private const STATUSES = [
        Appointment::STATUS_PENDING,
        Appointment::STATUS_CONFIRMED,
        Appointment::STATUS_ONGOING,
        Appointment::STATUS_COMPLETED,
        Appointment::STATUS_NO_SHOW,
        Appointment::STATUS_CANCELLED,
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', 'string', Rule::in(self::STATUSES)],
        ]);

        $query = Appointment::query()
            ->with('assignedStaff:id,name,email')
            ->orderBy('starts_at');

        if (! empty($validated['from'])) {
            $query->whereDate('starts_at', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->whereDate('starts_at', '<=', $validated['to']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json([
            'data' => $query->get()->map(fn (Appointment $a) => $a->toApiArray()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateAppointmentBody($request, false);
        $data = $this->applyGuestsToServices($data);
        $data['starts_at'] = $this->resolveStartsAt($data['appointment_date'], $data['time_slot']);
        $data['ends_at'] = $data['ends_at'] ?? null;

        if (array_key_exists('assigned_staff_id', $data)) {
            $this->assertStaffUser($data['assigned_staff_id']);
        }

        $appointment = Appointment::create($data);

        return response()->json($appointment->fresh()->toApiArray(), 201);
    }

    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $this->validateAppointmentBody($request, true);
        $data = $this->applyGuestsToServices($data, true);

        if (array_key_exists('assigned_staff_id', $data)) {
            $this->assertStaffUser($data['assigned_staff_id']);
        }

        if (array_key_exists('appointment_date', $data) || array_key_exists('time_slot', $data)) {
            $date = $data['appointment_date'] ?? $appointment->appointment_date->format('Y-m-d');
            $slot = $data['time_slot'] ?? $appointment->time_slot;
            $data['starts_at'] = $this->resolveStartsAt($date, $slot);
        }

        if (array_key_exists('starts_at', $data) && is_string($data['starts_at'])) {
            $data['starts_at'] = Carbon::parse($data['starts_at']);
        }
        if (array_key_exists('ends_at', $data) && is_string($data['ends_at'])) {
            $data['ends_at'] = Carbon::parse($data['ends_at']);
        }

        $appointment->fill($data);
        $appointment->save();

        return response()->json($appointment->fresh()->toApiArray());
    }

    public function destroy(Appointment $appointment): JsonResponse
    {
        $appointment->status = Appointment::STATUS_CANCELLED;
        $appointment->save();

        return response()->json($appointment->fresh()->toApiArray());
    }

    public function assignStaff(Request $request, Appointment $appointment): JsonResponse
    {
        $validated = $request->validate([
            'assigned_staff_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $this->assertStaffUser($validated['assigned_staff_id']);

        $appointment->assigned_staff_id = $validated['assigned_staff_id'];
        $appointment->save();

        return response()->json($appointment->fresh()->toApiArray());
    }

    private function assertStaffUser(?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        $staff = User::query()
            ->where('id', $userId)
            ->where('role', 'staff')
            ->exists();

        if (! $staff) {
            throw ValidationException::withMessages([
                'assigned_staff_id' => ['Selected user is not a staff account.'],
            ]);
        }
    }

    private function validateAppointmentBody(Request $request, bool $partial): array
    {
        $p = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'customer_name' => [$p, 'string', 'max:255'],
            'customer_email' => [$p, 'email', 'max:255'],
            'customer_phone' => [$p, 'string', 'max:64'],
            'services' => ['nullable', 'array'],
            'services.*.name' => ['required_with:services', 'string', 'max:255'],
            'services.*.category' => ['required_with:services', 'string', 'max:255'],
            'services.*.price' => ['nullable', 'numeric', 'min:0'],
            'guests' => ['nullable', 'array'],
            'guests.*.label' => ['nullable', 'string', 'max:120'],
            'guests.*.services' => ['required_with:guests', 'array', 'min:1'],
            'guests.*.services.*.name' => ['required', 'string', 'max:255'],
            'guests.*.services.*.category' => ['required', 'string', 'max:255'],
            'guests.*.services.*.price' => ['nullable', 'numeric', 'min:0'],
            'number_of_people' => [$p, 'integer', 'min:1', 'max:20'],
            'quoted_total' => [$p, 'numeric', 'min:0'],
            'appointment_date' => [$p, 'date'],
            'time_slot' => [$p, 'string', 'max:191'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['nullable', 'date'],
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'assigned_staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'customer_notes' => ['nullable', 'string', 'max:5000'],
            'staff_notes' => ['nullable', 'string', 'max:5000'],
            'schedule_overridden' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * When guests[] is present, flatten into services and set number_of_people.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyGuestsToServices(array $data, bool $partial = false): array
    {
        if (! empty($data['guests'])) {
            $flat = Appointment::flattenGuestServicesIntoList($data['guests']);
            if ($flat === []) {
                throw ValidationException::withMessages([
                    'guests' => ['Each guest must have at least one service.'],
                ]);
            }
            $data['services'] = $flat;
            $data['number_of_people'] = count($data['guests']);
        } elseif (! $partial && empty($data['services'])) {
            throw ValidationException::withMessages([
                'services' => ['Provide services or guests with services.'],
            ]);
        }

        return $data;
    }

    private function resolveStartsAt(string $date, string $timeSlot): Carbon
    {
        $combined = trim($date.' '.$timeSlot);

        try {
            return Carbon::parse($combined);
        } catch (\Throwable) {
            return Carbon::parse($date)->setTime(12, 0);
        }
    }
}
