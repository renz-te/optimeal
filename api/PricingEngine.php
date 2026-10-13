<?php

class PricingEngine
{
    const TIMEZONE = 'Asia/Manila';

    /**
     * Get the active discount percentage based on settings and current time in Asia/Manila.
     *
     * @param array $settings Global settings row containing closing_time, tiers, and emergency flag
     * @param string $current_timestamp_str Optional timestamp string for testing (default: 'now')
     * @return float Active discount percent (0.00, tier1, tier2, or 50.00)
     */
    public static function getActiveDiscountPercent(array $settings, $current_timestamp_str = 'now'): float
    {
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
     * Resolves the effective closing time (HH:MM).
     * Uses override_closing_time if override_closing_date matches today in Asia/Manila.
     */
    public static function getEffectiveClosingTime(array $settings): string
    {
        $tz = new DateTimeZone(self::TIMEZONE);
        $today = (new DateTime('now', $tz))->format('Y-m-d');

        if (
            !empty($settings['override_closing_date']) &&
            $settings['override_closing_date'] === $today &&
            !empty($settings['override_closing_time'])
        ) {
            return substr($settings['override_closing_time'], 0, 5);
        }

        return substr($settings['closing_time'] ?? '20:00', 0, 5);
    }

    /**
     * Returns true if current Manila time is equal to or past the effective closing time.
     */
    public static function isPastClosing(array $settings, string $current_timestamp_str = 'now'): bool
    {
        $tz = new DateTimeZone(self::TIMEZONE);
        $now = new DateTime($current_timestamp_str, $tz);
        $effectiveClosing = self::getEffectiveClosingTime($settings);

        $closingDt = DateTime::createFromFormat('Y-m-d H:i', $now->format('Y-m-d') . ' ' . $effectiveClosing, $tz);
        if (!$closingDt) {
            return false;
        }

        return $now >= $closingDt;
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

        // Stepped, non-compounding discount
        $discountedPrice = $discountPercent > 0
            ? round($basePrice * (1 - ($discountPercent / 100)), 2)
            : $basePrice;

        // Strict price floor enforcement bounded by base price
        return min((float)$basePrice, max((float)$priceFloor, (float)$discountedPrice));
    }
}
