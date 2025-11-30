<?php

namespace Tests\Feature;

use App\Helpers\DurationNormalization;
use Tests\TestCase;

/**
 * Tests for DurationNormalization helper class
 *
 * These tests verify the duration and data limit normalization
 * logic used for Marzneshin and Eylandoo panel integrations.
 */
class DurationNormalizationTest extends TestCase
{
    /**
     * Test seconds to days conversion with various inputs
     */
    public function test_normalizes_seconds_to_days_using_ceiling(): void
    {
        // 1 hour (3600 seconds) → 1 day
        $this->assertEquals(1, DurationNormalization::normalizeUsageDurationSecondsToDays(3600));

        // 24 hours (86400 seconds) → 1 day
        $this->assertEquals(1, DurationNormalization::normalizeUsageDurationSecondsToDays(86400));

        // 25 hours (90000 seconds) → 2 days (ceiling)
        $this->assertEquals(2, DurationNormalization::normalizeUsageDurationSecondsToDays(90000));

        // 30 days (2592000 seconds) → 30 days
        $this->assertEquals(30, DurationNormalization::normalizeUsageDurationSecondsToDays(2592000));

        // 1 second → 1 day (minimum)
        $this->assertEquals(1, DurationNormalization::normalizeUsageDurationSecondsToDays(1));

        // 0 seconds → 0 days
        $this->assertEquals(0, DurationNormalization::normalizeUsageDurationSecondsToDays(0));

        // Negative → 0 days
        $this->assertEquals(0, DurationNormalization::normalizeUsageDurationSecondsToDays(-100));
    }

    /**
     * Test partial days are rounded up to full days
     */
    public function test_partial_days_rounded_up_to_full_days(): void
    {
        // 12 hours (43200 seconds) → 1 day
        $this->assertEquals(1, DurationNormalization::normalizeUsageDurationSecondsToDays(43200));

        // 36 hours (129600 seconds) → 2 days
        $this->assertEquals(2, DurationNormalization::normalizeUsageDurationSecondsToDays(129600));

        // 2 days + 1 second (172801 seconds) → 3 days
        $this->assertEquals(3, DurationNormalization::normalizeUsageDurationSecondsToDays(172801));
    }

    /**
     * Test Eylandoo data limit uses MB for small values
     */
    public function test_eylandoo_data_limit_uses_mb_for_small_values(): void
    {
        // 50 MB (52428800 bytes)
        $result = DurationNormalization::prepareEylandooDataLimit(52428800);
        $this->assertEquals(50, $result['value']);
        $this->assertEquals('MB', $result['unit']);

        // 500 MB (524288000 bytes)
        $result = DurationNormalization::prepareEylandooDataLimit(524288000);
        $this->assertEquals(500, $result['value']);
        $this->assertEquals('MB', $result['unit']);

        // 100 MB (104857600 bytes)
        $result = DurationNormalization::prepareEylandooDataLimit(104857600);
        $this->assertEquals(100, $result['value']);
        $this->assertEquals('MB', $result['unit']);
    }

    /**
     * Test Eylandoo data limit uses GB for large values
     */
    public function test_eylandoo_data_limit_uses_gb_for_large_values(): void
    {
        // 1 GB (1073741824 bytes)
        $result = DurationNormalization::prepareEylandooDataLimit(1073741824);
        $this->assertEquals(1.0, $result['value']);
        $this->assertEquals('GB', $result['unit']);

        // 1.5 GB (1610612736 bytes)
        $result = DurationNormalization::prepareEylandooDataLimit(1610612736);
        $this->assertEquals(1.5, $result['value']);
        $this->assertEquals('GB', $result['unit']);

        // 10 GB (10737418240 bytes)
        $result = DurationNormalization::prepareEylandooDataLimit(10737418240);
        $this->assertEquals(10.0, $result['value']);
        $this->assertEquals('GB', $result['unit']);
    }

    /**
     * Test minimum 1 MB for any positive bytes
     */
    public function test_eylandoo_data_limit_minimum_1_mb(): void
    {
        // 1 byte → 1 MB minimum
        $result = DurationNormalization::prepareEylandooDataLimit(1);
        $this->assertEquals(1, $result['value']);
        $this->assertEquals('MB', $result['unit']);

        // 1000 bytes → 1 MB minimum (less than 1 MB)
        $result = DurationNormalization::prepareEylandooDataLimit(1000);
        $this->assertEquals(1, $result['value']);
        $this->assertEquals('MB', $result['unit']);

        // 500000 bytes → 1 MB minimum (still less than 1 MB threshold)
        $result = DurationNormalization::prepareEylandooDataLimit(500000);
        $this->assertEquals(1, $result['value']);
        $this->assertEquals('MB', $result['unit']);
    }

    /**
     * Test zero and negative bytes handling
     */
    public function test_eylandoo_data_limit_zero_and_negative(): void
    {
        // 0 bytes → 0 GB
        $result = DurationNormalization::prepareEylandooDataLimit(0);
        $this->assertEquals(0, $result['value']);
        $this->assertEquals('GB', $result['unit']);

        // Negative → 0 GB
        $result = DurationNormalization::prepareEylandooDataLimit(-1000);
        $this->assertEquals(0, $result['value']);
        $this->assertEquals('GB', $result['unit']);
    }

    /**
     * Test threshold boundary (just under 1 GB)
     */
    public function test_eylandoo_data_limit_threshold_boundary(): void
    {
        // Just under 1 GB (1073741823 bytes) → should be MB
        $result = DurationNormalization::prepareEylandooDataLimit(1073741823);
        $this->assertEquals('MB', $result['unit']);
        $this->assertEquals(1023, $result['value']); // floor(1073741823 / 1048576) = 1023

        // Exactly 1 GB (1073741824 bytes) → should be GB
        $result = DurationNormalization::prepareEylandooDataLimit(1073741824);
        $this->assertEquals('GB', $result['unit']);
        $this->assertEquals(1.0, $result['value']);
    }

    /**
     * Test GB precision (2 decimal places)
     */
    public function test_eylandoo_data_limit_gb_precision(): void
    {
        // 1.25 GB (1342177280 bytes)
        $result = DurationNormalization::prepareEylandooDataLimit(1342177280);
        $this->assertEquals(1.25, $result['value']);
        $this->assertEquals('GB', $result['unit']);

        // 2.33 GB (approximately 2502095257 bytes)
        $result = DurationNormalization::prepareEylandooDataLimit(2502095257);
        $this->assertEquals(2.33, $result['value']);
        $this->assertEquals('GB', $result['unit']);
    }
}
