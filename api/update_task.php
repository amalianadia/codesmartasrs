<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['task_id']) || !isset($input['status'])) {
        throw new Exception('Missing required fields: task_id and status');
    }
    
    $task_id = $input['task_id'];
    $status = $input['status'];
    
    if (!in_array($status, ['done', 'failed'])) {
        throw new Exception('Invalid status. Must be "done" or "failed"');
    }
    
    $stmt = $pdo->prepare("SELECT * FROM queue_robot WHERE task_id = ?");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch();
    
    if (!$task) {
        throw new Exception('Task not found');
    }
    
    $update = $pdo->prepare("UPDATE queue_robot SET status = ? WHERE task_id = ?");
    $update->execute([$status, $task_id]);
    
    if ($status === 'done') {
        if ($task['action'] === 'store') {
            $updateSlot = $pdo->prepare("UPDATE slot SET barang_id = ?, status = 'Isi' WHERE slot_id = ?");
            $updateSlot->execute([$task['barang_id'], $task['slot']]);
        } elseif ($task['action'] === 'pickup') {
            $updateSlot = $pdo->prepare("UPDATE slot SET barang_id = NULL, status = 'Kosong' WHERE slot_id = ?");
            $updateSlot->execute([$task['slot']]);
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Task updated successfully',
        'task_id' => $task_id,
        'status' => $status
    ]);
    
} catch(Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
