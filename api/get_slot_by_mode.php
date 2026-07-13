<?php
session_start();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

$barcode = $_GET['barcode'] ?? '';
$mode = $_SESSION['storage_mode'] ?? 'FIFO';

if (!$barcode) {
    echo json_encode(['success' => false, 'message' => 'Barcode required']);
    exit;
}

try {
    // Base query
    $query = "
        SELECT 
            s.slot_id,
            b.barang_id,
            b.nama_barang,
            b.jenis,
            b.expired_date,
            b.created_at,
            DATEDIFF(b.expired_date, CURDATE()) as days_to_expire
        FROM slot s
        INNER JOIN barang b ON s.barang_id = b.barang_id
        WHERE b.barcode = ?
        AND s.barang_id IS NOT NULL
    ";
    
    // Tambahkan ORDER BY sesuai mode
    switch ($mode) {
        case 'FIFO':
            // Oldest first (yang paling lama masuk)
            $query .= " ORDER BY b.created_at ASC LIMIT 1";
            break;
            
        case 'LIFO':
            // Newest first (yang paling baru masuk)
            $query .= " ORDER BY b.created_at DESC LIMIT 1";
            break;
            
        case 'FEFO':
            // Earliest expiry first (yang paling dekat expired)
            $query .= " ORDER BY b.expired_date ASC, b.created_at ASC LIMIT 1";
            break;
            
        case 'MANUAL':
            // Return all slots
            $query .= " ORDER BY s.slot_id ASC";
            break;
    }
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$barcode]);
    
    if ($mode === 'MANUAL') {
        $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'success' => true,
            'mode' => $mode,
            'slots' => $slots,
            'manual' => true
        ]);
    } else {
        $slot = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($slot) {
            echo json_encode([
                'success' => true,
                'mode' => $mode,
                'slot' => $slot
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Barang dengan barcode ini tidak ditemukan di storage'
            ]);
        }
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
