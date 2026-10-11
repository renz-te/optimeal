<?php

class PricingEngine {
    const TIMEZONE = 'Asia/Manila';

    /**
     * Get the active discount percentage based on settings and current time in Asia/Manila.
     *
     * @param array $settings Global settings row containing closing_time, tiers, and emergency flag
     * @param string $current_timestamp_str Optional timestamp string for testing (default: 'now')
     * @return float Active discount percent (0.00, tier1, tier2, or 50.00)
     */
    public static function getActiveDiscountPercent(array $settings, $current_timestamp_str = 'now'): float {
        $tz = new DateTimeZone(self::TIMEZONE);
        $now = new DateTime($current_timestamp_str, $tz);

        // Emergency clearance takes absolute priority (flat 50%)
        if (!empty($settings['emergency_clearance_active'])) {
            return 50.00;
        }

        $closingTimeStr = !empty($settings['closing_time']) ? trim($settings['closing_time']) : '20:00';
        $closingTime = new DateTime($now->format('Y-m-d') . ' ' . $closingTimeStr, $tz);

        // If currently at or after closing time, maximum tier is active
        $tier1Minutes = isset($settings['tier1_minutes_before_close']) ? (int)$settings['tier1_minutes_before_close'] : 120;
        $tier1Discount = isset($settings['tier1_discount_percent']) ? (float)$settings['tier1_discount_percent'] : 25.00;
        $tier2Minutes = isset($settings['tier2_minutes_before_close']) ? (int)$settings['tier2_minutes_before_close'] : 60;
        $tier2Discount = isset($settings['tier2_discount_percent']) ? (float)$settings['tier2_discount_percent'] : 40.00;

        $t1Start = (clone $closingTime)->modify("-{$tier1Minutes} minutes");
        $t2Start = (clone $closingTime)->modify("-{$tier2Minutes} minutes");

        // Stepped, non-compounding tier evaluation
        if ($now >= $t2Start) {
            return $tier2Discount;
        } elseif ($now >= $t1Start) {
            return $tier1Discount;
        }

        return 0.00;
    }

    /**
     * Check if current time in Asia/Manila is past the cafeteria closing time.
     *
     * @param array $settings
     * @param string $current_timestamp_str
     * @return bool
     */
    public static function isPastClosing(array $settings, $current_timestamp_str = 'now'): bool {
        $tz = new DateTimeZone(self::TIMEZONE);
        $now = new DateTime($current_timestamp_str, $tz);

        $closingTimeStr = !empty($settings['closing_time']) ? trim($settings['closing_time']) : '20:00';
        $closingTime = new DateTime($now->format('Y-m-d') . ' ' . $closingTimeStr, $tz);

        return $now >= $closingTime;
    }

    /**
     * Calculate current price based on surplus decay tiers or emergency clearance.
     * Stepped, non-compounding, clamped at price_floor.
     *
     * @param float|int $base_price
     * @param float|int $price_floor
     * @param array $settings Global settings row
     * @param string $current_timestamp_str
     * @return float Clamped price rounded to 2 decimal places
     */
    public static function calculateCurrentPrice(
        $base_price,
        $price_floor,
        array $settings,
        $current_timestamp_str = 'now'
    ): float {
        $basePrice = (float)$base_price;
        $priceFloor = (float)$price_floor;

        $discountPercent = self::getActiveDiscountPercent($settings, $current_timestamp_str);

        if ($discountPercent <= 0) {
            return round($basePrice, 2);
        }

        // Stepped, non-compounding discount
        $calculatedPrice = round($basePrice * (1 - ($discountPercent / 100)), 2);

        // Strict price floor enforcement
        return max($priceFloor, $calculatedPrice);
    }
}
