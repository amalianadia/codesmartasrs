<?php
/**
 * esp32_queue.php
 * 
 * Handler antrian TAKE mode untuk ESP32.
 * 
 * PENTING: Saat update_status=done, endpoint ini HANYA update
 * status output_queue. Update slot + history untuk TAKE mode
 * sudah dihandle oleh update_progress.php saat percent=100.
 * 
 * Ini mencegah duplikasi INSERT history dan UPDATE slot.
 */

require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

/*
|--------------------------------------------------------------------------
| ESP32 GET PENDING QUEUE
|--------------------------------------------------------------------------
*/
if ($action === 'get_pending') {

    $stmt = $pdo->query("
        SELECT id, slot_id, barang_id, nama_barang
        FROM output_queue
        WHERE status = 'pending'
        ORDER BY created_at ASC
        LIMIT 1
    ");

    $q = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$q) {
        echo json_encode([
            'success' => true,
            'queue'   => []
        ]);
        exit;
    }

    // Mapping slot → command robot (huruf untuk TAKE mode)
    $slotMap = [
        'A1' => 'A', 'A2' => 'B', 'A3' => 'C',
        'B1' => 'D', 'B2' => 'E', 'B3' => 'F',
        'C1' => 'G', 'C2' => 'H', 'C3' => 'I'
    ];

    $command = $slotMap[$q['slot_id']] ?? null;

    if (!$command) {
        echo json_encode([
            'success' => false,
            'message' => 'Slot mapping not found for: ' . $q['slot_id']
        ]);
        exit;
    }

    // Ambil barcode barang
    $stmt = $pdo->prepare("SELECT barcode FROM barang WHERE barang_id = ?");
    $stmt->execute([$q['barang_id']]);
    $barang  = $stmt->fetch(PDO::FETCH_ASSOC);
    $barcode = $barang['barcode'] ?? '';

    // Buat robot_commands baru untuk TAKE
    // command = huruf 'A'-'I' → is_numeric() = false → TAKE mode di update_progress.php
    $stmt = $pdo->prepare("
        INSERT INTO robot_commands (command, barcode, status, esp_id, created_at)
        VALUES (?, ?, 'processing', 'ESP1', NOW())
    ");
    $stmt->execute([$command, $barcode]);

    $commandId = (int)$pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'queue'   => [
            [
                'id'         => $q['id'],
                'slot_id'    => $q['slot_id'],
                'command_id' => $commandId
            ]
        ]
    ]);

    exit;
}


/*
|--------------------------------------------------------------------------
| UPDATE STATUS QUEUE
| HANYA update status output_queue saja.
| Slot + history sudah dihandle update_progress.php saat percent=100.
|--------------------------------------------------------------------------
*/
if ($action === 'update_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $input  = json_decode(file_get_contents('php://input'), true);
    $id     = $input['id']     ?? null;
    $status = $input['status'] ?? null;

    if (!$id || !in_array($status, ['processing', 'done'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid parameters'
        ]);
        exit;
    }

    try {
        $pdo->prepare("
            UPDATE output_queue SET status = ? WHERE id = ?
        ")->execute([$status, $id]);

        echo json_encode(['success' => true]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
}


/*
|--------------------------------------------------------------------------
| DEFAULT
|--------------------------------------------------------------------------
*/
echo json_encode([
    'success' => false,
    'message' => 'Unknown action'
]);