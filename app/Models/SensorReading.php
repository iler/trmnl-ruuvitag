<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SensorReading extends Model
{
    protected $fillable = [
        'sensor_id', 'temperature', 'humidity', 'pressure',
        'battery_mv', 'tx_power_dbm', 'rssi', 'measurement_sequence', 'measured_at',
    ];

    protected $casts = [
        'temperature' => 'float',
        'humidity' => 'float',
        'measured_at' => 'datetime',
    ];

    public function sensor(): BelongsTo
    {
        return $this->belongsTo(Sensor::class);
    }
}
