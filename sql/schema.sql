CREATE DATABASE IF NOT EXISTS campus_system;
USE campus_system;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    fullname VARCHAR(100) NOT NULL,
    role ENUM('student', 'admin') DEFAULT 'student',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    category ENUM('equipment', 'facility') NOT NULL,
    status ENUM('available', 'maintenance', 'unavailable') DEFAULT 'available'
);

CREATE TABLE requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    item_id INT NOT NULL,
    description TEXT,
    status ENUM('pending', 'approved', 'completed', 'rejected') DEFAULT 'pending',
    request_date DATE NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
);

INSERT INTO users (username, password, fullname, role) VALUES 
('student01', '1234', 'Somchai Dee', 'student'),
('admin01', '1234', 'Admin Sompong', 'admin');

INSERT INTO items (name, category, status) VALUES 
('Projector HD', 'equipment', 'available'),
('Extension Plug 5m', 'equipment', 'available'),
('Air Conditioner Building A', 'facility', 'maintenance');