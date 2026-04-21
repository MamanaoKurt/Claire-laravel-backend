<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone', 64);
            $table->json('services');
            $table->unsignedTinyInteger('number_of_people')->default(1);
            $table->decimal('quoted_total', 12, 2)->default(0);
            $table->date('appointment_date');
            $table->string('time_slot', 191);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('assigned_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('customer_notes')->nullable();
            $table->text('staff_notes')->nullable();
            $table->timestamp('reschedule_requested_at')->nullable();
            $table->text('reschedule_note')->nullable();
            $table->dateTime('reschedule_proposed_starts_at')->nullable();
            $table->boolean('schedule_overridden')->default(false);
            $table->timestamps();

            $table->index(['starts_at']);
            $table->index(['status']);
            $table->index(['assigned_staff_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
