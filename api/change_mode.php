<?php
session_start();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$mode = $input['mode'] ?? '';

$validModes = ['FIFO', 'LIFO', 'FEFO', 'MANUAL'];

if (!in_array($mode, $validModes)) {
    echo json_encode(['success' => false, 'message' => 'Mode tidak valid']);
    exit;
}

// Simpan mode ke session
$_SESSION['storage_mode'] = $mode;

// Simpan ke database
try {
    $stmt = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value, updated_at) 
        VALUES ('storage_mode', ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = NOW()
    ");
    $stmt->execute([$mode, $mode]);
} catch (PDOException $e) {
    // Skip jika tabel belum ada
}

echo json_encode([
    'success' => true, 
    'message' => "Mode berhasil diubah ke $mode",
    'mode' => $mode
]);
