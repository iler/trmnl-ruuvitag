<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $mac
 * @property ?float $temperature
 * @property ?float $humidity
 * @property ?int $pressure
 * @property ?int $battery_mv
 * @property ?int $tx_power_dbm
 * @property ?int $rssi
 * @property ?int $measurement_sequence
 * @property Carbon $measured_at
 */
class SensorReading extends Model
{
    protected $fillable = [
        'mac', 'temperature', 'humidity', 'pressure',
        'battery_mv', 'tx_power_dbm', 'rssi', 'measurement_sequence', 'measured_at',
    ];

    protected $casts = [
        'temperature' => 'float',
        'humidity' => 'float',
        'measured_at' => 'datetime',
    ];
}
