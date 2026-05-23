<?php
include 'config.php';

// Create affected_persons table if not exists
$conn->query("CREATE TABLE IF NOT EXISTS affected_persons (
    id INT PRIMARY KEY AUTO_INCREMENT,
    full_name VARCHAR(255) NOT NULL,
    location VARCHAR(255) NOT NULL,
    family_members INT NOT NULL DEFAULT 1,
    needs TEXT,
    registration_status ENUM('pending','assigned','completed') DEFAULT 'pending',
    camp_id INT NULL,
    access_key VARCHAR(32) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (camp_id) REFERENCES camps(id) ON DELETE SET NULL
)");

$conn->query("CREATE TABLE IF NOT EXISTS affected_help_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    affected_id INT NOT NULL,
    category VARCHAR(100) NOT NULL,
    urgency ENUM('low','medium','high','critical') DEFAULT 'medium',
    description TEXT,
    contact VARCHAR(20),
    status ENUM('pending','in_progress','resolved') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (affected_id) REFERENCES affected_persons(id)
)");

$conn->query("CREATE TABLE IF NOT EXISTS affected_messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    affected_id INT NOT NULL,
    sender ENUM('affected','support') DEFAULT 'affected',
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (affected_id) REFERENCES affected_persons(id)
)");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = sanitize($_POST['name'] ?? '');
    $location = sanitize($_POST['location'] ?? '');
    $members  = intval($_POST['family_members'] ?? 1);

    if (!$name || !$location || $members < 1) {
        echo json_encode(['success' => false, 'message' => 'Missing fields']);
        exit;
    }

    $key = strtoupper(substr(md5(uniqid(rand(), true)), 0, 10));

    $stmt = $conn->prepare("INSERT INTO affected_persons (full_name, location, family_members, access_key) VALUES (?,?,?,?)");
    $stmt->bind_param("ssis", $name, $location, $members, $key);

    if ($stmt->execute()) {
        $id = $conn->insert_id;
        $_SESSION['affected_id']   = $id;
        $_SESSION['affected_name'] = $name;
        $_SESSION['affected_key']  = $key;
        echo json_encode(['success' => true, 'key' => $key, 'redirect' => 'affected_dashboard.php']);
    } else {
        echo json_encode(['success' => false, 'message' => 'DB error']);
    }
    exit;
}
?>
