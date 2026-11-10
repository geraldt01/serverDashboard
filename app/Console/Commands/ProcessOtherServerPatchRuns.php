<?php

namespace App\Console\Commands;

use App\Models\OtherServerPatchRun;
use App\Services\OtherServerPatchDryRun;
use Illuminate\Console\Command;

class ProcessOtherServerPatchRuns extends Command
{
    protected $signature = 'other-servers:process-patch-runs';

    protected $description = 'Process read-only Inspector/service dry runs and retry failure alerts. Never install patches.';

    public function handle(OtherServerPatchDryRun $dryRuns): int
    {
        OtherServerPatchRun::whereIn('status', ['queued', 'preparing', 'running', 'install_queued', 'install_submitting', 'installing', 'post_checking'])->orderBy('id')->chunkById(10, function ($runs) use ($dryRuns) {
            foreach ($runs as $run) {
                $dryRuns->advance($run);
            }
        });
        OtherServerPatchRun::where('status', 'failed')->whereNull('alert_sent_at')->orderBy('id')->chunkById(10, function ($runs) use ($dryRuns) {
            foreach ($runs as $run) {
                $dryRuns->notifyFailure($run);
            }
        });
        $this->info('Dry-run statuses processed. No patch installation requested.');

        return self::SUCCESS;
    }
}