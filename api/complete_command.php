<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

$commandId = $_POST['command_id'] ?? $_GET['command_id'] ?? null;

if (!$commandId) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing command_id'
    ]);
    exit;
}

try {
    // Get command details
    $stmt = $pdo->prepare("
        SELECT command, barcode 
        FROM robot_commands 
        WHERE id = ?
    ");
    $stmt->execute([$commandId]);
    $commandData = $stmt->fetch();
    
    if (!$commandData) {
        echo json_encode([
            'success' => false,
            'message' => 'Command not found'
        ]);
        exit;
    }
    
    $command = $commandData['command'];
    $barcode = $commandData['barcode'];
    
    // Cek PUT (1-9) atau TAKE (A-I)
    $isPut = is_numeric($command);
    
    if ($isPut) {
        // PUT MODE
        $slotMap = [
            '1' => 'A1', '2' => 'A2', '3' => 'A3',
            '4' => 'B1', '5' => 'B2', '6' => 'B3',
            '7' => 'C1', '8' => 'C2', '9' => 'C3'
        ];
        
        $slotId = $slotMap[$command] ?? null;
        
        if ($slotId) {
            // Get barang_id
            $stmt = $pdo->prepare("SELECT barang_id FROM barang WHERE barcode = ?");
            $stmt->execute([$barcode]);
            $barang = $stmt->fetch();
            
            if ($barang) {
                $barangId = $barang['barang_id'];
                
                // Update slot
                $stmt = $pdo->prepare("UPDATE slot SET barang_id = ? WHERE slot_id = ?");
                $stmt->execute([$barangId, $slotId]);
                
                // Insert history
                $stmt = $pdo->prepare("
                    INSERT INTO history (barang_id, slot_id, action_type, created_at)
                    VALUES (?, ?, 'INPUT', NOW())
                ");
                $stmt->execute([$barangId, $slotId]);
                
                // Log
                $stmt = $pdo->prepare("
                    INSERT INTO log_aktivitas (user_id, aktivitas, created_at)
                    VALUES (1, ?, NOW())
                ");
                $stmt->execute(["Robot PUT: $barcode → Slot $slotId"]);
                
                // Update command status
                $stmt = $pdo->prepare("
                    UPDATE robot_commands 
                    SET status = 'completed', updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$commandId]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'PUT completed - Item stored',
                    'action' => 'PUT',
                    'slot' => $slotId,
                    'barcode' => $barcode
                ]);
            }
        }
        
    } else {
        // TAKE MODE
        $slotMap = [
            'A' => 'A1', 'B' => 'A2', 'C' => 'A3',
            'D' => 'B1', 'E' => 'B2', 'F' => 'B3',
            'G' => 'C1', 'H' => 'C2', 'I' => 'C3'
        ];
        
        $slotId = $slotMap[$command] ?? null;
        
        if ($slotId) {
            // Get barang_id from slot
            $stmt = $pdo->prepare("SELECT barang_id FROM slot WHERE slot_id = ?");
            $stmt->execute([$slotId]);
            $slot = $stmt->fetch();
            
            if ($slot && $slot['barang_id']) {
                $barangId = $slot['barang_id'];
                
                // Clear slot
                $stmt = $pdo->prepare("UPDATE slot SET barang_id = NULL WHERE slot_id = ?");
                $stmt->execute([$slotId]);
                
                // Insert history
                $stmt = $pdo->prepare("
                    INSERT INTO history (barang_id, slot_id, action_type, created_at)
                    VALUES (?, ?, 'OUTPUT', NOW())
                ");
                $stmt->execute([$barangId, $slotId]);
                
                // Log
                $stmt = $pdo->prepare("
                    INSERT INTO log_aktivitas (user_id, aktivitas, created_at)
                    VALUES (1, ?, NOW())
                ");
                $stmt->execute(["Robot TAKE: $barcode ← Slot $slotId"]);
                
                // Update command status
                $stmt = $pdo->prepare("
                    UPDATE robot_commands 
                    SET status = 'completed', updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$commandId]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'TAKE completed - Item retrieved',
                    'action' => 'TAKE',
                    'slot' => $slotId,
                    'barcode' => $barcode
                ]);
            }
        }
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
