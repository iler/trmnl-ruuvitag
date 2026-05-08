<?php

namespace App\Services\Ruuvi;

/**
 * Decodes RuuviTag Rawv2 (Data Format 5) measurement packets.
 *
 * Spec: https://docs.ruuvi.com/communication/bluetooth-advertisements/data-format-5-rawv2
 *
 * The 24-byte payload layout:
 *   Offset 0       : data format (must be 0x05)
 *   Offset 1-2     : temperature, int16 BE, in 0.005 °C/bit
 *   Offset 3-4     : humidity,    uint16 BE, in 0.0025 %/bit
 *   Offset 5-6     : pressure,    uint16 BE, in 1 Pa, +50000 Pa offset
 *   Offset 7-8     : acceleration X, int16 BE, in mG
 *   Offset 9-10    : acceleration Y, int16 BE, in mG
 *   Offset 11-12   : acceleration Z, int16 BE, in mG
 *   Offset 13-14   : power info, 11+5 bits packed (battery mV above 1.6V, TX dBm above -40)
 *   Offset 15      : movement counter, uint8
 *   Offset 16-17   : measurement sequence number, uint16 BE
 *   Offset 18-23   : 48-bit MAC address
 *
 * Invalid sentinels per the spec:
 *   - signed:   smallest representable value (e.g. 0x8000 for int16)
 *   - unsigned: largest representable value  (e.g. 0xFFFF for uint16)
 *   - battery:  raw 11-bit value 2047 (= 3647 mV)
 *   - tx power: raw 5-bit value 31  (= +20 dBm)
 *   - movement: 0xFF
 */
class Rawv2Decoder
{
    private const FORMAT_RAWV2 = 0x05;
    private const PAYLOAD_MIN_LENGTH = 24;

    private const INVALID_INT16 = -32768;       // 0x8000
    private const INVALID_UINT16 = 0xFFFF;
    private const INVALID_BATTERY_RAW = 2047;   // 11-bit max
    private const INVALID_TX_POWER_RAW = 31;    // 5-bit max
    private const INVALID_UINT8 = 0xFF;

    public function decode(string $hex): Reading
    {
        $bytes = @hex2bin($hex);
        if ($bytes === false || strlen($bytes) < self::PAYLOAD_MIN_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid Rawv2 payload: expected at least %d bytes, got %d',
                self::PAYLOAD_MIN_LENGTH,
                $bytes === false ? 0 : strlen($bytes),
            ));
        }

        $format = ord($bytes[0]);
        if ($format !== self::FORMAT_RAWV2) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported data format: expected 0x05, got 0x%02X',
                $format,
            ));
        }

        $tempRaw   = $this->int16BE($bytes, 1);
        $humRaw    = $this->uint16BE($bytes, 3);
        $pressRaw  = $this->uint16BE($bytes, 5);
        $accelX    = $this->int16BE($bytes, 7);
        $accelY    = $this->int16BE($bytes, 9);
        $accelZ    = $this->int16BE($bytes, 11);
        $powerInfo = $this->uint16BE($bytes, 13);
        $movement  = ord($bytes[15]);
        $sequence  = $this->uint16BE($bytes, 16);

        // Power info is packed: 11 bits battery + 5 bits TX power
        $batteryRaw = ($powerInfo >> 5) & 0x7FF;
        $txPowerRaw = $powerInfo & 0x1F;

        return new Reading(
            temperature: $tempRaw === self::INVALID_INT16
                ? null
                : round($tempRaw * 0.005, 3),

            humidity: $humRaw === self::INVALID_UINT16
                ? null
                : round($humRaw * 0.0025, 4),

            pressure: $pressRaw === self::INVALID_UINT16
                ? null
                : $pressRaw + 50000,

            accelerationX: $accelX === self::INVALID_INT16 ? null : $accelX,
            accelerationY: $accelY === self::INVALID_INT16 ? null : $accelY,
            accelerationZ: $accelZ === self::INVALID_INT16 ? null : $accelZ,

            batteryMv: $batteryRaw === self::INVALID_BATTERY_RAW
                ? null
                : 1600 + $batteryRaw,

            txPowerDbm: $txPowerRaw === self::INVALID_TX_POWER_RAW
                ? null
                : -40 + ($txPowerRaw * 2),

            movementCounter: $movement === self::INVALID_UINT8 ? null : $movement,

            measurementSequence: $sequence === self::INVALID_UINT16 ? null : $sequence,
        );
    }

    private function int16BE(string $bytes, int $offset): int
    {
        $u = unpack('n', substr($bytes, $offset, 2))[1];
        return $u >= 0x8000 ? $u - 0x10000 : $u;
    }

    private function uint16BE(string $bytes, int $offset): int
    {
        return unpack('n', substr($bytes, $offset, 2))[1];
    }
}
