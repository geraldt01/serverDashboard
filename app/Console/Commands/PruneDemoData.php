<?php

namespace App\Console\Commands;

use App\Models\Ec2PatchStatus;
use App\Models\TrafficEvent;
use App\Models\WordpressPluginUpdate;
use Illuminate\Console\Command;

class PruneDemoData extends Command
{
    protected $signature = 'dashboard:prune-demo-data {--dry-run : Only report the rows that would be deleted}';

    protected $description = 'Remove leftover seed/mock demo rows (wordpress-main, wp-prod-1, api-prod-1) from the dashboard tables.';

    private const DEMO_SITE = 'wordpress-main';

    private const DEMO_INSTANCE_IDS = ['i-0a1b2c3d4e5f001', 'i-0a1b2c3d4e5f002'];

    public function handle(): int
    {
        $traffic = TrafficEvent::where('site_name', self::DEMO_SITE);
        $plugins = WordpressPluginUpdate::where('site_name', self::DEMO_SITE);
        $ec2 = Ec2PatchStatus::whereIn('instance_id', self::DEMO_INSTANCE_IDS);

        if ($this->option('dry-run')) {
            $this->info('traffic_events: '.$traffic->count());
            $this->info('wordpress_plugin_updates: '.$plugins->count());
            $this->info('ec2_patch_statuses: '.$ec2->count());

            return self::SUCCESS;
        }

        $this->info('Deleted traffic_events: '.$traffic->delete());
        $this->info('Deleted wordpress_plugin_updates: '.$plugins->delete());
        $this->info('Deleted ec2_patch_statuses: '.$ec2->delete());

        return self::SUCCESS;
    }
}
