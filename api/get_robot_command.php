<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

$espId = $_GET['esp_id'] ?? 'ESP1';

try {
    // Update ESP1 heartbeat
    $stmt = $pdo->prepare("
        INSERT INTO esp_status (esp_id, last_update) 
        VALUES (?, NOW())
        ON DUPLICATE KEY UPDATE last_update = NOW()
    ");
    $stmt->execute([$espId]);
    
    // Ambil command pending tertua
    $stmt = $pdo->prepare("
        SELECT id, command, barcode, created_at
        FROM robot_commands 
        WHERE status = 'pending' 
        ORDER BY created_at ASC 
        LIMIT 1
    ");
    $stmt->execute();
    $command = $stmt->fetch();
    
    if ($command) {
        // Update status ke processing
        $updateStmt = $pdo->prepare("
            UPDATE robot_commands 
            SET status = 'processing' 
            WHERE id = ?
        ");
        $updateStmt->execute([$command['id']]);
        
        echo json_encode([
            'success' => true,
            'has_command' => true,
            'command_id' => $command['id'],
            'command' => $command['command'],
            'barcode' => $command['barcode'],
            'created_at' => $command['created_at']
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'has_command' => false,
            'message' => 'No pending commands'
        ]);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
