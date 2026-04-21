<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
	use WithoutModelEvents;

	public function run(): void
	{
		User::updateOrCreate(
			['email' => 'admin@claire.local'],
			[
				'name' => 'Claire Admin',
				'role' => 'admin',
				'password' => Hash::make('Admin123!'),
			]
		);

		$staff = User::updateOrCreate(
			['email' => 'staff@claire.local'],
			[
				'name' => 'Claire Staff',
				'role' => 'staff',
				'password' => Hash::make('Staff123!'),
			]
		);

		if (Appointment::query()->exists()) {
			return;
		}

		$day = Carbon::now()->addDays(2)->setTime(10, 30);
		Appointment::create([
			'customer_name' => 'Demo Client',
			'customer_email' => 'demo.client@example.com',
			'customer_phone' => '09171234567',
			'services' => [
				['name' => 'Gel', 'category' => 'Manicure', 'price' => 400],
			],
			'guests' => [
				[
					'label' => 'Guest 1',
					'services' => [
						['name' => 'Gel', 'category' => 'Manicure', 'price' => 400],
					],
				],
			],
			'number_of_people' => 1,
			'quoted_total' => 400,
			'appointment_date' => $day->toDateString(),
			'time_slot' => '10:30 AM',
			'starts_at' => $day,
			'status' => Appointment::STATUS_CONFIRMED,
			'assigned_staff_id' => $staff->id,
			'customer_notes' => 'Demo appointment for staff portal.',
		]);
	}
}
