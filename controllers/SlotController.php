<?php
require_once __DIR__ . '/../config/db.php';

class SlotController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function getAllSlots() {
        $stmt = $this->pdo->query("
            SELECT s.*, b.nama_barang, b.kategori 
            FROM slot s 
            LEFT JOIN barang b ON s.barang_id = b.barang_id 
            ORDER BY s.slot_id
        ");
        return $stmt->fetchAll();
    }

    public function getSlotById($slot_id) {
        $stmt = $this->pdo->prepare("
            SELECT s.*, b.* 
            FROM slot s 
            LEFT JOIN barang b ON s.barang_id = b.barang_id 
            WHERE s.slot_id = ?
        ");
        $stmt->execute([$slot_id]);
        return $stmt->fetch();
    }

    public function getEmptySlots() {
        $stmt = $this->pdo->query("SELECT * FROM slot WHERE status = 'Kosong' ORDER BY slot_id");
        return $stmt->fetchAll();
    }

    public function getSlotStats() {
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(*) as total_slot,
                SUM(CASE WHEN status = 'Kosong' THEN 1 ELSE 0 END) as slot_kosong,
                SUM(CASE WHEN status = 'Isi' THEN 1 ELSE 0 END) as slot_isi
            FROM slot
        ");
        return $stmt->fetch();
    }

    public function updateSlotStatus($slot_id, $barang_id, $status) {
        $stmt = $this->pdo->prepare("UPDATE slot SET barang_id = ?, status = ? WHERE slot_id = ?");
        return $stmt->execute([$barang_id, $status, $slot_id]);
    }
}
?>
