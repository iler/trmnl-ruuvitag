<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensors', function (Blueprint $table) {
            $table->id();
            $table->string('mac', 17)->unique();
            $table->string('display_name');
            $table->boolean('enabled')->default(true);
            $table->float('temp_min')->nullable();
            $table->float('temp_max')->nullable();
            $table->float('humidity_min')->nullable();
            $table->float('humidity_max')->nullable();
            $table->integer('battery_low_mv')->default(2500);
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('sensor_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sensor_id')->constrained()->cascadeOnDelete();
            $table->float('temperature');
            $table->float('humidity');
            $table->integer('pressure');
            $table->integer('battery_mv');
            $table->integer('tx_power_dbm')->nullable();
            $table->integer('rssi')->nullable();
            $table->integer('measurement_sequence')->nullable();
            $table->timestamp('measured_at');
            $table->timestamps();
            $table->index(['sensor_id', 'measured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_readings');
        Schema::dropIfExists('sensors');
    }
};
