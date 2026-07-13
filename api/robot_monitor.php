<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

try {
    // ✅ FIX: Ambil command yang punya progress terbaru ATAU status active
    // Prioritas: command yang punya robot_progress terbaru dalam 5 menit terakhir
    $cmd = null;

    // Coba cari command yang punya progress aktif terbaru (dalam 10 menit terakhir)
    $stmt = $pdo->query("
        SELECT rc.id, rc.command, rc.barcode, rc.status,
               b.nama_barang, b.barcode as item_barcode
        FROM robot_commands rc
        LEFT JOIN barang b ON rc.barcode = b.barcode
        WHERE rc.id = (
            SELECT command_id FROM robot_progress
            WHERE timestamp >= NOW() - INTERVAL 10 MINUTE
            ORDER BY timestamp DESC
            LIMIT 1
        )
        LIMIT 1
    ");
    $cmd = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fallback 1: command dengan status processing/pending
    if (!$cmd) {
        $stmt = $pdo->query("
            SELECT rc.id, rc.command, rc.barcode, rc.status,
                   b.nama_barang, b.barcode as item_barcode
            FROM robot_commands rc
            LEFT JOIN barang b ON rc.barcode = b.barcode
            WHERE rc.status IN ('processing','pending')
            ORDER BY rc.created_at DESC
            LIMIT 1
        ");
        $cmd = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Fallback 2: command terbaru apapun statusnya
    if (!$cmd) {
        $stmt = $pdo->query("
            SELECT rc.id, rc.command, rc.barcode, rc.status,
                   b.nama_barang, b.barcode as item_barcode
            FROM robot_commands rc
            LEFT JOIN barang b ON rc.barcode = b.barcode
            ORDER BY rc.created_at DESC
            LIMIT 1
        ");
        $cmd = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // 2. Ambil semua progress steps untuk command ini
    $allSteps       = [];
    $latestProgress = null;
    if ($cmd) {
        $stmt = $pdo->prepare("
            SELECT step, percent, timestamp
            FROM robot_progress
            WHERE command_id = ?
            ORDER BY timestamp ASC
        ");
        $stmt->execute([$cmd['id']]);
        $allSteps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($allSteps)) {
            $latestProgress = end($allSteps);
        }
    }

    // 3. Queue stats
    $stats = [];
    foreach (['pending', 'processing', 'done'] as $s) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM output_queue WHERE status = ?");
        $st->execute([$s]);
        $stats[$s] = (int)$st->fetchColumn();
    }

    // 4. ✅ Status dari progress percent, bukan command.status
    $robotStatus = 'idle';
    $percent     = 0;
    $step        = 'Menunggu...';

    if ($latestProgress) {
        $percent = (int)$latestProgress['percent'];
        $step    = $latestProgress['step'];
        if ($percent >= 100)  $robotStatus = 'completed';
        elseif ($percent > 0) $robotStatus = 'processing';
    }

    if ($cmd && in_array($cmd['status'], ['pending', 'processing']) && $robotStatus === 'idle') {
        $robotStatus = 'processing';
    }

    // 5. Active output_queue item
    $activeQueue = $pdo->query("
        SELECT oq.slot_id, b.nama_barang, b.barcode
        FROM output_queue oq
        LEFT JOIN barang b ON oq.barang_id = b.barang_id
        WHERE oq.status IN ('processing','pending')
        ORDER BY oq.created_at ASC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    // 6. Latest activity
    $activities = $pdo->query("
        SELECT h.action_type, h.created_at, h.slot_id as history_slot_id,
               COALESCE(b.nama_barang, CONCAT('Item #', h.barang_id)) as nama_barang,
               COALESCE(b.barcode, 'No Barcode') as barcode
        FROM history h
        LEFT JOIN barang b ON h.barang_id = b.barang_id
        ORDER BY h.created_at DESC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 7. Display info
    $displayName    = $activeQueue ? ($activeQueue['nama_barang'] ?? 'N/A')
                                   : ($cmd ? ($cmd['nama_barang'] ?? 'N/A') : '—');
    $displayBarcode = $activeQueue ? ($activeQueue['barcode'] ?? '')
                                   : ($cmd ? ($cmd['item_barcode'] ?? '') : '');
    $activeSlot     = $activeQueue ? $activeQueue['slot_id'] : null;

    echo json_encode([
        'success'     => true,
        'command_id'  => $cmd ? (int)$cmd['id'] : null,
        'status'      => $robotStatus,
        'percent'     => $percent,
        'step'        => $step,
        'nama_barang' => $displayName,
        'barcode'     => $displayBarcode,
        'active_slot' => $activeSlot,
        'steps'       => $allSteps,
        'stats'       => $stats,
        'activities'  => $activities,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}