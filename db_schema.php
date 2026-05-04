<?php
// Database Schema for DisasterRelief
// This file contains all database tables creation

$conn = new mysqli('localhost', 'root', '', 'disasterrelief');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// SQL Queries for creating tables
$tables = array(

    // Users Table
    "CREATE TABLE IF NOT EXISTS users (
        id INT PRIMARY KEY AUTO_INCREMENT,
        role ENUM('volunteer', 'donor', 'affected', 'organization', 'admin', 'camp_manager') NOT NULL,
        full_name VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        phone VARCHAR(20),
        password VARCHAR(255) NOT NULL,
        profile_image VARCHAR(255),
        location VARCHAR(255),
        skills TEXT,
        status ENUM('active', 'inactive', 'blocked') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",

    // Camps Table
    "CREATE TABLE IF NOT EXISTS camps (
        id INT PRIMARY KEY AUTO_INCREMENT,
        camp_name VARCHAR(255) NOT NULL,
        location VARCHAR(255) NOT NULL,
        manager_id INT,
        description TEXT,
        capacity INT,
        current_occupancy INT DEFAULT 0,
        status ENUM('active', 'inactive', 'full') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (manager_id) REFERENCES users(id)
    )",

    // Campaigns Table
    "CREATE TABLE IF NOT EXISTS campaigns (
        id INT PRIMARY KEY AUTO_INCREMENT,
        campaign_name VARCHAR(255) NOT NULL,
        description TEXT,
        location VARCHAR(255),
        goal_amount DECIMAL(10, 2),
        raised_amount DECIMAL(10, 2) DEFAULT 0,
        urgency ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
        status ENUM('active', 'completed', 'suspended') DEFAULT 'active',
        start_date DATETIME,
        end_date DATETIME,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )",

    // Tasks Table
    "CREATE TABLE IF NOT EXISTS tasks (
        id INT PRIMARY KEY AUTO_INCREMENT,
        task_name VARCHAR(255) NOT NULL,
        description TEXT,
        camp_id INT,
        assigned_to INT,
        assigned_by INT,
        priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
        status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending',
        due_date DATETIME,
        completed_date DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (camp_id) REFERENCES camps(id),
        FOREIGN KEY (assigned_to) REFERENCES users(id),
        FOREIGN KEY (assigned_by) REFERENCES users(id)
    )",

    // Donations Table
    "CREATE TABLE IF NOT EXISTS donations (
        id INT PRIMARY KEY AUTO_INCREMENT,
        donor_id INT,
        campaign_id INT,
        amount DECIMAL(10, 2),
        donation_type ENUM('money', 'supplies', 'other') DEFAULT 'money',
        status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
        payment_method VARCHAR(100),
        transaction_id VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (donor_id) REFERENCES users(id),
        FOREIGN KEY (campaign_id) REFERENCES campaigns(id)
    )",

    // Messages/Chat Table
    "CREATE TABLE IF NOT EXISTS messages (
        id INT PRIMARY KEY AUTO_INCREMENT,
        sender_id INT,
        receiver_id INT,
        message_text TEXT,
        is_read BOOLEAN DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sender_id) REFERENCES users(id),
        FOREIGN KEY (receiver_id) REFERENCES users(id)
    )",

    // Emergency Reports Table
    "CREATE TABLE IF NOT EXISTS emergency_reports (
        id INT PRIMARY KEY AUTO_INCREMENT,
        reported_by INT,
        camp_id INT,
        report_category ENUM('activity', 'issue') DEFAULT 'issue',
        issue_type VARCHAR(100),
        priority ENUM('low', 'medium', 'high', 'critical') DEFAULT 'high',
        location VARCHAR(255),
        people_affected INT,
        description TEXT,
        immediate_action TEXT,
        status ENUM('pending', 'in_progress', 'resolved') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME,
        FOREIGN KEY (reported_by) REFERENCES users(id),
        FOREIGN KEY (camp_id) REFERENCES camps(id)
    )",

    // Volunteer Assignments Table
    "CREATE TABLE IF NOT EXISTS volunteer_assignments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        volunteer_id INT,
        camp_id INT,
        assignment_date DATETIME,
        end_date DATETIME,
        status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
        hours_worked DECIMAL(5, 2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (volunteer_id) REFERENCES users(id),
        FOREIGN KEY (camp_id) REFERENCES camps(id)
    )",

    // Notifications Table
    "CREATE TABLE IF NOT EXISTS notifications (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT,
        notification_type VARCHAR(100),
        title VARCHAR(255),
        message TEXT,
        is_read BOOLEAN DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )",

    // Families Table
    "CREATE TABLE IF NOT EXISTS families (
        id INT PRIMARY KEY AUTO_INCREMENT,
        head_name VARCHAR(255) NOT NULL,
        family_members INT NOT NULL,
        village VARCHAR(255),
        contact VARCHAR(20),
        needs TEXT,
        camp_id INT,
        status ENUM('registered', 'displaced', 'relocated') DEFAULT 'registered',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (camp_id) REFERENCES camps(id)
    )",
    "CREATE TABLE IF NOT EXISTS inventory (
        id INT PRIMARY KEY AUTO_INCREMENT,
        camp_id INT,
        item_name VARCHAR(255) NOT NULL,
        category VARCHAR(100),
        quantity DECIMAL(10, 2) DEFAULT 0.00,
        unit VARCHAR(50),
        status ENUM('In Stock', 'Limited', 'Out of Stock') DEFAULT 'In Stock',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (camp_id) REFERENCES camps(id)
    )",
    // Schedules Table
    "CREATE TABLE IF NOT EXISTS schedules (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT,
        day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        location VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )"
);

// Create all tables
foreach ($tables as $table) {
    if ($conn->query($table) === TRUE) {
        // Tables created successfully
    } else {
        echo "Error creating table: " . $conn->error . "<br>";
    }
}

// Insert sample data (optional)
$sampleData = array(
    // Sample users
    "INSERT IGNORE INTO users (id, role, full_name, email, phone, password, location) VALUES
    (1, 'admin', 'Admin User', 'admin@disasterrelief.bd', '+8801234567890', '" . password_hash('admin123', PASSWORD_BCRYPT) . "', 'Dhaka'),
    (2, 'camp_manager', 'Rajesh Kumar', 'rajesh@disasterrelief.bd', '+8801712345678', '" . password_hash('manager123', PASSWORD_BCRYPT) . "', 'Mumbai'),
    (3, 'volunteer', 'Rahul Singh', 'rahul@disasterrelief.bd', '+8801987654321', '" . password_hash('volunteer123', PASSWORD_BCRYPT) . "', 'Camp Alpha'),
    (4, 'donor', 'John Doe', 'john@example.com', '+8801111111111', '" . password_hash('donor123', PASSWORD_BCRYPT) . "', 'Dhaka')",

    // Sample camps
    "INSERT IGNORE INTO camps (id, camp_name, location, manager_id, capacity, current_occupancy, status) VALUES
    (1, 'Camp Alpha', 'Mumbai', 2, 500, 350, 'active'),
    (2, 'Camp Beta', 'Dhaka', 2, 300, 250, 'active')",

    // Sample campaigns
    "INSERT IGNORE INTO campaigns (id, campaign_name, location, goal_amount, raised_amount, urgency, status) VALUES
    (1, 'Flood Relief - Dhaka Division', 'Dhaka', 10000000, 4500000, 'urgent', 'active'),
    (2, 'Cyclone Recovery - Coastal Areas', 'Coastal', 15000000, 7800000, 'urgent', 'active'),
    (3, 'Flood Affected Areas', 'Various', 1750000, 1320000, 'high', 'active')",

    // Sample tasks
    "INSERT IGNORE INTO tasks (id, task_name, description, camp_id, assigned_to, assigned_by, priority, status, due_date) VALUES
    (1, 'Food Distribution - Section A', 'Distribute food packets to 25 families in Section A', 1, 3, 2, 'high', 'pending', '2026-05-04 15:00:00'),
    (2, 'Medicine Delivery', 'Deliver medical supplies to elderly residents', 1, 3, 2, 'high', 'in_progress', '2026-05-04 17:00:00'),
    (3, 'Water Supply Check', 'Ensure water supply is adequate in all sections', 1, 3, 2, 'medium', 'completed', '2026-05-04 12:00:00'),
    (4, 'Blanket Distribution', 'Distribute blankets to 15 families', 1, 3, 2, 'low', 'pending', '2026-05-04 14:00:00'),
    (5, 'Registration Assistance', 'Help new families with registration process', 1, 3, 2, 'medium', 'in_progress', '2026-05-02 10:00:00')",
    
    // Sample schedules
    "INSERT IGNORE INTO schedules (id, user_id, day_of_week, start_time, end_time, location) VALUES
    (1, 3, 'Monday', '09:00:00', '17:00:00', 'Central Relief Camp'),
    (2, 3, 'Wednesday', '13:00:00', '21:00:00', 'Central Relief Camp'),
    (3, 3, 'Friday', '09:00:00', '17:00:00', 'North Emergency Shelter')"
);

// Insert sample data
foreach ($sampleData as $query) {
    if (!$conn->query($query)) {
        // Error inserting data - but we continue
    }
}

$conn->close();

// Return success message
echo "Database initialized successfully!";
?>
