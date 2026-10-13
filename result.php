<?php
header('Content-Type: application/json; charset=utf-8');

// Простой кэш для идемпотентности и защиты от частых запросов (60 секунд)
$cacheFile = sys_get_temp_dir() . '/order_sync_cache.json';
$cacheTtl = 60; 

if (file_exists($cacheFile)) {
    $cacheData = json_decode(file_get_contents($cacheFile), true);
    if (isset($cacheData['timestamp']) && (time() - $cacheData['timestamp'] < $cacheTtl)) {
        echo json_encode($cacheData['result'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$url = 'https://test.ooobs.ru/api/orders';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch orders']);
    exit;
}

$orders = json_decode($response, true);
if (!is_array($orders)) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid JSON from source']);
    exit;
}

$received = count($orders);
$invalidDropped = 0;
$uniqueClientsMap = [];

foreach ($orders as $order) {
    $phone = $order['phone'] ?? '';
    $normalizedPhone = normalizePhone($phone);

    if (!$normalizedPhone) {
        $invalidDropped++;
        continue;
    }

    $currentAmount = isset($order['amount']) ? (float)$order['amount'] : 0.0;
    $currentDate = isset($order['created_at']) ? strtotime($order['created_at']) : 0;

    if (!isset($uniqueClientsMap[$normalizedPhone])) {
        $uniqueClientsMap[$normalizedPhone] = $order;
    } else {
        $existing = $uniqueClientsMap[$normalizedPhone];
        $existingAmount = isset($existing['amount']) ? (float)$existing['amount'] : 0.0;
        $existingDate = isset($existing['created_at']) ? strtotime($existing['created_at']) : 0;

        // Правило дедупликации: макс. amount, при равенстве — более поздний created_at
        if ($currentAmount > $existingAmount) {
            $uniqueClientsMap[$normalizedPhone] = $order;
        } elseif ($currentAmount == $existingAmount) {
            if ($currentDate > $existingDate) {
                $uniqueClientsMap[$normalizedPhone] = $order;
            }
        }
    }
}

$unique = count($uniqueClientsMap);
$duplicatesMerged = $received - $invalidDropped - $unique;

$clients = [];
foreach ($uniqueClientsMap as $normPhone => $order) {
    $amountVal = isset($order['amount']) ? (float)$order['amount'] : 0.0;
    // Если число целое, приводим к int для красивого вывода (как в примере: 5000, а не 5000.0)
    $amountOut = ($amountVal == (int)$amountVal) ? (int)$amountVal : $amountVal;

    $clients[] = [
        'name'   => $order['name'] ?? '',
        'phone'  => $normPhone,
        'email'  => $order['email'] ?? '',
        'amount' => $amountOut
    ];
}

$result = [
    'clients' => $clients,
    'stats'   => [
        'received'         => $received,
        'unique'           => $unique,
        'duplicates_merged'=> $duplicatesMerged,
        'invalid_dropped'  => $invalidDropped
    ]
];

// Сохраняем в кэш (подавляем ошибки, если вдруг файловая система read-only, как в некоторых serverless-средах)
@file_put_contents($cacheFile, json_encode([
    'timestamp' => time(),
    'result'    => $result
]));

echo json_encode($result, JSON_UNESCAPED_UNICODE);

/**
 * Нормализация телефона к формату +7XXXXXXXXXX
 */
function normalizePhone($phone) {
    if (!is_scalar($phone)) return null;
    
    // Удаляем все нецифровые символы
    $digits = preg_replace('/\D/', '', (string)$phone);
    
    // Если 11 цифр и начинается с 7 или 8
    if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) {
        return '+7' . substr($digits, 1);
    }
    
    // Если 10 цифр (например, 9991234567)
    if (strlen($digits) === 10) {
        return '+7' . $digits;
    }
    
    // Всё остальное (12+ цифр, другие коды стран) — невалидно
    return null;
}
?>