<?php

namespace App\Services;

class OtherServerDryRunScript
{
    public function build(string $platform, array $services, array $packages, int $runId): array
    {
        foreach ($services as $service) {
            if (! is_string($service) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.@-]{0,99}\z/', $service)) {
                throw new \RuntimeException('Invalid critical service name. Use exact service identifiers, not shell commands.');
            }
        }
        foreach ($packages as $package) {
            if (! is_string($package) || ! preg_match('/\A[a-z0-9][a-z0-9+.-]{0,127}(?::[a-z0-9]+)?\z/', $package)) {
                throw new \RuntimeException('Inspector returned an unsafe package name; no command was submitted.');
            }
        }
        if ($services === [] || count($services) > 30 || count($packages) > 150) {
            throw new \RuntimeException('Dry runs require 1-30 critical services and at most 150 package names.');
        }
        $prefix = 'SD_DRY_RUN_' . $runId;

        if ($platform === 'Windows') {
            $names = implode(',', array_map(fn ($service) => "'{$service}'", $services));
            $script = <<<POWERSHELL
\$ErrorActionPreference = 'Stop'
\$failed = \$false
foreach (\$name in @({$names})) {
    try {
        \$service = Get-Service -Name \$name -ErrorAction Stop
        if (\$service.Status -ne 'Running') { throw "Service state: \$(\$service.Status)" }
        Write-Output "{$prefix}:SERVICE_OK:\$name"
    } catch {
        Write-Output "{$prefix}:SERVICE_FAIL:\$name"
        \$failed = \$true
    }
}
Write-Output '{$prefix}:PREVIEW_MANUAL'
Write-Output '{$prefix}:DONE'
if (\$failed) { exit 1 }
POWERSHELL;

            return ['document' => 'AWS-RunPowerShellScript', 'script' => $script];
        }
        if ($platform !== 'Linux') {
            throw new \RuntimeException('Unsupported SSM platform; no command was submitted.');
        }

        $names = implode(' ', array_map(fn ($service) => "'{$service}'", $services));
        $arguments = implode(' ', array_map(fn ($package) => "'{$package}'", $packages));
        $script = <<<BASH
#!/bin/sh
set -u
PATH=/usr/sbin:/usr/bin:/sbin:/bin
export PATH
failed=0
for name in {$names}; do
    if systemctl is-active --quiet "\$name"; then
        printf '%s\n' "{$prefix}:SERVICE_OK:\$name"
    else
        printf '%s\n' "{$prefix}:SERVICE_FAIL:\$name"
        failed=1
    fi
done
BASH;
        if ($packages !== []) {
            $script .= <<<BASH

if command -v apt-get >/dev/null 2>&1; then
    if apt-get -s --only-upgrade --no-remove -o Debug::NoLocking=1 install {$arguments}; then
        printf '%s\n' '{$prefix}:PREVIEW_OK'
    else
        printf '%s\n' '{$prefix}:PREVIEW_FAIL'
        failed=1
    fi
else
    printf '%s\n' '{$prefix}:PREVIEW_MANUAL'
fi
BASH;
        } else {
            $script .= "\nprintf '%s\\n' '{$prefix}:PREVIEW_MANUAL'\n";
        }
        $script .= "\nprintf '%s\\n' '{$prefix}:DONE'\nexit \"\$failed\"\n";

        return ['document' => 'AWS-RunShellScript', 'script' => $script];
    }

    public function verify(string $output, array $services, int $runId): void
    {
        $lines = preg_split('/\r?\n/', $output);
        $prefix = 'SD_DRY_RUN_' . $runId;
        foreach ($services as $service) {
            if (! in_array("{$prefix}:SERVICE_OK:{$service}", $lines, true)
                || in_array("{$prefix}:SERVICE_FAIL:{$service}", $lines, true)) {
                throw new \RuntimeException("Critical service {$service} is not running, is missing, or its result is unavailable. Inspect the service configuration and dependency logs; restore the known-good configuration using your runbook. Do not restart production services without availability approval.");
            }
        }
        if (! in_array("{$prefix}:DONE", $lines, true)
            || (! in_array("{$prefix}:PREVIEW_OK", $lines, true) && ! in_array("{$prefix}:PREVIEW_MANUAL", $lines, true))) {
            throw new \RuntimeException('Dry-run output is incomplete or the package simulation failed. Inspect the SSM invocation output and package dependencies/repository metadata. Refresh repositories only in an approved maintenance process and repeat the dry run. No packages were installed.');
        }
        if (in_array("{$prefix}:PREVIEW_FAIL", $lines, true)) {
            throw new \RuntimeException('Package simulation failed. Inspect repository metadata, held packages and dependency conflicts before planning remediation. No packages were installed.');
        }
    }
}