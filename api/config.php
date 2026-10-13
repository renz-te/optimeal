<?php
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
    }
}

if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-3.5-flash');
}

if (!defined('PAYMONGO_SECRET_KEY')) {
    define('PAYMONGO_SECRET_KEY', getenv('PAYMONGO_SECRET_KEY') ?: 'sk_test_placeholder_secret_key');
}
if (!defined('PAYMONGO_PUBLIC_KEY')) {
    define('PAYMONGO_PUBLIC_KEY', getenv('PAYMONGO_PUBLIC_KEY') ?: 'pk_test_placeholder_public_key');
}
if (!defined('PAYMONGO_API_BASE')) {
    define('PAYMONGO_API_BASE', getenv('PAYMONGO_API_BASE') ?: 'https://api.paymongo.com/v1');
}

const FLOOR_COST_MULTIPLIER = 1.2;

class BusinessException extends Exception {}
