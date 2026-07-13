<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

try {
    $response = [
        'success' => true,
        'esp1' => ['status' => 'offline', 'last_update' => null],
        'esp2' => ['status' => 'offline', 'last_update' => null]
    ];
    
    // Get ESP1 status
    $stmt = $pdo->query("SELECT last_update FROM esp_status WHERE esp_id = 'ESP1' LIMIT 1");
    $esp1 = $stmt->fetch();
    if ($esp1 && $esp1['last_update']) {
        $lastUpdate = new DateTime($esp1['last_update']);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $lastUpdate->getTimestamp();
        $response['esp1']['status'] = ($diff < 300) ? 'online' : 'offline';
        $response['esp1']['last_update'] = $lastUpdate->format('H:i:s');
    }
    
    // Get ESP2 status
    $stmt = $pdo->query("SELECT last_update FROM esp_status WHERE esp_id = 'ESP2' LIMIT 1");
    $esp2 = $stmt->fetch();
    if ($esp2 && $esp2['last_update']) {
        $lastUpdate = new DateTime($esp2['last_update']);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $lastUpdate->getTimestamp();
        $response['esp2']['status'] = ($diff < 300) ? 'online' : 'offline';
        $response['esp2']['last_update'] = $lastUpdate->format('H:i:s');
    }
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
