<?php
session_start();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$barcode = $_GET['barcode'] ?? '';

if (!$barcode) {
    echo json_encode(['success' => false, 'message' => 'Barcode required']);
    exit;
}

try {
    // Cari barang berdasarkan barcode di slot (yang masih ada)
    $stmt = $pdo->prepare("
        SELECT 
            b.barang_id,
            b.nama_barang,
            b.jenis,
            b.expired_date,
            b.created_at,
            COUNT(s.slot_id) as available_qty
        FROM barang b
        INNER JOIN slot s ON b.barang_id = s.barang_id
        WHERE b.barcode = ?
        GROUP BY b.barang_id, b.nama_barang, b.jenis, b.expired_date, b.created_at
    ");
    $stmt->execute([$barcode]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($product) {
        echo json_encode([
            'success' => true,
            'found' => true,
            'product' => $product
        ]);
    } else {
        // Cek di history apakah pernah ada
        $stmtHistory = $pdo->prepare("
            SELECT DISTINCT nama_barang, jenis
            FROM history
            WHERE barcode = ?
            LIMIT 1
        ");
        $stmtHistory->execute([$barcode]);
        $historyProduct = $stmtHistory->fetch(PDO::FETCH_ASSOC);
        
        if ($historyProduct) {
            echo json_encode([
                'success' => true,
                'found' => false,
                'message' => 'Barang pernah ada tapi stok kosong',
                'history' => $historyProduct
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'found' => false,
                'message' => 'Barcode belum terdaftar'
            ]);
        }
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
