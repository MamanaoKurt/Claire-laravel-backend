<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $items = match ($user->role) {
            'admin' => $this->adminNotifications(),
            'staff' => $this->staffNotifications($user),
            default => $this->customerNotifications($user),
        };

        return response()->json([
            'data' => array_values($items),
        ]);
    }

    private function adminNotifications(): array
    {
        $appointments = Appointment::query()
            ->with('assignedStaff:id,name,email')
            ->latest('updated_at')
            ->limit(12)
            ->get();

        return $appointments->map(function (Appointment $appointment) {
            $title = 'Appointment update';
            $description = "{$appointment->customer_name} has an updated appointment.";
            $tone = 'gold';

            if ($appointment->status === Appointment::STATUS_PENDING) {
                $title = 'New booking request';
                $description = "{$appointment->customer_name} requested an appointment for {$appointment->appointment_date?->format('M d, Y')} at {$appointment->time_slot}.";
                $tone = 'gold';
            } elseif ($appointment->status === Appointment::STATUS_CONFIRMED) {
                $title = 'Appointment confirmed';
                $description = "{$appointment->customer_name} is confirmed for {$appointment->appointment_date?->format('M d, Y')} at {$appointment->time_slot}.";
                $tone = 'sage';
            } elseif ($appointment->status === Appointment::STATUS_CANCELLED) {
                $title = 'Appointment cancelled';
                $description = "{$appointment->customer_name}'s appointment was cancelled.";
                $tone = 'rose';
            } elseif ($appointment->status === Appointment::STATUS_COMPLETED) {
                $title = 'Service completed';
                $description = "{$appointment->customer_name}'s appointment has been marked completed.";
                $tone = 'sage';
            }

            if ($appointment->reschedule_requested_at) {
                $title = 'Reschedule request';
                $description = "{$appointment->customer_name} has a reschedule request waiting for review.";
                $tone = 'rose';
            }

            if (!$appointment->assigned_staff_id) {
                $description .= ' No staff assigned yet.';
            }

            return $this->formatItem($appointment, $title, $description, $tone);
        })->all();
    }

    private function staffNotifications(User $user): array
    {
        $appointments = Appointment::query()
            ->where(function ($query) use ($user) {
                $query->where('assigned_staff_id', $user->id)
                    ->orWhere(function ($sub) {
                        $sub->whereNull('assigned_staff_id')
                            ->whereIn('status', [
                                Appointment::STATUS_PENDING,
                                Appointment::STATUS_CONFIRMED,
                            ]);
                    });
            })
            ->latest('updated_at')
            ->limit(12)
            ->get();

        return $appointments->map(function (Appointment $appointment) use ($user) {
            $isAssignedToUser = (int) $appointment->assigned_staff_id === (int) $user->id;

            if ($isAssignedToUser) {
                $title = 'Assigned appointment';
                $description = "{$appointment->customer_name} is scheduled on {$appointment->appointment_date?->format('M d, Y')} at {$appointment->time_slot}.";
                $tone = 'sage';

                if ($appointment->status === Appointment::STATUS_ONGOING) {
                    $title = 'Appointment ongoing';
                    $description = "{$appointment->customer_name}'s appointment is currently ongoing.";
                } elseif ($appointment->status === Appointment::STATUS_COMPLETED) {
                    $title = 'Appointment completed';
                    $description = "{$appointment->customer_name}'s appointment has been completed.";
                } elseif ($appointment->status === Appointment::STATUS_NO_SHOW) {
                    $title = 'Client no-show';
                    $description = "{$appointment->customer_name} was marked as no-show.";
                    $tone = 'rose';
                }
            } else {
                $title = 'Open booking available';
                $description = "{$appointment->customer_name} booked {$appointment->appointment_date?->format('M d, Y')} at {$appointment->time_slot}. Waiting for staff assignment.";
                $tone = 'gold';
            }

            if ($appointment->reschedule_requested_at) {
                $title = 'Reschedule noted';
                $description = "{$appointment->customer_name}'s appointment has a reschedule note.";
                $tone = 'rose';
            }

            return $this->formatItem($appointment, $title, $description, $tone);
        })->all();
    }

    private function customerNotifications(User $user): array
    {
        $appointments = Appointment::query()
            ->where('user_id', $user->id)
            ->latest('updated_at')
            ->limit(12)
            ->get();

        return $appointments->map(function (Appointment $appointment) {
            $title = 'Booking received';
            $description = "Your appointment for {$appointment->appointment_date?->format('M d, Y')} at {$appointment->time_slot} has been received.";
            $tone = 'gold';

            if ($appointment->status === Appointment::STATUS_CONFIRMED) {
                $title = 'Booking confirmed';
                $description = "Your appointment on {$appointment->appointment_date?->format('M d, Y')} at {$appointment->time_slot} is confirmed.";
                $tone = 'sage';
            } elseif ($appointment->status === Appointment::STATUS_COMPLETED) {
                $title = 'Appointment completed';
                $description = "Your appointment has been marked completed.";
                $tone = 'sage';
            } elseif ($appointment->status === Appointment::STATUS_CANCELLED) {
                $title = 'Appointment cancelled';
                $description = "Your appointment has been cancelled.";
                $tone = 'rose';
            } elseif ($appointment->status === Appointment::STATUS_NO_SHOW) {
                $title = 'Appointment marked no-show';
                $description = "Your appointment was marked as no-show.";
                $tone = 'rose';
            } elseif ($appointment->status === Appointment::STATUS_ONGOING) {
                $title = 'Appointment ongoing';
                $description = "Your appointment is currently ongoing.";
                $tone = 'sage';
            }

            if ($appointment->reschedule_requested_at) {
                $title = 'Reschedule request sent';
                $description = 'Your reschedule request has been recorded.';
                $tone = 'rose';
            }

            return $this->formatItem($appointment, $title, $description, $tone);
        })->all();
    }

    private function formatItem(Appointment $appointment, string $title, string $description, string $tone): array
    {
        $referenceTime = $appointment->updated_at ?? $appointment->created_at ?? now();
        $isUnread = $referenceTime->greaterThanOrEqualTo(now()->subDay());

        return [
            'id' => $appointment->id,
            'title' => $title,
            'description' => $description,
            'time' => $referenceTime->diffForHumans(),
            'tone' => in_array($tone, ['gold', 'rose', 'sage'], true) ? $tone : 'gold',
            'unread' => $isUnread,
            'appointment_id' => $appointment->id,
            'status' => $appointment->status,
            'created_at' => optional($appointment->created_at)->toIso8601String(),
            'updated_at' => optional($appointment->updated_at)->toIso8601String(),
        ];
    }
}