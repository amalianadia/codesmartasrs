<?php
// api/output_barang.php
session_start();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['slots']) || !is_array($input['slots']) || empty($input['slots'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$slots = $input['slots'];
$userId = $_SESSION['user_id'];

try {
    $pdo->beginTransaction();
    
    // Loop setiap slot untuk output
    foreach ($slots as $slotId) {
        // PERBAIKAN: Validasi slot dengan barang_id NOT NULL (tidak peduli status)
        $stmt = $pdo->prepare("
            SELECT s.slot_id, s.barang_id, b.nama_barang, b.jenis
            FROM slot s
            INNER JOIN barang b ON s.barang_id = b.barang_id
            WHERE s.slot_id = ? AND s.barang_id IS NOT NULL
        ");
        $stmt->execute([$slotId]);
        $slot = $stmt->fetch();
        
        if (!$slot) {
            throw new Exception("Slot {$slotId} tidak valid atau kosong");
        }
        
        // DIPERBAIKI: Insert ke history (OUTPUT) dengan nama_barang dan jenis
        $stmt = $pdo->prepare("
            INSERT INTO history (barang_id, slot_id, action_type, user_id, nama_barang, jenis, created_at)
            VALUES (?, ?, 'OUTPUT', ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $slot['barang_id'],
            $slotId,
            $userId,
            $slot['nama_barang'],  // BARU: Simpan nama barang
            $slot['jenis']         // BARU: Simpan jenis
        ]);
        
        // Update slot jadi kosong
        $stmt = $pdo->prepare("
            UPDATE slot 
            SET barang_id = NULL, status = 'Kosong'
            WHERE slot_id = ?
        ");
        $stmt->execute([$slotId]);
    }
    
    // Cek apakah masih ada slot untuk setiap barang
    // Jika tidak, hapus barang dari tabel barang
    $stmt = $pdo->query("
        SELECT DISTINCT b.barang_id
        FROM barang b
        LEFT JOIN slot s ON b.barang_id = s.barang_id AND s.barang_id IS NOT NULL
        WHERE s.slot_id IS NULL
    ");
    $emptyBarang = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($emptyBarang as $barangId) {
        $stmt = $pdo->prepare("DELETE FROM barang WHERE barang_id = ?");
        $stmt->execute([$barangId]);
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Output berhasil',
        'slots' => $slots,
        'count' => count($slots)
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}