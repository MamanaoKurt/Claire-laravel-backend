<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : now()->subDays(30)->startOfDay();
        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : now()->endOfDay();

        $base = Appointment::query()
            ->whereBetween('starts_at', [$from, $to]);

        $bookingCount = (clone $base)->count();

        $revenue = (clone $base)
            ->where('status', Appointment::STATUS_COMPLETED)
            ->sum('quoted_total');

        $appointments = (clone $base)->get(['services']);

        $popular = [];
        foreach ($appointments as $row) {
            foreach ($row->services ?? [] as $svc) {
                $name = $svc['name'] ?? 'Unknown';
                $category = $svc['category'] ?? 'General';
                $key = $category.' — '.$name;
                $popular[$key] = ($popular[$key] ?? 0) + 1;
            }
        }

        arsort($popular);
        $popularServices = collect($popular)
            ->take(15)
            ->map(fn (int $count, string $label) => ['label' => $label, 'count' => $count])
            ->values();

        return response()->json([
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'booking_count' => $bookingCount,
            'revenue_completed' => (string) $revenue,
            'popular_services' => $popularServices,
        ]);
    }
}
