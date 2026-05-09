<?php

use App\Services\Ruuvi\Rawv2Decoder;
use App\Services\Ruuvi\Reading;

/*
 * Test vectors taken verbatim from the official Ruuvi spec:
 * https://docs.ruuvi.com/communication/bluetooth-advertisements/data-format-5-rawv2
 *
 * The decoder must pass every assertion below without modification.
 * If a vector starts failing, the spec changed — update the assertions and
 * verify against the docs page before adjusting the decoder.
 */

beforeEach(function () {
    $this->decoder = new Rawv2Decoder;
});

it('decodes spec test case 1: valid data', function () {
    $hex = '0512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F';
    $r = $this->decoder->decode($hex);

    expect($r->temperature)->toEqualWithDelta(24.3, 0.001);
    expect($r->humidity)->toEqualWithDelta(53.49, 0.001);
    expect($r->pressure)->toBe(100044);
    expect($r->accelerationX)->toBe(4);            // 0.004 G
    expect($r->accelerationY)->toBe(-4);           // -0.004 G
    expect($r->accelerationZ)->toBe(1036);         // 1.036 G
    expect($r->batteryMv)->toBe(2977);             // 2.977 V
    expect($r->txPowerDbm)->toBe(4);
    expect($r->movementCounter)->toBe(66);
    expect($r->measurementSequence)->toBe(205);
});

it('decodes spec test case 2: maximum values', function () {
    $hex = '057FFFFFFEFFFE7FFF7FFF7FFFFFDEFEFFFECBB8334C884F';
    $r = $this->decoder->decode($hex);

    expect($r->temperature)->toEqualWithDelta(163.835, 0.001);
    expect($r->humidity)->toEqualWithDelta(163.835, 0.001);
    expect($r->pressure)->toBe(115534);
    expect($r->accelerationX)->toBe(32767);
    expect($r->accelerationY)->toBe(32767);
    expect($r->accelerationZ)->toBe(32767);
    expect($r->batteryMv)->toBe(3646);
    expect($r->txPowerDbm)->toBe(20);
    expect($r->movementCounter)->toBe(254);
    expect($r->measurementSequence)->toBe(65534);
});

it('decodes spec test case 3: minimum values', function () {
    $hex = '058001000000008001800180010000000000CBB8334C884F';
    $r = $this->decoder->decode($hex);

    expect($r->temperature)->toEqualWithDelta(-163.835, 0.001);
    expect($r->humidity)->toEqualWithDelta(0.0, 0.001);
    expect($r->pressure)->toBe(50000);
    expect($r->accelerationX)->toBe(-32767);
    expect($r->accelerationY)->toBe(-32767);
    expect($r->accelerationZ)->toBe(-32767);
    expect($r->batteryMv)->toBe(1600);
    expect($r->txPowerDbm)->toBe(-40);
    expect($r->movementCounter)->toBe(0);
    expect($r->measurementSequence)->toBe(0);
});

// Spec test case 4: every field set to its "not available" sentinel.
// The decoder must return null for each, never a phantom value like
// "163.84 °C" or "3647 mV".
it('decodes spec test case 4: invalid values become null', function () {
    $hex = '058000FFFFFFFF800080008000FFFFFFFFFFFFFFFFFFFFFF';
    $r = $this->decoder->decode($hex);

    expect($r->temperature)->toBeNull();
    expect($r->humidity)->toBeNull();
    expect($r->pressure)->toBeNull();
    expect($r->accelerationX)->toBeNull();
    expect($r->accelerationY)->toBeNull();
    expect($r->accelerationZ)->toBeNull();
    expect($r->batteryMv)->toBeNull();
    expect($r->txPowerDbm)->toBeNull();
    expect($r->movementCounter)->toBeNull();
    expect($r->measurementSequence)->toBeNull();
});

it('returns a Reading instance', function () {
    $hex = '0512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F';
    expect($this->decoder->decode($hex))->toBeInstanceOf(Reading::class);
});

it('accepts lowercase hex input', function () {
    $hex = strtolower('0512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F');
    $r = $this->decoder->decode($hex);
    expect($r->temperature)->toEqualWithDelta(24.3, 0.001);
});

it('rejects non-hex input', function () {
    $this->decoder->decode('zzzz');
})->throws(InvalidArgumentException::class);

it('rejects short payload', function () {
    // 10 bytes only
    $this->decoder->decode('05000000000000000000');
})->throws(InvalidArgumentException::class, 'expected at least 24 bytes');

it('rejects unknown format byte', function () {
    // 24 bytes of zeros, but with format byte 0x03 (deprecated RAWv1)
    $this->decoder->decode('03'.str_repeat('00', 23));
})->throws(InvalidArgumentException::class, 'expected 0x05, got 0x03');

it('handles payload longer than 24 bytes', function () {
    // Some gateways append RSSI or other framing. The decoder should
    // happily ignore trailing bytes.
    $hex = '0512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F'.'DEADBEEF';
    $r = $this->decoder->decode($hex);
    expect($r->temperature)->toEqualWithDelta(24.3, 0.001);
});
