<?php

namespace Tests\Unit;

use App\Services\OtherServerDryRunScript;
use PHPUnit\Framework\TestCase;

class OtherServerDryRunScriptTest extends TestCase
{
    public function test_linux_preview_only_simulates_and_checks_service_status(): void
    {
        $result = (new OtherServerDryRunScript())->build('Linux', ['nginx', 'php8.3-fpm'], ['libxml2'], 1);
        $this->assertSame('AWS-RunShellScript', $result['document']);
        $this->assertStringContainsString('apt-get -s --only-upgrade --no-remove', $result['script']);
        $this->assertStringContainsString('systemctl is-active --quiet', $result['script']);
        foreach (['apt-get update', 'dist-upgrade', 'reboot', 'systemctl restart', 'systemctl stop', 'yum update', 'dnf upgrade'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $result['script']);
        }
    }

    public function test_windows_checks_services_without_installing_updates(): void
    {
        $result = (new OtherServerDryRunScript())->build('Windows', ['W3SVC'], [], 2);
        $this->assertSame('AWS-RunPowerShellScript', $result['document']);
        $this->assertStringContainsString('Get-Service', $result['script']);
        $this->assertStringNotContainsString('Restart-', $result['script']);
        $this->assertStringNotContainsString('Install-', $result['script']);
    }

    public function test_command_injection_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new OtherServerDryRunScript())->build('Linux', ['nginx; reboot'], ['libxml2'], 1);
    }

    public function test_package_injection_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        (new OtherServerDryRunScript())->build('Linux', ['nginx'], ['--allow-unauthenticated'], 1);
    }

    public function test_success_requires_complete_matching_service_results(): void
    {
        (new OtherServerDryRunScript())->verify("SD_DRY_RUN_1:SERVICE_OK:nginx\nSD_DRY_RUN_1:PREVIEW_OK\nSD_DRY_RUN_1:DONE\n", ['nginx'], 1);
        $this->addToAssertionCount(1);
    }

    public function test_truncated_output_never_passes(): void
    {
        $this->expectException(\RuntimeException::class);
        (new OtherServerDryRunScript())->verify("SD_DRY_RUN_1:SERVICE_OK:nginx\nSD_DRY_RUN_1:PREVIEW_OK", ['nginx'], 1);
    }

    public function test_failed_service_names_the_service_and_recovery_guidance(): void
    {
        $this->expectExceptionMessage('Critical service nginx');
        (new OtherServerDryRunScript())->verify("SD_DRY_RUN_1:SERVICE_FAIL:nginx\nSD_DRY_RUN_1:DONE", ['nginx'], 1);
    }
}