<?php

namespace App\Services\Ruuvi;

/**
 * Helpers for the BLE advertisement payloads the Ruuvi Cloud API returns
 * in `measurements[].data`. Those strings are the full advertisement —
 * a sequence of AD structures (length + type + data) — not the bare
 * sensor payload, so the format byte the decoder expects sits nested
 * inside a type-0xFF Manufacturer-Specific AD.
 */
class BleAdvertisement
{
    // Ruuvi Innovations Ltd, assigned by the Bluetooth SIG.
    // Stored little-endian in BLE adverts: the bytes 0x99 0x04.
    private const RUUVI_MANUFACTURER_ID_LE = "\x99\x04";

    private const AD_TYPE_MANUFACTURER_SPECIFIC = 0xFF;

    /**
     * Returns the Ruuvi sensor payload (still hex-encoded) carried inside
     * the Manufacturer-Specific AD, or null if the advertisement does not
     * contain Ruuvi data. The returned payload starts with the format byte
     * (0x05 for Rawv2, 0xE1 for Air, etc.) — the caller decides which
     * decoder applies.
     */
    public static function extractRuuviPayload(string $hex): ?string
    {
        if ($hex === '' || strlen($hex) % 2 !== 0 || ! ctype_xdigit($hex)) {
            return null;
        }
        $bytes = hex2bin($hex);
        if ($bytes === false) {
            return null;
        }

        $len = strlen($bytes);
        $i = 0;
        while ($i < $len) {
            $adLen = ord($bytes[$i]);
            if ($adLen === 0 || $i + 1 + $adLen > $len) {
                return null;
            }

            $adType = ord($bytes[$i + 1]);
            $adData = substr($bytes, $i + 2, $adLen - 1);

            if ($adType === self::AD_TYPE_MANUFACTURER_SPECIFIC
                && str_starts_with($adData, self::RUUVI_MANUFACTURER_ID_LE)) {
                return bin2hex(substr($adData, 2));
            }

            $i += 1 + $adLen;
        }

        return null;
    }
}
