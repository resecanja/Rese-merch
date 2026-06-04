<?php
require_once __DIR__ . '/mp-config.php';

// Mercado Pago webhook handler
// Receives payment notifications and forwards to email

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true) ?: [];

// Log all incoming notifications for debugging
$logEntry = [
    'timestamp' => date('c'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '?',
    'topic' => $_GET['topic'] ?? $payload['type'] ?? null,
    'id' => $_GET['id'] ?? $payload['data']['id'] ?? null,
    'payload' => $payload,
];
@file_put_contents(ORDER_LOG_PATH, json_encode($logEntry) . "\n", FILE_APPEND);

// Acknowledge first so MP doesn't retry
http_response_code(200);
echo 'OK';

if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

// Process payment notification
$topic = $_GET['topic'] ?? $payload['type'] ?? null;
$id = $_GET['id'] ?? $payload['data']['id'] ?? null;

if ($topic !== 'payment' || !$id) exit;

// Fetch full payment details from MP API
$ch = curl_init('https://api.mercadopago.com/v1/payments/' . urlencode($id));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . MP_ACCESS_TOKEN],
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) exit;

$payment = json_decode($response, true);
$status = $payment['status'] ?? '?';
$amount = $payment['transaction_amount'] ?? 0;
$method = $payment['payment_method_id'] ?? '?';
$payerEmail = $payment['payer']['email'] ?? '?';
$payerName = trim(($payment['payer']['first_name'] ?? '') . ' ' . ($payment['payer']['last_name'] ?? ''));
$extRef = $payment['external_reference'] ?? '?';

// Log payment update
@file_put_contents(ORDER_LOG_PATH, json_encode([
    'timestamp' => date('c'),
    'event' => 'payment_processed',
    'payment_id' => $id,
    'status' => $status,
    'amount' => $amount,
    'method' => $method,
    'external_reference' => $extRef,
    'payer' => $payerEmail,
]) . "\n", FILE_APPEND);

// Send email notification for approved payments
if ($status === 'approved') {
    $items = $payment['additional_info']['items'] ?? [];
    $itemList = '';
    foreach ($items as $i) {
        $itemList .= sprintf("- %s x %s — R$ %.2f\n", $i['quantity'], $i['title'], $i['unit_price']);
    }

    $shipping = $payment['additional_info']['shipments']['receiver_address'] ?? null;
    $addr = $shipping ? sprintf(
        "%s, %s - %s %s\nCEP %s, %s",
        $shipping['street_name'] ?? '?',
        $shipping['street_number'] ?? '?',
        $shipping['floor'] ?? '',
        $shipping['apartment'] ?? '',
        $shipping['zip_code'] ?? '?',
        $shipping['city_name'] ?? '?'
    ) : 'Não informado';

    $body = "PEDIDO APROVADO — Loja Rese\n\n"
        . "Ref: $extRef\n"
        . "Pagamento: $id ($method)\n"
        . "Valor total: R$ " . number_format($amount, 2, ',', '.') . "\n\n"
        . "Comprador:\n  $payerName <$payerEmail>\n\n"
        . "Itens:\n$itemList\n"
        . "Endereço de entrega:\n$addr\n\n"
        . "Painel: https://www.mercadopago.com.br/activities/$id\n";

    $headers = "From: " . ORDER_EMAIL_FROM . "\r\n"
        . "Reply-To: $payerEmail\r\n"
        . "X-Mailer: rese-merch-mp-webhook\r\n";

    @mail(ORDER_EMAIL_TO, "[Rese Merch] Pedido aprovado - R$ " . number_format($amount, 2, ',', '.'), $body, $headers);
}
