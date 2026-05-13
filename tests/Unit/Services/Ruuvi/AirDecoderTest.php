<?php

use App\Services\Ruuvi\AirDecoder;
use App\Services\Ruuvi\Reading;

beforeEach(function () {
    $this->decoder = new AirDecoder;
});

// Captured live from a "Makuuhuone Air" sensor in the developer's account;
// only the trailing AD ('030398FC') and the BLE wrapper bytes are stripped.
//   Format byte 0xE1
//   Temp raw 0x104E   = 4174  → 20.87 °C
//   Hum  raw 0x4B70   = 19312 → 48.28 %
//   Pres raw 0xBD85   = 48517 → 98517 Pa
//   Sequence 0x0A1F9B = 663451
//   MAC  CD:0D:61:D2:ED:8C
const HEX_E1_VALID = 'E1104E4B70BD8500040008000C000E02C63700FFFFFFFFFFFF0A1F9BFCFFFFFFFFFFCD0D61D2ED8C';

it('decodes a captured Air payload', function () {
    $r = $this->decoder->decode(HEX_E1_VALID);

    expect($r->temperature)->toEqualWithDelta(20.87, 0.001);
    expect($r->humidity)->toEqualWithDelta(48.28, 0.001);
    expect($r->pressure)->toBe(98517);
    expect($r->measurementSequence)->toBe(663451);
});

it('returns a Reading instance with non-Air fields set to null', function () {
    $r = $this->decoder->decode(HEX_E1_VALID);

    expect($r)->toBeInstanceOf(Reading::class);
    expect($r->accelerationX)->toBeNull();
    expect($r->accelerationY)->toBeNull();
    expect($r->accelerationZ)->toBeNull();
    expect($r->batteryMv)->toBeNull();
    expect($r->txPowerDbm)->toBeNull();
    expect($r->movementCounter)->toBeNull();
});

it('returns null for fields the tag marks as not-available', function () {
    // All measurement bytes set to their invalid sentinels, format byte E1, mac 0..0
    //   temp:  8000        humidity: FFFF        pressure: FFFF
    //   PM/CO2/VOC/NOX/lum: all FFFF…  reserved: 0..0
    //   sequence: FFFFFF   flags: 00   reserved + mac: 0..0
    $hex = 'E1'.'8000'.'FFFF'.'FFFF'.str_repeat('FF', 18).'FFFFFF'.'00'.str_repeat('00', 11);

    $r = $this->decoder->decode($hex);

    expect($r->temperature)->toBeNull();
    expect($r->humidity)->toBeNull();
    expect($r->pressure)->toBeNull();
    expect($r->measurementSequence)->toBeNull();
});

it('rejects payloads shorter than 40 bytes', function () {
    $this->decoder->decode('E1'.str_repeat('00', 30));
})->throws(InvalidArgumentException::class, 'expected at least 40 bytes');

it('rejects unknown format byte', function () {
    $this->decoder->decode('05'.str_repeat('00', 39));
})->throws(InvalidArgumentException::class, 'expected 0xE1, got 0x05');
