<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }

/**
 * OptiMeal Phase D9.1 Automated Verification Suite
 * Tests:
 * - P1: Upload directory security (.htaccess blocks script execution, permits images).
 * - P1: POST /vendor/dish/image accepts valid JPEG/PNG/WebP, enforces finfo + getimagesize.
 * - P1: Random hex filename generation in uploads/dishes/.
 * - P2: handleSaveDish strictly validates uploads/dishes/ path prefix and prevents traversal.
 * - P2: Safe deletion of old dish photos strictly inside uploads/dishes/.
 * - P3: Multi-role authentication (student blocked with 403, vendor allowed).
 */

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/db.php';

$baseUrl = "http://localhost/optimeal/api";
$webRootUrl = "http://localhost/optimeal";
$tempDir = sys_get_temp_dir();
$cookieVendor = $tempDir . '/cookie_vendor_d9.txt';
$cookieStudent = $tempDir . '/cookie_student_d9.txt';

if (file_exists($cookieVendor)) @unlink($cookieVendor);
if (file_exists($cookieStudent)) @unlink($cookieStudent);

function d9JsonRequest($url, $method = 'GET', $data = null, $cookieFile = null) {
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

function d9UploadRequest($url, $filePath, $postName = 'test.jpg', $mimeType = 'image/jpeg', $cookieFile = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if ($filePath && file_exists($filePath)) {
        $cfile = new CURLFile($filePath, $mimeType, $postName);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['image' => $cfile]);
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, []);
    }

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $status,
        'data' => json_decode($response, true) ?: [],
        'raw' => $response
    ];
}

echo "=== PHASE D9.1 DISH PHOTOS AUTOMATED VERIFICATION ===\n\n";

$createdDishIds = [];
$uploadedFiles = [];
$tempLocalFiles = [];

try {
    // 1. Authenticate Sessions
    $vendorLogin = d9JsonRequest("$baseUrl/auth/login", 'POST', [
        'email' => 'canteen@optimeal.test',
        'password' => 'password123'
    ], $cookieVendor);

    if ($vendorLogin['status'] !== 200) {
        echo "✗ Vendor login failed: HTTP {$vendorLogin['status']}\n";
        exit(1);
    }
    echo "✓ 1. Vendor Authenticated (Store 1)\n";

    $studentLogin = d9JsonRequest("$baseUrl/auth/login", 'POST', [
        'email' => 'student@optimeal.test',
        'password' => 'password123'
    ], $cookieStudent);

    if ($studentLogin['status'] !== 200) {
        echo "✗ Student login failed: HTTP {$studentLogin['status']}\n";
        exit(1);
    }
    echo "✓ 2. Student Authenticated\n";

    // 2. Authorization Enforcement on Upload Endpoint
    echo "\n--- TEST 1: Role Authorization ---\n";
    $noAuthRes = d9UploadRequest("$baseUrl/vendor/dish/image", null, 'empty.jpg', 'image/jpeg', null);
    if ($noAuthRes['status'] === 401) {
        echo "✓ Unauthenticated upload correctly rejected with HTTP 401.\n";
    } else {
        echo "✗ Expected HTTP 401 for unauthenticated upload, got {$noAuthRes['status']}\n";
        exit(1);
    }

    $studentUploadRes = d9UploadRequest("$baseUrl/vendor/dish/image", null, 'empty.jpg', 'image/jpeg', $cookieStudent);
    if ($studentUploadRes['status'] === 403) {
        echo "✓ Student role upload correctly rejected with HTTP 403.\n";
    } else {
        echo "✗ Expected HTTP 403 for student upload, got {$studentUploadRes['status']}\n";
        exit(1);
    }

    // 3. Validation: Missing file, Invalid MIME, and Corrupt Binary
    echo "\n--- TEST 2: Dual Validation (finfo + getimagesize) & Size Bounds ---\n";
    
    // 3a. Missing file
    $emptyUpload = d9UploadRequest("$baseUrl/vendor/dish/image", null, 'empty.jpg', 'image/jpeg', $cookieVendor);
    if ($emptyUpload['status'] === 422) {
        echo "✓ Missing file upload correctly rejected with HTTP 422.\n";
    } else {
        echo "✗ Expected HTTP 422 for missing file, got {$emptyUpload['status']}\n";
        exit(1);
    }

    // 3b. Non-image text file disguised as jpg
    $fakeImagePath = $tempDir . '/fake_photo_' . time() . '.jpg';
    file_put_contents($fakeImagePath, "<?php echo 'Malicious script content'; ?>\n");
    $tempLocalFiles[] = $fakeImagePath;

    $fakeUpload = d9UploadRequest("$baseUrl/vendor/dish/image", $fakeImagePath, 'dish.jpg', 'image/jpeg', $cookieVendor);
    if ($fakeUpload['status'] === 422) {
        echo "✓ Disguised non-image text file correctly rejected with HTTP 422: '{$fakeUpload['data']['error']}'\n";
    } else {
        echo "✗ Expected HTTP 422 for disguised text file, got {$fakeUpload['status']}\n";
        exit(1);
    }

    // 3c. Oversized file (> 2MB)
    $oversizedPath = $tempDir . '/oversized_' . time() . '.jpg';
    $oversizedFp = fopen($oversizedPath, 'wb');
    // Write 2.1 MB of dummy data
    fseek($oversizedFp, (int)(2.1 * 1024 * 1024));
    fwrite($oversizedFp, "\0");
    fclose($oversizedFp);
    $tempLocalFiles[] = $oversizedPath;

    $oversizedUpload = d9UploadRequest("$baseUrl/vendor/dish/image", $oversizedPath, 'large.jpg', 'image/jpeg', $cookieVendor);
    if ($oversizedUpload['status'] === 422 && strpos($oversizedUpload['data']['error'], '2MB') !== false) {
        echo "✓ Oversized file (>2MB) correctly rejected with HTTP 422: '{$oversizedUpload['data']['error']}'\n";
    } else {
        echo "✗ Expected HTTP 422 for oversized file, got {$oversizedUpload['status']}\n";
        exit(1);
    }

    // 4. Valid Image Upload (Real JPEG generated via GD)
    echo "\n--- TEST 3: Valid Image Upload & Hex Filename Generation ---\n";
    $validJpegPath = $tempDir . '/valid_dish_' . time() . '.jpg';
    $gdImg = imagecreatetruecolor(80, 80);
    $bgCol = imagecolorallocate($gdImg, 16, 185, 129); // emerald
    imagefill($gdImg, 0, 0, $bgCol);
    imagejpeg($gdImg, $validJpegPath, 90);
    imagedestroy($gdImg);
    $tempLocalFiles[] = $validJpegPath;

    $validUploadRes = d9UploadRequest("$baseUrl/vendor/dish/image", $validJpegPath, 'photo.jpg', 'image/jpeg', $cookieVendor);
    if ($validUploadRes['status'] === 200 && !empty($validUploadRes['data']['image_url'])) {
        $imageUrl1 = $validUploadRes['data']['image_url'];
        $uploadedFiles[] = $imageUrl1;
        echo "✓ Valid JPEG uploaded successfully: '{$imageUrl1}'\n";

        // Verify random hex filename pattern: uploads/dishes/[32 hex chars].jpg
        if (preg_match('#^uploads/dishes/[a-f0-9]{32}\.jpg$#', $imageUrl1)) {
            echo "✓ Filename pattern verified: 32-character random hex with .jpg extension.\n";
        } else {
            echo "✗ Unexpected image_url format: {$imageUrl1}\n";
            exit(1);
        }

        // Verify file physically exists in uploads/dishes/
        $diskPath1 = __DIR__ . '/../' . $imageUrl1;
        if (file_exists($diskPath1)) {
            echo "✓ Physical file confirmed on disk (" . filesize($diskPath1) . " bytes).\n";
        } else {
            echo "✗ Physical file not found at: {$diskPath1}\n";
            exit(1);
        }
    } else {
        echo "✗ Valid upload failed: HTTP {$validUploadRes['status']} - " . json_encode($validUploadRes['data']) . "\n";
        exit(1);
    }

    // 5. handleSaveDish Validation & Persistence
    echo "\n--- TEST 4: Dish Save Path Traversal Defense & Persistence ---\n";

    // 5a. Reject external malicious URL
    $externalSaveRes = d9JsonRequest("$baseUrl/vendor/dish", 'POST', [
        'name' => 'D9 Attack Dish External',
        'base_price' => 100,
        'image_url' => 'https://malicious-site.example/hacked.jpg'
    ], $cookieVendor);

    if ($externalSaveRes['status'] === 422) {
        echo "✓ External image_url rejected with HTTP 422: '{$externalSaveRes['data']['error']}'\n";
    } else {
        echo "✗ Expected HTTP 422 for external image_url, got {$externalSaveRes['status']}\n";
        exit(1);
    }

    // 5b. Reject path traversal
    $traversalSaveRes = d9JsonRequest("$baseUrl/vendor/dish", 'POST', [
        'name' => 'D9 Attack Dish Traversal',
        'base_price' => 100,
        'image_url' => 'uploads/dishes/../../config.php'
    ], $cookieVendor);

    if ($traversalSaveRes['status'] === 422) {
        echo "✓ Path traversal image_url rejected with HTTP 422: '{$traversalSaveRes['data']['error']}'\n";
    } else {
        echo "✗ Expected HTTP 422 for path traversal, got {$traversalSaveRes['status']}\n";
        exit(1);
    }

    // 5c. Valid dish save with imageUrl1
    $validSaveRes = d9JsonRequest("$baseUrl/vendor/dish", 'POST', [
        'name' => 'D9 Adobo Photo Test',
        'category' => 'Ulam / Stews',
        'base_price' => 120,
        'price_floor' => 100,
        'image_url' => $imageUrl1
    ], $cookieVendor);

    if ($validSaveRes['status'] === 200 && !empty($validSaveRes['data']['id'])) {
        $dishId = (int)$validSaveRes['data']['id'];
        $createdDishIds[] = $dishId;
        echo "✓ Dish #$dishId created successfully with image_url = '{$imageUrl1}'.\n";

        // Verify in DB
        $dbDish = $pdo->query("SELECT name, image_url FROM menu_items WHERE id = $dishId")->fetch(PDO::FETCH_ASSOC);
        if ($dbDish['image_url'] === $imageUrl1) {
            echo "✓ DB verified: menu_items.image_url matches '{$imageUrl1}'.\n";
        } else {
            echo "✗ DB mismatch: expected '{$imageUrl1}', got '{$dbDish['image_url']}'\n";
            exit(1);
        }
    } else {
        echo "✗ Failed to save dish with image: HTTP {$validSaveRes['status']} - " . json_encode($validSaveRes['data']) . "\n";
        exit(1);
    }

    // 6. Safe Old File Cleanup on Image Replacement
    echo "\n--- TEST 5: Safe Old Photo Cleanup on Replacement ---\n";

    // Upload a second valid image (PNG)
    $validPngPath = $tempDir . '/valid_dish_' . time() . '.png';
    $gdImgPng = imagecreatetruecolor(60, 60);
    $bgColPng = imagecolorallocate($gdImgPng, 239, 68, 68); // red
    imagefill($gdImgPng, 0, 0, $bgColPng);
    imagepng($gdImgPng, $validPngPath);
    imagedestroy($gdImgPng);
    $tempLocalFiles[] = $validPngPath;

    $uploadRes2 = d9UploadRequest("$baseUrl/vendor/dish/image", $validPngPath, 'second.png', 'image/png', $cookieVendor);
    if ($uploadRes2['status'] !== 200) {
        echo "✗ Second image upload failed: HTTP {$uploadRes2['status']}\n";
        exit(1);
    }
    $imageUrl2 = $uploadRes2['data']['image_url'];
    $uploadedFiles[] = $imageUrl2;
    echo "✓ Second image uploaded: '{$imageUrl2}'\n";

    // Update dish #$dishId with imageUrl2
    $updateSaveRes = d9JsonRequest("$baseUrl/vendor/dish", 'POST', [
        'id' => $dishId,
        'name' => 'D9 Adobo Photo Test Updated',
        'category' => 'Ulam / Stews',
        'base_price' => 120,
        'price_floor' => 100,
        'image_url' => $imageUrl2
    ], $cookieVendor);

    if ($updateSaveRes['status'] === 200) {
        echo "✓ Dish #$dishId updated with new image.\n";

        // Check that old image (diskPath1) was safely deleted from disk
        if (!file_exists($diskPath1)) {
            echo "✓ Old dish photo ('{$imageUrl1}') was safely deleted from uploads/dishes/.\n";
        } else {
            echo "✗ Old dish photo was NOT deleted after replacement!\n";
            exit(1);
        }

        // Check that new image exists on disk
        $diskPath2 = __DIR__ . '/../' . $imageUrl2;
        if (file_exists($diskPath2)) {
            echo "✓ New dish photo ('{$imageUrl2}') is present on disk.\n";
        } else {
            echo "✗ New dish photo is missing from disk!\n";
            exit(1);
        }
    } else {
        echo "✗ Updating dish failed: HTTP {$updateSaveRes['status']}\n";
        exit(1);
    }

    // 7. Apache .htaccess Script Execution Block
    echo "\n--- TEST 6: Apache .htaccess Security Policy ---\n";
    $testScriptPath = __DIR__ . '/../uploads/dishes/test_exec.php';
    file_put_contents($testScriptPath, "<?php echo 'EXECUTED_PHP'; ?>\n");

    $chWeb = curl_init("$webRootUrl/uploads/dishes/test_exec.php");
    curl_setopt($chWeb, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chWeb, CURLOPT_TIMEOUT, 5);
    $webResp = curl_exec($chWeb);
    $webCode = curl_getinfo($chWeb, CURLINFO_HTTP_CODE);
    curl_close($chWeb);

    @unlink($testScriptPath);

    if ($webCode === 403) {
        echo "✓ Apache .htaccess strictly denied execution of PHP script (HTTP 403 Forbidden).\n";
    } else {
        echo "✗ Expected HTTP 403 Forbidden for PHP in uploads/dishes/, got HTTP {$webCode}.\n";
        exit(1);
    }

    echo "\n========================================================\n";
    echo "✓ ALL SUB-PHASE D9.1 AUTOMATED TESTS PASSED SUCCESSFULLY!\n";
    echo "========================================================\n";

} finally {
    echo "\nCleaning up test artifacts...\n";
    if (isset($pdo) && !empty($createdDishIds)) {
        foreach ($createdDishIds as $dId) {
            $pdo->exec("DELETE FROM inventory WHERE menu_item_id = $dId");
            $pdo->exec("DELETE FROM menu_items WHERE id = $dId");
        }
        echo "✓ Removed test menu items.\n";
    }

    // Remove any created test images in uploads/dishes/
    if (!empty($uploadedFiles)) {
        foreach ($uploadedFiles as $uPath) {
            $p = __DIR__ . '/../' . $uPath;
            if (file_exists($p) && basename($p) !== '.htaccess') {
                @unlink($p);
            }
        }
        echo "✓ Removed test uploaded image files.\n";
    }

    // Remove temp local files
    foreach ($tempLocalFiles as $tlf) {
        if (file_exists($tlf)) @unlink($tlf);
    }

    if (file_exists($cookieVendor)) @unlink($cookieVendor);
    if (file_exists($cookieStudent)) @unlink($cookieStudent);
    echo "✓ Cleanup complete.\n";
}
