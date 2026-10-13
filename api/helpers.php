<?php

date_default_timezone_set('Asia/Manila');

/**
 * OptiMeal Phase B4.1 General Backend Helpers
 */

if (!function_exists('getEffectiveClosingTime')) {
    /**
     * Determine the effective cafeteria closing time.
     *
     * @param PDO $pdo
     * @return string Effective closing time in HH:MM or HH:MM:SS format
     */
    function getEffectiveClosingTime($pdo): string
    {
        $stmt = $pdo->prepare("
            SELECT closing_time, override_closing_time, override_expires_at, override_closing_date 
            FROM settings 
            WHERE id = 1
        ");
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$settings) {
            return '20:00:00';
        }

        $tz = new DateTimeZone('Asia/Manila');
        $now = new DateTime('now', $tz);
        $currentDate = $now->format('Y-m-d');

        // Check override expiration (supporting override_expires_at and backwards-compatible override_closing_date)
        $expiresAt = $settings['override_expires_at'] ?? $settings['override_closing_date'] ?? null;

        if (
            !empty($settings['override_closing_time']) &&
            !empty($expiresAt) &&
            $expiresAt >= $currentDate
        ) {
            return $settings['override_closing_time'];
        }

        return $settings['closing_time'] ?? '20:00:00';
    }
}

if (!function_exists('jsonResponse')) {
    function jsonResponse($data, $status = 200)
    {
        http_response_code($status);
        echo json_encode($data);
        exit;
    }
}
