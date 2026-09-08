<?php

namespace Database\Seeders;

use App\Models\TrafficEvent;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Dashboard Admin', 'password' => Hash::make('Admin#12345'), 'role' => 'admin']
        );

        User::updateOrCreate(
            ['email' => 'viewer@example.com'],
            ['name' => 'Dashboard Viewer', 'password' => Hash::make('Viewer#12345'), 'role' => 'viewer']
        );

        if (TrafficEvent::query()->doesntExist()) {
            foreach (range(0, 13) as $daysAgo) {
                foreach (['nexgen-configapp', 'nexus-central-app'] as $siteName) {
                    TrafficEvent::create([
                        'site_name' => $siteName,
                        'visits' => random_int(80, 780),
                        'recorded_at' => now()->subDays($daysAgo),
                    ]);
                }
            }
        }
    }
}
