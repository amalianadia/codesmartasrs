<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../controllers/AuthController.php';

$auth = new AuthController($pdo);
$auth->requireLogin();

// GET ACTIVE MODE FROM settings (sesuai struktur table yang ada)
$activeMode = 'FIFO'; // Default fallback
try {
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'storage_mode' LIMIT 1");
    $row = $stmt->fetch();
    if ($row && in_array($row['setting_value'], ['FIFO', 'LIFO', 'FEFO'])) {
        $activeMode = $row['setting_value'];
    }
} catch (PDOException $e) {
    error_log("Settings error: " . $e->getMessage());
    $activeMode = 'FIFO';
}

// Build query berdasarkan mode
$orderBy = "b.created_at ASC"; // Default FIFO
$modeName = "FIFO";
$modeIcon = "⏱️";
$modeDescription = "First In First Out - Barang masuk pertama diambil pertama";
$modeColor = "#ffc107"; // Yellow

switch ($activeMode) {
    case 'LIFO':
        $orderBy = "b.created_at DESC";
        $modeName = "LIFO";
        $modeIcon = "🔄";
        $modeDescription = "Last In First Out - Barang masuk terakhir diambil pertama";
        $modeColor = "#ffc107"; // Yellow
        break;
    
    case 'FEFO':
        $orderBy = "b.exp_date ASC";
        $modeName = "FEFO";
        $modeIcon = "📅";
        $modeDescription = "First Expired First Out - Barang kadaluarsa paling dekat diambil pertama";
        $modeColor = "#ffc107"; // Yellow
        break;
    
    default: // FIFO
        $orderBy = "b.created_at ASC";
        $modeName = "FIFO";
        $modeIcon = "⏱️";
        $modeDescription = "First In First Out - Barang masuk pertama diambil pertama";
        $modeColor = "#ffc107"; // Yellow
        break;
}

// Query dengan dynamic ORDER BY
$stmt = $pdo->query("
    SELECT 
        s.slot_id,
        b.barang_id,
        b.nama_barang,
        b.jenis,
        b.exp_date,
        b.created_at,
        DATEDIFF(b.exp_date, CURDATE()) as days_to_expire,
        DATEDIFF(CURDATE(), b.created_at) as days_in_storage
    FROM slot s
    INNER JOIN barang b ON s.barang_id = b.barang_id
    WHERE s.barang_id IS NOT NULL
    ORDER BY $orderBy
");
$filledSlots = $stmt->fetchAll();

// Filter barang yang akan expired (<= 1 hari)
$expiringSoon = [];
foreach ($filledSlots as $slot) {
    if ($slot['exp_date'] && $slot['days_to_expire'] <= 1 && $slot['days_to_expire'] >= 0) {
        $slotId = $slot['slot_id'];
        if (!isset($expiringSoon[$slotId])) {
            $expiringSoon[$slotId] = $slot;
        }
    }
}

// Priority items (sesuai mode yang aktif)
$priorityItems = [];
foreach ($filledSlots as $slot) {
    $slotId = $slot['slot_id'];
    if (!isset($priorityItems[$slotId])) {
        $priorityItems[$slotId] = $slot;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Output Barang - Automated Storage</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            min-height: 100vh;
            color: #1a1a1a;
            overflow-x: hidden;
        }

        /* Navbar */
        .navbar {
            background: rgba(255, 255, 255, 0.98);
            padding: 1.2rem 2rem;
            box-shadow: 
                0 4px 20px rgba(0, 0, 0, 0.08),
                0 0 40px rgba(255, 193, 7, 0.12);
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

        .navbar h1::before {
            content: '🤖';
            font-size: 1.8rem;
            filter: 
                drop-shadow(0 0 12px rgba(255, 193, 7, 0.7))
                drop-shadow(0 0 20px rgba(255, 193, 7, 0.5));
            animation: iconPulse 3s ease-in-out infinite;
        }

        @keyframes iconPulse {
            0%, 100% { 
                filter: 
                    drop-shadow(0 0 12px rgba(255, 193, 7, 0.7))
                    drop-shadow(0 0 20px rgba(255, 193, 7, 0.5));
            }
            50% { 
                filter: 
                    drop-shadow(0 0 18px rgba(255, 193, 7, 0.9))
                    drop-shadow(0 0 30px rgba(255, 193, 7, 0.7));
            }
        }

        .navbar-menu {
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

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
            background: rgba(255, 193, 7, 0.15);
            box-shadow: 0 0 20px rgba(255, 193, 7, 0.25);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding-left: 1.5rem;
            border-left: 2px solid rgba(255, 193, 7, 0.3);
        }

        .user-info span {
            font-weight: 600;
            color: #1a1a1a;
            font-size: 0.9rem;
        }

        .user-info span::before {
            content: '👤';
            margin-right: 0.4rem;
            filter: 
                drop-shadow(0 0 8px rgba(255, 193, 7, 0.6))
                drop-shadow(0 0 15px rgba(255, 193, 7, 0.4));
        }

        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            border: none;
            padding: 0.6rem 1.2rem;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.9rem;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4);
            text-decoration: none;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(239, 68, 68, 0.6);
        }

        /* Container */
        .container {
            max-width: 1800px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        /* Page Header */
        .page-header {
            margin-bottom: 2rem;
        }

        .page-header h2 {
            font-size: 2rem;
            font-weight: 800;
            color: #1a1a1a;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: #666;
            font-size: 0.95rem;
            font-weight: 500;
        }

        /* Mode Badge */
        .mode-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.7rem 1.3rem;
            border-radius: 12px;
            font-size: 0.9rem;
            font-weight: 700;
            margin-top: 1rem;
            background: rgba(255, 193, 7, 0.15);
            color: #1a1a1a;
            border: 2px solid rgba(255, 193, 7, 0.4);
            box-shadow: 0 4px 15px rgba(255, 193, 7, 0.2);
        }

        .mode-badge span:first-child {
            filter: 
                drop-shadow(0 0 8px rgba(255, 193, 7, 0.6))
                drop-shadow(0 0 15px rgba(255, 193, 7, 0.4));
        }

        /* Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }

        /* Card */
        .card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            border: 2px solid rgba(255, 193, 7, 0.3);
            box-shadow: 
                0 10px 40px rgba(0, 0, 0, 0.08),
                0 0 50px rgba(255, 193, 7, 0.15);
            padding: 2rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .card:hover {
            border-color: rgba(255, 193, 7, 0.5);
            box-shadow: 
                0 12px 45px rgba(0, 0, 0, 0.1),
                0 0 60px rgba(255, 193, 7, 0.2);
        }

        /* Priority Card */
        .priority-card {
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.08) 0%, rgba(255, 205, 56, 0.05) 100%);
            border: 2px solid rgba(255, 193, 7, 0.4);
        }

        .priority-card:hover {
            border-color: #ffc107;
            box-shadow: 
                0 12px 45px rgba(255, 193, 7, 0.3),
                0 0 60px rgba(255, 193, 7, 0.25);
        }

        /* Card Header */
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid rgba(255, 193, 7, 0.2);
        }

        .card-header-left {
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .card-header h3 {
            font-size: 1.4rem;
            font-weight: 800;
            color: #1a1a1a;
        }

        .card-icon {
            font-size: 1.8rem;
            filter: 
                drop-shadow(0 0 8px rgba(255, 193, 7, 0.6))
                drop-shadow(0 0 15px rgba(255, 193, 7, 0.4));
        }

        .selected-count {
            background: rgba(255, 193, 7, 0.15);
            color: #1a1a1a;
            padding: 0.6rem 1.2rem;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.9rem;
            border: 2px solid rgba(255, 193, 7, 0.3);
        }

        /* Priority Input Group */
        .priority-content {
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
            align-items: center;
            justify-content: space-between;
        }

        .priority-info {
            flex: 1;
            min-width: 300px;
        }

        .priority-info p {
            color: #666;
            font-size: 0.95rem;
            margin-bottom: 1rem;
            line-height: 1.6;
            font-weight: 500;
        }

        .priority-info strong {
            color: #1a1a1a;
            font-weight: 700;
        }

        .priority-input-group {
            display: flex;
            gap: 0.8rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .priority-input-label {
            font-size: 0.9rem;
            color: #1a1a1a;
            font-weight: 700;
        }

        #priorityCountInput {
            width: 90px;
            padding: 0.7rem;
            border-radius: 10px;
            border: 2px solid rgba(255, 193, 7, 0.4);
            background: #fafafa;
            color: #1a1a1a;
            font-weight: 700;
            font-size: 1.1rem;
            text-align: center;
            transition: all 0.3s;
            box-shadow: 0 2px 10px rgba(255, 193, 7, 0.15);
            font-family: 'Poppins', sans-serif;
        }

        #priorityCountInput:focus {
            outline: none;
            border-color: #ffc107;
            box-shadow: 
                0 0 0 4px rgba(255, 193, 7, 0.2),
                0 4px 15px rgba(255, 193, 7, 0.25);
            background: #ffffff;
        }

        .priority-actions {
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
        }

        .badge-count {
            font-size: 0.85rem;
            color: #666;
            font-weight: 600;
            background: rgba(255, 193, 7, 0.15);
            padding: 0.5rem 1rem;
            border-radius: 10px;
            border: 1px solid rgba(255, 193, 7, 0.3);
        }

        /* Barang Grid */
        .barang-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
        }

        .barang-card {
            background: #fafafa;
            border: 2px solid rgba(255, 193, 7, 0.3);
            border-radius: 16px;
            padding: 1.5rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.05);
        }

        .barang-card:hover {
            border-color: #ffc107;
            transform: translateY(-6px);
            box-shadow: 
                0 12px 32px rgba(255, 193, 7, 0.3),
                0 0 40px rgba(255, 193, 7, 0.2);
            background: #ffffff;
        }

        .barang-card.selected {
            border-color: #ffc107;
            background: rgba(255, 193, 7, 0.12);
            box-shadow: 
                0 8px 24px rgba(255, 193, 7, 0.35),
                0 0 35px rgba(255, 193, 7, 0.25);
        }

        .barang-card.expired {
            border-color: #ef4444;
            background: rgba(239, 68, 68, 0.08);
        }

        .barang-card.expired.selected {
            border-color: #ef4444;
            background: rgba(239, 68, 68, 0.15);
            box-shadow: 
                0 8px 24px rgba(239, 68, 68, 0.35),
                0 0 35px rgba(239, 68, 68, 0.25);
        }

        .barang-card.priority {
            border-color: rgba(255, 193, 7, 0.5);
            background: rgba(255, 193, 7, 0.1);
        }

        .barang-card.priority.selected {
            border-color: #ffc107;
            background: rgba(255, 193, 7, 0.18);
            box-shadow: 
                0 8px 24px rgba(255, 193, 7, 0.4),
                0 0 40px rgba(255, 193, 7, 0.3);
        }

        /* Priority Badge */
        .priority-badge {
            position: absolute;
            top: 1rem;
            left: 1rem;
            background: linear-gradient(135deg, #ffc107 0%, #ffcd38 100%);
            color: #1a1a1a;
            padding: 0.4rem 0.9rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            z-index: 2;
            box-shadow: 
                0 4px 12px rgba(255, 193, 7, 0.4),
                0 0 20px rgba(255, 193, 7, 0.3);
            animation: priorityPulse 2s infinite;
        }

        @keyframes priorityPulse {
            0%, 100% { 
                box-shadow: 
                    0 4px 12px rgba(255, 193, 7, 0.4),
                    0 0 20px rgba(255, 193, 7, 0.3);
            }
            50% { 
                box-shadow: 
                    0 4px 20px rgba(255, 193, 7, 0.6),
                    0 0 30px rgba(255, 193, 7, 0.5);
            }
        }

        .barang-checkbox {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 26px;
            height: 26px;
            cursor: pointer;
            z-index: 3;
            accent-color: #ffc107;
        }

        .barang-name {
            font-size: 1.1rem;
            font-weight: 800;
            color: #1a1a1a;
            margin-bottom: 0.5rem;
            margin-right: 2rem;
        }

        .barang-card.expired .barang-name {
            color: #ef4444;
        }

        .barang-card.priority .barang-name {
            color: #1a1a1a;
        }

        .barang-info {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .barang-info-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
        }

        .barang-info-label {
            color: #999;
            font-weight: 500;
        }

        .barang-info-value {
            color: #1a1a1a;
            font-weight: 700;
        }

        /* Badges */
        .badge {
            padding: 0.35rem 0.9rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.15);
            color: #059669;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.15);
            color: #d97706;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .badge-danger {
            background: rgba(239, 68, 68, 0.15);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        .btn-primary {
            flex: 1;
            padding: 1rem 2rem;
            background: linear-gradient(135deg, #ffc107 0%, #ffcd38 100%);
            color: #1a1a1a;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 
                0 4px 16px rgba(255, 193, 7, 0.4),
                0 0 30px rgba(255, 193, 7, 0.2);
            letter-spacing: 0.5px;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 
                0 8px 28px rgba(255, 193, 7, 0.5),
                0 0 45px rgba(255, 193, 7, 0.3);
        }

        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .btn-secondary {
            padding: 1rem 2rem;
            background: #ffffff;
            color: #1a1a1a;
            border: 2px solid rgba(255, 193, 7, 0.3);
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-secondary:hover {
            background: rgba(255, 193, 7, 0.1);
            border-color: #ffc107;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(255, 193, 7, 0.2);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #999;
        }

        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.6;
            filter: 
                drop-shadow(0 0 8px rgba(255, 193, 7, 0.3))
                drop-shadow(0 0 15px rgba(255, 193, 7, 0.2));
        }

        .empty-state h3 {
            font-size: 1.3rem;
            font-weight: 700;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            color: #999;
            font-size: 0.95rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .navbar {
                flex-direction: column;
                gap: 1rem;
                padding: 1rem;
            }

            .navbar-menu {
                flex-direction: column;
                width: 100%;
                gap: 0.5rem;
            }

            .user-info {
                border-left: none;
                border-top: 2px solid rgba(255, 193, 7, 0.3);
                padding-left: 0;
                padding-top: 1rem;
                width: 100%;
                justify-content: center;
            }

            .container {
                padding: 1rem;
            }

            .page-header h2 {
                font-size: 1.5rem;
            }

            .card {
                padding: 1.5rem;
            }

            .priority-content {
                flex-direction: column;
                align-items: stretch;
            }

            .priority-actions {
                width: 100%;
            }

            .priority-actions .btn-secondary,
            .priority-actions .btn-primary {
                flex: 1;
            }

            .action-buttons {
                flex-direction: column;
            }

            .barang-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<nav class="navbar">
    <h1>Automated Storage</h1>
    <div class="navbar-menu">
        <a href="dashboard.php">Dashboard</a>
        <a href="input.php">Input Barang</a>
        <a href="output.php" class="active">Output Barang</a>
        <a href="run_robot.php">🤖 Run Robot</a>
        <a href="history.php">History</a>
        <div class="user-info">
            <span><?= htmlspecialchars($_SESSION['nama_lengkap']) ?></span>
            <a href="../index.php?action=logout" class="btn-danger">Logout</a>
        </div>
    </div>
</nav>

<div class="container">
    <div class="page-header">
        <h2>📤 Output Barang</h2>
        <p>Ambil barang dari sistem penyimpanan otomatis</p>
        <div class="mode-badge">
            <span><?= $modeIcon ?></span>
            <span>Mode Aktif: <?= $modeName ?></span>
        </div>
        <p style="color:#999; font-size:0.85rem; margin-top:0.5rem; font-weight:500;"><?= $modeDescription ?></p>
    </div>

    <!-- Alert Kadaluarsa -->
    <?php if (!empty($expiringSoon)): ?>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        Swal.fire({
            title: '⚠️ Ada Slot Segera Kadaluarsa!',
            html: `
                <div style="text-align:left; padding:1.5rem;">
                    <p style="color:#666; margin-bottom:1rem;">Apakah Anda ingin langsung mengambil semua barang yang segera kadaluarsa?</p>
                    <div style="background:rgba(239,68,68,0.08); padding:1.2rem; border-radius:12px; border:2px solid rgba(239,68,68,0.2);">
                        <ul style="list-style:none; padding:0; margin:0;">
                            <?php foreach ($expiringSoon as $slot): ?>
                            <li style="margin-bottom:0.8rem; padding:0.8rem; background:rgba(239,68,68,0.05); border-radius:8px; border:1px solid rgba(239,68,68,0.15);">
                                <strong style="color:#dc2626; font-size:1rem;"><?= htmlspecialchars($slot['nama_barang']) ?></strong><br>
                                <span style="color:#666; font-size:0.85rem;">Slot <strong><?= $slot['slot_id'] ?></strong> • Kadaluarsa <strong><?= $slot['days_to_expire'] == 0 ? 'Hari Ini' : 'Besok' ?></strong></span>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '🚀 Ambil Barang Kadaluarsa',
            cancelButtonText: 'Batal',
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#999',
            background: '#ffffff',
            color: '#1a1a1a'
        }).then((result) => {
            if (result.isConfirmed) {
                processBatchOutput(<?= json_encode(array_keys($expiringSoon)) ?>);
            }
        });
    });
    </script>
    <?php endif; ?>

    <div class="content-grid">
        <!-- Priority Card (Dynamic Mode) -->
        <?php if (!empty($priorityItems)): ?>
        <div class="card priority-card">
            <div class="card-header">
                <div class="card-header-left">
                    <span class="card-icon"><?= $modeIcon ?></span>
                    <h3><?= $modeName ?> Output</h3>
                </div>
                <div class="badge-count">
                    <?= count($priorityItems) ?> barang tersedia
                </div>
            </div>

            <div class="priority-content">
                <div class="priority-info">
                    <p>
                        <?php 
                        switch ($activeMode) {
                            case 'LIFO':
                                echo '🔄 Ambil barang dari yang <strong>paling baru masuk</strong> (Last In First Out)';
                                break;
                            case 'FEFO':
                                echo '📅 Ambil barang dari yang <strong>paling dekat kadaluarsa</strong> (First Expired First Out)';
                                break;
                            default:
                                echo '⏱️ Ambil barang dari yang <strong>paling lama masuk</strong> (First In First Out)';
                        }
                        ?>
                    </p>
                    <div class="priority-input-group">
                        <span class="priority-input-label">Jumlah yang ingin diambil:</span>
                        <input 
                            type="number" 
                            id="priorityCountInput"
                            min="1" 
                            max="<?= count($priorityItems) ?>" 
                            value="1"
                        >
                        <span style="font-size:0.85rem; color:#999; font-weight:600;">
                            (maksimal <?= count($priorityItems) ?>)
                        </span>
                    </div>
                </div>

                <div class="priority-actions">
                    <button 
                        class="btn-secondary" 
                        type="button"
                        onclick="previewPriority()">
                        👀 Lihat Slot
                    </button>
                    <button 
                        class="btn-primary" 
                        type="button"
                        onclick="executePriority()">
                        🚀 Ambil <?= $modeName ?>
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Card Pilih Barang Manual -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-left">
                    <span class="card-icon">📦</span>
                    <h3>Pilih Barang Manual</h3>
                </div>
                <div class="selected-count" id="selectedCount">0 slot dipilih</div>
            </div>

            <?php if (empty($filledSlots)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📭</div>
                <h3>Tidak Ada Barang</h3>
                <p>Semua slot kosong atau belum ada barang yang diinput</p>
            </div>
            <?php else: ?>
            <div class="barang-grid" id="barangGrid">
                <?php 
                $priorityCounter = 1;
                foreach ($filledSlots as $index => $slot): 
                    $classes = [];
                    $badges = [];
                    $showPriorityBadge = ($index < 5);

                    // Kadaluarsa
                    if ($slot['exp_date']) {
                        if ($slot['days_to_expire'] < 0) {
                            $classes[] = 'expired';
                            $badges[] = '<span class="badge badge-danger">Expired</span>';
                        } elseif ($slot['days_to_expire'] <= 1) {
                            $classes[] = 'expired';
                            $badges[] = '<span class="badge badge-danger">⚠️ Segera Expired</span>';
                        } elseif ($slot['days_to_expire'] <= 7) {
                            $badges[] = '<span class="badge badge-warning">' . $slot['days_to_expire'] . ' hari lagi</span>';
                        } else {
                            $badges[] = '<span class="badge badge-success">' . date('d/m/Y', strtotime($slot['exp_date'])) . '</span>';
                        }
                    }

                    // Priority visual indicator
                    if ($showPriorityBadge && !in_array('expired', $classes)) {
                        $classes[] = 'priority';
                    }

                    $classString = implode(' ', $classes);
                    $badgeString = implode('', $badges);
                ?>
                    <div class="barang-card <?= $classString ?>" 
                         data-slot-id="<?= $slot['slot_id'] ?>"
                         data-priority-order="<?= $index + 1 ?>"
                         onclick="toggleSlot(this)">
                        
                        <?php if ($showPriorityBadge && !in_array('expired', $classes)): ?>
                        <div class="priority-badge">
                            <?= $modeIcon ?> <?= $modeName ?> #<?= $priorityCounter++ ?>
                        </div>
                        <?php endif; ?>
                        
                        <input type="checkbox" class="barang-checkbox" onclick="event.stopPropagation()" />
                        <div class="barang-name"><?= htmlspecialchars($slot['nama_barang']) ?></div>
                        <div class="barang-info">
                            <div class="barang-info-row">
                                <span class="barang-info-label">Slot:</span>
                                <span class="barang-info-value"><?= $slot['slot_id'] ?></span>
                            </div>
                            <div class="barang-info-row">
                                <span class="barang-info-label">Jenis:</span>
                                <span class="barang-info-value"><?= htmlspecialchars($slot['jenis']) ?></span>
                            </div>
                            <div class="barang-info-row">
                                <span class="barang-info-label">Disimpan:</span>
                                <span class="barang-info-value"><?= $slot['days_in_storage'] ?> hari</span>
                            </div>
                            <div class="barang-info-row">
                                <span class="barang-info-label">Kadaluarsa:</span>
                                <span><?= $badgeString ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="action-buttons">
                <button class="btn-secondary" onclick="clearSelection()">🔄 Reset</button>
                <button class="btn-primary" id="outputBtn" onclick="processOutput()" disabled>🚀 Ambil Barang</button>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Global Variables
let selectedSlots = new Set();
const prioritySlotsOrdered = <?= json_encode(array_keys($priorityItems)) ?>;
const activeMode = '<?= $activeMode ?>';
const modeName = '<?= $modeName ?>';
const modeColor = '#ffc107';

// Priority Functions (Dynamic)
function getPrioritySelection() {
    const input = document.getElementById('priorityCountInput');
    let count = parseInt(input.value);
    
    if (isNaN(count) || count <= 0) {
        count = 1;
        input.value = 1;
    }
    
    if (count > prioritySlotsOrdered.length) {
        count = prioritySlotsOrdered.length;
        input.value = count;
    }
    
    return prioritySlotsOrdered.slice(0, count);
}

function previewPriority() {
    const selectedSlots = getPrioritySelection();
    const count = selectedSlots.length;
    
    Swal.fire({
        title: `👀 Preview ${modeName}`,
        html: `
            <div style="text-align:left; padding:1.5rem;">
                <p style="color:#1a1a1a; font-weight:700; margin-bottom:1.2rem; font-size:1.1rem;">
                    Akan mengambil ${count} barang (${modeName}):
                </p>
                <div style="background:rgba(255,193,7,0.08); padding:1.2rem; border-radius:12px; max-height:350px; overflow-y:auto; border:2px solid rgba(255,193,7,0.2);">
                    ${selectedSlots.map((slot, idx) => `
                        <div style="display:flex; justify-content:space-between; align-items:center; padding:0.8rem; border-bottom:1px solid rgba(255,193,7,0.15); background:rgba(255,193,7,0.05); margin-bottom:0.5rem; border-radius:8px;">
                            <span style="color:#1a1a1a; font-weight:700; font-size:0.95rem;">${modeName} #${idx + 1}</span>
                            <span style="color:#1a1a1a; font-weight:800; font-size:1.1rem; background:rgba(255,193,7,0.2); padding:0.4rem 1rem; border-radius:8px;">Slot ${slot}</span>
                        </div>
                    `).join('')}
                </div>
            </div>
        `,
        icon: 'info',
        confirmButtonText: 'OK',
        confirmButtonColor: '#ffc107',
        background: '#ffffff',
        color: '#1a1a1a'
    });
}

function executePriority() {
    const selectedSlots = getPrioritySelection();
    const count = selectedSlots.length;
    
    Swal.fire({
        title: `🤖 Konfirmasi ${modeName}`,
        html: `
            <div style="text-align:left; padding:1.5rem;">
                <p style="color:#1a1a1a; font-weight:700; margin-bottom:1.2rem; font-size:1.1rem;">
                    Anda akan mengambil ${count} barang (${modeName}):
                </p>
                <div style="background:rgba(255,193,7,0.12); padding:1.5rem; border-radius:12px; border:2px solid rgba(255,193,7,0.3);">
                    <p style="color:#1a1a1a; font-weight:800; text-align:center; font-size:1.4rem; letter-spacing:1px;">
                        ${selectedSlots.join(', ')}
                    </p>
                </div>
                <p style="margin-top:1.2rem; color:#666; font-size:0.9rem; text-align:center; background:rgba(255,193,7,0.08); padding:0.8rem; border-radius:8px; font-weight:600;">
                    ⚠️ Robot akan mengambil barang sesuai urutan ${modeName}
                </p>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '🚀 Ya, Ambil Sekarang',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#ffc107',
        cancelButtonColor: '#999',
        background: '#ffffff',
        color: '#1a1a1a'
    }).then((result) => {
        if (!result.isConfirmed) return;
        processBatchOutput(selectedSlots);
    });
}

// Manual Selection Functions
function toggleSlot(card) {
    const slotId = card.dataset.slotId;
    const checkbox = card.querySelector('.barang-checkbox');
    
    if (selectedSlots.has(slotId)) {
        selectedSlots.delete(slotId);
        card.classList.remove('selected');
        checkbox.checked = false;
    } else {
        selectedSlots.add(slotId);
        card.classList.add('selected');
        checkbox.checked = true;
    }
    updateSelectedCount();
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.barang-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function(e) {
            e.stopPropagation();
            const card = this.closest('.barang-card');
            const slotId = card.dataset.slotId;
            
            if (this.checked) {
                selectedSlots.add(slotId);
                card.classList.add('selected');
            } else {
                selectedSlots.delete(slotId);
                card.classList.remove('selected');
            }
            updateSelectedCount();
        });
    });
});

function updateSelectedCount() {
    const count = selectedSlots.size;
    document.getElementById('selectedCount').textContent = `${count} slot dipilih`;
    document.getElementById('outputBtn').disabled = count === 0;
}

function clearSelection() {
    selectedSlots.clear();
    document.querySelectorAll('.barang-card').forEach(card => {
        card.classList.remove('selected');
        card.querySelector('.barang-checkbox').checked = false;
    });
    updateSelectedCount();
}

async function processOutput() {
    if (selectedSlots.size === 0) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Pilih minimal 1 slot',
            confirmButtonColor: '#ef4444',
            background: '#ffffff',
            color: '#1a1a1a'
        });
        return;
    }
    
    const slots = Array.from(selectedSlots);
    Swal.fire({
        title: 'Konfirmasi Output',
        html: `
            <div style="text-align:left; padding:1.5rem;">
                <p style="margin-bottom:1.2rem; color:#666; font-weight:600;"><strong style="color:#1a1a1a;">Slot yang akan diambil:</strong></p>
                <div style="background:rgba(255,193,7,0.08); padding:1.2rem; border-radius:12px; border:2px solid rgba(255,193,7,0.2);">
                    <p style="color:#1a1a1a; font-weight:800; font-size:1.3rem; text-align:center; letter-spacing:1px;">
                        ${slots.join(', ')}
                    </p>
                </div>
                <p style="margin-top:1.2rem; text-align:center; font-weight:600;"><strong style="color:#666;">Total:</strong> <span style="color:#1a1a1a; font-size:1.2rem; font-weight:800;">${slots.length} slot</span></p>
            </div>
        `,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: '🚀 Ya, Ambil Barang',
        cancelButtonText: 'Batal',
        confirmButtonColor: '#ffc107',
        cancelButtonColor: '#999',
        background: '#ffffff',
        color: '#1a1a1a'
    }).then(result => {
        if (result.isConfirmed) {
            processBatchOutput(slots);
        }
    });
}

// Batch Output Processing
async function processBatchOutput(slots) {
    try {
        Swal.fire({
            title: '⏳ Menyimpan ke Antrian...',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => Swal.showLoading()
        });

        const response = await fetch('../api/output_barang.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ slots: slots })
        });

        const result = await response.json();
        if (!result.success) throw new Error(result.message);

        await Swal.fire({
            icon: 'success',
            title: '✅ Masuk Antrian!',
            html: `<p><strong>${slots.length} slot</strong> ditambahkan ke antrian robot.</p>
                   <p style="color:#999; font-size:0.9rem;">Robot akan mengambil otomatis.</p>`,
            timer: 3000,
            showConfirmButton: false
        });

        window.location.reload();

    } catch (err) {
        Swal.fire({ icon: 'error', title: 'Gagal', text: err.message });
    }
}

// ESP32 WebSocket Communication
function mapSlotToPosition(slotId) {
    const map = {
        'A1': 1, 'A2': 2, 'A3': 3,
        'B1': 4, 'B2': 5, 'B3': 6,
        'C1': 7, 'C2': 8, 'C3': 9
    };
    return map[slotId] || null;
}

function sendToESP32Sequential(slotIds, mode = 'INPUT') {
    if (!slotIds || slotIds.length === 0) {
        return Promise.resolve();
    }
    
    return new Promise((resolve, reject) => {
        const ESP32_IP = '192.168.1.21';
        const WS_PORT = 81;
        const RESPONSE_TIMEOUT = 60000;
        let currentIndex = 0;
        let ws = null;
        let responseTimeout = null;

        function connect() {
            console.log(`🔌 Connecting to ws://${ESP32_IP}:${WS_PORT}...`);
            ws = new WebSocket(`ws://${ESP32_IP}:${WS_PORT}`);

            ws.onopen = function() {
                console.log('✅ WebSocket connected!');
                sendNextCommand();
            };

            ws.onmessage = function(evt) {
                console.log('📨 ESP32:', evt.data);
                try {
                    const response = JSON.parse(evt.data);
                    if (response.status === 'progress') {
                        const slotId = slotIds[currentIndex];
                        updateProgressFromESP32(currentIndex, slotIds.length, slotId, response.percent, response.message, mode);
                        clearTimeout(responseTimeout);
                        responseTimeout = setTimeout(() => {
                            ws.close();
                            reject(new Error(`Timeout (Slot ${slotId})`));
                        }, RESPONSE_TIMEOUT);
                    } else if (response.status === 'ok') {
                        clearTimeout(responseTimeout);
                        console.log(`✅ Slot ${slotIds[currentIndex]} SELESAI`);
                        currentIndex++;
                        if (currentIndex < slotIds.length) {
                            setTimeout(() => sendNextCommand(), 1500);
                        } else {
                            ws.close();
                            resolve();
                        }
                    }
                } catch (e) {
                    console.warn('Non-JSON message:', evt.data);
                }
            };

            ws.onerror = function(err) {
                clearTimeout(responseTimeout);
                reject(new Error('Gagal terhubung ke robot'));
            };

            ws.onclose = function() {
                clearTimeout(responseTimeout);
            };
        }

        function sendNextCommand() {
            const slotId = slotIds[currentIndex];
            const position = mapSlotToPosition(slotId);
            
            if (!position) {
                currentIndex++;
                if (currentIndex < slotIds.length) {
                    sendNextCommand();
                } else {
                    ws.close();
                    resolve();
                }
                return;
            }
            
            let command;
            if (mode === 'OUTPUT') {
                command = String.fromCharCode(64 + position);
                console.log(`📤 Sending TAKE: ${command} (Slot ${slotId})`);
            } else {
                command = String(position);
                console.log(`📤 Sending PUT: ${command} (Slot ${slotId})`);
            }
            
            updateProgressFromESP32(currentIndex, slotIds.length, slotId, 0, 'Memulai...', mode);
            ws.send(command);
            
            responseTimeout = setTimeout(() => {
                ws.close();
                reject(new Error(`Timeout (Slot ${slotId})`));
            }, RESPONSE_TIMEOUT);
        }

        connect();
    });
}

function updateProgressFromESP32(current, total, slotId, percent, message, mode = 'INPUT') {
    const slotWeight = 100 / total;
    const slotProgress = Math.round(current * slotWeight + (percent / 100) * slotWeight);
    
    if (Swal.isVisible()) {
        let title = mode === 'OUTPUT' ? '🚀 Mengambil Barang...' : '📥 Menyimpan Barang...';
        Swal.update({
            title: title,
            html: `
                <div style="text-align:center; padding:1.5rem;">
                    <p style="margin-bottom:0.8rem; font-size:0.95rem; color:#999; font-weight:600;">
                        Slot ${current + 1}/${total}: <strong style="color:#1a1a1a;">${slotId}</strong>
                    </p>
                    <p style="margin-bottom:1.2rem; font-size:1.15rem; font-weight:700; color:#1a1a1a;">${message}</p>
                    <div style="background:#f5f5f5; height:28px; border-radius:14px; overflow:hidden; margin:1.2rem 0; border:2px solid rgba(255,193,7,0.3);">
                        <div style="width:${slotProgress}%; height:100%; background:linear-gradient(90deg,#ffc107,#ffcd38); transition:width 0.3s; box-shadow:0 0 20px rgba(255,193,7,0.5);"></div>
                    </div>
                    <p style="color:#1a1a1a; font-size:1.4rem; font-weight:800; text-shadow:0 2px 12px rgba(255,193,7,0.3);">${slotProgress}%</p>
                </div>
            `
        });
    }
}
</script>
</body>
</html>