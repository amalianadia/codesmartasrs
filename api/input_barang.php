<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../controllers/AuthController.php';

header('Content-Type: application/json');

$auth = new AuthController($pdo);
$auth->requireLogin();

try {
    $nama_barang = trim($_POST['nama_barang'] ?? '');
    $jenis       = trim($_POST['jenis'] ?? '');
    $jumlah      = (int)($_POST['jumlah'] ?? 0);
    $exp_date    = !empty($_POST['exp_date']) ? $_POST['exp_date'] : null;

    // ========================================
    // TAMBAHAN: Debug log untuk troubleshooting
    // ========================================
    error_log("INPUT BARANG REQUEST:");
    error_log("  - Nama: '$nama_barang'");
    error_log("  - Jenis: '$jenis'");
    error_log("  - Jumlah: $jumlah");
    error_log("  - Exp Date: " . ($exp_date ?? 'NULL'));
    error_log("  - User: " . ($_SESSION['nama_lengkap'] ?? 'Unknown'));

    // ========================================
    // PERBAIKAN: Validasi lebih spesifik
    // ========================================
    if ($nama_barang === '') {
        throw new Exception('Nama barang tidak boleh kosong');
    }
    
    if ($jenis === '') {
        throw new Exception('Jenis barang harus dipilih');
    }
    
    if ($jumlah < 1) {
        throw new Exception('Jumlah barang harus minimal 1');
    }
    
    if ($jumlah > 9) {
        throw new Exception('Jumlah maksimal 9 slot');
    }

    // AMBIL SLOT KOSONG (tanpa placeholder LIMIT, biar tidak error di MariaDB lama)
    $limit = (int)$jumlah;
    $sqlSlots = "
        SELECT slot_id, koordinat_x, koordinat_y, koordinat_z
        FROM slot
        WHERE barang_id IS NULL AND status = 'Kosong'
        ORDER BY slot_id
        LIMIT $limit
    ";
    $stmtSlots = $pdo->query($sqlSlots);
    $availableSlots = $stmtSlots->fetchAll(PDO::FETCH_ASSOC);

    if (count($availableSlots) < $jumlah) {
        throw new Exception('Slot tidak cukup. Tersedia: ' . count($availableSlots));
    }

    $pdo->beginTransaction();

    // INSERT BARANG
    $stmtBarang = $pdo->prepare("
        INSERT INTO barang (nama_barang, jenis_barang, jenis, exp_date, status, created_at, updated_at)
        VALUES (?, ?, ?, ?, 'disimpan', NOW(), NOW())
    ");
    $stmtBarang->execute([$nama_barang, $jenis, $jenis, $exp_date]);
    $barang_id = $pdo->lastInsertId();
    if (!$barang_id) {
        throw new Exception('Gagal menyimpan data barang');
    }

    error_log("  - Barang ID created: $barang_id");

    // UPDATE SLOT
    $assignedSlots = [];
    $stmtUpdate = $pdo->prepare("
        UPDATE slot
        SET barang_id = ?, status = 'Terisi', updated_at = NOW()
        WHERE slot_id = ? AND barang_id IS NULL AND status = 'Kosong'
    ");
    foreach ($availableSlots as $slot) {
        $ok = $stmtUpdate->execute([$barang_id, $slot['slot_id']]);
        if ($ok && $stmtUpdate->rowCount() > 0) {
            $assignedSlots[] = $slot;
            error_log("  - Slot assigned: " . $slot['slot_id']);
        } else {
            throw new Exception('Gagal assign slot ' . $slot['slot_id']);
        }
    }

    if (count($assignedSlots) !== $jumlah) {
        throw new Exception('Tidak semua slot berhasil di-assign');
    }

    // HISTORY - DIPERBAIKI: Simpan nama_barang dan jenis
    $stmtHistory = $pdo->prepare("
        INSERT INTO history (barang_id, slot_id, action_type, user_id, nama_barang, jenis, created_at)
        VALUES (?, ?, 'INPUT', ?, ?, ?, NOW())
    ");
    foreach ($assignedSlots as $slot) {
        $stmtHistory->execute([
            $barang_id, 
            $slot['slot_id'], 
            $_SESSION['user_id'],
            $nama_barang,
            $jenis
        ]);
    }

    // QUEUE ROBOT
    $stmtQueue = $pdo->prepare("
        INSERT INTO queue_robot (action, slot, koordinat_x, koordinat_y, koordinat_z, barang_id, status, created_at, updated_at)
        VALUES ('STORE', ?, ?, ?, ?, ?, 'pending', NOW(), NOW())
    ");
    foreach ($assignedSlots as $slot) {
        $stmtQueue->execute([
            $slot['slot_id'],
            $slot['koordinat_x'],
            $slot['koordinat_y'],
            $slot['koordinat_z'],
            $barang_id
        ]);
    }

    $pdo->commit();

    error_log("✅ INPUT BARANG SUCCESS - ID: $barang_id, Slots: " . implode(', ', array_column($assignedSlots, 'slot_id')));

    echo json_encode([
        'success'      => true,
        'message'      => 'Barang berhasil disimpan',
        'barang_id'    => $barang_id,
        'nama_barang'  => $nama_barang,
        'jenis'        => $jenis,
        'jumlah'       => count($assignedSlots),
        'slots'        => array_column($assignedSlots, 'slot_id'),
        'details'      => $assignedSlots,
        'exp_date'     => $exp_date,
        'user'         => $_SESSION['nama_lengkap'] ?? 'Unknown',
        'timestamp'    => date('Y-m-d H:i:s'),
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // ========================================
    // TAMBAHAN: Log error untuk debugging
    // ========================================
    error_log("❌ INPUT BARANG ERROR: " . $e->getMessage());
    error_log("   Stack trace: " . $e->getTraceAsString());

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
