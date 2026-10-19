<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TamperSimulation extends Model
{
    protected $guarded = [];

    protected $casts = [
        'simulated_choices' => 'array',
        'is_simulation' => 'boolean',
        'alert_queued_at' => 'datetime',
    ];
}
