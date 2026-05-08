<?php

namespace App\Services\Ruuvi;

/**
 * Decoded RuuviTag Rawv2 measurement.
 *
 * Any field may be null if the sensor reported the spec's "not available"
 * sentinel for it (see Rawv2Decoder). In practice you'll most often see
 * acceleration nulls when the accelerometer is asleep, and movement
 * counter nulls on early firmware. Temperature/humidity/pressure are
 * almost always present on a healthy tag.
 */
final readonly class Reading
{
    public function __construct(
        public ?float $temperature,        // °C, range -163.835 to +163.835
        public ?float $humidity,           // %,  range 0 to ~163.835 (clipped at 100 in practice)
        public ?int $pressure,             // Pa, range 50000 to 115534
        public ?int $accelerationX,        // mG
        public ?int $accelerationY,        // mG
        public ?int $accelerationZ,        // mG
        public ?int $batteryMv,            // mV, range 1600 to 3646
        public ?int $txPowerDbm,           // dBm, range -40 to +20
        public ?int $movementCounter,      // 0..254 (rolls over)
        public ?int $measurementSequence,  // 0..65534, used for dedupe
    ) {}
}
