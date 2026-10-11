<?php

namespace App\Console\Commands;

use App\Services\Ec2PatchStatusCollector;
use Illuminate\Console\Command;

class CollectEc2PatchStatuses extends Command
{
    protected $signature = 'ec2:collect-patch-status';

    protected $description = 'Collect EC2 Systems Manager patch compliance without installing patches.';

    public function handle(Ec2PatchStatusCollector $collector): int
    {
        $result = $collector->collect();
        $this->info("Checked {$result['instances']} EC2 instance(s) across {$result['regions']} enabled region(s); saved {$result['patch_snapshots']} SSM patch snapshot(s).");
        if ($result['failed_regions'] !== []) {
            $this->warn('Collection failed in: '.implode(', ', $result['failed_regions']));
        }

        return $result['failed_regions'] === [] ? self::SUCCESS : self::FAILURE;
    }
}