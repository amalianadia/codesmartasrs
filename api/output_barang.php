<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!isset($input['slots']) || empty($input['slots'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$slots = $input['slots'];
$userId = $_SESSION['user_id'];

try {
    $pdo->beginTransaction();

    foreach ($slots as $slotId) {
        // Validasi slot berisi barang
        $stmt = $pdo->prepare("
            SELECT s.slot_id, s.barang_id, b.nama_barang, b.jenis, b.exp_date
            FROM slot s
            INNER JOIN barang b ON s.barang_id = b.barang_id
            WHERE s.slot_id = ? AND s.barang_id IS NOT NULL
        ");
        $stmt->execute([$slotId]);
        $slot = $stmt->fetch();

        if (!$slot) {
            throw new Exception("Slot {$slotId} tidak valid atau kosong");
        }

        // Cek apakah slot sudah ada di queue (pending/processing)
        $stmt = $pdo->prepare("
            SELECT id FROM output_queue 
            WHERE slot_id = ? AND status IN ('pending', 'processing')
        ");
        $stmt->execute([$slotId]);
        if ($stmt->fetch()) {
            throw new Exception("Slot {$slotId} sudah ada di antrian");
        }

        // Masukkan ke output_queue
        $stmt = $pdo->prepare("
            INSERT INTO output_queue (slot_id, barang_id, nama_barang, status)
            VALUES (?, ?, ?, 'pending')
        ");
        $stmt->execute([$slotId, $slot['barang_id'], $slot['nama_barang']]);
    }

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => 'Ditambahkan ke antrian robot',
        'count' => count($slots)
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}