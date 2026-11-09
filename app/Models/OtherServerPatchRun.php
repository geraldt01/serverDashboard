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
    ];

    public function target(): OtherServer
    {
        return (new OtherServer())->forceFill([
            'id' => $this->other_server_id ?? $this->id,
            'aws_instance_id' => $this->instance_id,
            'aws_role_arn' => $this->role_arn,
        ]);
    }
}