<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../controllers/AuthController.php';

$auth = new AuthController($pdo);
$auth->requireLogin();

// ============================================
// Ambil barang dari HISTORY (yang pernah diinput)
// UNIQUE by nama + jenis, tanpa exp_date
// ============================================
$stmt = $pdo->query("
    SELECT DISTINCT 
        nama_barang, 
        jenis
    FROM history
    WHERE action_type = 'INPUT'
    ORDER BY created_at DESC
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

// Get current stock (yang masih ada di slot)
$stmtStock = $pdo->query("
    SELECT 
        b.nama_barang, 
        b.jenis, 
        COUNT(DISTINCT s.slot_id) AS jumlah_slot,
        MAX(b.exp_date) AS last_exp_date
    FROM barang b
    INNER JOIN slot s ON b.barang_id = s.barang_id
    WHERE s.barang_id IS NOT NULL
    GROUP BY b.nama_barang, b.jenis
    ORDER BY b.created_at DESC
");
$stockBarang = $stmtStock->fetchAll();
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
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: #0a0a0a;
            min-height: 100vh;
            color: #e8e8e8;
        }
        .navbar {
            background: linear-gradient(135deg, #1a1a1a 0%, #0d0d0d 100%);
            padding: 1.2rem 2rem;
            display:flex; justify-content:space-between; align-items:center;
            border-bottom:1px solid rgba(0,153,255,0.3);
            position:sticky; top:0; z-index:100;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
        }
        .navbar h1 {
            font-size:1.5rem; font-weight:700;
            background:linear-gradient(135deg,#00d4ff 0%,#0099ff 50%,#0066ff 100%);
            -webkit-background-clip:text; -webkit-text-fill-color:transparent;
        }
        .navbar-menu { display:flex; align-items:center; gap:0.8rem; }
        .navbar-menu a {
            text-decoration:none; color:#b8b8b8; font-weight:500;
            padding:0.6rem 1.2rem; border-radius:10px; font-size:0.9rem;
            transition:all 0.3s;
        }
        .navbar-menu a:hover, .navbar-menu a.active {
            color:#00d4ff; background:rgba(0,153,255,0.15);
        }
        .user-info {
            display:flex; align-items:center; gap:1rem;
            padding-left:1.5rem; border-left:1px solid rgba(0,153,255,0.3);
        }
        .user-info span { color:#00d4ff; font-size:0.9rem; }
        .btn-danger {
            background:linear-gradient(135deg,#e74c3c,#c0392b);
            color:#fff; padding:0.6rem 1.2rem; border-radius:10px;
            text-decoration:none; font-size:0.9rem; font-weight:700;
            box-shadow:0 4px 15px rgba(231,76,60,0.4); border:none; cursor:pointer;
            transition:all 0.3s;
        }
        .btn-danger:hover {
            transform:translateY(-2px);
            box-shadow:0 6px 20px rgba(231,76,60,0.6);
        }
        .container { max-width:1600px; margin:0 auto; padding:2rem; }
        .page-header { margin-bottom:2rem; }
        .page-header h2 {
            font-size:2rem; font-weight:700;
            background:linear-gradient(135deg,#00d4ff,#0099ff);
            -webkit-background-clip:text; -webkit-text-fill-color:transparent;
        }
        .page-header p { color:#999; font-size:0.95rem; }
        .content-grid { display:grid; grid-template-columns:1fr 1fr; gap:2rem; }
        
        .card {
            background:linear-gradient(135deg,#1a1a1a,#0f0f0f);
            border-radius:20px;
            border:1px solid rgba(0,153,255,0.2);
            padding:2rem;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
            transition:all 0.3s;
        }
        .card:hover {
            border-color:rgba(0,153,255,0.4);
            box-shadow: 0 12px 40px rgba(0,153,255,0.15);
        }
        .card-header {
            display:flex; align-items:center; gap:0.8rem;
            margin-bottom:1.5rem; padding-bottom:1rem;
            border-bottom:1px solid rgba(0,153,255,0.2);
        }
        .card-header h3 {
            font-size:1.4rem; font-weight:700;
            background:linear-gradient(135deg,#00d4ff,#0099ff);
            -webkit-background-clip:text; -webkit-text-fill-color:transparent;
        }
        .card-icon { 
            font-size:1.8rem;
            filter: drop-shadow(0 0 8px rgba(0,212,255,0.4));
        }
        
        /* Mode Toggle */
        .mode-toggle {
            display:flex;
            gap:0.5rem;
            margin-bottom:1.5rem;
            background:rgba(0,153,255,0.08);
            padding:0.4rem;
            border-radius:12px;
            border:1px solid rgba(0,153,255,0.2);
        }
        .mode-btn {
            flex:1;
            padding:0.7rem;
            background:transparent;
            border:none;
            color:#999;
            font-weight:600;
            font-size:0.9rem;
            border-radius:8px;
            cursor:pointer;
            transition:all 0.3s;
        }
        .mode-btn.active {
            background:linear-gradient(135deg,#0066ff,#00d4ff);
            color:#fff;
            box-shadow:0 4px 12px rgba(0,153,255,0.3);
        }
        
        .form-group { margin-bottom:1.5rem; }
        .form-group label {
            display:block; margin-bottom:0.5rem;
            color:#00d4ff; font-weight:600; font-size:0.9rem;
        }
        .form-group input,
        .form-group select {
            width:100%; padding:0.9rem 1.2rem;
            background:#1a1a1a;
            border:2px solid rgba(0,153,255,0.3);
            border-radius:12px; color:#e8e8e8;
            font-size:0.95rem;
            font-family: 'Poppins', sans-serif;
            transition:all 0.3s;
        }
        .form-group select option {
            background:#1a1a1a;
            color:#e8e8e8;
            padding:0.5rem;
        }
        .form-group input:focus,
        .form-group select:focus {
            outline:none; 
            border-color:#00d4ff;
            background:#0f0f0f;
            box-shadow:0 0 0 4px rgba(0,153,255,0.1);
        }
        .form-group input[readonly] {
            cursor:not-allowed;
        }
        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
        
        .info-box {
            background:rgba(0,153,255,0.12);
            border:2px solid rgba(0,153,255,0.3);
            border-radius:12px; padding:1rem 1.2rem;
            margin-bottom:1.5rem; display:flex; gap:1rem;
            align-items:center;
        }
        .info-box-icon {
            font-size:2rem;
            filter: drop-shadow(0 0 8px rgba(0,212,255,0.5));
        }
        .info-box-text .label { color:#999; font-size:0.8rem; }
        .info-box-text .value { 
            color:#00d4ff; 
            font-size:1.3rem; 
            font-weight:700;
            text-shadow: 0 2px 8px rgba(0,212,255,0.3);
        }
        
        .btn-primary {
            width:100%; padding:1.1rem 2rem;
            background:linear-gradient(135deg,#0066ff,#00d4ff);
            color:#fff; border:none; border-radius:12px;
            font-size:1rem; font-weight:700;
            cursor:pointer;
            transition:all 0.3s;
            box-shadow: 0 4px 16px rgba(0,102,255,0.3);
        }
        .btn-primary:hover {
            transform:translateY(-3px);
            box-shadow:0 8px 28px rgba(0,153,255,0.5);
        }
        .btn-primary:disabled {
            opacity:0.5;
            cursor:not-allowed;
            transform:none;
        }
        
        .quantity-preview {
            background:rgba(0,153,255,0.08);
            border:2px dashed rgba(0,153,255,0.3);
            border-radius:12px; padding:1.2rem; margin-top:1rem; display:none;
        }
        .quantity-preview.active { display:block; }
        .quantity-preview h4 {
            color:#00d4ff;
            font-size:0.95rem;
            margin-bottom:0.8rem;
        }
        .slot-preview { display:flex; flex-wrap:wrap; gap:0.5rem; }
        .slot-item {
            background:rgba(0,153,255,0.2);
            color:#00d4ff; padding:0.5rem 0.9rem;
            border-radius:8px; font-size:0.85rem; font-weight:700;
            border:1px solid rgba(0,153,255,0.3);
            box-shadow:0 2px 8px rgba(0,153,255,0.2);
        }
        
        .stock-empty { 
            text-align:center; 
            padding:3rem; 
            color:#666;
            opacity:0.5;
        }
        .stock-table { width:100%; border-collapse:collapse; }
        .stock-table th {
            padding:1rem; text-align:left;
            color:#00d4ff; border-bottom:2px solid rgba(0,153,255,0.3);
            font-size:0.9rem;
        }
        .stock-table td {
            padding:1rem; border-bottom:1px solid rgba(0,153,255,0.1);
            color:#b8b8b8;
        }
        .stock-table tr:hover {
            background:rgba(0,153,255,0.05);
        }
        
        .badge { 
            padding:0.35rem 0.9rem; 
            border-radius:20px; 
            font-size:0.75rem; 
            font-weight:600;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        .badge-success { 
            background:rgba(16,185,129,0.2); 
            color:#10b981;
            border:1px solid rgba(16,185,129,0.3);
        }
        .badge-warning { 
            background:rgba(245,158,11,0.2); 
            color:#f59e0b;
            border:1px solid rgba(245,158,11,0.3);
        }
        .badge-danger { 
            background:rgba(239,68,68,0.2); 
            color:#ef4444;
            border:1px solid rgba(239,68,68,0.3);
        }
        
        /* SweetAlert2 Dark Theme */
        .swal2-popup {
            background: linear-gradient(135deg, #1a1a1a, #0f0f0f) !important;
            border: 2px solid rgba(0,153,255,0.3) !important;
            border-radius: 20px !important;
            box-shadow: 0 12px 40px rgba(0,0,0,0.6) !important;
        }
        .swal2-title {
            color: #00d4ff !important;
            font-family: 'Poppins', sans-serif !important;
            font-weight: 700 !important;
        }
        .swal2-html-container {
            color: #e8e8e8 !important;
            font-family: 'Poppins', sans-serif !important;
        }
    </style>
</head>
<body>
<nav class="navbar">
    <h1>🤖 Automated Storage</h1>
    <div class="navbar-menu">
        <a href="dashboard.php">Dashboard</a>
        <a href="input.php" class="active">Input Barang</a>
        <a href="output.php">Output Barang</a>
        <a href="history.php">History</a>
        <div class="user-info">
            <span>👤 <?= htmlspecialchars($_SESSION['nama_lengkap']) ?></span>
            <a href="../index.php?action=logout" class="btn-danger">Logout</a>
        </div>
    </div>
</nav>

<div class="container">
    <div class="page-header">
        <h2>📥 Input Barang</h2>
        <p>Tambahkan barang baru atau restock barang yang sudah pernah ada</p>
    </div>

    <div class="content-grid">
        <div class="card">
            <div class="card-header">
                <span class="card-icon">📦</span>
                <h3>Form Input Barang</h3>
            </div>

            <div class="info-box">
                <div class="info-box-icon">📊</div>
                <div class="info-box-text">
                    <div class="label">Slot Tersedia</div>
                    <div class="value"><?= $availableSlotsCount ?> / 9 Slot</div>
                </div>
            </div>

            <!-- Mode Toggle: Barang Baru vs Existing -->
            <div class="mode-toggle">
                <button class="mode-btn active" type="button" onclick="setMode('new')">
                    ✨ Barang Baru
                </button>
                <button class="mode-btn" type="button" onclick="setMode('existing')">
                    🔄 Restock Barang
                </button>
            </div>

            <form id="inputForm" method="POST">
                <!-- Dropdown Barang Existing (hidden by default) -->
                <div class="form-group" id="existingDropdownGroup" style="display:none;">
                    <label>Pilih Barang yang Pernah Diinput *</label>
                    <select name="existing_barang" id="existing_barang">
                        <option value="">-- Pilih Barang --</option>
                        <?php if (empty($existingBarang)): ?>
                            <option value="" disabled>Belum ada barang yang pernah diinput</option>
                        <?php else: ?>
                            <?php foreach ($existingBarang as $item): ?>
                            <option 
                                value="<?= htmlspecialchars($item['nama_barang']) ?>" 
                                data-jenis="<?= htmlspecialchars($item['jenis']) ?>">
                                <?= htmlspecialchars($item['nama_barang']) ?> (<?= htmlspecialchars($item['jenis']) ?>)
                            </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Nama Barang *</label>
                    <input type="text" name="nama_barang" id="nama_barang" required>
                </div>

                <div class="form-group">
                    <label>Jenis Barang *</label>
                    <select name="jenis" id="jenis" required>
                        <option value="">-- Pilih Jenis --</option>
                        <option value="Minuman">Minuman</option>
                        <option value="Makanan">Makanan</option>
                        <option value="Elektronik">Elektronik</option>
                        <option value="Sembako">Sembako</option>
                        <option value="Obat-obatan">Obat-obatan</option>
                        <option value="Lainnya">Lainnya</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Jumlah Barang *</label>
                        <input type="number" name="jumlah" id="jumlah" min="1" max="<?= $availableSlotsCount ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Masa Kadaluarsa</label>
                        <input type="date" name="exp_date" id="exp_date" min="<?= date('Y-m-d') ?>">
                    </div>
                </div>

                <div class="quantity-preview" id="quantityPreview">
                    <h4>🎯 Preview Penempatan:</h4>
                    <div class="slot-preview" id="slotPreview"></div>
                </div>

                <button type="submit" class="btn-primary" id="submitBtn">✨ Simpan Barang</button>
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-icon">📋</span>
                <h3>Stok Barang Saat Ini</h3>
            </div>
            <?php if (empty($stockBarang)): ?>
                <div class="stock-empty">
                    <p>📭</p>
                    <p>Belum ada barang dalam sistem</p>
                </div>
            <?php else: ?>
                <table class="stock-table">
                    <thead>
                    <tr>
                        <th>Nama Barang</th>
                        <th>Jenis</th>
                        <th>Jumlah</th>
                        <th>Kadaluarsa</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($stockBarang as $item): ?>
                        <?php
                        $expClass = 'badge-success';
                        $expText = '-';
                        if ($item['last_exp_date']) {
                            $expDate = new DateTime($item['last_exp_date']);
                            $today = new DateTime();
                            $diff = $today->diff($expDate)->days;
                            if ($expDate < $today) {
                                $expClass = 'badge-danger';
                                $expText = 'Expired';
                            } elseif ($diff <= 7) {
                                $expClass = 'badge-warning';
                                $expText = $expDate->format('d/m/Y');
                            } else {
                                $expText = $expDate->format('d/m/Y');
                            }
                        }
                        ?>
                        <tr>
                            <td><strong style="color:#00d4ff;"><?= htmlspecialchars($item['nama_barang']) ?></strong></td>
                            <td><span class="badge badge-success"><?= htmlspecialchars($item['jenis']) ?></span></td>
                            <td><strong><?= $item['jumlah_slot'] ?> slot</strong></td>
                            <td><span class="badge <?= $expClass ?>"><?= $expText ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// ========================================
// GLOBAL VARIABLES
// ========================================
const availableSlots = <?= json_encode($availableSlots) ?>;
let currentMode = 'new'; // 'new' atau 'existing'

const existingDropdownGroup = document.getElementById('existingDropdownGroup');
const existingBarangSelect = document.getElementById('existing_barang');
const namaBarangInput = document.getElementById('nama_barang');
const jenisSelect = document.getElementById('jenis');
const expDateInput = document.getElementById('exp_date');
const jumlahInput = document.getElementById('jumlah');
const quantityPreview = document.getElementById('quantityPreview');
const slotPreview = document.getElementById('slotPreview');
const form = document.getElementById('inputForm');

// ========================================
// MODE TOGGLE - PERBAIKAN: readonly instead of disabled
// ========================================
function setMode(mode) {
    currentMode = mode;
    
    document.querySelectorAll('.mode-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
    
    if (mode === 'existing') {
        existingDropdownGroup.style.display = 'block';
        
        // PERBAIKAN: readonly + styling, bukan disabled
        namaBarangInput.readOnly = true;
        namaBarangInput.style.cursor = 'not-allowed';
        namaBarangInput.style.backgroundColor = 'rgba(0,153,255,0.05)';
        
        jenisSelect.style.pointerEvents = 'none';
        jenisSelect.style.cursor = 'not-allowed';
        jenisSelect.style.backgroundColor = 'rgba(0,153,255,0.05)';
        
        namaBarangInput.value = '';
        jenisSelect.value = '';
        existingBarangSelect.value = '';
        expDateInput.value = '';
        
        existingBarangSelect.required = true;
        namaBarangInput.required = false;
        jenisSelect.required = false;
    } else {
        existingDropdownGroup.style.display = 'none';
        
        namaBarangInput.readOnly = false;
        namaBarangInput.style.cursor = 'text';
        namaBarangInput.style.backgroundColor = '#1a1a1a';
        
        jenisSelect.style.pointerEvents = 'auto';
        jenisSelect.style.cursor = 'pointer';
        jenisSelect.style.backgroundColor = '#1a1a1a';
        
        namaBarangInput.value = '';
        jenisSelect.value = '';
        existingBarangSelect.value = '';
        expDateInput.value = '';
        
        existingBarangSelect.required = false;
        namaBarangInput.required = true;
        jenisSelect.required = true;
    }
    
    quantityPreview.classList.remove('active');
}

// ========================================
// EXISTING BARANG SELECTION (tanpa exp_date)
// ========================================
existingBarangSelect.addEventListener('change', function() {
    const selected = this.options[this.selectedIndex];
    
    if (this.value) {
        namaBarangInput.value = this.value;
        jenisSelect.value = selected.getAttribute('data-jenis');
        expDateInput.value = '';
    } else {
        namaBarangInput.value = '';
        jenisSelect.value = '';
        expDateInput.value = '';
    }
});

// ========================================
// PREVIEW SLOT SAAT INPUT JUMLAH
// ========================================
jumlahInput.addEventListener('input', function () {
    const qty = parseInt(this.value) || 0;
    if (qty > 0 && qty <= availableSlots.length) {
        quantityPreview.classList.add('active');
        slotPreview.innerHTML = '';
        for (let i = 0; i < qty; i++) {
            const div = document.createElement('div');
            div.className = 'slot-item';
            div.textContent = availableSlots[i];
            slotPreview.appendChild(div);
        }
    } else {
        quantityPreview.classList.remove('active');
    }
});

// ========================================
// MAPPING SLOT TO ROBOT POSITION
// ========================================
function mapSlotToPosition(slotId) {
    const map = {
        'A1': 1, 'A2': 2, 'A3': 3,
        'B1': 4, 'B2': 5, 'B3': 6,
        'C1': 7, 'C2': 8, 'C3': 9
    };
    return map[slotId] || null;
}

// ========================================
// SEQUENTIAL WEBSOCKET - LOOPING PER SLOT
// ========================================
function sendToESP32Sequential(slotIds) {
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
                        updateProgressFromESP32(
                            currentIndex, 
                            slotIds.length, 
                            slotId, 
                            response.percent, 
                            response.message
                        );
                        
                        clearTimeout(responseTimeout);
                        responseTimeout = setTimeout(() => {
                            ws.close();
                            reject(new Error(`Timeout (Slot ${slotId})`));
                        }, RESPONSE_TIMEOUT);
                    }
                    else if (response.status === 'ok') {
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
                } catch (e) {}
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
            if (currentIndex >= slotIds.length) {
                return;
            }
            
            const slotId = slotIds[currentIndex];
            const position = mapSlotToPosition(slotId);
            
            if (!position) {
                console.warn(`⚠️ Unknown slot: ${slotId}, skipping...`);
                currentIndex++;
                if (currentIndex < slotIds.length) {
                    sendNextCommand();
                } else {
                    ws.close();
                    resolve();
                }
                return;
            }

            const command = String(position);
            console.log(`📤 Sending PUT: ${command} (Slot ${slotId})`);
            
            updateProgressFromESP32(currentIndex, slotIds.length, slotId, 0, 'Memulai...');
            ws.send(command);
            
            responseTimeout = setTimeout(() => {
                ws.close();
                reject(new Error(`Timeout (Slot ${slotId})`));
            }, RESPONSE_TIMEOUT);
        }

        connect();
    });
}

function updateProgressFromESP32(current, total, slotId, percent, message) {
    const slotWeight = 100 / total;
    const overall = Math.round(current * slotWeight + (percent / 100) * slotWeight);
    
    if (Swal.isVisible()) {
        Swal.update({
            html: `
                <div style="text-align:center; padding:1.5rem;">
                    <p style="margin-bottom:0.8rem; font-size:0.95rem; color:#999;">
                        Slot ${current + 1}/${total}: <strong style="color:#00d4ff;">${slotId}</strong>
                    </p>
                    <p style="margin-bottom:1.2rem; font-size:1.15rem; font-weight:600; color:#e8e8e8;">${message}</p>
                    <div style="background:#1a1a1a; height:28px; border-radius:14px; overflow:hidden; margin:1.2rem 0; border:2px solid rgba(0,153,255,0.3);">
                        <div style="width:${overall}%; height:100%; background:linear-gradient(90deg,#0066ff,#00d4ff); transition:width 0.3s; box-shadow:0 0 20px rgba(0,153,255,0.6);"></div>
                    </div>
                    <p style="color:#00d4ff; font-size:1.4rem; font-weight:700; text-shadow:0 2px 12px rgba(0,212,255,0.4);">${overall}% selesai</p>
                </div>
            `
        });
    }
}

// ========================================
// FORM SUBMIT HANDLER
// ========================================
form.addEventListener('submit', async function (e) {
    e.preventDefault();
    
    if (currentMode === 'existing' && !existingBarangSelect.value) {
        Swal.fire({
            icon: 'warning',
            title: 'Pilih Barang',
            text: 'Silakan pilih barang dari dropdown',
            confirmButtonColor: '#f59e0b'
        });
        return;
    }
    
    const formData = new FormData(this);
    const submitBtn = document.getElementById('submitBtn');
    const jumlah = parseInt(formData.get('jumlah'));
    
    // Debug log
    console.log('📤 FORM DATA YANG DIKIRIM:');
    for (let [key, value] of formData.entries()) {
        console.log(`   ${key}: "${value}"`);
    }
    
    if (!jumlah || jumlah <= 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Jumlah Tidak Valid',
            text: 'Masukkan jumlah barang minimal 1',
            confirmButtonColor: '#f59e0b'
        });
        return;
    }
    
    if (jumlah > availableSlots.length) {
        Swal.fire({
            icon: 'error',
            title: 'Slot Tidak Cukup',
            text: `Hanya tersedia ${availableSlots.length} slot kosong`,
            confirmButtonColor: '#ef4444'
        });
        return;
    }
    
    submitBtn.disabled = true;
    submitBtn.textContent = '⏳ Menyimpan...';

    try {
        console.log('💾 Saving to database...');
        const response = await fetch('../api/input_barang.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        console.log('📦 Database response:', result);
        
        if (!result.success) {
            throw new Error(result.message || 'Gagal menyimpan ke database');
        }

        const modeText = currentMode === 'existing' ? '🔄 Restock' : '✨ Input Baru';
        const confirmation = await Swal.fire({
            icon: 'success',
            title: `${modeText} Berhasil!`,
            html: `
                <div style="padding:1rem;">
                    <p style="color:#ccc; margin-bottom:1rem;"><strong style="color:#00d4ff; font-size:1.2rem;">${result.nama_barang}</strong> akan ditempatkan di:</p>
                    <div style="background:rgba(0,153,255,0.12); padding:1.2rem; border-radius:12px; border:1px solid rgba(0,153,255,0.3);">
                        <p style="color:#00d4ff; font-weight:bold; font-size:1.3rem; letter-spacing:1px;">
                            ${result.slots.join(', ')}
                        </p>
                    </div>
                    <p style="margin-top:1rem; color:#999; font-size:0.9rem;">
                        Total: <strong style="color:#00d4ff;">${result.jumlah} slot</strong>
                    </p>
                </div>
            `,
            confirmButtonText: '🚀 Mulai Penempatan Robot',
            showCancelButton: true,
            cancelButtonText: 'Reload Tanpa Robot',
            confirmButtonColor: '#0066ff',
            cancelButtonColor: '#666'
        });

        if (confirmation.isDismissed || confirmation.dismiss === Swal.DismissReason.cancel) {
            console.log('⏭️  User skipped robot placement');
            window.location.reload();
            return;
        }

        console.log('🤖 Starting robot placement...');
        console.log('📦 Akan memproses:', result.slots);
        
        Swal.fire({
            title: '🤖 Robot Sedang Bekerja...',
            html: `
                <div style="text-align:center; padding:1.5rem;">
                    <p style="margin-bottom:1.2rem; font-size:1.1rem; color:#ccc;">Mempersiapkan...</p>
                    <div style="background:#1a1a1a; height:28px; border-radius:14px; overflow:hidden; margin:1.2rem 0; border:2px solid rgba(0,153,255,0.3);">
                        <div style="width:0%; height:100%; background:linear-gradient(90deg,#0066ff,#00d4ff); transition:width 0.5s;"></div>
                    </div>
                    <p style="color:#00d4ff; font-size:1.4rem; font-weight:700;">0% selesai</p>
                </div>
            `,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false
        });

        await sendToESP32Sequential(result.slots);

        await Swal.fire({
            icon: 'success',
            title: '✅ Selesai!',
            html: `<p style="color:#ccc; font-size:1.1rem;"><strong style="color:#10b981;">${result.slots.length} barang</strong> berhasil ditempatkan</p>`,
            timer: 3000,
            showConfirmButton: false
        });

        window.location.reload();

    } catch (err) {
        console.error('❌ Error:', err);
        
        Swal.fire({
            icon: 'error',
            title: 'Gagal',
            text: err.message || 'Terjadi kesalahan',
            confirmButtonColor: '#ef4444'
        });
        
        submitBtn.disabled = false;
        submitBtn.textContent = '✨ Simpan Barang';
    }
});
</script>
</body>
</html>
