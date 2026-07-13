<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../controllers/AuthController.php';

$auth = new AuthController($pdo);
$auth->requireLogin();

// Get stock data untuk grid
$stmt = $pdo->query("
    SELECT s.slot_id, b.barang_id, b.nama_barang, COUNT(*) as jumlah
    FROM slot s
    LEFT JOIN barang b ON s.barang_id = b.barang_id
    WHERE s.slot_id IN ('A1','A2','A3','B1','B2','B3','C1','C2','C3')
    GROUP BY s.slot_id, b.barang_id, b.nama_barang
    ORDER BY s.slot_id
");
$stocks = $stmt->fetchAll();

// Build grid
$grid = [];
for ($row = 1; $row <= 3; $row++) {
    for ($col = 1; $col <= 3; $col++) {
        $slot_id = chr(64 + $row) . $col;
        $grid[$row][$col] = [
            'slot_id' => $slot_id,
            'items' => []
        ];
    }
}

foreach($stocks as $stock) {
    if(!$stock['slot_id'] || !$stock['nama_barang']) continue;
    $row = ord($stock['slot_id'][0]) - ord('A') + 1;
    $col = (int)$stock['slot_id'][1];
    $grid[$row][$col]['items'][] = [
        'barang_id' => $stock['barang_id'],
        'nama_barang' => $stock['nama_barang'],
        'jumlah' => $stock['jumlah']
    ];
}

// Get current mode from settings
$currentMode = 'FIFO'; // Default
try {
    $stmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'storage_mode' LIMIT 1");
    $row = $stmt->fetch();
    if ($row && in_array($row['setting_value'], ['FIFO', 'LIFO', 'FEFO'])) {
        $currentMode = $row['setting_value'];
    }
} catch (PDOException $e) {
    $currentMode = 'FIFO';
}

// Get ESP Status from database
$esp1Status = 'offline';
$esp2Status = 'offline';
$esp1LastUpdate = null;
$esp2LastUpdate = null;

try {
    // Get ESP1 status
    $stmt = $pdo->query("SELECT last_update FROM esp_status WHERE esp_id = 'ESP1' LIMIT 1");
    $esp1 = $stmt->fetch();
    if ($esp1 && $esp1['last_update']) {
        $lastUpdate = new DateTime($esp1['last_update']);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $lastUpdate->getTimestamp();
        $esp1Status = ($diff < 300) ? 'online' : 'offline'; // 300 detik = 5 menit
        $esp1LastUpdate = $lastUpdate->format('H:i:s');
    }
    
    // Get ESP2 status
    $stmt = $pdo->query("SELECT last_update FROM esp_status WHERE esp_id = 'ESP2' LIMIT 1");
    $esp2 = $stmt->fetch();
    if ($esp2 && $esp2['last_update']) {
        $lastUpdate = new DateTime($esp2['last_update']);
        $now = new DateTime();
        $diff = $now->getTimestamp() - $lastUpdate->getTimestamp();
        $esp2Status = ($diff < 300) ? 'online' : 'offline';
        $esp2LastUpdate = $lastUpdate->format('H:i:s');
    }
} catch (PDOException $e) {
    // Keep default offline status
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Automated Storage</title>
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

        /* ESP Status Container */
        .esp-status-container {
            position: fixed;
            top: 95px;
            right: 25px;
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
            z-index: 1000;
        }

        .esp-status-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.9rem 1.3rem;
            border-radius: 14px;
            border: 2px solid;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
            min-width: 200px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            background: rgba(255, 255, 255, 0.95);
        }

        .esp-icon {
            font-size: 2rem;
            filter: 
                drop-shadow(0 0 8px rgba(255, 193, 7, 0.6))
                drop-shadow(0 0 15px rgba(255, 193, 7, 0.4));
        }

        .esp-info {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
            flex: 1;
        }

        .esp-label {
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            opacity: 0.7;
        }

        .esp-state {
            font-size: 0.9rem;
            font-weight: 700;
        }

        .esp-time {
            font-size: 0.7rem;
            opacity: 0.6;
            font-weight: 600;
        }

        /* ESP Online Status */
        .esp-online {
            border-color: #10b981;
        }

        .esp-online .esp-label {
            color: #059669;
        }

        .esp-online .esp-state {
            color: #059669;
        }

        .esp-online .esp-time {
            color: #059669;
        }

        .esp-online .esp-icon {
            animation: espPulse 2s ease-in-out infinite;
        }

        @keyframes espPulse {
            0%, 100% { 
                transform: scale(1);
                filter: 
                    drop-shadow(0 0 8px rgba(16, 185, 129, 0.6))
                    drop-shadow(0 0 15px rgba(16, 185, 129, 0.4));
            }
            50% { 
                transform: scale(1.1);
                filter: 
                    drop-shadow(0 0 12px rgba(16, 185, 129, 0.8))
                    drop-shadow(0 0 20px rgba(16, 185, 129, 0.6));
            }
        }

        /* ESP Offline Status */
        .esp-offline {
            border-color: #ef4444;
            animation: espBlink 2s ease-in-out infinite;
        }

        .esp-offline .esp-label {
            color: #dc2626;
        }

        .esp-offline .esp-state {
            color: #dc2626;
        }

        .esp-offline .esp-time {
            color: #dc2626;
        }

        .esp-offline .esp-icon {
            filter: 
                drop-shadow(0 0 8px rgba(239, 68, 68, 0.6))
                drop-shadow(0 0 15px rgba(239, 68, 68, 0.4));
            opacity: 0.7;
        }

        @keyframes espBlink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }

        /* Container */
        .container {
            max-width: 1800px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        /* Main Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 450px;
            gap: 1.5rem;
            align-items: start;
        }

        /* Left Section - Storage Grid */
        .storage-section {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            border: 2px solid rgba(255, 193, 7, 0.3);
            box-shadow: 
                0 10px 40px rgba(0, 0, 0, 0.08),
                0 0 50px rgba(255, 193, 7, 0.15);
            padding: 2rem;
        }

        .section-header {
            margin-bottom: 1.5rem;
        }

        .section-header h2 {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1a1a1a;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-header h2::before {
            content: '📦';
            filter: 
                drop-shadow(0 0 10px rgba(255, 193, 7, 0.6))
                drop-shadow(0 0 18px rgba(255, 193, 7, 0.4));
        }

        .section-header p {
            color: #666;
            font-size: 0.9rem;
            font-weight: 500;
        }

        /* Storage Grid 3x3 */
        .storage-grid {
            display: grid;
            grid-template-columns: 80px repeat(3, 1fr);
            grid-template-rows: 60px repeat(3, 1fr);
            gap: 15px;
            aspect-ratio: 4/3.5;
        }

        .grid-cell {
            border: 2px solid #e0e0e0;
            padding: 1.5rem;
            text-align: center;
            border-radius: 15px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background: #fafafa;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .grid-cell::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at center, rgba(255, 193, 7, 0.08), transparent);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .grid-cell:hover::before {
            opacity: 1;
        }

        .grid-header {
            background: linear-gradient(135deg, #ffc107 0%, #ffcd38 100%);
            color: #1a1a1a;
            font-weight: 800;
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            border: none;
            box-shadow: 
                0 5px 20px rgba(255, 193, 7, 0.4),
                0 0 30px rgba(255, 193, 7, 0.2);
        }

        .grid-empty {
            background: #f5f5f5;
            color: #999;
            border-color: #e0e0e0;
        }

        .grid-filled {
            background: rgba(255, 193, 7, 0.12);
            border-color: rgba(255, 193, 7, 0.5);
            cursor: pointer;
        }

        .grid-filled:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: 
                0 10px 35px rgba(255, 193, 7, 0.5),
                0 0 50px rgba(255, 193, 7, 0.3);
            border-color: #ffc107;
        }

        .slot-id {
            position: absolute;
            top: 10px;
            left: 10px;
            background: rgba(255, 193, 7, 0.25);
            color: #1a1a1a;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 800;
            letter-spacing: 0.5px;
        }

        .item-name {
            font-weight: 800;
            font-size: 1.2rem;
            color: #1a1a1a;
            margin-bottom: 8px;
        }

        .item-count {
            color: #666;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .item-icon {
            font-size: 2.5rem;
            margin-bottom: 12px;
            opacity: 0.9;
            filter: 
                drop-shadow(0 0 8px rgba(255, 193, 7, 0.5))
                drop-shadow(0 0 15px rgba(255, 193, 7, 0.3));
        }

        /* Right Section - Control Panel */
        .control-section {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            position: sticky;
            top: 115px;
        }

        .control-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            border: 2px solid rgba(255, 193, 7, 0.3);
            box-shadow: 
                0 10px 40px rgba(0, 0, 0, 0.08),
                0 0 50px rgba(255, 193, 7, 0.15);
            padding: 1.8rem;
        }

        .control-card h3 {
            font-size: 1.3rem;
            font-weight: 800;
            color: #1a1a1a;
            margin-bottom: 1.3rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .control-card h3::before {
            filter: 
                drop-shadow(0 0 8px rgba(255, 193, 7, 0.6))
                drop-shadow(0 0 15px rgba(255, 193, 7, 0.4));
        }

        /* Mode Selection */
        .mode-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .mode-option {
            background: #fafafa;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 1.3rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 0.6rem;
            position: relative;
        }

        .mode-option:hover {
            border-color: #ffc107;
            background: rgba(255, 193, 7, 0.08);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(255, 193, 7, 0.25);
        }

        .mode-option.active {
            background: rgba(255, 193, 7, 0.2);
            border-color: #ffc107;
            box-shadow: 
                0 0 30px rgba(255, 193, 7, 0.4),
                0 8px 25px rgba(255, 193, 7, 0.3);
        }

        .mode-option.active .mode-name {
            color: #1a1a1a;
            font-weight: 900;
        }

        .mode-option.active .mode-icon {
            filter: 
                drop-shadow(0 0 10px rgba(255, 193, 7, 0.7))
                drop-shadow(0 0 18px rgba(255, 193, 7, 0.5));
        }

        .mode-icon {
            font-size: 2.5rem;
            filter: 
                drop-shadow(0 0 6px rgba(255, 193, 7, 0.4))
                drop-shadow(0 0 12px rgba(255, 193, 7, 0.2));
        }

        .mode-name {
            font-weight: 700;
            font-size: 1.1rem;
            color: #1a1a1a;
            transition: all 0.3s;
        }

        .mode-desc {
            font-size: 0.75rem;
            color: #666;
            font-weight: 500;
        }

        .mode-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: linear-gradient(135deg, #ffc107, #ffcd38);
            color: #1a1a1a;
            padding: 0.3rem 0.7rem;
            border-radius: 20px;
            font-size: 0.65rem;
            font-weight: 900;
            box-shadow: 
                0 2px 10px rgba(255, 193, 7, 0.5),
                0 0 20px rgba(255, 193, 7, 0.3);
        }

        /* Input/Output Section */
        .io-section {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .io-button {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(255, 193, 7, 0.12);
            border: 2px solid rgba(255, 193, 7, 0.4);
            border-radius: 14px;
            padding: 1.4rem 1.6rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .io-button:hover {
            border-color: #ffc107;
            background: rgba(255, 193, 7, 0.25);
            transform: translateX(8px);
            box-shadow: 
                0 8px 30px rgba(255, 193, 7, 0.4),
                0 0 40px rgba(255, 193, 7, 0.2);
        }

        .io-button-content {
            display: flex;
            align-items: center;
            gap: 1.2rem;
        }

        .io-icon {
            font-size: 2.5rem;
            filter: 
                drop-shadow(0 0 10px rgba(255, 193, 7, 0.6))
                drop-shadow(0 0 18px rgba(255, 193, 7, 0.4));
        }

        .io-title {
            font-weight: 800;
            font-size: 1.2rem;
            color: #1a1a1a;
            letter-spacing: 1px;
        }

        .io-arrow {
            font-size: 1.8rem;
            color: #1a1a1a;
            font-weight: 700;
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
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(239, 68, 68, 0.6);
        }

        /* Responsive */
        @media (max-width: 1400px) {
            .dashboard-grid {
                grid-template-columns: 1fr 420px;
            }
            
            .storage-grid {
                grid-template-columns: 70px repeat(3, 1fr);
                grid-template-rows: 55px repeat(3, 1fr);
                gap: 12px;
            }
        }

        @media (max-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            
            .control-section {
                position: static;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 1.5rem;
            }
        }

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

            .esp-status-container {
                top: auto;
                bottom: 20px;
                right: 20px;
                flex-direction: row;
            }

            .esp-status-item {
                min-width: 150px;
                padding: 0.7rem 1rem;
            }

            .storage-grid {
                grid-template-columns: 60px repeat(3, 1fr);
                grid-template-rows: 50px repeat(3, 1fr);
                gap: 10px;
            }
            
            .grid-cell {
                padding: 1rem;
            }
            
            .item-name {
                font-size: 1rem;
            }
            
            .item-icon {
                font-size: 2rem;
            }

            .mode-selector {
                grid-template-columns: 1fr;
            }
            
            .control-section {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <h1>Automated Storage</h1>
        <div class="navbar-menu">
            <a href="dashboard.php" class="active">Dashboard</a>
            <a href="input.php">Input Barang</a>
            <a href="output.php">Output Barang</a>
            <a href="run_robot.php">🤖 Run Robot</a>
            <a href="history.php">History</a>
            <div class="user-info">
                <span><?= htmlspecialchars($_SESSION['nama_lengkap']) ?></span>
                <a href="../index.php?action=logout" class="btn-danger">Logout</a>
            </div>
        </div>
    </nav>

    <!-- ESP Status Indicators -->
    <div class="esp-status-container">
        <div class="esp-status-item esp-<?= $esp1Status ?>" id="esp1Status">
            <div class="esp-icon">🤖</div>
            <div class="esp-info">
                <div class="esp-label">ESP-1</div>
                <div class="esp-state"><?= $esp1Status === 'online' ? '✅ Online' : '⚠️ Offline' ?></div>
                <?php if ($esp1LastUpdate): ?>
                    <div class="esp-time">Last: <?= $esp1LastUpdate ?></div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="esp-status-item esp-<?= $esp2Status ?>" id="esp2Status">
            <div class="esp-icon">🤖</div>
            <div class="esp-info">
                <div class="esp-label">ESP-2</div>
                <div class="esp-state"><?= $esp2Status === 'online' ? '✅ Online' : '⚠️ Offline' ?></div>
                <?php if ($esp2LastUpdate): ?>
                    <div class="esp-time">Last: <?= $esp2LastUpdate ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="dashboard-grid">
            <!-- Left: Storage Grid -->
            <div class="storage-section">
                <div class="section-header">
                    <h2>Storage Grid 3×3</h2>
                    <p>Overview status penyimpanan barang real-time</p>
                </div>
                
                <div class="storage-grid">
                    <div class="grid-cell grid-header"></div>
                    <div class="grid-cell grid-header">COL 1</div>
                    <div class="grid-cell grid-header">COL 2</div>
                    <div class="grid-cell grid-header">COL 3</div>
                    
                    <?php for($row = 1; $row <= 3; $row++): ?>
                        <div class="grid-cell grid-header">ROW <?= $row ?></div>
                        <?php for($col = 1; $col <= 3; $col++): ?>
                            <?php 
                            $cell = $grid[$row][$col];
                            $isEmpty = empty($cell['items']);
                            $class = $isEmpty ? 'grid-empty' : 'grid-filled';
                            ?>
                            <div class="grid-cell <?= $class ?>">
                                <span class="slot-id"><?= $cell['slot_id'] ?></span>
                                <?php if($isEmpty): ?>
                                    <div class="item-icon">📦</div>
                                    <div class="item-name" style="color: #999;">Kosong</div>
                                <?php else: ?>
                                    <div class="item-icon">📦</div>
                                    <div class="item-name"><?= htmlspecialchars($cell['items'][0]['nama_barang']) ?></div>
                                    <div class="item-count"><?= $cell['items'][0]['jumlah'] ?> item • <?= count($cell['items']) ?> jenis</div>
                                <?php endif; ?>
                            </div>
                        <?php endfor; ?>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Right: Control Panel -->
            <div class="control-section">
                <!-- Mode Selection -->
                <div class="control-card">
                    <h3><span style="filter: drop-shadow(0 0 8px rgba(255, 193, 7, 0.6)) drop-shadow(0 0 15px rgba(255, 193, 7, 0.4));">⚙️</span> Mode Operasi</h3>
                    <div class="mode-selector">
                        <div class="mode-option <?= $currentMode === 'FIFO' ? 'active' : '' ?>" onclick="changeMode('FIFO')">
                            <?php if($currentMode === 'FIFO'): ?>
                                <div class="mode-badge">AKTIF</div>
                            <?php endif; ?>
                            <div class="mode-icon">⏱️</div>
                            <div class="mode-name">FIFO</div>
                            <div class="mode-desc">First In First Out</div>
                        </div>

                        <div class="mode-option <?= $currentMode === 'LIFO' ? 'active' : '' ?>" onclick="changeMode('LIFO')">
                            <?php if($currentMode === 'LIFO'): ?>
                                <div class="mode-badge">AKTIF</div>
                            <?php endif; ?>
                            <div class="mode-icon">🔄</div>
                            <div class="mode-name">LIFO</div>
                            <div class="mode-desc">Last In First Out</div>
                        </div>

                        <div class="mode-option <?= $currentMode === 'FEFO' ? 'active' : '' ?>" onclick="changeMode('FEFO')">
                            <?php if($currentMode === 'FEFO'): ?>
                                <div class="mode-badge">AKTIF</div>
                            <?php endif; ?>
                            <div class="mode-icon">📅</div>
                            <div class="mode-name">FEFO</div>
                            <div class="mode-desc">First Expired First Out</div>
                        </div>
                    </div>
                </div>

                <!-- Input/Output Control -->
                <div class="control-card">
                    <h3><span style="filter: drop-shadow(0 0 8px rgba(255, 193, 7, 0.6)) drop-shadow(0 0 15px rgba(255, 193, 7, 0.4));">🎛️</span> Kontrol I/O</h3>
                    <div class="io-section">
                        <div class="io-button" onclick="goToInput()">
                            <div class="io-button-content">
                                <div class="io-icon">📥</div>
                                <div class="io-title">INPUT</div>
                            </div>
                            <div class="io-arrow">→</div>
                        </div>

                        <div class="io-button" onclick="goToOutput()">
                            <div class="io-button-content">
                                <div class="io-icon">📤</div>
                                <div class="io-title">OUTPUT</div>
                            </div>
                            <div class="io-arrow">←</div>
                        </div>

                        <div class="io-button" onclick="goToRobot()">
                            <div class="io-button-content">
                                <div class="io-icon">🤖</div>
                                <div class="io-title">RUN ROBOT</div>
                            </div>
                            <div class="io-arrow">⚡</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Auto refresh ESP status every 10 seconds
        function refreshESPStatus() {
            fetch('../api/get_esp_status.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateESPDisplay('ESP1', data.esp1);
                        updateESPDisplay('ESP2', data.esp2);
                    }
                })
                .catch(error => console.error('Error fetching ESP status:', error));
        }

        function updateESPDisplay(espId, espData) {
            const espElement = document.getElementById(espId.toLowerCase() + 'Status');
            if (!espElement) return;

            const statusClass = espData.status === 'online' ? 'esp-online' : 'esp-offline';
            const statusText = espData.status === 'online' ? '✅ Online' : '⚠️ Offline';
            
            // Update class
            espElement.className = `esp-status-item ${statusClass}`;
            
            // Update state text
            const stateElement = espElement.querySelector('.esp-state');
            if (stateElement) {
                stateElement.textContent = statusText;
            }
            
            // Update time
            const timeElement = espElement.querySelector('.esp-time');
            if (timeElement && espData.last_update) {
                timeElement.textContent = `Last: ${espData.last_update}`;
                timeElement.style.display = 'block';
            } else if (timeElement) {
                timeElement.style.display = 'none';
            }
        }

        // Change Mode
        async function changeMode(mode) {
            const result = await Swal.fire({
                title: `Ubah ke Mode ${mode}?`,
                html: `<div style="color: #666; margin-top: 10px; font-weight: 500;">Mode ini akan mengatur prioritas pengambilan barang</div>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Ubah',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#ffc107',
                cancelButtonColor: '#d0d0d0',
                background: '#ffffff',
                color: '#1a1a1a'
            });

            if (result.isConfirmed) {
                try {
                    const response = await fetch('../api/change_mode.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ mode: mode })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Mode Diubah!',
                            text: `Mode berhasil diubah ke ${mode}`,
                            confirmButtonColor: '#ffc107',
                            background: '#ffffff',
                            color: '#1a1a1a'
                        }).then(() => location.reload());
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Gagal',
                            text: data.message,
                            confirmButtonColor: '#ffc107',
                            background: '#ffffff',
                            color: '#1a1a1a'
                        });
                    }
                } catch (error) {
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal',
                        text: 'Terjadi kesalahan saat mengubah mode',
                        confirmButtonColor: '#ffc107',
                        background: '#ffffff',
                        color: '#1a1a1a'
                    });
                }
            }
        }

        function goToInput() {
            window.location.href = 'input.php';
        }

        function goToOutput() {
            window.location.href = 'output.php';
        }

        function goToRobot() {
            window.location.href = 'run_robot.php';
        }

        // Initialize - refresh ESP status every 10 seconds
        window.addEventListener('load', () => {
            refreshESPStatus();
            setInterval(refreshESPStatus, 10000); // Refresh every 10 seconds
        });
    </script>
</body>
</html>
esp_command.php