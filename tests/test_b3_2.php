<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/PricingEngine.php';

require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/PricingEngine.php';

// $pdo is already initialized by api/db.php

echo "=== SUB-PHASE B3.2 CLOSING TIME OVERRIDE VERIFICATION ===\n";

$backup = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

try {
    $tz = new DateTimeZone('Asia/Manila');
    $now = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');

    // 1. Default Closing Evaluation
    echo "1. Default Closing Evaluation:\n";
    $testSettings = [
        'closing_time' => '20:00:00',
        'override_closing_time' => null,
        'override_closing_date' => null
    ];
    $effective = PricingEngine::getEffectiveClosingTime($testSettings);
    assert($effective === '20:00', "Effective should be 20:00, got: $effective");
    echo "   ✓ Default 20:00 resolved when override is empty.\n";

    // 2. Active override for today (1 minute in the past) -> Evaluates as closed
    echo "2. Past Override for Today:\n";
    $pastMinute = (clone $now)->modify('-1 minute')->format('H:i');
    $testSettings = [
        'closing_time' => '20:00:00',
        'override_closing_time' => $pastMinute . ':00',
        'override_closing_date' => $today
    ];
    $effective = PricingEngine::getEffectiveClosingTime($testSettings);
    assert($effective === $pastMinute, "Effective should be $pastMinute, got: $effective");
    $isPast = PricingEngine::isPastClosing($testSettings);
    assert($isPast === true, "isPastClosing must be TRUE for past override.");
    echo "   ✓ Override time '$pastMinute' applied and correctly evaluated as closed.\n";

    // 3. Active override for today (10 minutes in the future) -> Evaluates as open
    echo "3. Future Override for Today:\n";
    $futureMinute = (clone $now)->modify('+10 minutes')->format('H:i');
    $testSettings['override_closing_time'] = $futureMinute . ':00';
    $isPast = PricingEngine::isPastClosing($testSettings);
    assert($isPast === false, "isPastClosing must be FALSE for future override.");
    echo "   ✓ Override time '$futureMinute' correctly evaluated as open.\n";

    // 4. Stale override from yesterday -> Ignored, falls back to default
    echo "4. Stale Override (Yesterday):\n";
    $yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
    $testSettings = [
        'closing_time' => '20:00:00',
        'override_closing_time' => '12:00:00',
        'override_closing_date' => $yesterday
    ];
    $effective = PricingEngine::getEffectiveClosingTime($testSettings);
    assert($effective === '20:00', "Stale override must fallback to 20:00, got: $effective");
    echo "   ✓ Yesterday's date ignored; fallback to default schedule preserved.\n";

    // 5. Database Save & Retrieve Test
    echo "5. Database Update & Query Test:\n";
    $stmt = $pdo->prepare("
        UPDATE settings 
        SET override_closing_time = :otime, override_closing_date = :odate 
        WHERE id = 1
    ");
    $stmt->execute([':otime' => $pastMinute . ':00', ':odate' => $today]);

    $fresh = $pdo->query("SELECT * FROM settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    assert(PricingEngine::isPastClosing($fresh) === true, "DB record must evaluate as closed");
    echo "   ✓ Database persistence evaluated successfully.\n";

    echo "\n✓ ALL SUB-PHASE B3.2 TESTS PASSED SUCCESSFULLY!\n";
} finally {
    $restore = $pdo->prepare("
        UPDATE settings 
        SET override_closing_time = :otime, override_closing_date = :odate 
        WHERE id = 1
    ");
    $restore->execute([
        ':otime' => $backup['override_closing_time'] ?? null,
        ':odate' => $backup['override_closing_date'] ?? null
    ]);
    echo "Cleaned up test settings.\n";
}
