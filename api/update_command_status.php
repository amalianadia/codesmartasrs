<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

$commandId = $_GET['command_id'] ?? $_POST['command_id'] ?? null;
$status = $_GET['status'] ?? $_POST['status'] ?? 'completed';

if (!$commandId) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing command_id'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE robot_commands 
        SET status = ?, updated_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$status, $commandId]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode([
            'success' => true,
            'message' => "Command $commandId updated to $status"
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Command not found'
        ]);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
    