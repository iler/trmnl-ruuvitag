<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $mac
 * @property string $display_name
 * @property bool $enabled
 * @property ?float $temp_min
 * @property ?float $temp_max
 * @property ?float $humidity_min
 * @property ?float $humidity_max
 * @property int $battery_low_mv
 * @property int $display_order
 * @property-read ?SensorReading $latestReading
 */
class Sensor extends Model
{
    protected $fillable = [
        'mac', 'display_name', 'enabled',
        'temp_min', 'temp_max', 'humidity_min', 'humidity_max',
        'battery_low_mv', 'display_order',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'temp_min' => 'float',
        'temp_max' => 'float',
        'humidity_min' => 'float',
        'humidity_max' => 'float',
    ];

    /**
     * @return HasMany<SensorReading, $this>
     */
    public function readings(): HasMany
    {
        return $this->hasMany(SensorReading::class);
    }

    /**
     * @return HasOne<SensorReading, $this>
     */
    public function latestReading(): HasOne
    {
        return $this->hasOne(SensorReading::class)->latestOfMany('measured_at');
    }
}
