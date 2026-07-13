<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../controllers/AuthController.php';

$auth = new AuthController($pdo);
$auth->requireLogin();
$stmt = $pdo->query("
    SELECT 
        h.history_id,
        h.barang_id,
        h.slot_id,
        h.action_type,
        h.user_id,
        h.created_at,
        COALESCE(h.nama_barang, b.nama_barang, 'Unknown') as nama_barang,
        COALESCE(h.jenis, b.jenis, '-') as jenis,
        COALESCE(h.berat, b.berat, '-') as berat,
        COALESCE(b.barcode, '-') as barcode,
        COALESCE(b.qr_code_path, '') as qr_code_path,
        u.nama_lengkap as user_name
    FROM history h
    LEFT JOIN barang b ON h.barang_id = b.barang_id
    LEFT JOIN users u ON h.user_id = u.user_id
    ORDER BY h.created_at DESC
    LIMIT 100
");

$histories = $stmt->fetchAll();

// Hitung statistik
$totalAktivitas = count($histories);
$totalMasuk = 0;
$totalKeluar = 0;

foreach ($histories as $h) {
    if ($h['action_type'] === 'INPUT') {
        $totalMasuk++;
    } else {
        $totalKeluar++;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History - Automated Storage</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
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

        .page-header-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: rgba(255, 193, 7, 0.15);
            border: 2px solid rgba(255, 193, 7, 0.3);
            padding: 0.5rem 1rem;
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 800;
            color: #1a1a1a;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 1rem;
        }

        .page-header-badge span:first-child {
            filter: 
                drop-shadow(0 0 8px rgba(255, 193, 7, 0.6))
                drop-shadow(0 0 15px rgba(255, 193, 7, 0.4));
        }

        .page-header h1 {
            font-size: 2.5rem;
            font-weight: 900;
            color: #1a1a1a;
            margin-bottom: 0.5rem;
            line-height: 1.1;
            letter-spacing: -0.02em;
        }

        .page-header p {
            font-size: 1rem;
            color: #666;
            max-width: 600px;
            line-height: 1.6;
            font-weight: 500;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            padding: 2rem;
            border-radius: 20px;
            border: 2px solid rgba(255, 193, 7, 0.3);
            box-shadow: 
                0 10px 40px rgba(0, 0, 0, 0.08),
                0 0 50px rgba(255, 193, 7, 0.15);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 50% 0%, rgba(255, 193, 7, 0.08), transparent 70%);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-8px);
            border-color: #ffc107;
            box-shadow: 
                0 16px 48px rgba(255, 193, 7, 0.25),
                0 0 80px rgba(255, 193, 7, 0.2);
        }

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-card-icon {
            width: 56px;
            height: 56px;
            background: rgba(255, 193, 7, 0.15);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 1.2rem;
            box-shadow: 0 4px 16px rgba(255, 193, 7, 0.3);
            filter: 
                drop-shadow(0 0 8px rgba(255, 193, 7, 0.4))
                drop-shadow(0 0 15px rgba(255, 193, 7, 0.3));
        }

        .stat-card-label {
            font-size: 0.875rem;
            font-weight: 700;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.8rem;
        }

        .stat-card-value {
            font-size: 3rem;
            font-weight: 900;
            line-height: 1;
            margin-bottom: 0.5rem;
            letter-spacing: -0.02em;
            color: #1a1a1a;
        }

        .stat-card-change {
            font-size: 0.8rem;
            color: #999;
            font-weight: 600;
        }

        /* Filter Bar */
        .filter-bar {
            background: rgba(255, 255, 255, 0.95);
            border: 2px solid rgba(255, 193, 7, 0.3);
            border-radius: 18px;
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 
                0 8px 30px rgba(0, 0, 0, 0.06),
                0 0 40px rgba(255, 193, 7, 0.12);
        }

        .filter-label {
            font-size: 0.875rem;
            font-weight: 700;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .filter-controls {
            display: flex;
            gap: 0.75rem;
        }

        .filter-btn {
            padding: 0.6rem 1.4rem;
            border-radius: 12px;
            border: 2px solid rgba(255, 193, 7, 0.3);
            background: #ffffff;
            color: #666;
            font-size: 0.875rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Poppins', sans-serif;
        }

        .filter-btn:hover {
            background: rgba(255, 193, 7, 0.1);
            color: #1a1a1a;
            border-color: rgba(255, 193, 7, 0.5);
        }

        .filter-btn.active {
            background: rgba(255, 193, 7, 0.2);
            border-color: #ffc107;
            color: #1a1a1a;
            box-shadow: 0 0 20px rgba(255, 193, 7, 0.3);
        }

        /* Table Card */
        .table-card {
            background: rgba(255, 255, 255, 0.95);
            border: 2px solid rgba(255, 193, 7, 0.3);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 
                0 10px 40px rgba(0, 0, 0, 0.08),
                0 0 50px rgba(255, 193, 7, 0.15);
        }

        .table-header {
            padding: 2rem 2.5rem;
            border-bottom: 2px solid rgba(255, 193, 7, 0.2);
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.08), rgba(255, 205, 56, 0.05));
        }

        .table-header h2 {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1a1a1a;
            margin-bottom: 0.5rem;
            letter-spacing: -0.02em;
        }

        .table-header p {
            font-size: 0.875rem;
            color: #666;
            font-weight: 500;
        }

        /* Modern Table */
        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        thead tr {
            background: rgba(255, 193, 7, 0.08);
        }

        thead th {
            padding: 1.2rem 2rem;
            text-align: left;
            font-weight: 800;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #1a1a1a;
            border-bottom: 2px solid rgba(255, 193, 7, 0.2);
        }

        tbody tr {
            transition: all 0.3s;
            cursor: pointer;
            position: relative;
        }

        tbody tr::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 0;
            height: 100%;
            background: linear-gradient(90deg, #ffc107, transparent);
            transition: width 0.3s;
        }

        tbody tr:hover {
            background: rgba(255, 193, 7, 0.08);
        }

        tbody tr:hover::before {
            width: 4px;
        }

        tbody tr:not(:last-child) td {
            border-bottom: 1px solid rgba(255, 193, 7, 0.12);
        }

        tbody td {
            padding: 1.5rem 2rem;
            font-size: 0.9rem;
        }

        /* Item Cell */
        .item-cell {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .item-avatar {
            width: 48px;
            height: 48px;
            background: rgba(255, 193, 7, 0.15);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
            border: 2px solid rgba(255, 193, 7, 0.3);
            filter: 
                drop-shadow(0 0 6px rgba(255, 193, 7, 0.3))
                drop-shadow(0 0 12px rgba(255, 193, 7, 0.2));
        }

        .item-info {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .item-name {
            font-weight: 800;
            color: #1a1a1a;
            font-size: 0.95rem;
        }

        .item-barcode {
            font-family: 'Courier New', monospace;
            font-size: 0.75rem;
            color: #999;
            font-weight: 600;
        }

        /* Badge */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 100px;
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .badge-input {
            background: rgba(16, 185, 129, 0.15);
            color: #059669;
            border: 2px solid rgba(16, 185, 129, 0.3);
        }

        .badge-output {
            background: rgba(245, 158, 11, 0.15);
            color: #d97706;
            border: 2px solid rgba(245, 158, 11, 0.3);
        }

        /* Slot Badge */
        .slot-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: rgba(255, 193, 7, 0.15);
            border: 2px solid rgba(255, 193, 7, 0.4);
            border-radius: 10px;
            color: #1a1a1a;
            font-weight: 800;
            font-size: 0.875rem;
            font-family: 'Courier New', monospace;
        }

        /* User Cell */
        .user-cell {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            color: #666;
            font-weight: 600;
        }

        .user-avatar {
            width: 32px;
            height: 32px;
            background: rgba(255, 193, 7, 0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            border: 2px solid rgba(255, 193, 7, 0.3);
        }

        /* Time Cell */
        .time-cell {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }

        .time-main {
            font-weight: 800;
            color: #1a1a1a;
            font-size: 0.9rem;
        }

        .time-sub {
            font-size: 0.75rem;
            color: #999;
            font-weight: 600;
        }

        /* Modal Popup */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(10px);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }

        .modal.show {
            display: flex;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border: 2px solid rgba(255, 193, 7, 0.3);
            border-radius: 24px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 
                0 20px 60px rgba(0, 0, 0, 0.2),
                0 0 100px rgba(255, 193, 7, 0.2);
            animation: slideUp 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }

        @keyframes slideUp {
            from {
                transform: translateY(50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 2rem 2.5rem;
            border-bottom: 2px solid rgba(255, 193, 7, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.08), transparent);
        }

        .modal-header h2 {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1a1a1a;
        }

        .modal-close {
            width: 40px;
            height: 40px;
            background: rgba(255, 193, 7, 0.1);
            border: 2px solid rgba(255, 193, 7, 0.3);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 1.5rem;
            color: #666;
            transition: all 0.3s;
            font-weight: 700;
        }

        .modal-close:hover {
            background: rgba(239, 68, 68, 0.15);
            border-color: rgba(239, 68, 68, 0.4);
            color: #ef4444;
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 2.5rem;
        }

        .modal-qr {
            text-align: center;
            margin-bottom: 2rem;
            padding: 2rem;
            background: #fafafa;
            border-radius: 16px;
            border: 2px solid rgba(255, 193, 7, 0.2);
        }

        .modal-qr img {
            width: 200px;
            height: 200px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            border: 3px solid #1a1a1a;
        }

        .modal-info {
            display: grid;
            gap: 1rem;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.2rem 1.5rem;
            background: rgba(255, 193, 7, 0.08);
            border: 2px solid rgba(255, 193, 7, 0.15);
            border-radius: 12px;
            transition: all 0.3s;
        }

        .info-row:hover {
            background: rgba(255, 193, 7, 0.12);
            border-color: rgba(255, 193, 7, 0.3);
        }

        .info-label {
            font-size: 0.875rem;
            color: #666;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .info-value {
            font-size: 1rem;
            color: #1a1a1a;
            font-weight: 800;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 5rem 2rem;
        }

        .empty-icon {
            font-size: 5rem;
            margin-bottom: 1.5rem;
            opacity: 0.4;
            animation: emptyFloat 3s ease-in-out infinite;
            filter: 
                drop-shadow(0 0 8px rgba(255, 193, 7, 0.3))
                drop-shadow(0 0 15px rgba(255, 193, 7, 0.2));
        }

        @keyframes emptyFloat {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
        }

        .empty-text {
            font-size: 1.1rem;
            color: #999;
            font-weight: 600;
        }

        /* Scrollbar */
        .table-container::-webkit-scrollbar {
            height: 8px;
        }

        .table-container::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        .table-container::-webkit-scrollbar-thumb {
            background: rgba(255, 193, 7, 0.6);
            border-radius: 10px;
        }

        .table-container::-webkit-scrollbar-thumb:hover {
            background: #ffc107;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .page-header h1 {
                font-size: 2rem;
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

            .container {
                padding: 1rem;
            }

            .page-header h1 {
                font-size: 1.8rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .filter-bar {
                flex-direction: column;
                align-items: flex-start;
                padding: 1.2rem 1.5rem;
            }

            .filter-controls {
                flex-wrap: wrap;
                width: 100%;
            }

            .filter-btn {
                flex: 1;
                min-width: 100px;
            }

            .table-container {
                overflow-x: scroll;
            }

            table {
                min-width: 900px;
            }

            .modal-content {
                width: 95%;
            }

            .modal-body {
                padding: 1.5rem;
            }

            .table-header {
                padding: 1.5rem;
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
            <a href="output.php">Output Barang</a>
            <a href="run_robot.php">🤖 Run Robot</a>
            <a href="history.php" class="active">History</a>
            <div class="user-info">
                <span><?= htmlspecialchars($_SESSION['nama_lengkap']) ?></span>
                <a href="../index.php?action=logout" class="btn-danger">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <div class="page-header-badge">
                <span>📊</span>
                <span>Activity Log</span>
            </div>
            <h1>History Aktivitas</h1>
            <p>Riwayat lengkap masuk dan keluar barang dari sistem penyimpanan otomatis dengan tracking real-time</p>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card total">
                <div class="stat-card-icon">📊</div>
                <div class="stat-card-label">Total Aktivitas</div>
                <div class="stat-card-value"><?= $totalAktivitas ?></div>
                <div class="stat-card-change">All time records</div>
            </div>
            <div class="stat-card input">
                <div class="stat-card-icon">📥</div>
                <div class="stat-card-label">Barang Masuk</div>
                <div class="stat-card-value"><?= $totalMasuk ?></div>
                <div class="stat-card-change">Input transactions</div>
            </div>
            <div class="stat-card output">
                <div class="stat-card-icon">📤</div>
                <div class="stat-card-label">Barang Keluar</div>
                <div class="stat-card-value"><?= $totalKeluar ?></div>
                <div class="stat-card-change">Output transactions</div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-label">🔍 Filter By:</div>
            <div class="filter-controls">
                <button class="filter-btn active" data-filter="all">Semua</button>
                <button class="filter-btn" data-filter="INPUT">📥 Masuk</button>
                <button class="filter-btn" data-filter="OUTPUT">📤 Keluar</button>
            </div>
        </div>

        <!-- History Table -->
        <div class="table-card">
            <div class="table-header">
                <h2>📋 Activity Log</h2>
                <p>Klik baris untuk melihat detail informasi produk</p>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Jenis</th>
                            <th>Slot</th>
                            <th>Action</th>
                            <th>User</th>
                            <th>Timestamp</th>
                        </tr>
                    </thead>
                    <tbody id="historyTable">
                        <?php if (empty($histories)): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <div class="empty-icon">📭</div>
                                        <div class="empty-text">Belum ada history aktivitas</div>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($histories as $h): ?>
                                <tr data-action="<?= $h['action_type'] ?>" 
                                    onclick='showDetail(<?= json_encode($h, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                    <td>
                                        <div class="item-cell">
                                            <div class="item-avatar">📦</div>
                                            <div class="item-info">
                                                <div class="item-name"><?= htmlspecialchars($h['nama_barang']) ?></div>
                                                <?php if ($h['barcode'] !== '-'): ?>
                                                    <div class="item-barcode"><?= htmlspecialchars($h['barcode']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="color: #666; font-weight: 600;"><?= htmlspecialchars($h['jenis']) ?></td>
                                    <td>
                                        <span class="slot-badge"><?= htmlspecialchars($h['slot_id']) ?></span>
                                    </td>
                                    <td>
                                        <?php if ($h['action_type'] === 'INPUT'): ?>
                                            <span class="badge badge-input">
                                                <span>📥</span> Input
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-output">
                                                <span>📤</span> Output
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="user-cell">
                                            <div class="user-avatar">👤</div>
                                            <span><?= htmlspecialchars($h['user_name'] ?? 'System') ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="time-cell">
                                            <div class="time-main"><?= date('H:i:s', strtotime($h['created_at'])) ?></div>
                                            <div class="time-sub"><?= date('d M Y', strtotime($h['created_at'])) ?></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Detail -->
    <div class="modal" id="detailModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>📦 Detail Produk</h2>
                <div class="modal-close" onclick="closeModal()">×</div>
            </div>
            <div class="modal-body">
                <div class="modal-qr" id="modalQR">
                    <!-- QR Code will be inserted here -->
                </div>
                <div class="modal-info">
                    <div class="info-row">
                        <span class="info-label">Nama Barang</span>
                        <span class="info-value" id="detailNama">-</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Barcode</span>
                        <span class="info-value" id="detailBarcode" style="font-family: 'Courier New', monospace;">-</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Jenis</span>
                        <span class="info-value" id="detailJenis">-</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Berat</span>
                        <span class="info-value" id="detailBerat">-</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Slot</span>
                        <span class="info-value" id="detailSlot">-</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Aksi</span>
                        <span class="info-value" id="detailAction">-</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">User</span>
                        <span class="info-value" id="detailUser">-</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Waktu</span>
                        <span class="info-value" id="detailTime">-</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Filter functionality
        const filterBtns = document.querySelectorAll('.filter-btn');
        const tableRows = document.querySelectorAll('#historyTable tr[data-action]');

        filterBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                filterBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');

                const filter = btn.dataset.filter;

                tableRows.forEach(row => {
                    if (filter === 'all' || row.dataset.action === filter) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            });
        });

        // Modal functionality
        function showDetail(data) {
            const modal = document.getElementById('detailModal');
            
            // Set QR Code
            const qrContainer = document.getElementById('modalQR');
            if (data.qr_code_path && data.qr_code_path !== '') {
                qrContainer.innerHTML = `<img src="${data.qr_code_path}" alt="QR Code">`;
            } else {
                qrContainer.innerHTML = '<div style="color:#999; padding:2rem; font-weight:600;">QR Code tidak tersedia</div>';
            }
            
            // Set data
            document.getElementById('detailNama').textContent = data.nama_barang || '-';
            document.getElementById('detailBarcode').textContent = data.barcode || '-';
            document.getElementById('detailJenis').textContent = data.jenis || '-';
            document.getElementById('detailBerat').textContent = data.berat ? data.berat + ' kg' : '-';
            document.getElementById('detailSlot').textContent = data.slot_id || '-';
            
            const actionBadge = data.action_type === 'INPUT' 
                ? '<span style="color:#059669; font-weight:800;">📥 INPUT</span>' 
                : '<span style="color:#d97706; font-weight:800;">📤 OUTPUT</span>';
            document.getElementById('detailAction').innerHTML = actionBadge;
            
            document.getElementById('detailUser').textContent = data.user_name || 'System';
            
            const timestamp = new Date(data.created_at);
            const formattedTime = timestamp.toLocaleString('id-ID', {
                day: '2-digit',
                month: 'long',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            document.getElementById('detailTime').textContent = formattedTime;
            
            modal.classList.add('show');
        }

        function closeModal() {
            document.getElementById('detailModal').classList.remove('show');
        }

        // Close modal on outside click
        document.getElementById('detailModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Close modal on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
            }
        });
    </script>
</body>
</html>