<?php

class PricingEngine {
    /**
     * Calculate current price based on time decay and inventory gating.
     * Pure function: isolated and testable without database dependencies.
     */
    public static function calculateCurrentPrice(
        $base_price,
        $price_floor,
        $rush_hour_end_time,
        $decay_start_offset_minutes,
        $decay_rate_percent,
        $decay_interval_minutes,
        $min_stock_to_trigger_decay,
        $online_pool_qty,
        $current_timestamp_str = 'now'
    ) {
        // 1. Inventory gating
        if ($online_pool_qty < $min_stock_to_trigger_decay) {
            return (float)$base_price;
        }

        $now = new DateTime($current_timestamp_str);
        
        // Parse rush hour end time (assume today for calculation context)
        $rush_end = new DateTime($now->format('Y-m-d') . ' ' . $rush_hour_end_time);
        
        // 2. Decay start time
        $decay_start = clone $rush_end;
        $decay_start->modify("+$decay_start_offset_minutes minutes");

        if ($now < $decay_start) {
            return (float)$base_price; // Not yet decaying
        }

        // Calculate elapsed minutes since decay started
        $interval = $now->diff($decay_start);
        $elapsed_minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;

        if ($elapsed_minutes <= 0) {
            return (float)$base_price;
        }

        // 3. Compute active decay periods
        $periods = floor($elapsed_minutes / $decay_interval_minutes);
        
        if ($periods <= 0) {
            return (float)$base_price;
        }

        // 4. Apply Linear decay off base price
        $discount_fraction = ($decay_rate_percent / 100) * $periods;
        $discount_amount = $base_price * $discount_fraction;
        $calculated_price = $base_price - $discount_amount;

        // 5. Respect price floor
        return max((float)$price_floor, (float)$calculated_price);
    }
}
