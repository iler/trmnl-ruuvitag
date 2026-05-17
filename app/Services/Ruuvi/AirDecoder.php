<?php

namespace App\Services\Ruuvi;

/**
 * Decodes Ruuvi Air (Data Format E1) measurement packets.
 *
 * Spec: https://docs.ruuvi.com/communication/bluetooth-advertisements/data-format-e1
 *
 * E1 is a 40-byte BT5 advertisement payload that shares the first three
 * fields with Rawv2 (temperature, humidity, pressure) and then carries
 * air-quality data: PM 1.0/2.5/4.0/10.0, CO₂, VOC index, NOX index,
 * luminosity. The TRMNL display only renders temp/humidity/pressure, so
 * this decoder skips the air-quality fields for now — they are still
 * present in the payload if a future iteration wants to surface them.
 *
 *   Offset 0       : data format (must be 0xE1)
 *   Offset 1-2     : temperature, int16 BE, in 0.005 °C/bit
 *   Offset 3-4     : humidity,    uint16 BE, in 0.0025 %/bit
 *   Offset 5-6     : pressure,    uint16 BE, in 1 Pa/bit, +50000 Pa offset
 *   Offset 7-24    : PM, CO₂, VOC, NOX, luminosity (decoded by future work)
 *   Offset 25-27   : measurement sequence number, uint24 BE
 *   Offset 28      : flags
 *   Offset 29-33   : reserved
 *   Offset 34-39   : 48-bit MAC address
 *
 * Battery, TX power, movement, and acceleration are not broadcast in E1
 * — those fields on the returned Reading are always null.
 *
 * Production-firmware caveats (from Ruuvi staff, https://f.ruuvi.com/t/ruuvi-air-ble-protocol/7777):
 *   - Luminosity sensor is not fitted on production Ruuvi Air hardware; the
 *     uint24 at offset 19-21 is always 0xFFFFFF. Treat as "field not supported"
 *     when adding the decoder, not as a transient sensor failure.
 *   - The 9th bits of VOC and NOX live in the LSB end of the flags byte at
 *     offset 28, not the MSB end the spec page implies (its `0bVXXX XXXV`
 *     diagram is ambiguous). Verify against the forum post when implementing.
 */
class AirDecoder
{
    private const FORMAT_E1 = 0xE1;

    private const PAYLOAD_LENGTH = 40;

    private const INVALID_INT16 = -32768;

    private const INVALID_UINT16 = 0xFFFF;

    private const INVALID_UINT24 = 0xFFFFFF;

    public function decode(string $hex): Reading
    {
        $bytes = @hex2bin($hex);
        if ($bytes === false || strlen($bytes) < self::PAYLOAD_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid E1 payload: expected at least %d bytes, got %d',
                self::PAYLOAD_LENGTH,
                $bytes === false ? 0 : strlen($bytes),
            ));
        }

        $format = ord($bytes[0]);
        if ($format !== self::FORMAT_E1) {
            throw new \InvalidArgumentException(sprintf(
                'Unsupported data format: expected 0xE1, got 0x%02X',
                $format,
            ));
        }

        $tempRaw = $this->int16BE($bytes, 1);
        $humRaw = $this->uint16BE($bytes, 3);
        $pressRaw = $this->uint16BE($bytes, 5);
        $sequence = $this->uint24BE($bytes, 25);

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

            accelerationX: null,
            accelerationY: null,
            accelerationZ: null,
            batteryMv: null,
            txPowerDbm: null,
            movementCounter: null,

            measurementSequence: $sequence === self::INVALID_UINT24 ? null : $sequence,
        );
    }

    private function int16BE(string $bytes, int $offset): int
    {
        $u = $this->uint16BE($bytes, $offset);

        return $u >= 0x8000 ? $u - 0x10000 : $u;
    }

    private function uint16BE(string $bytes, int $offset): int
    {
        $unpacked = unpack('n', substr($bytes, $offset, 2));
        assert($unpacked !== false);

        return $unpacked[1];
    }

    private function uint24BE(string $bytes, int $offset): int
    {
        return (ord($bytes[$offset]) << 16)
            | (ord($bytes[$offset + 1]) << 8)
            | ord($bytes[$offset + 2]);
    }
}
