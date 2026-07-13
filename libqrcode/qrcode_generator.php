<?php
// File: lib/qrcode_generator.php
require_once __DIR__ . '/phpqrcode/qrlib.php';

function generateProductQRCode($barcode, $namaBarang = '', $berat = null) {
    // Folder absolut untuk save file
    $absoluteFolder = __DIR__ . '/../storage/qrcodes/';
    
    // Buat folder jika belum ada
    if (!file_exists($absoluteFolder)) {
        mkdir($absoluteFolder, 0777, true);
    }
    
    // Nama file
    $fileName = 'QR_' . $barcode . '.png';
    $absolutePath = $absoluteFolder . $fileName;
    
    // Generate QR Code
    QRcode::png($barcode, $absolutePath, QR_ECLEVEL_L, 10, 2);
    
    // Cek apakah file berhasil dibuat
    if (!file_exists($absolutePath)) {
        throw new Exception("Gagal membuat QR Code file!");
    }
    
    // Return RELATIVE path untuk browser (PENTING!)
    // Bukan /storage/qrcodes/, tapi ../storage/qrcodes/
    return '../storage/qrcodes/' . $fileName;
}

function generateBarcodeWithCounter($prefix = 'PROD', $pdo = null) {
    $date = date('Ymd');
    
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM barang WHERE DATE(created_at) = CURDATE()");
            $result = $stmt->fetch();
            $counter = $result['total'] + 1;
        } catch (PDOException $e) {
            $counter = 1;
        }
    } else {
        $counter = 1;
    }
    
    $counterStr = str_pad($counter, 4, '0', STR_PAD_LEFT);
    return "{$prefix}-{$date}-{$counterStr}";
}

function generateUniqueBarcode() {
    $timestamp = date('YmdHis');
    $random = strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
    return "PROD-{$timestamp}-{$random}";
}

function isBarcodeExists($barcode, $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM barang WHERE barcode = ?");
        $stmt->execute([$barcode]);
        $result = $stmt->fetch();
        return $result['total'] > 0;
    } catch (PDOException $e) {
        return false;
    }
}
