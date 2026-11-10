<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtherServerPatchRun extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'services' => 'array',
        'plan' => 'array',
        'deadline_at' => 'datetime',
        'completed_at' => 'datetime',
        'alert_sent_at' => 'datetime',
        'install_requested_at' => 'datetime',
    ];

    public function target(): OtherServer
    {
        return (new OtherServer())->forceFill([
            'id' => $this->other_server_id ?? $this->id,
            'aws_instance_id' => $this->instance_id,
            'aws_role_arn' => $this->role_arn,
        ]);
    }

    public function canInstall(): bool
    {
        return $this->status === 'dry_run_passed' && $this->hasInstallableFindings();
    }

    public function hasInstallableFindings(): bool
    {
        if (! in_array($this->plan['platform'] ?? null, ['Linux', 'Windows'], true)) {
            return false;
        }

        return collect($this->plan['findings'] ?? [])->contains(fn ($finding) =>
            ($finding['type'] ?? null) === 'PACKAGE_VULNERABILITY'
            && in_array($finding['fix'] ?? null, ['YES', 'PARTIAL'], true));
    }
}