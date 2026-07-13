<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/db.php';

ob_clean();
header('Content-Type: text/plain');

$barcode = isset($_GET['barcode']) ? trim($_GET['barcode']) : null;
$debug   = isset($_GET['debug']);

function dbg($msg) {
    global $debug;
    if ($debug) echo "[DEBUG] $msg\n";
}

if (!$barcode) {
    dbg("STOP: barcode kosong");
    echo '0'; exit;
}

dbg("Barcode: $barcode");

try {
    $pdo->beginTransaction();

    // 1. Cari barang
    $stmt = $pdo->prepare("SELECT barang_id FROM barang WHERE barcode = ? LIMIT 1");
    $stmt->execute([$barcode]);
    $barang = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$barang) {
        $pdo->rollBack();
        dbg("STOP: barcode tidak ditemukan di tabel barang");
        echo '0'; exit;
    }
    $barangId = (int)$barang['barang_id'];
    dbg("barang_id = $barangId");

    // ✅ TIDAK ada cek "barang sudah di slot" — karena 1 barang bisa masuk berkali-kali

    // 2. Cari slot kosong
    $stmt = $pdo->query("
        SELECT slot_id FROM slot
        WHERE barang_id IS NULL
        ORDER BY slot_id
        LIMIT 1
        FOR UPDATE
    ");
    $emptySlot = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emptySlot) {
        $pdo->rollBack();
        dbg("STOP: tidak ada slot kosong");
        echo '0'; exit;
    }
    $slotId = $emptySlot['slot_id'];
    dbg("Slot kosong: $slotId");

    // 3. Map slot ke command
    $commandMap = [
        'A1'=>'1','A2'=>'2','A3'=>'3',
        'B1'=>'4','B2'=>'5','B3'=>'6',
        'C1'=>'7','C2'=>'8','C3'=>'9',
    ];
    $command = $commandMap[$slotId] ?? null;
    if (!$command) {
        $pdo->rollBack();
        dbg("STOP: slot '$slotId' tidak ada di commandMap");
        echo '0'; exit;
    }
    dbg("Command: $command");

    // 4. Update slot — tandai terpakai
    $stmt = $pdo->prepare("UPDATE slot SET barang_id = ? WHERE slot_id = ? AND barang_id IS NULL");
    $stmt->execute([$barangId, $slotId]);
    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        dbg("STOP: UPDATE slot gagal (race condition?)");
        echo '0'; exit;
    }
    dbg("Slot updated OK");

    // 5. Insert history INPUT
    $stmt = $pdo->prepare("
        INSERT INTO history (barang_id, slot_id, action_type, created_at)
        VALUES (?, ?, 'INPUT', NOW())
    ");
    $stmt->execute([$barangId, $slotId]);
    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        dbg("STOP: INSERT history gagal");
        echo '0'; exit;
    }
    dbg("History OK");

    // 6. Insert robot_commands
    $stmt = $pdo->prepare("
        INSERT INTO robot_commands (command, barcode, status, created_at, updated_at)
        VALUES (?, ?, 'pending', NOW(), NOW())
    ");
    $stmt->execute([$command, $barcode]);
    dbg("robot_commands OK, id=" . $pdo->lastInsertId());

    // 7. Log aktivitas
    $pdo->prepare("
        INSERT INTO log_aktivitas (user_id, aktivitas, created_at)
        VALUES (1, ?, NOW())
    ")->execute(["Scan INPUT: barcode=$barcode -> slot=$slotId cmd=$command"]);
    dbg("log_aktivitas OK");

    $pdo->commit();
    dbg("COMMIT OK!");

    echo $command;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    dbg("PDO EXCEPTION: " . $e->getMessage());
    error_log("[robot_api] Error: " . $e->getMessage() . " | barcode=$barcode");
    echo '0';
}