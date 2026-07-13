<?php
/**
 * esp32_put.php
 *
 * Dipanggil ESP2 setelah terima ESP-NOW dari ESP1.
 * Ambil command_id dari robot_commands yang sudah dibuat
 * oleh robot_api.php (sender ESP1), lalu update status.
 *
 * FIX: Hapus kondisi esp_id IS NULL karena robot_api.php
 *      sudah insert dengan esp_id='ESP1' dari awal.
 *
 * Method : POST
 * Body   : { "slot": 1-9 }
 * Return : { "success": true, "command_id": 145, "barcode": "PROD-..." }
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);
$slot  = $input['slot'] ?? null;

if (!$slot || !is_numeric($slot) || (int)$slot < 1 || (int)$slot > 9) {
    echo json_encode([
        'success'  => false,
        'message'  => 'Invalid slot. Must be numeric 1-9.',
        'received' => $input
    ]);
    exit;
}

$slot = (int)$slot;

try {
    // SELECT robot_commands yang dibuat robot_api.php
    // TIDAK pakai esp_id IS NULL — robot_api.php sudah set esp_id='ESP1'
    // Cukup filter command = slot dan status = pending, ambil terbaru
    $stmt = $pdo->prepare("
        SELECT id, barcode FROM robot_commands
        WHERE command = ?
          AND status = 'pending'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([(string)$slot]);
    $row = $stmt->fetch();

    if (!$row) {
        echo json_encode([
            'success' => false,
            'message' => "Tidak ada robot_commands pending untuk slot $slot"
        ]);
        exit;
    }

    // Tandai sedang diproses oleh ESP2
    $pdo->prepare("
        UPDATE robot_commands
        SET status = 'processing', updated_at = NOW()
        WHERE id = ?
    ")->execute([$row['id']]);

    echo json_encode([
        'success'    => true,
        'command_id' => (int)$row['id'],
        'barcode'    => $row['barcode'],
        'slot'       => $slot
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}