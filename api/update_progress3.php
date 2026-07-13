<?php
/**
 * update_progress.php
 * 
 * Menerima laporan progress dari ESP32 robot.
 * Saat percent >= 100, update slot + history untuk PUT mode.
 * TAKE mode: slot & history sudah dihandle oleh esp32_queue.php
 *            saat update_status=done, TIDAK dihandle di sini
 *            untuk menghindari duplikasi.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

$raw = file_get_contents('php://input');
file_put_contents(__DIR__ . "/debug_progress.txt", date('Y-m-d H:i:s') . " | " . $raw . "\n", FILE_APPEND);

$input = json_decode($raw, true);

// Ambil dari JSON body dulu, fallback ke POST/GET
$commandId = $input['command_id'] ?? $_POST['command_id'] ?? $_GET['command_id'] ?? null;
$step      = $input['step']       ?? $_POST['step']       ?? $_GET['step']       ?? null;
$percent   = $input['percent']    ?? $_POST['percent']    ?? $_GET['percent']    ?? 0;

if (!$commandId || !$step) {
    echo json_encode([
        'success'  => false,
        'message'  => 'Missing required parameters',
        'received' => $input
    ]);
    exit;
}

try {
    // Insert progress log
    $stmt = $pdo->prepare("
        INSERT INTO robot_progress (command_id, step, percent, timestamp)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$commandId, $step, $percent]);

    // Update status robot_commands
    $status = ($percent >= 100) ? 'completed' : 'processing';
    $pdo->prepare("
        UPDATE robot_commands SET status = ?, updated_at = NOW() WHERE id = ?
    ")->execute([$status, $commandId]);

    // -------------------------------------------------------
    // Jika progress 100% → hanya proses PUT mode
    // TAKE mode TIDAK diproses di sini (sudah di esp32_queue.php)
    // -------------------------------------------------------
    if ($percent >= 100) {
        $stmt = $pdo->prepare("
            SELECT command, barcode FROM robot_commands WHERE id = ?
        ");
        $stmt->execute([$commandId]);
        $commandData = $stmt->fetch();

        if ($commandData) {
            $command = $commandData['command'];
            $barcode = $commandData['barcode'];
            $isPut   = is_numeric($command); // '1'-'9' = PUT, 'A'-'I' = TAKE

            if ($isPut) {
                // ---- PUT MODE: update slot + history ----
                $slotMap = [
                    '1' => 'A1', '2' => 'A2', '3' => 'A3',
                    '4' => 'B1', '5' => 'B2', '6' => 'B3',
                    '7' => 'C1', '8' => 'C2', '9' => 'C3'
                ];
                $slotId = $slotMap[$command] ?? null;

                if ($slotId) {
                    // Cari barang_id dari barcode
                    $stmt = $pdo->prepare("SELECT barang_id FROM barang WHERE barcode = ?");
                    $stmt->execute([$barcode]);
                    $barang = $stmt->fetch();

                    if ($barang) {
                        $barangId = $barang['barang_id'];

                        // Update slot
                        $pdo->prepare("
                            UPDATE slot SET barang_id = ?, status = 'Terisi' WHERE slot_id = ?
                        ")->execute([$barangId, $slotId]);

                        // Insert history INPUT
                        $pdo->prepare("
                            INSERT INTO history (barang_id, slot_id, action_type, created_at)
                            VALUES (?, ?, 'INPUT', NOW())
                        ")->execute([$barangId, $slotId]);

                        // Log aktivitas
                        $pdo->prepare("
                            INSERT INTO log_aktivitas (user_id, aktivitas, created_at)
                            VALUES (1, ?, NOW())
                        ")->execute(["Robot PUT selesai: Barcode $barcode disimpan di slot $slotId"]);

                    } else {
                        // Barcode tidak ditemukan di tabel barang — log warning
                        $pdo->prepare("
                            INSERT INTO log_aktivitas (user_id, aktivitas, created_at)
                            VALUES (1, ?, NOW())
                        ")->execute(["Robot PUT WARNING: Barcode $barcode tidak ditemukan di tabel barang (slot $slotId)"]);
                    }
                }

            }
            // TAKE mode: tidak ada action di sini
            // slot + history sudah dihandle oleh esp32_queue.php update_status=done
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "Progress updated: $step ($percent%)",
        'status'  => $status
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}