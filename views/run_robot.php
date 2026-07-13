    <?php
    session_start();
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../controllers/AuthController.php';

    $auth = new AuthController($pdo);
    $auth->requireLogin();

    // Ambil command aktif (processing/pending), fallback ke terbaru
    $stmt = $pdo->query("
        SELECT rc.*, b.nama_barang, b.barcode as item_barcode
        FROM robot_commands rc
        LEFT JOIN barang b ON rc.barcode = b.barcode
        WHERE rc.status IN ('processing','pending')
        ORDER BY rc.created_at DESC
        LIMIT 1
    ");
    $latestCommand = $stmt->fetch();

    if (!$latestCommand) {
        $stmt = $pdo->query("
            SELECT rc.*, b.nama_barang, b.barcode as item_barcode
            FROM robot_commands rc
            LEFT JOIN barang b ON rc.barcode = b.barcode
            ORDER BY rc.created_at DESC
            LIMIT 1
        ");
        $latestCommand = $stmt->fetch();
    }

    // Get progress steps
    $currentProgress = null;
    $progressSteps   = [];
    if ($latestCommand) {
        $stmt = $pdo->prepare("
            SELECT * FROM robot_progress
            WHERE command_id = ?
            ORDER BY timestamp ASC
        ");
        $stmt->execute([$latestCommand['id']]);
        $progressSteps = $stmt->fetchAll();

        $stmt2 = $pdo->prepare("
            SELECT * FROM robot_progress
            WHERE command_id = ?
            ORDER BY timestamp DESC LIMIT 1
        ");
        $stmt2->execute([$latestCommand['id']]);
        $currentProgress = $stmt2->fetch();
    }

    // ✅ FIX: Hitung percent & step awal yang benar untuk render pertama
    $initialPercent = $currentProgress ? (int)$currentProgress['percent'] : 0;
    $initialStep    = $currentProgress ? $currentProgress['step'] : 'Menunggu...';

    // Status robot dari progress (bukan dari command status)
    $robotStatus = 'idle';
    if ($currentProgress) {
        $pct = (int)$currentProgress['percent'];
        if ($pct >= 100)     $robotStatus = 'completed';
        elseif ($pct > 0)    $robotStatus = 'processing';
    }
    if ($latestCommand && in_array($latestCommand['status'], ['pending','processing']) && $robotStatus === 'idle') {
        $robotStatus = 'processing';
    }

    // Get latest activity — INPUT & OUTPUT
    $stmt = $pdo->query("
        SELECT h.history_id, h.action_type, h.created_at, h.barang_id,
            h.slot_id as history_slot_id,
            COALESCE(b.nama_barang, CONCAT('Item #', h.barang_id)) as nama_barang,
            COALESCE(b.barcode, 'No Barcode') as barcode
        FROM history h
        LEFT JOIN barang b ON h.barang_id = b.barang_id
        ORDER BY h.created_at DESC LIMIT 10
    ");
    $latestActivity = $stmt->fetchAll();

    // Queue stats dari output_queue
    $pendingCount    = $pdo->query("SELECT COUNT(*) FROM output_queue WHERE status='pending'")->fetchColumn();
    $processingCount = $pdo->query("SELECT COUNT(*) FROM output_queue WHERE status='processing'")->fetchColumn();
    $doneCount       = $pdo->query("SELECT COUNT(*) FROM output_queue WHERE status='done'")->fetchColumn();

    // Active queue item
    $activeQueue = $pdo->query("
        SELECT oq.*, b.nama_barang, b.barcode
        FROM output_queue oq
        LEFT JOIN barang b ON oq.barang_id = b.barang_id
        WHERE oq.status IN ('processing','pending')
        ORDER BY oq.created_at ASC LIMIT 1
    ")->fetch();

    // Nama & barcode untuk display
    $displayName    = $activeQueue ? ($activeQueue['nama_barang'] ?? 'N/A')
                                : ($latestCommand ? ($latestCommand['nama_barang'] ?? 'N/A') : '—');
    $displayBarcode = $activeQueue ? ($activeQueue['barcode'] ?? '')
                                : ($latestCommand ? ($latestCommand['item_barcode'] ?? '') : '');
    $displaySlot    = $activeQueue ? $activeQueue['slot_id'] : null;
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Robot Monitor - Automated Storage</title>
        <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }

            body {
                font-family: 'Poppins', sans-serif;
                background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
                min-height: 100vh;
                color: #1a1a1a;
                overflow-x: hidden;
            }

            .navbar {
                background: rgba(255,255,255,0.98);
                padding: 1.2rem 2rem;
                box-shadow: 0 4px 20px rgba(0,0,0,0.08), 0 0 40px rgba(255,193,7,0.12);
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-bottom: 3px solid #ffc107;
                position: sticky;
                top: 0;
                z-index: 100;
                backdrop-filter: blur(20px);
            }

            .navbar h1 {
                font-size: 1.5rem;
                font-weight: 800;
                color: #1a1a1a;
                display: flex;
                align-items: center;
                gap: 0.5rem;
            }

            .navbar h1 img {
                width: 38px; height: 38px;
                border-radius: 10px;
                object-fit: cover;
            }

            .navbar-menu { display: flex; align-items: center; gap: 0.8rem; }

            .navbar-menu a {
                text-decoration: none;
                color: #666;
                font-weight: 600;
                transition: all 0.3s;
                padding: 0.6rem 1.2rem;
                border-radius: 10px;
                font-size: 0.9rem;
            }

            .navbar-menu a:hover, .navbar-menu a.active {
                color: #1a1a1a;
                background: rgba(255,193,7,0.15);
                box-shadow: 0 0 20px rgba(255,193,7,0.25);
            }

            .user-info {
                display: flex;
                align-items: center;
                gap: 1rem;
                padding-left: 1.5rem;
                border-left: 2px solid rgba(255,193,7,0.3);
            }

            .user-info span { font-weight: 600; color: #1a1a1a; font-size: 0.9rem; }
            .user-info span::before { content: '👤'; margin-right: 0.4rem; }

            .btn-danger {
                background: linear-gradient(135deg, #ef4444, #dc2626);
                color: white;
                border: none;
                padding: 0.6rem 1.2rem;
                border-radius: 10px;
                font-weight: 700;
                font-size: 0.9rem;
                box-shadow: 0 4px 15px rgba(239,68,68,0.4);
                text-decoration: none;
                transition: all 0.3s;
            }

            .btn-danger:hover { transform: translateY(-2px); box-shadow: 0 6px 25px rgba(239,68,68,0.6); }

            .container { max-width: 1800px; margin: 0 auto; padding: 1.5rem; }

            .grid-main {
                display: grid;
                grid-template-columns: 1.5fr 1fr;
                gap: 1.5rem;
                margin-bottom: 1.5rem;
            }

            .card {
                background: rgba(255,255,255,0.95);
                border-radius: 24px;
                border: 2px solid rgba(255,193,7,0.3);
                padding: 2rem;
                box-shadow: 0 10px 40px rgba(0,0,0,0.08), 0 0 50px rgba(255,193,7,0.1);
                transition: all 0.3s;
                position: relative;
                overflow: hidden;
            }

            .card::before {
                content: '';
                position: absolute;
                top: 0; left: 0; width: 100%; height: 100%;
                background: radial-gradient(circle at 50% 0%, rgba(255,193,7,0.06), transparent 70%);
                pointer-events: none;
            }

            .card:hover {
                border-color: #ffc107;
                box-shadow: 0 12px 48px rgba(255,193,7,0.2);
                transform: translateY(-2px);
            }

            .card h2 {
                font-size: 1.5rem;
                font-weight: 800;
                color: #1a1a1a;
                margin-bottom: 0.3rem;
                display: flex;
                align-items: center;
                gap: 0.7rem;
            }

            .card > p {
                color: #888;
                margin-bottom: 1.5rem;
                font-size: 0.88rem;
                font-weight: 500;
            }

            .robot-hero {
                display: flex;
                align-items: center;
                gap: 1.5rem;
                padding: 1.2rem 0;
                border-bottom: 2px solid rgba(255,193,7,0.12);
                margin-bottom: 1.5rem;
            }

            .robot-icon-wrap {
                font-size: 80px;
                flex-shrink: 0;
                filter: drop-shadow(0 0 16px rgba(255,193,7,0.5));
                animation: robotFloat 3s ease-in-out infinite;
            }

            @keyframes robotFloat {
                0%,100% { transform: translateY(0); }
                50%      { transform: translateY(-8px); }
            }

            .robot-hero-info { flex: 1; }

            .status-indicator {
                display: inline-flex;
                align-items: center;
                gap: 0.6rem;
                padding: 0.6rem 1.5rem;
                border-radius: 60px;
                font-size: 0.95rem;
                font-weight: 800;
                margin-bottom: 0.7rem;
            }

            .status-pulse {
                width: 9px; height: 9px;
                border-radius: 50%;
                animation: sPulse 1.5s ease infinite;
            }

            @keyframes sPulse {
                0%,100% { opacity:1; transform:scale(1); }
                50%      { opacity:0.4; transform:scale(1.4); }
            }

            .status-idle       { background:rgba(150,150,150,0.12); border:2px solid rgba(150,150,150,0.35); color:#777; }
            .status-idle .status-pulse { background:#999; }

            .status-processing {
                background:rgba(16,185,129,0.12);
                border:2px solid rgba(16,185,129,0.45);
                color:#059669;
                animation: sGlow 2s ease infinite;
            }
            .status-processing .status-pulse { background:#10b981; }

            @keyframes sGlow {
                0%,100% { box-shadow:0 4px 16px rgba(16,185,129,0.25); }
                50%      { box-shadow:0 4px 28px rgba(16,185,129,0.55); }
            }

            .status-completed { background:rgba(59,130,246,0.12); border:2px solid rgba(59,130,246,0.45); color:#2563eb; }
            .status-completed .status-pulse { background:#3b82f6; }

            .current-item-label { font-size:0.8rem; color:#aaa; font-weight:600; }
            .current-item-name  { font-size:1.2rem; font-weight:800; color:#1a1a1a; margin-top:0.15rem; }
            .current-item-sub   { font-size:0.8rem; color:#bbb; font-weight:600; font-family:'Courier New',monospace; }

            .active-slot-badge {
                display: inline-flex;
                align-items: center;
                gap: 0.4rem;
                background: linear-gradient(135deg, #ffc107, #ffcd38);
                color: #1a1a1a;
                border-radius: 10px;
                padding: 0.3rem 0.9rem;
                font-weight: 800;
                font-size: 0.85rem;
                margin-top: 0.4rem;
                box-shadow: 0 3px 10px rgba(255,193,7,0.35);
            }

            .step-progress-wrap { margin-bottom: 1.5rem; }

            .step-progress-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 0.5rem;
            }

            .step-label { font-size:0.88rem; font-weight:700; color:#555; }
            .step-pct   { font-size:1.05rem; font-weight:900; color:#ffc107; }

            .progress-bar {
                width: 100%;
                height: 34px;
                background: #f3f3f3;
                border-radius: 17px;
                overflow: hidden;
                border: 2px solid rgba(255,193,7,0.2);
                box-shadow: inset 0 2px 6px rgba(0,0,0,0.05);
            }

            .progress-fill {
                height: 100%;
                background: linear-gradient(90deg, #ffc107, #ffcd38);
                border-radius: 17px;
                transition: width 0.7s cubic-bezier(0.4,0,0.2,1);
                display: flex;
                align-items: center;
                justify-content: center;
                color: #1a1a1a;
                font-weight: 800;
                font-size: 0.82rem;
                position: relative;
                box-shadow: 0 0 16px rgba(255,193,7,0.4);
                min-width: 38px;
            }

            .progress-fill::after {
                content: '';
                position: absolute;
                inset: 0;
                background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
                animation: shine 2s infinite;
            }

            @keyframes shine {
                0%   { transform:translateX(-100%); }
                100% { transform:translateX(100%); }
            }

            .step-name-badge {
                display: inline-flex;
                align-items: center;
                gap: 0.4rem;
                background: rgba(255,193,7,0.1);
                border: 1.5px solid rgba(255,193,7,0.35);
                border-radius: 9px;
                padding: 0.45rem 0.9rem;
                font-size: 0.82rem;
                font-weight: 700;
                color: #b8860b;
                margin-top: 0.7rem;
            }

            .step-dot {
                width:7px; height:7px;
                border-radius:50%;
                background:#ffc107;
                animation: sPulse 1.2s ease infinite;
            }

            .stats-grid {
                display: grid;
                grid-template-columns: repeat(3,1fr);
                gap: 0.8rem;
                margin-bottom: 1.5rem;
            }

            .stat-item {
                background: rgba(255,193,7,0.06);
                border: 2px solid rgba(255,193,7,0.2);
                border-radius: 13px;
                padding: 1rem;
                text-align: center;
                transition: all 0.3s;
            }

            .stat-item:hover {
                background: rgba(255,193,7,0.12);
                transform: translateY(-2px);
                box-shadow: 0 6px 20px rgba(255,193,7,0.18);
            }

            .stat-value { font-size:2rem; font-weight:900; color:#1a1a1a; }
            .stat-label { font-size:0.75rem; color:#999; margin-top:0.25rem; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; }

            .stat-pending .stat-value    { color:#f59e0b; }
            .stat-processing .stat-value { color:#10b981; }
            .stat-done .stat-value       { color:#3b82f6; }

            .timeline-wrap {
                max-height: 340px;
                overflow-y: auto;
                padding-right: 0.3rem;
            }

            .timeline-wrap::-webkit-scrollbar { width:4px; }
            .timeline-wrap::-webkit-scrollbar-thumb { background:rgba(255,193,7,0.4); border-radius:4px; }

            .timeline {
                position: relative;
                padding-left: 1.4rem;
            }

            .timeline::before {
                content: '';
                position: absolute;
                left: 6px; top: 0; bottom: 0;
                width: 2px;
                background: linear-gradient(to bottom, #ffc107, rgba(255,193,7,0.08));
            }

            .timeline-item {
                position: relative;
                margin-bottom: 0.65rem;
            }

            .timeline-dot {
                position: absolute;
                left: -1.4rem;
                top: 5px;
                width: 13px; height: 13px;
                border-radius: 50%;
                border: 2px solid #fff;
            }

            .timeline-dot.done   { background:#10b981; box-shadow:0 0 0 2px rgba(16,185,129,0.35); }
            .timeline-dot.active { background:#ffc107; box-shadow:0 0 0 3px rgba(255,193,7,0.4); animation:sPulse 1s ease infinite; }

            .timeline-content {
                background: #fafafa;
                border: 1.5px solid rgba(255,193,7,0.18);
                border-radius: 9px;
                padding: 0.5rem 0.9rem;
                transition: all 0.3s;
            }

            .timeline-content.done   { border-color:rgba(16,185,129,0.28); background:rgba(16,185,129,0.03); }
            .timeline-content.active { border-color:rgba(255,193,7,0.45); background:rgba(255,193,7,0.06); }

            .tl-row { display:flex; justify-content:space-between; align-items:center; }
            .tl-step { font-size:0.82rem; font-weight:700; color:#1a1a1a; }
            .tl-pct  { font-size:0.78rem; font-weight:700; color:#aaa; }
            .tl-time { font-size:0.7rem; color:#ccc; font-weight:500; margin-top:0.1rem; }

            .timeline-content.done   .tl-step { color:#059669; }
            .timeline-content.active .tl-step { color:#b8860b; }

            .activity-list {
                display: flex;
                flex-direction: column;
                gap: 0.65rem;
                max-height: 520px;
                overflow-y: auto;
                padding-right: 0.3rem;
            }

            .activity-list::-webkit-scrollbar { width:4px; }
            .activity-list::-webkit-scrollbar-thumb { background:rgba(255,193,7,0.4); border-radius:4px; }

            .activity-item {
                background: #fafafa;
                border: 2px solid rgba(255,193,7,0.18);
                border-radius: 11px;
                padding: 0.85rem 1rem;
                display: flex;
                justify-content: space-between;
                align-items: center;
                transition: all 0.3s;
                position: relative;
                overflow: hidden;
            }

            .activity-item::before {
                content:'';
                position:absolute;
                left:0; top:0;
                width:0; height:100%;
                transition:width 0.3s;
            }

            .activity-item.input::before  { background:linear-gradient(135deg,#10b981,#059669); }
            .activity-item.output::before { background:linear-gradient(135deg,#f59e0b,#d97706); }

            .activity-item:hover { transform:translateX(4px); background:#fff; box-shadow:0 3px 14px rgba(255,193,7,0.18); }
            .activity-item:hover::before { width:4px; }

            .activity-left { display:flex; align-items:center; gap:0.8rem; flex:1; }

            .activity-badge {
                padding:0.22rem 0.65rem;
                border-radius:7px;
                font-size:0.66rem;
                font-weight:800;
                text-transform:uppercase;
                letter-spacing:0.4px;
                white-space:nowrap;
            }

            .badge-input  { background:rgba(16,185,129,0.1);  color:#059669; border:1.5px solid rgba(16,185,129,0.28); }
            .badge-output { background:rgba(245,158,11,0.1);  color:#d97706; border:1.5px solid rgba(245,158,11,0.28); }

            .activity-info h4 { font-size:0.88rem; margin-bottom:0.18rem; color:#1a1a1a; font-weight:700; }

            .activity-meta { display:flex; align-items:center; gap:0.6rem; font-size:0.7rem; }
            .activity-barcode { font-family:'Courier New',monospace; color:#999; font-weight:600; }
            .activity-time    { color:#ccc; font-weight:500; }

            .activity-slot {
                background: linear-gradient(135deg,#ffc107,#ffcd38);
                color: #1a1a1a;
                padding: 0.38rem 0.85rem;
                border-radius: 8px;
                font-weight: 800;
                font-size: 0.82rem;
                box-shadow: 0 3px 8px rgba(255,193,7,0.28);
                white-space: nowrap;
            }

            .live-badge {
                display: inline-flex;
                align-items: center;
                gap: 0.35rem;
                background: rgba(239,68,68,0.08);
                border: 1.5px solid rgba(239,68,68,0.35);
                color: #dc2626;
                border-radius: 20px;
                padding: 0.22rem 0.75rem;
                font-size:0.7rem;
                font-weight:800;
                text-transform:uppercase;
                letter-spacing:0.4px;
            }

            .live-dot {
                width:6px; height:6px;
                background:#ef4444;
                border-radius:50%;
                animation: sPulse 1s ease infinite;
            }

            .empty-state { text-align:center; padding:2.5rem 1.5rem; color:#ccc; }
            .empty-icon { font-size:3.5rem; margin-bottom:0.8rem; opacity:0.45; animation:emptyFloat 3s ease infinite; }

            @keyframes emptyFloat {
                0%,100% { transform:translateY(0); }
                50%      { transform:translateY(-7px); }
            }

            .empty-state h3 { color:#bbb; font-weight:700; margin-bottom:0.3rem; font-size:0.95rem; }
            .empty-state p  { font-size:0.82rem; }

            .section-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 0.8rem;
            }

            .section-title { font-size:0.8rem; font-weight:700; color:#aaa; text-transform:uppercase; letter-spacing:0.5px; }

            @media (max-width:1200px) { .grid-main { grid-template-columns:1fr; } }

            @media (max-width:768px) {
                .navbar { flex-direction:column; gap:1rem; padding:1rem; }
                .navbar-menu { flex-direction:column; width:100%; }
                .user-info { border-left:none; border-top:2px solid rgba(255,193,7,0.3); padding-left:0; padding-top:1rem; justify-content:center; }
                .container { padding:1rem; }
                .stats-grid { grid-template-columns:repeat(3,1fr); }
                .card { padding:1.2rem; }
                .robot-hero { flex-direction:column; text-align:center; }
            }
        </style>
    </head>
    <body>

    <nav class="navbar">
        <h1>
            <img src="../assets/img/robot-icon.png" alt="logo" onerror="this.style.display='none'">
            Automated Storage
        </h1>
        <div class="navbar-menu">
            <a href="dashboard.php">Dashboard</a>
            <a href="input.php">Input Barang</a>
            <a href="output.php">Output Barang</a>
            <a href="run_robot.php" class="active">🤖 Robot Monitor</a>
            <a href="history.php">History</a>
            <div class="user-info">
                <span><?= htmlspecialchars($_SESSION['nama_lengkap']) ?></span>
                <a href="../index.php?action=logout" class="btn-danger">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="grid-main">

            <!-- LEFT: Robot Progress -->
            <div class="card">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:0.3rem;">
                    <h2>📡 Robot Progress</h2>
                    <span class="live-badge"><span class="live-dot"></span>Live</span>
                </div>
                <p>Status dan progres robot secara real-time</p>

                <div class="robot-hero">
                    <div class="robot-icon-wrap" id="robotIcon">🤖</div>
                    <div class="robot-hero-info">
                        <?php
                        $statusClass = 'status-idle';
                        $statusText  = 'IDLE';
                        if ($robotStatus === 'processing') { $statusClass = 'status-processing'; $statusText = 'PROCESSING'; }
                        if ($robotStatus === 'completed')  { $statusClass = 'status-completed';  $statusText = 'COMPLETED'; }
                        ?>
                        <div class="status-indicator <?= $statusClass ?>" id="statusIndicator">
                            <div class="status-pulse"></div>
                            <span id="statusText"><?= $statusText ?></span>
                        </div>
                        <div class="current-item-label">Item Aktif</div>
                        <div class="current-item-name" id="currentItemName">
                            <?= htmlspecialchars($displayName) ?>
                        </div>
                        <div class="current-item-sub" id="currentItemSub">
                            <?= htmlspecialchars($displayBarcode) ?>
                        </div>
                        <div class="active-slot-badge" id="activeSlotBadge"
                            style="<?= $displaySlot ? '' : 'display:none;' ?>">
                            📦 Slot: <?= htmlspecialchars($displaySlot ?? '—') ?>
                        </div>
                    </div>
                </div>

                <!-- Step Progress Bar -->
                <div class="step-progress-wrap">
                    <div class="step-progress-header">
                        <span class="step-label" id="stepLabel">
                            <?= htmlspecialchars($initialStep) ?>
                        </span>
                        <span class="step-pct" id="stepPct">
                            <?= $initialPercent ?>%
                        </span>
                    </div>
                    <div class="progress-bar">
                        <!-- ✅ FIX: Pakai $initialPercent bukan $currentProgress['percent'] langsung -->
                        <div class="progress-fill" id="progressFill"
                            style="width:<?= $initialPercent ?>%">
                            <span id="progressPct"><?= $initialPercent ?>%</span>
                        </div>
                    </div>
                    <div>
                        <span class="step-name-badge">
                            <span class="step-dot"></span>
                            <span id="stepNameText"><?= htmlspecialchars($initialStep) ?></span>
                        </span>
                    </div>
                </div>

                <!-- Queue Stats -->
                <div class="stats-grid">
                    <div class="stat-item stat-pending">
                        <div class="stat-value" id="statPending"><?= $pendingCount ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                    <div class="stat-item stat-processing">
                        <div class="stat-value" id="statProcessing"><?= $processingCount ?></div>
                        <div class="stat-label">Processing</div>
                    </div>
                    <div class="stat-item stat-done">
                        <div class="stat-value" id="statDone"><?= $doneCount ?></div>
                        <div class="stat-label">Done</div>
                    </div>
                </div>

                <!-- Step Timeline -->
                <div class="section-header">
                    <span class="section-title">
                        Step Timeline — Command #<span id="timelineCmdId">
                            <?= $latestCommand ? $latestCommand['id'] : '—' ?>
                        </span>
                    </span>
                </div>
                <div class="timeline-wrap">
                    <div class="timeline" id="stepTimeline">
                        <?php if (empty($progressSteps)): ?>
                            <div class="empty-state" style="padding:1.2rem;">
                                <div class="empty-icon">⏳</div>
                                <p>Belum ada progress</p>
                            </div>
                        <?php else: ?>
                            <?php $lastIdx = count($progressSteps) - 1; ?>
                            <?php foreach ($progressSteps as $i => $step): ?>
                                <?php $cls = ($i === $lastIdx) ? 'active' : 'done'; ?>
                                <div class="timeline-item">
                                    <span class="timeline-dot <?= $cls ?>"></span>
                                    <div class="timeline-content <?= $cls ?>">
                                        <div class="tl-row">
                                            <span class="tl-step"><?= htmlspecialchars($step['step']) ?></span>
                                            <span class="tl-pct"><?= $step['percent'] ?>%</span>
                                        </div>
                                        <div class="tl-time"><?= date('H:i:s', strtotime($step['timestamp'])) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Latest Activity -->
            <div class="card">
                <h2>📊 Latest Activity</h2>
                <p>Real-time feed dari robot automation</p>

                <?php if (empty($latestActivity)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📭</div>
                        <p>Belum ada aktivitas</p>
                    </div>
                <?php else: ?>
                    <div class="activity-list" id="activityList">
                        <?php foreach ($latestActivity as $act): ?>
                            <div class="activity-item <?= strtolower($act['action_type']) ?>">
                                <div class="activity-left">
                                    <span class="activity-badge badge-<?= strtolower($act['action_type']) ?>">
                                        <?= $act['action_type'] === 'INPUT' ? '📥 IN' : '📤 OUT' ?>
                                    </span>
                                    <div class="activity-info">
                                        <h4><?= htmlspecialchars($act['nama_barang']) ?></h4>
                                        <div class="activity-meta">
                                            <span class="activity-barcode"><?= htmlspecialchars($act['barcode']) ?></span>
                                            <span class="activity-time"><?= date('H:i:s', strtotime($act['created_at'])) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="activity-slot"><?= htmlspecialchars($act['history_slot_id'] ?? 'N/A') ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    const POLL_INTERVAL = 2000;

    // ✅ FIX: Track state dengan benar dari PHP render awal
    let lastCmdId    = <?= $latestCommand ? (int)$latestCommand['id'] : 'null' ?>;
    let lastStepHash = '<?= implode('|', array_map(fn($s) => $s['step'].$s['percent'].$s['timestamp'], $progressSteps)) ?>';
    let isPolling    = false;

    // ================================================
    // UPDATE PROGRESS UI — selalu dijalankan tanpa syarat
    // ================================================
    function updateProgressUI(data) {
        // ✅ FIX: Tangani 0 dengan benar (jangan skip kalau percent = 0)
        const pct  = (data.percent !== undefined && data.percent !== null) ? parseInt(data.percent) : 0;
        const step = data.step || 'Menunggu...';
        const status = data.status || 'idle';

        document.getElementById('progressFill').style.width = pct + '%';
        document.getElementById('progressPct').textContent  = pct + '%';
        document.getElementById('stepPct').textContent      = pct + '%';
        document.getElementById('stepLabel').textContent    = step;
        document.getElementById('stepNameText').textContent = step;

        // Status badge
        const ind = document.getElementById('statusIndicator');
        let cls = 'status-idle', txt = 'IDLE';
        if      (status === 'processing') { cls = 'status-processing'; txt = 'PROCESSING'; }
        else if (status === 'completed')  { cls = 'status-completed';  txt = 'COMPLETED'; }
        ind.className = 'status-indicator ' + cls;
        document.getElementById('statusText').textContent = txt;

        // Kecepatan animasi robot
        document.getElementById('robotIcon').style.animation =
            status === 'processing'
                ? 'robotFloat 0.8s ease-in-out infinite'
                : 'robotFloat 3s ease-in-out infinite';

        // Info item
        if (data.nama_barang) document.getElementById('currentItemName').textContent = data.nama_barang;
        if (data.barcode !== undefined) document.getElementById('currentItemSub').textContent = data.barcode;

        // Slot badge
        const slotBadge = document.getElementById('activeSlotBadge');
        if (data.active_slot) {
            slotBadge.innerHTML = '📦 Slot: ' + escHtml(String(data.active_slot));
            slotBadge.style.display = 'inline-flex';
        } else {
            slotBadge.style.display = 'none';
        }

        // Command ID label
        if (data.command_id) {
            document.getElementById('timelineCmdId').textContent = data.command_id;
        }
    }

    // ================================================
    // UPDATE STATS
    // ================================================
    function updateStats(stats) {
        if (!stats) return;
        animateNumber('statPending',    stats.pending);
        animateNumber('statProcessing', stats.processing);
        animateNumber('statDone',       stats.done);
    }

    function animateNumber(id, newVal) {
        const el = document.getElementById(id);
        if (!el || parseInt(el.textContent) === newVal) return;
        el.style.transform  = 'scale(1.3)';
        el.style.transition = 'transform 0.2s ease';
        el.textContent      = newVal;
        setTimeout(() => { el.style.transform = 'scale(1)'; }, 200);
    }

    // ================================================
    // BUILD TIMELINE
    // ================================================
    function buildTimeline(steps) {
        const tl = document.getElementById('stepTimeline');
        if (!steps || steps.length === 0) {
            tl.innerHTML = `<div class="empty-state" style="padding:1.2rem;">
                <div class="empty-icon">⏳</div><p>Belum ada progress</p></div>`;
            return;
        }
        let html = '';
        steps.forEach((s, i) => {
            const isLast = i === steps.length - 1;
            const cls    = isLast ? 'active' : 'done';
            const time   = s.timestamp ? s.timestamp.substr(11, 8) : '';
            html += `
            <div class="timeline-item">
                <span class="timeline-dot ${cls}"></span>
                <div class="timeline-content ${cls}">
                    <div class="tl-row">
                        <span class="tl-step">${escHtml(s.step)}</span>
                        <span class="tl-pct">${s.percent}%</span>
                    </div>
                    <div class="tl-time">${time}</div>
                </div>
            </div>`;
        });
        tl.innerHTML = html;
        const wrap = tl.parentElement;
        wrap.scrollTo({ top: wrap.scrollHeight, behavior: 'smooth' });
    }

    // ================================================
    // BUILD ACTIVITY LIST
    // ================================================
    function buildActivityList(activities) {
        if (!activities || activities.length === 0) return;
        const list = document.getElementById('activityList');
        if (!list) return;
        let html = '';
        activities.forEach(a => {
            const type  = (a.action_type || '').toLowerCase();
            const badge = type === 'input' ? '📥 IN' : '📤 OUT';
            const time  = a.created_at ? a.created_at.substr(11, 8) : '';
            html += `
            <div class="activity-item ${type}">
                <div class="activity-left">
                    <span class="activity-badge badge-${type}">${badge}</span>
                    <div class="activity-info">
                        <h4>${escHtml(a.nama_barang)}</h4>
                        <div class="activity-meta">
                            <span class="activity-barcode">${escHtml(a.barcode)}</span>
                            <span class="activity-time">${time}</span>
                        </div>
                    </div>
                </div>
                <div class="activity-slot">${escHtml(a.history_slot_id || 'N/A')}</div>
            </div>`;
        });
        list.innerHTML = html;
    }

    // ================================================
    // CONNECTION INDICATOR
    // ================================================
    function setConnectionStatus(ok) {
        const dot   = document.querySelector('.live-dot');
        const badge = document.querySelector('.live-badge');
        if (!dot || !badge) return;
        dot.style.background    = ok ? '#ef4444' : '#999';
        badge.style.borderColor = ok ? 'rgba(239,68,68,0.35)' : 'rgba(150,150,150,0.35)';
        badge.style.color       = ok ? '#dc2626' : '#999';
    }

    // ================================================
    // HELPER
    // ================================================
    function escHtml(str) {
        const d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    // ================================================
    // POLLING UTAMA — tiap 2 detik
    // ================================================
    async function poll() {
        if (isPolling) return;
        isPolling = true;
        try {
            const res = await fetch('../api/robot_monitor.php', {
                cache: 'no-store',
                signal: AbortSignal.timeout(4000)
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);

            const data = await res.json();
            if (!data.success) { setConnectionStatus(false); return; }
            setConnectionStatus(true);

            const newCmdId = data.command_id;

            // ✅ FIX 1: Command baru terdeteksi → reset progress bar ke 0 dengan benar
            if (newCmdId && newCmdId !== lastCmdId) {
                console.log('[Monitor] Command baru:', newCmdId, '← was:', lastCmdId);
                const fill = document.getElementById('progressFill');
                fill.style.transition = 'none';   // matikan transisi supaya langsung reset
                fill.style.width      = '0%';
                document.getElementById('progressPct').textContent  = '0%';
                document.getElementById('stepPct').textContent      = '0%';
                document.getElementById('stepLabel').textContent    = 'Memulai...';
                document.getElementById('stepNameText').textContent = 'STARTING';
                lastStepHash = '';
                lastCmdId    = newCmdId;
                // Aktifkan kembali transisi setelah browser merender frame reset
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        fill.style.transition = 'width 0.7s cubic-bezier(0.4,0,0.2,1)';
                    });
                });
            }

            // ✅ FIX 2: Update progress UI selalu (tanpa syarat apapun)
            updateProgressUI(data);

            // Update stats
            updateStats(data.stats);

            // ✅ FIX 3: Hash dari step+percent+timestamp — lebih akurat dari hanya count
            //           Mendeteksi perubahan meski jumlah step sama (misal: re-run command)
            if (data.steps) {
                const newHash = data.steps.map(s => s.step + s.percent + s.timestamp).join('|');
                if (newHash !== lastStepHash) {
                    lastStepHash = newHash;
                    buildTimeline(data.steps);
                }
            }

            // Update activity list
            if (data.activities && data.activities.length > 0) {
                const newHash = data.activities.map(a => (a.history_slot_id || '') + a.created_at).join('|');
                if (newHash !== window._lastActivityHash) {
                    window._lastActivityHash = newHash;
                    buildActivityList(data.activities);
                }
            }

        } catch (e) {
            setConnectionStatus(false);
            console.warn('[Monitor] Poll error:', e.message);
        } finally {
            isPolling = false;
        }
    }

    // Pause polling saat tab tidak aktif, resume saat aktif kembali
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearInterval(window._pollTimer);
        } else {
            poll();
            window._pollTimer = setInterval(poll, POLL_INTERVAL);
        }
    });

    // Start polling
    window._pollTimer = setInterval(poll, POLL_INTERVAL);
    poll(); // langsung poll pertama
    </script>

    </body>
    </html>