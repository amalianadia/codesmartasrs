<?php
require_once __DIR__ . '/../config/db.php';

class BarangController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function createBarang($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO barang (nama_barang, kategori, berat, panjang, lebar, tinggi, keterangan) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['nama_barang'], $data['kategori'], $data['berat'],
            $data['panjang'], $data['lebar'], $data['tinggi'], $data['keterangan']
        ]);
        return $this->pdo->lastInsertId();
    }

    public function getBarangById($barang_id) {
        $stmt = $this->pdo->prepare("SELECT * FROM barang WHERE barang_id = ?");
        $stmt->execute([$barang_id]);
        return $stmt->fetch();
    }

    public function getTotalBarang() {
        $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM barang");
        return $stmt->fetch()['total'];
    }

    public function createTask($action, $slot_id, $x, $y, $z, $barang_id) {
        $stmt = $this->pdo->prepare("
            INSERT INTO queue_robot (action, slot, koordinat_x, koordinat_y, koordinat_z, barang_id) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$action, $slot_id, $x, $y, $z, $barang_id]);
    }

    public function addLog($user_id, $aktivitas, $barang_id, $slot, $keterangan = '') {
        $stmt = $this->pdo->prepare("
            INSERT INTO log_aktivitas (user_id, aktivitas, barang_id, slot, keterangan) 
            VALUES (?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$user_id, $aktivitas, $barang_id, $slot, $keterangan]);
    }

    public function getRecentLogs($limit = 10) {
        $stmt = $this->pdo->prepare("
            SELECT l.*, u.nama_lengkap, b.nama_barang 
            FROM log_aktivitas l
            LEFT JOIN users u ON l.user_id = u.user_id
            LEFT JOIN barang b ON l.barang_id = b.barang_id
            ORDER BY l.created_at DESC LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
}
?>
