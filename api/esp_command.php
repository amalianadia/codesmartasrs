<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

// Terima data dari ESP2
$espId = $_POST['esp_id'] ?? $_GET['esp_id'] ?? null;
$barcode = $_POST['barcode'] ?? $_GET['barcode'] ?? null;

if (!$espId || !$barcode) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing esp_id or barcode'
    ]);
    exit;
}

try {
    // Update heartbeat ESP2
    $stmt = $pdo->prepare("
        INSERT INTO esp_status (esp_id, last_update) 
        VALUES (?, NOW())
        ON DUPLICATE KEY UPDATE last_update = NOW()
    ");
    $stmt->execute([$espId]);
    
    // Cek apakah barcode sudah ada di tabel barang
    $stmt = $pdo->prepare("
        SELECT b.barang_id, b.nama_barang, s.slot_id 
        FROM barang b
        LEFT JOIN slot s ON b.barang_id = s.barang_id
        WHERE b.barcode = ? 
        LIMIT 1
    ");
    $stmt->execute([$barcode]);
    $barang = $stmt->fetch();
    
    if ($barang && $barang['slot_id']) {
        // TAKE MODE - Barang sudah ada di slot
        $slotId = $barang['slot_id'];
        
        // Convert slot_id ke command (A-I)
        $commandMap = [
            'A1' => 'A', 'A2' => 'B', 'A3' => 'C',
            'B1' => 'D', 'B2' => 'E', 'B3' => 'F',
            'C1' => 'G', 'C2' => 'H', 'C3' => 'I'
        ];
        
        $command = $commandMap[$slotId] ?? null;
        
        if ($command) {
            // Insert command untuk ESP1
            $stmt = $pdo->prepare("
                INSERT INTO robot_commands (command, barcode, status, created_at) 
                VALUES (?, ?, 'pending', NOW())
            ");
            $stmt->execute([$command, $barcode]);
            $commandId = $pdo->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'message' => 'TAKE command created - Robot akan mengambil barang',
                'action' => 'TAKE',
                'command' => $command,
                'barcode' => $barcode,
                'nama_barang' => $barang['nama_barang'],
                'slot' => $slotId,
                'command_id' => $commandId
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Invalid slot_id mapping'
            ]);
        }
        
    } else {
        // PUT MODE - Barang baru, belum ada di slot
        
        // Cek apakah barcode ini sudah pernah di-scan (cek di robot_commands)
        $stmt = $pdo->prepare("
            SELECT id FROM robot_commands 
            WHERE barcode = ? AND status IN ('pending', 'processing')
            LIMIT 1
        ");
        $stmt->execute([$barcode]);
        $existingCommand = $stmt->fetch();
        
        if ($existingCommand) {
            echo json_encode([
                'success' => false,
                'message' => 'Barcode sudah di-scan sebelumnya, menunggu proses robot'
            ]);
            exit;
        }
        
        // Cari slot kosong
        $stmt = $pdo->query("
            SELECT slot_id 
            FROM slot 
            WHERE barang_id IS NULL 
            ORDER BY slot_id 
            LIMIT 1
        ");
        $emptySlot = $stmt->fetch();
        
        if ($emptySlot) {
            $slotId = $emptySlot['slot_id'];
            
            // Convert slot_id ke command (1-9)
            $commandMap = [
                'A1' => '1', 'A2' => '2', 'A3' => '3',
                'B1' => '4', 'B2' => '5', 'B3' => '6',
                'C1' => '7', 'C2' => '8', 'C3' => '9'
            ];
            
            $command = $commandMap[$slotId] ?? null;
            
            if ($command) {
                // Insert command untuk ESP1
                $stmt = $pdo->prepare("
                    INSERT INTO robot_commands (command, barcode, status, created_at) 
                    VALUES (?, ?, 'pending', NOW())
                ");
                $stmt->execute([$command, $barcode]);
                $commandId = $pdo->lastInsertId();
                
                // Reserve slot (tandai akan diisi)
                if ($barang) {
                    $stmt = $pdo->prepare("
                        UPDATE slot SET barang_id = ? WHERE slot_id = ?
                    ");
                    $stmt->execute([$barang['barang_id'], $slotId]);
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'PUT command created - Robot akan menyimpan barang',
                    'action' => 'PUT',
                    'command' => $command,
                    'barcode' => $barcode,
                    'slot' => $slotId,
                    'command_id' => $commandId
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid slot mapping'
                ]);
            }
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Storage penuh! Tidak ada slot kosong.'
            ]);
        }
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
