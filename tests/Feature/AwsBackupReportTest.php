<?php

namespace Tests\Feature;

use App\Services\AwsBackupReport;
use Aws\Backup\BackupClient;
use Aws\Result;
use Mockery;
use Tests\TestCase;

class AwsBackupReportTest extends TestCase
{
    public function test_it_reads_backup_plans_and_recent_jobs_without_mutating_backup_configuration(): void
    {
        config(['services.backup.regions' => ['ap-southeast-2']]);
        $client = Mockery::mock(BackupClient::class);
        $client->shouldReceive('listBackupPlans')->once()->with(['MaxResults' => 1000])->andReturn(new Result([
            'BackupPlansList' => [['BackupPlanName' => 'Production daily'] ],
        ]));
        $client->shouldReceive('listBackupJobs')->once()->with(['MaxResults' => 1000])->andReturn(new Result([
            'BackupJobs' => [[
                'State' => 'COMPLETED',
                'ResourceName' => 'production-instance',
                'CreationDate' => 1792540800,
            ]],
        ]));
        $client->shouldNotReceive('createBackupPlan');
        $client->shouldNotReceive('updateBackupPlan');
        $client->shouldNotReceive('startBackupJob');
        $client->shouldNotReceive('deleteBackupPlan');

        $report = (new AwsBackupReport($client))->build();

        $this->assertTrue($report['available']);
        $this->assertSame([['name' => 'Production daily', 'region' => 'ap-southeast-2']], $report['plans']);
        $this->assertSame('COMPLETED', $report['jobs'][0]['state']);
        $this->assertSame('production-instance', $report['jobs'][0]['resource']);
        $this->assertSame('ap-southeast-2', $report['jobs'][0]['region']);
        $this->assertSame(1792540800, $report['jobs'][0]['created_at']->getTimestamp());
        $this->assertSame(['COMPLETED' => 1], $report['job_counts']);
    }

    public function test_it_follows_next_tokens_when_listing_backup_plans(): void
    {
        config(['services.backup.regions' => ['ap-southeast-2']]);
        $client = Mockery::mock(BackupClient::class);
        $client->shouldReceive('listBackupPlans')->once()->with(['MaxResults' => 1000])->andReturn(new Result([
            'BackupPlansList' => [['BackupPlanName' => 'First plan']],
            'NextToken' => 'next-page',
        ]));
        $client->shouldReceive('listBackupPlans')->once()->with(['MaxResults' => 1000, 'NextToken' => 'next-page'])->andReturn(new Result([
            'BackupPlansList' => [['BackupPlanName' => 'Second plan']],
        ]));
        $client->shouldReceive('listBackupJobs')->once()->with(['MaxResults' => 1000])->andReturn(new Result(['BackupJobs' => []]));

        $report = (new AwsBackupReport($client))->build();

        $this->assertSame(['First plan', 'Second plan'], array_column($report['plans'], 'name'));
    }
}