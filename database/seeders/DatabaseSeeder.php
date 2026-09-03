<?php

namespace Database\Seeders;

use App\Models\Ec2PatchStatus;
use App\Models\TrafficEvent;
use App\Models\User;
use App\Models\WordpressPluginUpdate;
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
                foreach (['wordpress-main', 'nexgen-configapp', 'nexus-central-app'] as $siteName) {
                    TrafficEvent::create([
                        'site_name' => $siteName,
                        'visits' => random_int(80, 780),
                        'recorded_at' => now()->subDays($daysAgo),
                    ]);
                }
            }
        }

        if (WordpressPluginUpdate::query()->doesntExist()) {
            WordpressPluginUpdate::insert([
                ['site_name' => 'wordpress-main', 'plugin_name' => 'akismet/akismet.php', 'current_version' => '5.3', 'latest_version' => '5.3', 'status' => 'up_to_date', 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['site_name' => 'wordpress-main', 'plugin_name' => 'elementor/elementor.php', 'current_version' => '3.21.0', 'latest_version' => '3.24.1', 'status' => 'outdated', 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['site_name' => 'wordpress-main', 'plugin_name' => 'wordfence/wordfence.php', 'current_version' => '7.11.5', 'latest_version' => '7.11.5', 'status' => 'up_to_date', 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        if (Ec2PatchStatus::query()->doesntExist()) {
            Ec2PatchStatus::insert([
                ['instance_id' => 'i-0a1b2c3d4e5f001', 'instance_name' => 'wp-prod-1', 'missing_count' => 4, 'installed_count' => 112, 'failed_count' => 0, 'reboot_required' => true, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()],
                ['instance_id' => 'i-0a1b2c3d4e5f002', 'instance_name' => 'api-prod-1', 'missing_count' => 0, 'installed_count' => 97, 'failed_count' => 0, 'reboot_required' => false, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }
}
