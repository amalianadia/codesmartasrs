CREATE DATABASE IF NOT EXISTS automated_storage;
USE automated_storage;

CREATE TABLE IF NOT EXISTS users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    nama_lengkap VARCHAR(100) NOT NULL,
    role ENUM('admin', 'operator') DEFAULT 'operator',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS barang (
    barang_id INT AUTO_INCREMENT PRIMARY KEY,
    nama_barang VARCHAR(100) NOT NULL,
    kategori VARCHAR(50),
    berat DECIMAL(10,2),
    panjang DECIMAL(10,2),
    lebar DECIMAL(10,2),
    tinggi DECIMAL(10,2),
    keterangan TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS slot (
    slot_id VARCHAR(10) PRIMARY KEY,
    barang_id INT DEFAULT NULL,
    koordinat_x INT NOT NULL,
    koordinat_y INT NOT NULL,
    koordinat_z INT NOT NULL,
    status ENUM('Kosong', 'Isi') DEFAULT 'Kosong',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barang_id) REFERENCES barang(barang_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS queue_robot (
    task_id INT AUTO_INCREMENT PRIMARY KEY,
    action ENUM('store', 'pickup') NOT NULL,
    slot VARCHAR(10) NOT NULL,
    koordinat_x INT NOT NULL,
    koordinat_y INT NOT NULL,
    koordinat_z INT NOT NULL,
    barang_id INT NOT NULL,
    status ENUM('pending', 'processing', 'done', 'failed') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (barang_id) REFERENCES barang(barang_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS log_aktivitas (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    aktivitas VARCHAR(100) NOT NULL,
    barang_id INT,
    slot VARCHAR(10),
    keterangan TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (barang_id) REFERENCES barang(barang_id) ON DELETE SET NULL
);

INSERT INTO users (username, password, nama_lengkap, role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin'),
('operator', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Operator', 'operator');

INSERT INTO slot (slot_id, koordinat_x, koordinat_y, koordinat_z, status) VALUES
('A1', 10, 10, 10, 'Kosong'), ('A2', 20, 10, 10, 'Kosong'), ('A3', 30, 10, 10, 'Kosong'),
('A4', 40, 10, 10, 'Kosong'), ('A5', 50, 10, 10, 'Kosong'), ('B1', 10, 20, 10, 'Kosong'),
('B2', 20, 20, 10, 'Kosong'), ('B3', 30, 20, 10, 'Kosong'), ('B4', 40, 20, 10, 'Kosong'),
('B5', 50, 20, 10, 'Kosong'), ('C1', 10, 30, 10, 'Kosong'), ('C2', 20, 30, 10, 'Kosong'),
('C3', 30, 30, 10, 'Kosong'), ('C4', 40, 30, 10, 'Kosong'), ('C5', 50, 30, 10, 'Kosong'),
('D1', 10, 10, 25, 'Kosong'), ('D2', 20, 10, 25, 'Kosong'), ('D3', 30, 10, 25, 'Kosong'),
('D4', 40, 10, 25, 'Kosong'), ('D5', 50, 10, 25, 'Kosong'), ('E1', 10, 20, 25, 'Kosong'),
('E2', 20, 20, 25, 'Kosong'), ('E3', 30, 20, 25, 'Kosong'), ('E4', 40, 20, 25, 'Kosong'),
('E5', 50, 20, 25, 'Kosong'), ('F1', 10, 30, 25, 'Kosong'), ('F2', 20, 30, 25, 'Kosong'),
('F3', 30, 30, 25, 'Kosong'), ('F4', 40, 30, 25, 'Kosong'), ('F5', 50, 30, 25, 'Kosong');
