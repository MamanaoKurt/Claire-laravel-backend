<?php

use App\Http\Controllers\Api\Admin\AppointmentController as AdminAppointmentController;
use App\Http\Controllers\Api\Admin\ReportsController as AdminReportsController;
use App\Http\Controllers\Api\Admin\StaffDirectoryController;
use App\Http\Controllers\Api\AvailabilityController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PublicAppointmentController;
use App\Http\Controllers\Api\Staff\AppointmentController as StaffAppointmentController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

Route::post('/register', function (Request $request) {
    $validated = $request->validate([
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        'password' => ['required', 'string', 'min:8', 'confirmed'],
    ]);

    $user = User::create([
        'name' => $validated['name'],
        'email' => $validated['email'],
        'password' => $validated['password'],
        'role' => 'customer',
    ]);

    $token = $user->createToken('frontend')->plainTextToken;

    return response()->json([
        'message' => 'Account created successfully.',
        'token' => $token,
        'user' => $user,
    ], 201);
});

Route::post('/login', function (Request $request) {
    $validated = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required', 'string'],
    ]);

    $user = User::where('email', $validated['email'])->first();

    if (! $user || ! Hash::check($validated['password'], $user->password)) {
        throw ValidationException::withMessages([
            'email' => ['The provided credentials are incorrect.'],
        ]);
    }

    $token = $user->createToken('frontend')->plainTextToken;

    return response()->json([
        'message' => 'Login successful.',
        'token' => $token,
        'user' => $user,
    ]);
});

Route::middleware('auth:sanctum')->post('/logout', function (Request $request) {
    $request->user()->currentAccessToken()?->delete();

    return response()->json([
        'message' => 'Logged out successfully.',
    ]);
});

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return response()->json($request->user());
});

Route::middleware('auth:sanctum')->get('/notifications', [NotificationController::class, 'index']);
Route::middleware('auth:sanctum')->get('/profile/upcoming-appointment', [PublicAppointmentController::class, 'upcomingAppointment']);

Route::get('/current-service', [PublicAppointmentController::class, 'currentService']);
Route::get('/appointments/availability', [AvailabilityController::class, 'index']);
Route::get('/appointments/calendar', [AvailabilityController::class, 'calendar']);
Route::post('/appointments', [PublicAppointmentController::class, 'store']);

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/staff', [StaffDirectoryController::class, 'index']);
    Route::get('/reports/summary', [AdminReportsController::class, 'summary']);
    Route::get('/appointments', [AdminAppointmentController::class, 'index']);
    Route::post('/appointments', [AdminAppointmentController::class, 'store']);
    Route::patch('/appointments/{appointment}', [AdminAppointmentController::class, 'update']);
    Route::delete('/appointments/{appointment}', [AdminAppointmentController::class, 'destroy']);
    Route::patch('/appointments/{appointment}/assign', [AdminAppointmentController::class, 'assignStaff']);
});

Route::middleware(['auth:sanctum', 'role:staff'])->prefix('staff')->group(function () {
    Route::get('/appointments', [StaffAppointmentController::class, 'index']);
    Route::patch('/appointments/{appointment}/status', [StaffAppointmentController::class, 'updateStatus']);
    Route::patch('/appointments/{appointment}/notes', [StaffAppointmentController::class, 'addNotes']);
    Route::post('/appointments/{appointment}/reschedule-request', [StaffAppointmentController::class, 'requestReschedule']);
});