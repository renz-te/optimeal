<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

/**
 * OptiMeal Sub-Phase B3.1 Automated Verification Suite
 * Admin UI, Navigation & Global Announcements (Tasks 1 & 4)
 *
 * Checks:
 * 1. Unauthorized/Non-admin blocked from modifying admin settings (HTTP 401/403).
 * 2. Admin successfully fetches current settings including 'global_announcement'.
 * 3. Admin successfully sets/updates global announcement via POST /api/admin/settings.
 * 4. Public endpoint GET /api/announcement returns the active global announcement without auth.
 * 5. Public customer endpoint GET /api/customer/menu includes 'global_announcement'.
 * 6. Announcement length limit safety (graceful truncate to 255 chars).
 * 7. Admin successfully clears global announcement (empty string converts to null).
 * 8. GET /api/announcement and GET /api/customer/menu reflect cleared announcement as null.
 */

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/db.php';

$baseUrl = "http://localhost/optimeal/api";
$tempDir = sys_get_temp_dir();
$cookieAdmin = $tempDir . '/cookie_admin_b3_1.txt';
$cookieStudent = $tempDir . '/cookie_student_b3_1.txt';

if (file_exists($cookieAdmin)) @unlink($cookieAdmin);
if (file_exists($cookieStudent)) @unlink($cookieStudent);

function b3Req($url, $method = 'GET', $data = null, $cookieFile = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $headers = ['Content-Type: application/json', 'Accept: application/json'];

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $status,
        'data' => json_decode($response, true) ?: [],
        'raw' => $response
    ];
}

echo "=== SUB-PHASE B3.1 AUTOMATED VERIFICATION ===\n\n";

$initialAnnouncement = null;
try {
    $origStmt = $pdo->query("SELECT global_announcement FROM settings WHERE id = 1");
    if ($origStmt) {
        $initialAnnouncement = $origStmt->fetchColumn();
    }
} catch (Exception $e) {
    echo "Warning: Could not fetch initial settings announcement: " . $e->getMessage() . "\n";
}

try {
    // 1. Authenticate users
    echo "--- 1. Authenticating Admin and Student ---\n";
    $loginAdmin = b3Req("$baseUrl/auth/login", 'POST', ['email' => 'admin@optimeal.test', 'password' => 'password123'], $cookieAdmin);
    assert($loginAdmin['status'] === 200, "Admin login failed: HTTP {$loginAdmin['status']}");
    echo "✓ Admin Authenticated (admin@optimeal.test)\n";

    $loginStudent = b3Req("$baseUrl/auth/login", 'POST', ['email' => 'student@optimeal.test', 'password' => 'password123'], $cookieStudent);
    assert($loginStudent['status'] === 200, "Student login failed: HTTP {$loginStudent['status']}");
    echo "✓ Student Authenticated (student@optimeal.test)\n\n";

    // 2. Authorization check: Student cannot update settings
    echo "--- 2. Security & RBAC Guard on Settings ---\n";
    $studentUpdate = b3Req("$baseUrl/admin/settings", 'POST', ['global_announcement' => 'Hacked announcement'], $cookieStudent);
    assert($studentUpdate['status'] === 403, "Expected HTTP 403 for student modifying settings, got {$studentUpdate['status']}");
    echo "✓ Student rejected with HTTP 403 when attempting to update admin settings\n\n";

    // 3. Admin retrieves settings with announcement field
    echo "--- 3. Admin GET /admin/settings ---\n";
    $getSettings = b3Req("$baseUrl/admin/settings", 'GET', null, $cookieAdmin);
    assert($getSettings['status'] === 200, "Expected HTTP 200 from GET /admin/settings, got {$getSettings['status']}");
    assert(isset($getSettings['data']['settings']), "Response missing 'settings' object");
    assert(array_key_exists('global_announcement', $getSettings['data']['settings']), "'settings' object missing 'global_announcement' key");
    echo "✓ Admin successfully retrieved settings with global_announcement key\n\n";

    // 4. Admin updates announcement
    echo "--- 4. Admin Set Global Announcement ---\n";
    $testText = "Special Advisory: Dining hall closed from 18:00 for disinfection. Claim meal reservations early.";
    $updateSettings = b3Req("$baseUrl/admin/settings", 'POST', ['global_announcement' => $testText], $cookieAdmin);
    assert($updateSettings['status'] === 200, "Expected HTTP 200 from POST /admin/settings, got {$updateSettings['status']}");
    assert($updateSettings['data']['settings']['global_announcement'] === $testText, "Announcement in response does not match: " . var_export($updateSettings['data']['settings']['global_announcement'], true));
    
    // Verify GET also reflects it
    $verifySettings = b3Req("$baseUrl/admin/settings", 'GET', null, $cookieAdmin);
    assert($verifySettings['data']['settings']['global_announcement'] === $testText, "Persisted announcement does not match in GET /admin/settings");
    echo "✓ Admin successfully updated and verified global announcement in settings\n\n";

    // 5. Public GET /announcement endpoint
    echo "--- 5. Public GET /announcement Endpoint ---\n";
    $pubAnnounce = b3Req("$baseUrl/announcement", 'GET');
    assert($pubAnnounce['status'] === 200, "Expected HTTP 200 from GET /announcement, got {$pubAnnounce['status']}");
    assert($pubAnnounce['data']['global_announcement'] === $testText, "Public announcement does not match expected broadcast");
    echo "✓ Public GET /announcement returns broadcast without authentication\n\n";

    // 6. Customer Menu endpoint returns announcement
    echo "--- 6. Customer GET /menu ---\n";
    $menuRes = b3Req("$baseUrl/menu?store_id=1", 'GET', null, $cookieStudent);
    assert($menuRes['status'] === 200, "Expected HTTP 200 from GET /menu, got {$menuRes['status']}");
    assert($menuRes['data']['global_announcement'] === $testText, "Customer menu payload does not match global announcement: " . var_export($menuRes['data']['global_announcement'] ?? null, true));
    echo "✓ Customer GET /menu includes active global announcement in payload\n\n";

    // 7. Long Announcement Truncation Safety
    echo "--- 7. Max Length (255) Safe Handling ---\n";
    $longText = str_repeat("OptiMeal Notice: ", 25); // > 255 chars
    $longUpdate = b3Req("$baseUrl/admin/settings", 'POST', ['global_announcement' => $longText], $cookieAdmin);
    assert($longUpdate['status'] === 200, "Expected HTTP 200 from long announcement update, got {$longUpdate['status']}");
    $savedLong = $longUpdate['data']['settings']['global_announcement'];
    assert(mb_strlen($savedLong) === 255, "Expected string truncated to exactly 255 chars, got length " . mb_strlen($savedLong));
    echo "✓ Long announcement gracefully truncated to 255 chars without DB error\n\n";

    // 8. Clear Announcement
    echo "--- 8. Admin Clear Global Announcement ---\n";
    $clearRes = b3Req("$baseUrl/admin/settings", 'POST', ['global_announcement' => ''], $cookieAdmin);
    assert($clearRes['status'] === 200, "Expected HTTP 200 from clearing announcement, got {$clearRes['status']}");
    assert($clearRes['data']['settings']['global_announcement'] === null, "Expected global_announcement to be null after clearing, got " . var_export($clearRes['data']['settings']['global_announcement'], true));

    $verifyClearPub = b3Req("$baseUrl/announcement", 'GET');
    assert($verifyClearPub['status'] === 200, "Expected HTTP 200 from GET /announcement after clear, got {$verifyClearPub['status']}");
    assert($verifyClearPub['data']['global_announcement'] === null, "Expected null public announcement after clear");

    $verifyClearMenu = b3Req("$baseUrl/menu?store_id=1", 'GET');
    assert($verifyClearMenu['status'] === 200, "Expected HTTP 200 from GET /menu after clear, got {$verifyClearMenu['status']}");
    assert($verifyClearMenu['data']['global_announcement'] === null, "Expected null announcement on menu after clear");
    echo "✓ Global announcement cleared and propagated as null across all public endpoints\n\n";

    echo "ALL SUB-PHASE B3.1 BACKEND ASSERTIONS PASSED!\n";
} finally {
    // Restore initial state
    try {
        $restoreStmt = $pdo->prepare("UPDATE settings SET global_announcement = ? WHERE id = 1");
        $restoreStmt->execute([$initialAnnouncement]);
        echo "Cleanup: Restored original global_announcement setting in DB.\n";
    } catch (Exception $e) {
        echo "Cleanup error: " . $e->getMessage() . "\n";
    }

    if (file_exists($cookieAdmin)) @unlink($cookieAdmin);
    if (file_exists($cookieStudent)) @unlink($cookieStudent);
}
