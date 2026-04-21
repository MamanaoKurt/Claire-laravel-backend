<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('role', 'staff_admin')
            ->update(['role' => 'admin']);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('role', 'admin')
            ->where('email', 'staffadmin@claire.local')
            ->update(['role' => 'staff_admin']);
    }
};
