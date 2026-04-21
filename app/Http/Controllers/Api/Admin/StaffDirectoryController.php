<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class StaffDirectoryController extends Controller
{
    public function index(): JsonResponse
    {
        $staff = User::query()
            ->where('role', 'staff')
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return response()->json(['data' => $staff]);
    }
}
