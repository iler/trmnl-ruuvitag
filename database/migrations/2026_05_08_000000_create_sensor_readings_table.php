<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_readings', function (Blueprint $table) {
            $table->id();
            $table->string('mac', 17);
            $table->float('temperature')->nullable();
            $table->float('humidity')->nullable();
            $table->integer('pressure')->nullable();
            $table->integer('battery_mv')->nullable();
            $table->integer('tx_power_dbm')->nullable();
            $table->integer('rssi')->nullable();
            $table->integer('measurement_sequence')->nullable();
            $table->timestamp('measured_at');
            $table->timestamps();
            $table->index(['mac', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_readings');
    }
};
