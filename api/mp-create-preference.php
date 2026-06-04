<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://merch.rese.com.br');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/mp-config.php';

// Catálogo fixo (preços controlados no servidor — NUNCA confiar em preço do frontend)
$catalog = [
    'camiseta' => ['title' => 'Camiseta Enquanto Isso', 'unit_price' => 80.00, 'description' => 'Camiseta creme com a arte do album'],
    'mini'     => ['title' => 'Mini Vinil 7" com NFC',  'unit_price' => 50.00, 'description' => 'Mini vinil com NFC do album Enquanto Isso'],
    'vinil'    => ['title' => 'Vinil 12" Enquanto Isso', 'unit_price' => 150.00, 'description' => 'Vinil 12 polegadas 33 1/3 RPM'],
];

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || empty($input['items'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing items']);
    exit;
}

$items = [];
foreach ($input['items'] as $item) {
    $id = $item['id'] ?? null;
    $qty = (int)($item['quantity'] ?? 1);
    if (!isset($catalog[$id]) || $qty < 1 || $qty > 99) continue;

    $p = $catalog[$id];
    $items[] = [
        'id' => $id,
        'title' => $p['title'],
        'description' => $p['description'],
        'quantity' => $qty,
        'currency_id' => 'BRL',
        'unit_price' => $p['unit_price'],
    ];
}

if (empty($items)) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid items']);
    exit;
}

$payer = [];
if (!empty($input['payer']['email'])) $payer['email'] = $input['payer']['email'];
if (!empty($input['payer']['name']))  $payer['name']  = $input['payer']['name'];

$preference = [
    'items' => $items,
    'payer' => $payer,
    'back_urls' => [
        'success' => MP_SUCCESS_URL,
        'failure' => MP_FAILURE_URL,
        'pending' => MP_PENDING_URL,
    ],
    'auto_return' => 'approved',
    'notification_url' => MP_NOTIFICATION_URL,
    'statement_descriptor' => 'RESE MERCH',
    'external_reference' => 'rese-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)),
];

$ch = curl_init('https://api.mercadopago.com/checkout/preferences');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . MP_ACCESS_TOKEN,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($preference),
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code < 200 || $code >= 300) {
    http_response_code(502);
    echo json_encode(['error' => 'Mercado Pago API error', 'mp_status' => $code, 'mp_response' => json_decode($response, true)]);
    exit;
}

$data = json_decode($response, true);
echo json_encode([
    'id' => $data['id'] ?? null,
    'init_point' => $data['init_point'] ?? null,
    'external_reference' => $preference['external_reference'],
]);
