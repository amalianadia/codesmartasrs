<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

try {
    $stmt = $pdo->prepare("
        SELECT * FROM queue_robot 
        WHERE status = 'pending' 
        ORDER BY created_at ASC 
        LIMIT 1
    ");
    $stmt->execute();
    $task = $stmt->fetch();
    
    if ($task) {
        $update = $pdo->prepare("UPDATE queue_robot SET status = 'processing' WHERE task_id = ?");
        $update->execute([$task['task_id']]);
        
        $response = [
            'success' => true,
            'task_id' => (int)$task['task_id'],
            'action' => $task['action'],
            'slot' => $task['slot'],
            'coordinate' => [
                'x' => (int)$task['koordinat_x'],
                'y' => (int)$task['koordinat_y'],
                'z' => (int)$task['koordinat_z']
            ],
            'barang_id' => (int)$task['barang_id']
        ];
        echo json_encode($response);
    } else {
        echo json_encode(['success' => false, 'message' => 'No pending task']);
    }
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
