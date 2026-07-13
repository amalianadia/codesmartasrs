<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

$commandId = $_GET['command_id'] ?? null;

if (!$commandId) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing command_id'
    ]);
    exit;
}

try {
    // Get latest progress
    $stmt = $pdo->prepare("
        SELECT step, percent, timestamp 
        FROM robot_progress 
        WHERE command_id = ? 
        ORDER BY timestamp DESC 
        LIMIT 1
    ");
    $stmt->execute([$commandId]);
    $progress = $stmt->fetch();
    
    // Get command status
    $stmt = $pdo->prepare("
        SELECT status, command, barcode 
        FROM robot_commands 
        WHERE id = ?
    ");
    $stmt->execute([$commandId]);
    $command = $stmt->fetch();
    
    if ($progress && $command) {
        echo json_encode([
            'success' => true,
            'command_id' => $commandId,
            'status' => $command['status'],
            'command' => $command['command'],
            'barcode' => $command['barcode'],
            'step' => $progress['step'],
            'percent' => (int)$progress['percent'],
            'timestamp' => $progress['timestamp']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Progress not found'
        ]);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
