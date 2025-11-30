<?php

namespace Tests\Unit;

use App\Helpers\ExpiryNormalizer;
use Tests\TestCase;

/**
 * Unit tests for ExpiryNormalizer helper class
 * 
 * These tests verify:
 * 1. Correct conversion of seconds to days for start_on_first_use strategy
 * 2. Regression prevention for the 3652 days bug (100 hours → 5 days, not 3652)
 * 3. Fixed date handling without conversion
 * 4. Never strategy far-future date handling
 */
class ExpiryNormalizerTest extends TestCase
{
    /**
     * Test: 100 hours submitted as seconds (360000) → 5 days
     * This is the primary regression test for the bug where 100 hours became 3652 days
     * 
     * Calculation: 360000 seconds / 86400 seconds per day = 4.17 days → ceil → 5 days
     */
    public function test_100_hours_in_seconds_becomes_5_days(): void
    {
        $normalizer = new ExpiryNormalizer();
        
        // 100 hours = 100 * 3600 = 360,000 seconds
        $result = $normalizer->normalize('start_on_first_use', [
            'usage_duration' => 360000,
        ]);
        
        $this->assertEquals('start_on_first_use', $result['expire_strategy']);
        $this->assertEquals(360000, $result['original_usage_duration_seconds']);
        $this->assertEquals(5, $result['normalized_usage_days']); // ceil(360000/86400) = ceil(4.17) = 5
        $this->assertNotEquals(3652, $result['normalized_usage_days']); // Regression check
    }

    /**
     * Test: 10 hours submitted as seconds (36000) → 1 day
     * 
     * Calculation: 36000 seconds / 86400 seconds per day = 0.42 days → ceil → 1 day
     */
    public function test_10_hours_in_seconds_becomes_1_day(): void
    {
        $normalizer = new ExpiryNormalizer();
        
        // 10 hours = 10 * 3600 = 36,000 seconds
        $result = $normalizer->normalize('start_on_first_use', [
            'usage_duration' => 36000,
        ]);
        
        $this->assertEquals(36000, $result['original_usage_duration_seconds']);
        $this->assertEquals(1, $result['normalized_usage_days']); // ceil(36000/86400) = ceil(0.42) = 1
    }

    /**
     * Test: 1 hour submitted as seconds (3600) → 1 day (minimum)
     */
    public function test_1_hour_in_seconds_becomes_1_day_minimum(): void
    {
        $normalizer = new ExpiryNormalizer();
        
        $result = $normalizer->normalize('start_on_first_use', [
            'usage_duration' => 3600,
        ]);
        
        $this->assertEquals(3600, $result['original_usage_duration_seconds']);
        $this->assertEquals(1, $result['normalized_usage_days']); // Minimum 1 day
    }

    /**
     * Test: 25 hours (90000 seconds) → 2 days
     * 
     * Calculation: 90000 / 86400 = 1.04 days → ceil → 2 days
     */
    public function test_25_hours_in_seconds_becomes_2_days(): void
    {
        $normalizer = new ExpiryNormalizer();
        
        // 25 hours = 25 * 3600 = 90,000 seconds
        $result = $normalizer->normalize('start_on_first_use', [
            'usage_duration' => 90000,
        ]);
        
        $this->assertEquals(90000, $result['original_usage_duration_seconds']);
        $this->assertEquals(2, $result['normalized_usage_days']); // ceil(90000/86400) = ceil(1.04) = 2
    }

    /**
     * Test: 30 days (2592000 seconds) → 30 days
     */
    public function test_30_days_in_seconds_becomes_30_days(): void
    {
        $normalizer = new ExpiryNormalizer();
        
        // 30 days = 30 * 86400 = 2,592,000 seconds
        $result = $normalizer->normalize('start_on_first_use', [
            'usage_duration' => 2592000,
        ]);
        
        $this->assertEquals(2592000, $result['original_usage_duration_seconds']);
        $this->assertEquals(30, $result['normalized_usage_days']);
    }

    /**
     * Test: 365 days (31536000 seconds) → 365 days
     */
    public function test_365_days_in_seconds_becomes_365_days(): void
    {
        $normalizer = new ExpiryNormalizer();
        
        // 365 days = 365 * 86400 = 31,536,000 seconds
        $result = $normalizer->normalize('start_on_first_use', [
            'usage_duration' => 31536000,
        ]);
        
        $this->assertEquals(31536000, $result['original_usage_duration_seconds']);
        $this->assertEquals(365, $result['normalized_usage_days']);
    }

    /**
     * Regression Test: Ensure normalized days is never ~3652 for reasonable inputs
     * The bug was that 100 hours (360000 seconds) was becoming ~3652 days
     */
    public function test_regression_no_unrealistic_3652_days_output(): void
    {
        $normalizer = new ExpiryNormalizer();
        
        // Test various hour inputs that should never result in 3652 days
        $testCases = [
            100 * 3600,  // 100 hours
            200 * 3600,  // 200 hours
            500 * 3600,  // 500 hours
            1000 * 3600, // 1000 hours
        ];
        
        foreach ($testCases as $seconds) {
            $result = $normalizer->normalize('start_on_first_use', [
                'usage_duration' => $seconds,
            ]);
            
            // These should all be well under 3652 days
            $expectedMaxDays = (int) ceil($seconds / 86400);
            $this->assertEquals(
                $expectedMaxDays, 
                $result['normalized_usage_days'],
                "Failed for input: {$seconds} seconds. Expected: {$expectedMaxDays}, Got: {$result['normalized_usage_days']}"
            );
            
            // None of these should ever be anywhere near 3652 days
            $this->assertLessThan(365, $result['normalized_usage_days'], 
                "Unrealistic day count for {$seconds} seconds: {$result['normalized_usage_days']}");
        }
    }

    /**
     * Test: fixed_date strategy does NOT convert date to days
     */
    public function test_fixed_date_strategy_keeps_date_unchanged(): void
    {
        $normalizer = new ExpiryNormalizer();
        
        $futureDate = now()->addDays(45)->toIso8601String();
        
        $result = $normalizer->normalize('fixed_date', [
            'expire_date' => $futureDate,
        ]);
        
        $this->assertEquals('fixed_date', $result['expire_strategy']);
        $this->assertNotNull($result['fixed_date']);
        $this->assertNull($result['normalized_usage_days']); // No day conversion for fixed_date
    }

    /**
     * Test: fixed_date with unix timestamp
     */
    public function test_fixed_date_with_unix_timestamp(): void
    {
        $normalizer = new ExpiryNormalizer();
        
        $futureTimestamp = now()->addDays(60)->timestamp;
        
        $result = $normalizer->normalize('fixed_date', [
            'expire' => $futureTimestamp,
        ]);
        
        $this->assertEquals('fixed_date', $result['expire_strategy']);
        $this->assertNotNull($result['fixed_date']);
        $this->assertEquals($futureTimestamp, $result['fixed_date']->timestamp);
    }

    /**
     * Test: never strategy sets stable long-term date (~10 years)
     */
    public function test_never_strategy_sets_10_year_date(): void
    {
        $normalizer = new ExpiryNormalizer();
        
        $result = $normalizer->normalize('never', []);
        
        $this->assertEquals('never', $result['expire_strategy']);
        $this->assertNotNull($result['fixed_date']);
        
        // Should be approximately 10 years in the future
        $expectedDate = now()->addYears(10);
        $daysDiff = $expectedDate->diffInDays($result['fixed_date']);
        
        // Allow 1 day tolerance for test timing
        $this->assertLessThanOrEqual(1, $daysDiff, 'Never strategy should set date ~10 years in future');
    }

    /**
     * Test: Zero usage_duration returns minimum 1 day with warning
     */
    public function test_zero_duration_returns_minimum_with_warning(): void
    {
        $normalizer = new ExpiryNormalizer();
        
        $result = $normalizer->normalize('start_on_first_use', [
            'usage_duration' => 0,
        ]);
        
        $this->assertEquals(1, $result['normalized_usage_days']); // Minimum fallback
        $this->assertNotEmpty($result['warnings']);
    }

    /**
     * Test: Unrealistic duration (> 10 years) is capped
     */
    public function test_unrealistic_duration_is_capped(): void
    {
        $normalizer = new ExpiryNormalizer();
        
        // 20 years in seconds = 20 * 365 * 86400 = 630,720,000 seconds
        $result = $normalizer->normalize('start_on_first_use', [
            'usage_duration' => 630720000,
        ]);
        
        $this->assertEquals(ExpiryNormalizer::MAX_DURATION_DAYS, $result['normalized_usage_days']);
        $this->assertNotEmpty($result['warnings']);
    }

    /**
     * Test: secondsToDays static helper method
     */
    public function test_static_seconds_to_days_helper(): void
    {
        // 100 hours
        $this->assertEquals(5, ExpiryNormalizer::secondsToDays(360000));
        
        // 1 hour
        $this->assertEquals(1, ExpiryNormalizer::secondsToDays(3600));
        
        // 25 hours
        $this->assertEquals(2, ExpiryNormalizer::secondsToDays(90000));
        
        // 30 days
        $this->assertEquals(30, ExpiryNormalizer::secondsToDays(2592000));
        
        // 0 seconds
        $this->assertEquals(0, ExpiryNormalizer::secondsToDays(0));
        
        // Negative
        $this->assertEquals(0, ExpiryNormalizer::secondsToDays(-1000));
    }

    /**
     * Test: validateUsageDuration static method
     */
    public function test_validate_usage_duration(): void
    {
        // Valid
        $result = ExpiryNormalizer::validateUsageDuration(360000);
        $this->assertTrue($result['valid']);
        $this->assertEquals(360000, $result['converted_value']);
        
        // Null
        $result = ExpiryNormalizer::validateUsageDuration(null);
        $this->assertFalse($result['valid']);
        
        // Zero
        $result = ExpiryNormalizer::validateUsageDuration(0);
        $this->assertFalse($result['valid']);
        
        // Negative
        $result = ExpiryNormalizer::validateUsageDuration(-100);
        $this->assertFalse($result['valid']);
        
        // String number
        $result = ExpiryNormalizer::validateUsageDuration('360000');
        $this->assertTrue($result['valid']);
        $this->assertEquals(360000, $result['converted_value']);
    }

    /**
     * Test: prepareForPanel for Marzneshin panel with start_on_first_use
     */
    public function test_prepare_for_marzneshin_panel_start_on_first_use(): void
    {
        $normalizer = new ExpiryNormalizer();
        
        $result = $normalizer->normalize('start_on_first_use', [
            'usage_duration' => 360000, // 100 hours
        ]);
        
        $payload = ExpiryNormalizer::prepareForPanel('marzneshin', $result);
        
        $this->assertEquals('start_on_first_use', $payload['expire_strategy']);
        $this->assertEquals(5, $payload['usage_duration']); // Marzneshin expects DAYS
    }

    /**
     * Test: prepareForPanel for Eylandoo panel with start_on_first_use
     * Eylandoo uses fixed_date, so we translate
     */
    public function test_prepare_for_eylandoo_panel_start_on_first_use(): void
    {
        $normalizer = new ExpiryNormalizer();
        
        $result = $normalizer->normalize('start_on_first_use', [
            'usage_duration' => 360000, // 100 hours
        ]);
        
        $payload = ExpiryNormalizer::prepareForPanel('eylandoo', $result);
        
        $this->assertEquals('fixed_date', $payload['activation_type']);
        $this->assertArrayHasKey('expiry_date_str', $payload);
    }

    /**
     * Test: prepareForPanel for Marzneshin with never strategy
     */
    public function test_prepare_for_marzneshin_panel_never(): void
    {
        $normalizer = new ExpiryNormalizer();
        
        $result = $normalizer->normalize('never', []);
        
        $payload = ExpiryNormalizer::prepareForPanel('marzneshin', $result);
        
        $this->assertEquals('never', $payload['expire_strategy']);
    }

    /**
     * Test: prepareForPanel for Eylandoo with never strategy
     */
    public function test_prepare_for_eylandoo_panel_never(): void
    {
        $normalizer = new ExpiryNormalizer();
        
        $result = $normalizer->normalize('never', []);
        
        $payload = ExpiryNormalizer::prepareForPanel('eylandoo', $result);
        
        $this->assertEquals('fixed_date', $payload['activation_type']);
        $this->assertArrayHasKey('expiry_date_str', $payload);
    }

    /**
     * Test: Accepts both usage_duration and usage_duration_seconds
     */
    public function test_accepts_legacy_and_new_field_names(): void
    {
        $normalizer = new ExpiryNormalizer();
        
        // Legacy field name
        $result1 = $normalizer->normalize('start_on_first_use', [
            'usage_duration' => 360000,
        ]);
        
        // New field name
        $result2 = $normalizer->normalize('start_on_first_use', [
            'usage_duration_seconds' => 360000,
        ]);
        
        $this->assertEquals($result1['normalized_usage_days'], $result2['normalized_usage_days']);
        $this->assertEquals(5, $result1['normalized_usage_days']);
    }
}
