<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_ONGOING = 'ongoing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_NO_SHOW = 'no_show';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'customer_name',
        'customer_email',
        'customer_phone',
        'services',
        'guests',
        'number_of_people',
        'quoted_total',
        'appointment_date',
        'time_slot',
        'starts_at',
        'ends_at',
        'status',
        'assigned_staff_id',
        'customer_notes',
        'staff_notes',
        'reschedule_requested_at',
        'reschedule_note',
        'reschedule_proposed_starts_at',
        'schedule_overridden',
    ];

    protected function casts(): array
    {
        return [
            'services' => 'array',
            'guests' => 'array',
            'appointment_date' => 'date',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'quoted_total' => 'decimal:2',
            'reschedule_requested_at' => 'datetime',
            'reschedule_proposed_starts_at' => 'datetime',
            'schedule_overridden' => 'boolean',
        ];
    }

    public function customerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignedStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_staff_id');
    }

    /**
     * @param  array<int, array{label?: string, services: array<int, mixed>}>  $guests
     * @return array<int, array<string, mixed>>
     */
    public static function flattenGuestServicesIntoList(array $guests): array
    {
        $flat = [];

        foreach ($guests as $guest) {
            foreach ($guest['services'] ?? [] as $line) {
                $flat[] = $line;
            }
        }

        return $flat;
    }

    public function toApiArray(): array
    {
        $this->loadMissing('assignedStaff:id,name,email');

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_phone' => $this->customer_phone,
            'services' => $this->services ?? [],
            'guests' => $this->guests ?? [],
            'number_of_people' => (int) $this->number_of_people,
            'quoted_total' => (float) $this->quoted_total,
            'appointment_date' => $this->appointment_date?->format('Y-m-d'),
            'time_slot' => $this->time_slot,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'status' => $this->status,
            'assigned_staff_id' => $this->assigned_staff_id,
            'assigned_staff' => $this->assignedStaff ? [
                'id' => $this->assignedStaff->id,
                'name' => $this->assignedStaff->name,
                'email' => $this->assignedStaff->email,
            ] : null,
            'customer_notes' => $this->customer_notes,
            'staff_notes' => $this->staff_notes,
            'reschedule_requested_at' => $this->reschedule_requested_at?->toIso8601String(),
            'reschedule_note' => $this->reschedule_note,
            'reschedule_proposed_starts_at' => $this->reschedule_proposed_starts_at?->toIso8601String(),
            'schedule_overridden' => (bool) $this->schedule_overridden,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}