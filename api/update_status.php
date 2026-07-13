<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

try {

    // Ambil esp_id dari POST atau GET
    $esp_id = $_POST['esp_id'] ?? $_GET['esp_id'] ?? null;

    if (!$esp_id) {
        echo json_encode([
            'success' => false,
            'message' => 'ESP ID is required'
        ]);
        exit;
    }

    // Cek apakah sudah ada di database
    $stmt = $pdo->prepare("SELECT id FROM esp_status WHERE esp_id = ?");
    $stmt->execute([$esp_id]);

    if ($stmt->rowCount() > 0) {
        // Jika sudah ada → update
        $update = $pdo->prepare("UPDATE esp_status SET last_update = NOW() WHERE esp_id = ?");
        $update->execute([$esp_id]);
    } else {
        // Jika belum ada → insert
        $insert = $pdo->prepare("INSERT INTO esp_status (esp_id, last_update) VALUES (?, NOW())");
        $insert->execute([$esp_id]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Status updated successfully',
        'esp_id' => $esp_id,
        'time' => date('H:i:s')
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}