<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../lib/qrcode_generator.php';

$auth = new AuthController($pdo);
$auth->requireLogin();

// Get existing products
$stmt = $pdo->query("
    SELECT DISTINCT nama_barang, jenis
    FROM history
    WHERE action_type = 'INPUT'
    ORDER BY created_at DESC
    LIMIT 50
");
$existingBarang = $stmt->fetchAll();

// Get available slots
$stmtSlots = $pdo->query("
    SELECT slot_id FROM slot
    WHERE barang_id IS NULL AND status = 'Kosong'
    ORDER BY slot_id
");
$availableSlots = $stmtSlots->fetchAll(PDO::FETCH_COLUMN);
$availableSlotsCount = count($availableSlots);

// Get current stock (yang sudah ada di slot)
$stmtStock = $pdo->query("
    SELECT 
        b.barang_id,
        b.nama_barang, 
        b.jenis,
        b.berat,
        b.barcode,
        b.qr_code_path
    FROM barang b
    INNER JOIN slot s ON b.barang_id = s.barang_id
    WHERE s.barang_id IS NOT NULL
    ORDER BY b.created_at DESC
");
$stockBarang = $stmtStock->fetchAll();

// Get barang yang sudah di-generate QR tapi belum masuk storage
$stmtPending = $pdo->query("
    SELECT 
        b.barang_id,
        b.nama_barang,
        b.jenis,
        b.berat,
        b.barcode,
        b.qr_code_path,
        b.created_at
    FROM barang b
    LEFT JOIN slot s ON b.barang_id = s.barang_id
    WHERE s.barang_id IS NULL
    ORDER BY b.created_at DESC
");
$pendingBarang = $stmtPending->fetchAll();

$error = '';
$success = '';
$generatedQRCode = null;
$generatedBarcode = null;
$savedBarangData = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        $nama_barang = trim($_POST['nama_barang']);
        $jenis = trim($_POST['jenis']);
        $berat = !empty($_POST['berat']) ? (float)$_POST['berat'] : null;
        $exp_date = !empty($_POST['exp_date']) ? $_POST['exp_date'] : null;
        
        if (empty($nama_barang) || empty($jenis)) {
            throw new Exception('Nama barang dan jenis harus diisi!');
        }
        
        $barcode = generateBarcodeWithCounter('PROD', $pdo);
        
        $attempts = 0;
        while (isBarcodeExists($barcode, $pdo) && $attempts < 10) {
            $barcode = generateUniqueBarcode();
            $attempts++;
        }
        
        if (isBarcodeExists($barcode, $pdo)) {
            throw new Exception('Gagal generate barcode unik. Silakan coba lagi.');
        }
        
        $qrCodePath = generateProductQRCode($barcode, $nama_barang, $berat);
        
        // Insert barang TANPA assign ke slot
        try {
            $stmt = $pdo->prepare("
                INSERT INTO barang (nama_barang, jenis, berat, barcode, qr_code_path, exp_date, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$nama_barang, $jenis, $berat, $barcode, $qrCodePath, $exp_date]);
        } catch (PDOException $e) {
            $stmt = $pdo->prepare("
                INSERT INTO barang (nama_barang, jenis, berat, barcode, qr_code_path, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$nama_barang, $jenis, $berat, $barcode, $qrCodePath]);
        }
        $barang_id = $pdo->lastInsertId();
        
        $pdo->commit();
        
        $generatedQRCode = $qrCodePath;
        $generatedBarcode = $barcode;
        $savedBarangData = [
            'nama_barang' => $nama_barang,
            'jenis' => $jenis,
            'berat' => $berat,
            'exp_date' => $exp_date
        ];
        
        $success = "✅ QR Code berhasil dibuat! Silakan scan di menu Run Robot untuk input ke storage.";
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Input Barang - Automated Storage</title>
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

        /* Card Styles */
        .card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            border: 2px solid rgba(255, 193, 7, 0.3);
            box-shadow: 
                0 10px 40px rgba(0, 0, 0, 0.08),
                0 0 50px rgba(255, 193, 7, 0.15);
            padding: 2rem;
            margin-bottom: 1.5rem;
        }

        .card h2 {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1a1a1a;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card h2::before {
            filter: 
                drop-shadow(0 0 10px rgba(255, 193, 7, 0.6))
                drop-shadow(0 0 18px rgba(255, 193, 7, 0.4));
        }

        .card p {
            color: #666;
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
        }

        /* Alert */
        .alert {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-weight: 600;
            font-size: 0.9rem;
            border: 2px solid;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-danger {
            background: rgba(239, 68, 68, 0.15);
            color: #dc2626;
            border-color: rgba(239, 68, 68, 0.5);
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            color: #059669;
            border-color: rgba(16, 185, 129, 0.5);
        }

        /* Info Box */
        .info-box {
            background: rgba(255, 193, 7, 0.12);
            border: 2px solid rgba(255, 193, 7, 0.4);
            border-radius: 12px;
            padding: 1rem 1.2rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .info-box::before {
            content: '📊';
            font-size: 1.5rem;
            filter: 
                drop-shadow(0 0 8px rgba(255, 193, 7, 0.6))
                drop-shadow(0 0 15px rgba(255, 193, 7, 0.4));
        }

        .info-box strong {
            color: #1a1a1a;
            font-weight: 700;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.6rem;
            color: #1a1a1a;
            font-weight: 700;
            font-size: 0.9rem;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.875rem 1.2rem;
            border: 2px solid rgba(255, 193, 7, 0.3);
            border-radius: 12px;
            font-size: 0.95rem;
            font-family: 'Poppins', sans-serif;
            background: #fafafa;
            color: #1a1a1a;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-weight: 500;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #ffc107;
            background: #ffffff;
            box-shadow: 
                0 0 0 3px rgba(255, 193, 7, 0.2),
                0 4px 20px rgba(255, 193, 7, 0.15);
        }

        .form-hint {
            display: block;
            margin-top: 0.5rem;
            font-size: 0.8rem;
            color: #999;
            font-weight: 500;
        }

        /* Button Primary */
        .btn-primary {
            background: linear-gradient(135deg, #ffc107 0%, #ffcd38 100%);
            color: #1a1a1a;
            border: none;
            padding: 0.875rem 1.5rem;
            border-radius: 12px;
            font-weight: 800;
            cursor: pointer;
            font-size: 0.95rem;
            width: 100%;
            box-shadow: 
                0 4px 15px rgba(255, 193, 7, 0.4),
                0 0 30px rgba(255, 193, 7, 0.2);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            letter-spacing: 0.5px;
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 
                0 8px 30px rgba(255, 193, 7, 0.5),
                0 0 50px rgba(255, 193, 7, 0.3);
        }

        /* QR Display */
        .qr-display {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 20px;
            padding: 2.5rem;
            text-align: center;
            margin: 2rem auto;
            max-width: 600px;
            border: 3px solid #ffc107;
            box-shadow: 
                0 10px 40px rgba(0, 0, 0, 0.12),
                0 0 60px rgba(255, 193, 7, 0.25);
        }

        .qr-display h3 {
            color: #1a1a1a;
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
        }

        .product-info {
            background: #fafafa;
            padding: 1.5rem;
            border-radius: 15px;
            margin-bottom: 2rem;
            text-align: left;
            border: 2px solid rgba(255, 193, 7, 0.2);
        }

        .product-info p {
            color: #1a1a1a;
            margin: 0.8rem 0;
            font-size: 0.95rem;
            font-weight: 500;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .product-info strong {
            color: #1a1a1a;
            font-weight: 700;
        }

        .qr-display img {
            max-width: 400px;
            width: 100%;
            border: 5px solid #1a1a1a;
            border-radius: 15px;
            margin: 1.5rem auto;
            display: block;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
        }

        .barcode-text {
            font-family: 'Courier New', monospace;
            font-size: 1.3rem;
            color: #1a1a1a;
            background: rgba(255, 193, 7, 0.2);
            padding: 0.8rem 1.5rem;
            border-radius: 10px;
            display: inline-block;
            margin-top: 1.5rem;
            border: 2px solid rgba(255, 193, 7, 0.5);
            letter-spacing: 3px;
            font-weight: bold;
        }

        /* QR Actions */
        .qr-actions {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
            flex-wrap: wrap;
        }

        .btn-download {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }

        .btn-download:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(16, 185, 129, 0.5);
        }

        .btn-print {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: #fff;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            border: none;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4);
        }

        .btn-print:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(245, 158, 11, 0.5);
        }

        .btn-new {
            background: linear-gradient(135deg, #ffc107, #ffcd38);
            color: #1a1a1a;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            text-decoration: none;
            display: inline-block;
            font-weight: 700;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(255, 193, 7, 0.4);
        }

        .btn-new:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 25px rgba(255, 193, 7, 0.5);
        }

        /* Grid Layout */
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        /* Table */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        table thead {
            background: rgba(255, 193, 7, 0.12);
        }

        table th,
        table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid rgba(255, 193, 7, 0.2);
        }

        table th {
            font-weight: 800;
            color: #1a1a1a;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        table td {
            color: #666;
            font-size: 0.9rem;
            font-weight: 500;
        }

        table tbody tr {
            transition: all 0.3s;
        }

        table tbody tr:hover {
            background: rgba(255, 193, 7, 0.08);
        }

        /* QR Thumb */
        .qr-thumb {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            border: 2px solid rgba(255, 193, 7, 0.3);
            cursor: pointer;
            transition: all 0.3s;
        }

        .qr-thumb:hover {
            transform: scale(1.1);
            border-color: #ffc107;
            box-shadow: 0 4px 15px rgba(255, 193, 7, 0.4);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 2rem;
            color: #999;
        }

        .empty-state .icon {
            font-size: 3.5rem;
            margin-bottom: 1rem;
            filter: 
                drop-shadow(0 0 8px rgba(255, 193, 7, 0.4))
                drop-shadow(0 0 15px rgba(255, 193, 7, 0.2));
        }

        .empty-state p {
            font-size: 1.1rem;
            font-weight: 600;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .empty-state small {
            display: block;
            color: #999;
            font-size: 0.85rem;
        }

        /* Scrollable Table Container */
        .table-container {
            max-height: 500px;
            overflow-y: auto;
            border-radius: 12px;
        }

        .table-container::-webkit-scrollbar {
            width: 8px;
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

        /* Table Footer */
        .table-footer {
            text-align: center;
            margin-top: 1rem;
            color: #999;
            font-size: 0.85rem;
            font-weight: 600;
        }

        /* Print Styles */
        @media print {
            body * {
                visibility: hidden;
            }
            
            .print-area,
            .print-area * {
                visibility: visible;
            }
            
            .print-area {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                padding: 2rem;
                background: white;
            }
            
            .qr-actions,
            .navbar {
                display: none !important;
            }
            
            .qr-display {
                border: 3px solid #000 !important;
                box-shadow: none !important;
            }
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
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

            .card {
                padding: 1.5rem;
            }

            .qr-actions {
                flex-direction: column;
            }

            .qr-actions a,
            .qr-actions button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<nav class="navbar">
    <h1>Automated Storage</h1>
    <div class="navbar-menu">
        <a href="dashboard.php">Dashboard</a>
        <a href="input.php" class="active">Input Barang</a>
        <a href="output.php">Output Barang</a>
        <a href="run_robot.php">🤖 Run Robot</a>
        <a href="history.php">History</a>
        <div class="user-info">
            <span><?= htmlspecialchars($_SESSION['nama_lengkap']) ?></span>
            <a href="../index.php?action=logout" class="btn-danger">Logout</a>
        </div>
    </div>
</nav>

<div class="container">
    <?php if ($generatedQRCode && $savedBarangData): ?>
        <!-- QR CODE DISPLAY -->
        <div class="qr-display print-area">
            <h3>🎉 QR Code Berhasil Dibuat!</h3>
            
            <div class="product-info">
                <p><strong>Nama Produk:</strong> <span><?= htmlspecialchars($savedBarangData['nama_barang']) ?></span></p>
                <p><strong>Jenis:</strong> <span><?= htmlspecialchars($savedBarangData['jenis']) ?></span></p>
                <?php if ($savedBarangData['berat']): ?>
                    <p><strong>Berat:</strong> <span><?= number_format($savedBarangData['berat'], 0, ',', '.') ?> gram</span></p>
                <?php endif; ?>
                <?php if ($savedBarangData['exp_date']): ?>
                    <p><strong>Kadaluarsa:</strong> <span><?= date('d/m/Y', strtotime($savedBarangData['exp_date'])) ?></span></p>
                <?php endif; ?>
            </div>
            
            <img src="<?= htmlspecialchars($generatedQRCode) ?>" alt="QR Code" id="qrCodeImage">
            
            <div class="barcode-text"><?= htmlspecialchars($generatedBarcode) ?></div>
            
            <div class="qr-actions">
                <a href="<?= htmlspecialchars($generatedQRCode) ?>" download="QR_<?= htmlspecialchars($generatedBarcode) ?>.png" class="btn-download">
                    📥 Download
                </a>
                <button onclick="printQRCode()" class="btn-print">
                    🖨️ Print
                </button>
                <a href="input.php" class="btn-new">
                    ➕ Produk Baru
                </a>
            </div>
        </div>
    <?php else: ?>
        <!-- FORM & STORAGE GRID -->
        <div class="content-grid">
            <!-- FORM INPUT -->
            <div class="card">
                <h2>📦 Input Produk Baru</h2>
                <p>Generate QR Code untuk produk baru</p>

                <div class="info-box">
                    <strong>Slot Tersedia:</strong> <?= $availableSlotsCount ?> / 9 slot
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger">❌ <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" id="inputForm">
                    <div class="form-group">
                        <label for="nama_barang">📦 Nama Produk *</label>
                        <input 
                            type="text" 
                            name="nama_barang" 
                            id="nama_barang" 
                            list="namaBarangList"
                            placeholder="Contoh: Indomie Goreng"
                            required
                            autofocus
                        >
                        <datalist id="namaBarangList">
                            <?php foreach ($existingBarang as $item): ?>
                                <option value="<?= htmlspecialchars($item['nama_barang']) ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <small class="form-hint">Ketik untuk melihat saran dari history</small>
                    </div>

                    <div class="form-group">
                        <label for="jenis">🏷️ Jenis Produk *</label>
                        <select name="jenis" id="jenis" required>
                            <option value="">-- Pilih Jenis --</option>
                            <option value="Makanan">Makanan</option>
                            <option value="Minuman">Minuman</option>
                            <option value="Elektronik">Elektronik</option>
                            <option value="Sembako">Sembako</option>
                            <option value="Obat-obatan">Obat-obatan</option>
                            <option value="Lainnya">Lainnya</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="berat">⚖️ Berat Produk (gram)</label>
                        <input 
                            type="number" 
                            name="berat" 
                            id="berat" 
                            min="0"
                            step="0.01"
                            placeholder="Contoh: 250"
                        >
                        <small class="form-hint">Opsional, masukkan berat dalam gram</small>
                    </div>

                    <div class="form-group">
                        <label for="exp_date">📅 Tanggal Kadaluarsa</label>
                        <input 
                            type="date" 
                            name="exp_date" 
                            id="exp_date" 
                            min="<?= date('Y-m-d') ?>"
                        >
                        <small class="form-hint">Diperlukan untuk mode FEFO (First Expired First Out)</small>
                    </div>

                    <button type="submit" class="btn-primary">
                        🎫 Generate QR Code
                    </button>
                </form>
            </div>

            <!-- PRODUK DI STORAGE -->
            <div class="card">
                <h2>🏪 Produk di Storage</h2>
                <p>Produk yang sudah masuk storage (via Robot)</p>

                <?php if (empty($stockBarang)): ?>
                    <div class="empty-state">
                        <div class="icon">📦</div>
                        <p>Belum ada produk dalam storage</p>
                        <small>Gunakan menu Run Robot untuk scan QR</small>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>QR</th>
                                    <th>Nama Produk</th>
                                    <th>Jenis</th>
                                    <th>Berat</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stockBarang as $item): ?>
                                    <tr>
                                        <td>
                                            <?php if ($item['qr_code_path']): ?>
                                                <img 
                                                    src="<?= htmlspecialchars($item['qr_code_path']) ?>" 
                                                    class="qr-thumb" 
                                                    alt="QR"
                                                    onclick="Swal.fire({imageUrl: '<?= htmlspecialchars($item['qr_code_path']) ?>', imageAlt: 'QR Code', showConfirmButton: false, background: '#ffffff'})"
                                                >
                                            <?php else: ?>
                                                <span style="color:#999;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?= htmlspecialchars($item['nama_barang']) ?></strong></td>
                                        <td><?= htmlspecialchars($item['jenis']) ?></td>
                                        <td>
                                            <?php if ($item['berat']): ?>
                                                <?= number_format($item['berat'], 0) ?>g
                                            <?php else: ?>
                                                <span style="color:#999;">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        Total: <?= count($stockBarang) ?> produk
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- QR CODE YANG SUDAH DIBUAT (BELUM MASUK STORAGE) -->
        <div class="card">
            <h2>🎫 QR Code Produk (Siap di-Scan)</h2>
            <p>Produk yang sudah di-generate QR Code tapi belum masuk storage</p>

            <?php if (empty($pendingBarang)): ?>
                <div class="empty-state">
                    <div class="icon">🎫</div>
                    <p>Belum ada QR Code yang siap di-scan</p>
                    <small>Generate QR Code baru di form di atas</small>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Waktu Dibuat</th>
                                <th>QR</th>
                                <th>Nama Produk</th>
                                <th>Jenis</th>
                                <th>Berat</th>
                                <th>Barcode</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingBarang as $item): ?>
                                <tr>
                                    <td style="font-size:0.85rem;">
                                        <?= date('d/m/Y H:i', strtotime($item['created_at'])) ?>
                                    </td>
                                    <td>
                                        <?php if ($item['qr_code_path']): ?>
                                            <img 
                                                src="<?= htmlspecialchars($item['qr_code_path']) ?>" 
                                                class="qr-thumb" 
                                                alt="QR"
                                                onclick="Swal.fire({imageUrl: '<?= htmlspecialchars($item['qr_code_path']) ?>', imageAlt: 'QR Code', showConfirmButton: false, background: '#ffffff'})"
                                            >
                                        <?php else: ?>
                                            <span style="color:#999;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong><?= htmlspecialchars($item['nama_barang']) ?></strong></td>
                                    <td><?= htmlspecialchars($item['jenis']) ?></td>
                                    <td>
                                        <?php if ($item['berat']): ?>
                                            <?= number_format($item['berat'], 0) ?>g
                                        <?php else: ?>
                                            <span style="color:#999;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-family:'Courier New', monospace; font-size:0.8rem;">
                                        <?= htmlspecialchars($item['barcode']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="table-footer">
                    Total: <?= count($pendingBarang) ?> QR Code siap di-scan
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function printQRCode() {
    const printWindow = window.open('', '_blank', 'width=800,height=600');
    
    const qrImage = document.querySelector('#qrCodeImage').src;
    const productInfo = document.querySelector('.product-info').innerHTML;
    const barcode = document.querySelector('.barcode-text').textContent;
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Print QR Code</title>
            <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: 'Poppins', sans-serif;
                    padding: 2rem;
                    background: white;
                }
                .qr-display {
                    max-width: 500px;
                    margin: 0 auto;
                    text-align: center;
                    border: 3px solid #ffc107;
                    border-radius: 15px;
                    padding: 2rem;
                    background: white;
                }
                h3 {
                    color: #1a1a1a;
                    font-size: 1.6rem;
                    font-weight: 800;
                    margin-bottom: 1.5rem;
                }
                .product-info {
                    background: #fafafa;
                    padding: 1.2rem;
                    border-radius: 10px;
                    margin-bottom: 2rem;
                    text-align: left;
                    border: 2px solid rgba(255, 193, 7, 0.2);
                }
                .product-info p {
                    color: #1a1a1a;
                    margin: 0.6rem 0;
                    font-size: 0.95rem;
                    font-weight: 500;
                    display: flex;
                    justify-content: space-between;
                }
                .product-info strong {
                    color: #1a1a1a;
                    font-weight: 700;
                }
                img {
                    max-width: 350px;
                    width: 100%;
                    border: 5px solid #1a1a1a;
                    border-radius: 10px;
                    margin: 1.5rem auto;
                    display: block;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                }
                .barcode-text {
                    font-family: 'Courier New', monospace;
                    font-size: 1.3rem;
                    color: #1a1a1a;
                    background: rgba(255, 193, 7, 0.2);
                    padding: 0.8rem 1.5rem;
                    border-radius: 8px;
                    display: inline-block;
                    margin-top: 1.5rem;
                    border: 2px solid rgba(255, 193, 7, 0.5);
                    letter-spacing: 3px;
                    font-weight: bold;
                }
                @media print {
                    body { padding: 0; }
                    .qr-display { 
                        border: 3px solid #000;
                        page-break-inside: avoid;
                    }
                }
            </style>
        </head>
        <body>
            <div class="qr-display">
                <h3>QR Code Produk</h3>
                <div class="product-info">
                    ${productInfo}
                </div>
                <img src="${qrImage}" alt="QR Code">
                <div class="barcode-text">${barcode}</div>
            </div>
        </body>
        </html>
    `);
    
    printWindow.document.close();
    
    setTimeout(() => {
        printWindow.focus();
        printWindow.print();
    }, 500);
}

// Auto-focus pada form saat halaman dimuat
window.addEventListener('load', function() {
    const namaBarangInput = document.getElementById('nama_barang');
    if (namaBarangInput) {
        namaBarangInput.focus();
    }
});
</script>
</body>
</html>