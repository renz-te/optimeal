<?php
require_once __DIR__ . '/api/config.php';
$apiKey = getenv('GEMINI_API_KEY');
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=" . $apiKey;
$payload = [
    "contents" => [
        ["parts" => [["text" => "respond in raw json: {\"status\":\"ok\"}"]]]
    ]
];
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP: $code\n";
echo "Response: $res\n";
