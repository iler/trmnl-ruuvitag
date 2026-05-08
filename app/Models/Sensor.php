<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    public function readings(): HasMany
    {
        return $this->hasMany(SensorReading::class);
    }

    public function latestReading(): HasOne
    {
        return $this->hasOne(SensorReading::class)->latestOfMany('measured_at');
    }
}
