<?php

require_once __DIR__ . '/../config.php';

class PayMongoService
{
    /**
     * Create a PayMongo hosted checkout session.
     *
     * @param array $lineItems Array of items with 'name', 'amount' (in centavos), 'quantity', and optional 'currency'
     * @param string $successUrl URL to redirect after successful payment
     * @param string $cancelUrl URL to redirect upon cancellation
     * @param string $description Description for the checkout session
     * @return array Decoded response containing 'id' and 'checkout_url'
     * @throws Exception
     */
    public static function createCheckoutSession(
        array $lineItems,
        string $successUrl,
        string $cancelUrl,
        string $description
    ): array {
        $formattedLineItems = [];
        foreach ($lineItems as $item) {
            $formattedLineItems[] = [
                'currency' => $item['currency'] ?? 'PHP',
                'amount' => (int)($item['amount'] ?? 0),
                'name' => (string)($item['name'] ?? 'Item'),
                'quantity' => max(1, (int)($item['quantity'] ?? 1)),
            ];
        }

        $payload = [
            'data' => [
                'attributes' => [
                    'send_email_receipt' => false,
                    'show_description' => true,
                    'show_line_items' => true,
                    'description' => $description,
                    'line_items' => $formattedLineItems,
                    'payment_method_types' => ['card', 'gcash', 'paymaya', 'grab_pay'],
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl
                ]
            ]
        ];

        $apiUrl = rtrim(PAYMONGO_API_BASE, '/') . '/checkout_sessions';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_USERPWD => PAYMONGO_SECRET_KEY . ':',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($curlError)) {
            throw new Exception("PayMongo cURL connection error: " . $curlError);
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400 || empty($decoded['data']['id'])) {
            $errorMsg = 'Failed to create PayMongo checkout session';
            if (!empty($decoded['errors'][0]['detail'])) {
                $errorMsg .= ': ' . $decoded['errors'][0]['detail'];
            } elseif (!empty($decoded['errors'][0]['code'])) {
                $errorMsg .= ' (' . $decoded['errors'][0]['code'] . ')';
            }
            throw new Exception($errorMsg . " (HTTP $httpCode)");
        }

        $sessionId = $decoded['data']['id'] ?? '';
        $checkoutUrl = $decoded['data']['attributes']['checkout_url'] ?? '';

        return [
            'id' => $sessionId,
            'checkout_url' => $checkoutUrl,
            'data' => $decoded['data'] ?? []
        ];
    }
}
