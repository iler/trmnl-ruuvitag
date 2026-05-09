<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $sensor_id
 * @property float $temperature
 * @property float $humidity
 * @property int $pressure
 * @property int $battery_mv
 * @property ?int $tx_power_dbm
 * @property ?int $rssi
 * @property ?int $measurement_sequence
 * @property Carbon $measured_at
 * @property-read Sensor $sensor
 */
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

    /**
     * @return BelongsTo<Sensor, $this>
     */
    public function sensor(): BelongsTo
    {
        return $this->belongsTo(Sensor::class);
    }
}
