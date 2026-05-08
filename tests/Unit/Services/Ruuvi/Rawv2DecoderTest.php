<?php

namespace Tests\Unit\Services\Ruuvi;

use App\Services\Ruuvi\Rawv2Decoder;
use App\Services\Ruuvi\Reading;
use PHPUnit\Framework\TestCase;

/**
 * Test vectors taken verbatim from the official Ruuvi spec:
 * https://docs.ruuvi.com/communication/bluetooth-advertisements/data-format-5-rawv2
 *
 * The decoder must pass every assertion below without modification.
 * If a vector starts failing, the spec changed — update the assertions and
 * verify against the docs page before adjusting the decoder.
 */
class Rawv2DecoderTest extends TestCase
{
    private Rawv2Decoder $decoder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->decoder = new Rawv2Decoder();
    }

    /** Spec Test Case 1: valid data */
    public function test_valid_vector(): void
    {
        $hex = '0512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F';
        $r = $this->decoder->decode($hex);

        $this->assertEqualsWithDelta(24.3, $r->temperature, 0.001);
        $this->assertEqualsWithDelta(53.49, $r->humidity, 0.001);
        $this->assertEquals(100044, $r->pressure);
        $this->assertEquals(4, $r->accelerationX);          // 0.004 G
        $this->assertEquals(-4, $r->accelerationY);         // -0.004 G
        $this->assertEquals(1036, $r->accelerationZ);       // 1.036 G
        $this->assertEquals(2977, $r->batteryMv);           // 2.977 V
        $this->assertEquals(4, $r->txPowerDbm);
        $this->assertEquals(66, $r->movementCounter);
        $this->assertEquals(205, $r->measurementSequence);
    }

    /** Spec Test Case 2: maximum values */
    public function test_max_values_vector(): void
    {
        $hex = '057FFFFFFEFFFE7FFF7FFF7FFFFFDEFEFFFECBB8334C884F';
        $r = $this->decoder->decode($hex);

        $this->assertEqualsWithDelta(163.835, $r->temperature, 0.001);
        $this->assertEqualsWithDelta(163.835, $r->humidity, 0.001);
        $this->assertEquals(115534, $r->pressure);
        $this->assertEquals(32767, $r->accelerationX);
        $this->assertEquals(32767, $r->accelerationY);
        $this->assertEquals(32767, $r->accelerationZ);
        $this->assertEquals(3646, $r->batteryMv);
        $this->assertEquals(20, $r->txPowerDbm);
        $this->assertEquals(254, $r->movementCounter);
        $this->assertEquals(65534, $r->measurementSequence);
    }

    /** Spec Test Case 3: minimum values */
    public function test_min_values_vector(): void
    {
        $hex = '058001000000008001800180010000000000CBB8334C884F';
        $r = $this->decoder->decode($hex);

        $this->assertEqualsWithDelta(-163.835, $r->temperature, 0.001);
        $this->assertEqualsWithDelta(0.0, $r->humidity, 0.001);
        $this->assertEquals(50000, $r->pressure);
        $this->assertEquals(-32767, $r->accelerationX);
        $this->assertEquals(-32767, $r->accelerationY);
        $this->assertEquals(-32767, $r->accelerationZ);
        $this->assertEquals(1600, $r->batteryMv);
        $this->assertEquals(-40, $r->txPowerDbm);
        $this->assertEquals(0, $r->movementCounter);
        $this->assertEquals(0, $r->measurementSequence);
    }

    /**
     * Spec Test Case 4: every field set to its "not available" sentinel.
     * The decoder must return null for each, never a phantom value like
     * "163.84 °C" or "3647 mV".
     */
    public function test_invalid_values_vector(): void
    {
        $hex = '058000FFFFFFFF800080008000FFFFFFFFFFFFFFFFFFFFFF';
        $r = $this->decoder->decode($hex);

        $this->assertNull($r->temperature);
        $this->assertNull($r->humidity);
        $this->assertNull($r->pressure);
        $this->assertNull($r->accelerationX);
        $this->assertNull($r->accelerationY);
        $this->assertNull($r->accelerationZ);
        $this->assertNull($r->batteryMv);
        $this->assertNull($r->txPowerDbm);
        $this->assertNull($r->movementCounter);
        $this->assertNull($r->measurementSequence);
    }

    public function test_returns_reading_instance(): void
    {
        $hex = '0512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F';
        $this->assertInstanceOf(Reading::class, $this->decoder->decode($hex));
    }

    public function test_lowercase_hex_input_is_accepted(): void
    {
        $hex = strtolower('0512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F');
        $r = $this->decoder->decode($hex);
        $this->assertEqualsWithDelta(24.3, $r->temperature, 0.001);
    }

    public function test_rejects_non_hex_input(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->decoder->decode('zzzz');
    }

    public function test_rejects_short_payload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expected at least 24 bytes');
        // 10 bytes only
        $this->decoder->decode('05000000000000000000');
    }

    public function test_rejects_unknown_format(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('expected 0x05, got 0x03');
        // 24 bytes of zeros, but with format byte 0x03 (deprecated RAWv1)
        $this->decoder->decode('03' . str_repeat('00', 23));
    }

    public function test_handles_payload_longer_than_24_bytes(): void
    {
        // Some gateways append RSSI or other framing. The decoder should
        // happily ignore trailing bytes.
        $hex = '0512FC5394C37C0004FFFC040CAC364200CDCBB8334C884F' . 'DEADBEEF';
        $r = $this->decoder->decode($hex);
        $this->assertEqualsWithDelta(24.3, $r->temperature, 0.001);
    }
}
