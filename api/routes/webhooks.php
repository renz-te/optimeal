<?php

/**
 * OptiMeal Webhook Route Handlers
 */

function handlePaymongoWebhook($pdo)
{
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    if (empty($payload) || !is_array($payload)) {
        jsonResponse(['error' => 'Invalid webhook payload'], 400);
    }

    $eventType = $payload['data']['attributes']['type'] ?? '';

    if ($eventType === 'checkout_session.payment.paid') {
        $sessionData = $payload['data']['attributes']['data'] ?? [];
        $sessionId = $sessionData['id'] ?? null;

        if (!empty($sessionId)) {
            // Find associated order
            $orderStmt = $pdo->prepare("SELECT id, user_id, store_id, payment_status FROM orders WHERE paymongo_session_id = ?");
            $orderStmt->execute([$sessionId]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

            if ($order) {
                $paymentId = $sessionData['attributes']['payments'][0]['id'] ?? null;

                // Update orders payment status and payment ID
                $updateStmt = $pdo->prepare("
                    UPDATE orders 
                    SET payment_status = 'paid',
                        paymongo_payment_id = COALESCE(?, paymongo_payment_id)
                    WHERE paymongo_session_id = ?
                ");
                $updateStmt->execute([$paymentId, $sessionId]);

                // Insert payment notification for customer
                if (!empty($order['user_id'])) {
                    try {
                        $notifStmt = $pdo->prepare("
                            INSERT INTO notifications (user_id, type, message, reference_id, is_read, created_at)
                            VALUES (?, 'order_status', ?, ?, 0, NOW())
                        ");
                        $notifStmt->execute([
                            $order['user_id'],
                            "Payment successful for order #{$order['id']}.",
                            $order['id']
                        ]);
                    } catch (Exception $e) {
                        error_log("Failed to insert payment notification: " . $e->getMessage());
                    }
                }
            }
        }

        jsonResponse(['status' => 'success', 'event' => $eventType]);
    }

    jsonResponse(['status' => 'ignored', 'event' => $eventType]);
}
