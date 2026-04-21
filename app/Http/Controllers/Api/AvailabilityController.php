<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    private function generateDailySlots(string $date): array
    {
        $start = Carbon::parse($date)->setTime(9, 0);
        $end = Carbon::parse($date)->setTime(19, 0);

        $bookedTimes = Appointment::query()
            ->whereDate('appointment_date', $date)
            ->whereNotIn('status', [
                Appointment::STATUS_CANCELLED,
                Appointment::STATUS_NO_SHOW,
            ])
            ->pluck('time_slot')
            ->filter()
            ->map(fn ($time) => trim((string) $time))
            ->values()
            ->all();

        $slots = [];

        while ($start < $end) {
            $time12 = $start->format('g:i A');
            $time24 = $start->format('H:i');

            $slots[] = [
                'time' => $time12,
                'value' => $time12,
                'time_24' => $time24,
                'available' => ! in_array($time12, $bookedTimes, true) && ! in_array($time24, $bookedTimes, true),
            ];

            $start->addHour();
        }

        return $slots;
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date'],
        ]);

        $date = $validated['date'];

        return response()->json([
            'date' => $date,
            'slots' => $this->generateDailySlots($date),
        ]);
    }

    public function calendar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $month = Carbon::createFromFormat('Y-m', $validated['month'])->startOfMonth();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $days = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $date = $cursor->format('Y-m-d');
            $slots = $this->generateDailySlots($date);

            $total = count($slots);
            $availableCount = collect($slots)->where('available', true)->count();

            $status = 'available';

            if ($availableCount === 0) {
                $status = 'fully_booked';
            } elseif ($availableCount < $total) {
                $status = 'partially_booked';
            }

            $days[$date] = [
                'date' => $date,
                'day' => (int) $cursor->format('j'),
                'status' => $status,
                'available_slots' => $availableCount,
                'total_slots' => $total,
            ];

            $cursor->addDay();
        }

        return response()->json([
            'month' => $month->format('Y-m'),
            'label' => $month->format('F Y'),
            'days' => $days,
        ]);
    }
}