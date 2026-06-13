<?php
session_start();
include 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    redirect('signin.php');
}

// Get user info
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Verify user is volunteer or admin
if ($user_role !== 'volunteer' && $user_role !== 'admin' && $user_role !== 'camp_manager') {
    redirect('index.php');
}

// Get user details
$user_query = $conn->query("SELECT * FROM users WHERE id = $user_id");
$user = $user_query->fetch_assoc();

// Get notifications count
$notifications_query = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0");
$notifications = $notifications_query->fetch_assoc();
$unread_count = $notifications['count'] ?? 0;

<<<<<<< HEAD
=======
// Get unread chat count
$unread_chat_query = $conn->query("SELECT COUNT(*) FROM messages WHERE receiver_id = $user_id AND is_read = 0");
$unread_chat = ($unread_chat_query && $unread_chat_query->num_rows > 0) ? $unread_chat_query->fetch_row()[0] : 0;

>>>>>>> Shoron
$all_notifications_query = $conn->query("SELECT * FROM notifications WHERE user_id = $user_id ORDER BY created_at DESC LIMIT 5");
$all_notifications = [];
if ($all_notifications_query) {
    while ($n = $all_notifications_query->fetch_assoc()) {
        $all_notifications[] = $n;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_notifications_read') {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id");
    header("Location: volunteer_dashboard.php?page=" . ($_GET['page'] ?? 'dashboard'));
    exit();
}

// API Endpoint for Notifications
if (isset($_GET['api']) && $_GET['api'] === 'check_notifications') {
    header('Content-Type: application/json');
    echo json_encode(['count' => $unread_count]);
    exit();
}

// Get task statistics
$task_stats = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(status='pending') as pending,
    SUM(status='in_progress') as in_progress,
    SUM(status='completed') as completed,
    SUM(status='completed' AND DATE(completed_date) = CURDATE()) as completed_today
FROM tasks WHERE assigned_to = $user_id");
$stats = $task_stats->fetch_assoc();

// Get current page
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// Get camp assignment
$camp_query = $conn->query("SELECT camps.* FROM camps 
    JOIN volunteer_assignments ON camps.id = volunteer_assignments.camp_id 
    WHERE volunteer_assignments.volunteer_id = $user_id AND volunteer_assignments.status = 'active' LIMIT 1");
$camp = $camp_query ? $camp_query->fetch_assoc() : null;
$camp_name = $camp ? $camp['camp_name'] : 'Not Assigned';
$camp_id = $camp ? $camp['id'] : null;

// Fetch inventory for distribution tasks
$inventory_items = [];
if (!empty($camp_id)) {
    $inv_query = $conn->query("SELECT * FROM inventory WHERE camp_id = " . intval($camp_id) . " AND quantity > 0");
    if ($inv_query) {
        while ($row = $inv_query->fetch_assoc()) {
            $inventory_items[] = $row;
        }
    }
}



// Handle task status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_task_status') {
        $task_id = intval($_POST['task_id']);
        $new_status = sanitize($_POST['status']);
        
        // Validate status
        $valid_statuses = ['pending', 'in_progress', 'completed'];
        if (in_array($new_status, $valid_statuses)) {
            // Check if it was a distribution task before updating to 'completed'
            if ($new_status === 'completed') {
                $task_check = $conn->query("SELECT * FROM tasks WHERE id = $task_id AND assigned_to = $user_id");
                $task_data = $task_check->fetch_assoc();
                
                if ($task_data && $task_data['task_type'] === 'distribution') {
                    $recipient = 'Affected Resident'; 
                    $item = $task_data['distribution_item'];
                    $qty = $task_data['distribution_qty'];
                    $camp_id_task = $task_data['camp_id'];
                    
                    // Update inventory
                    $conn->query("UPDATE inventory SET 
                        quantity = quantity - $qty,
                        status = CASE 
                            WHEN quantity - $qty <= 0 THEN 'Out of Stock'
                            WHEN quantity - $qty < 10 THEN 'Limited'
                            ELSE 'In Stock'
                        END
                        WHERE camp_id = $camp_id_task AND item_name = '$item'");

                    // Log distribution
                    $conn->query("INSERT INTO distributions (camp_id, recipient_name, items, quantity, distributed_by) VALUES ($camp_id_task, '$recipient', '$item', $qty, $user_id)");
                }

            }

            $completed_date_clause = ($new_status === 'completed') ? ", completed_date = NOW()" : "";
            $stmt = $conn->prepare("UPDATE tasks SET status = ?$completed_date_clause WHERE id = ? AND assigned_to = ?");
            $stmt->bind_param("sii", $new_status, $task_id, $user_id);
            
            if ($stmt->execute()) {
                header("Location: volunteer_dashboard.php?page=" . $page);
                exit();
            }
        }
    } elseif ($_POST['action'] === 'submit_report') {
        $category = sanitize($_POST['report_category']);
        $issue_type = sanitize($_POST['issue_type']);
        $priority = sanitize($_POST['priority']);
        $location = sanitize($_POST['location']);
        $people_affected = intval($_POST['people_affected']);
        $description = sanitize($_POST['description']);
        $immediate_action = sanitize($_POST['immediate_action']);
        
        $stmt = $conn->prepare("INSERT INTO emergency_reports (reported_by, camp_id, report_category, issue_type, priority, location, people_affected, description, immediate_action) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iissssiss", $user_id, $camp_id, $category, $issue_type, $priority, $location, $people_affected, $description, $immediate_action);
        
        if ($stmt->execute()) {
            $_SESSION['success_msg'] = "Report submitted successfully!";
            header("Location: volunteer_dashboard.php?page=field_reports");
            exit();
        } else {
            $error_msg = "Error submitting report: " . $conn->error;
        }
    } elseif ($_POST['action'] === 'create_task') {
        $task_name = sanitize($_POST['task_name']);
        $description = sanitize($_POST['description']);
        $priority = sanitize($_POST['priority']);
        $due_date = sanitize($_POST['due_date']);
        $assignment_type = sanitize($_POST['assignment_type']);
        $task_type = sanitize($_POST['task_type'] ?? 'standard');
        $dist_item = sanitize($_POST['distribution_item'] ?? '');
        $dist_qty = floatval($_POST['distribution_qty'] ?? 0);
        
        // If normal, assigned_to is NULL, otherwise it's the current user
        $assignee = ($assignment_type === 'self') ? $user_id : null;
        
        $stmt = $conn->prepare("INSERT INTO tasks (task_name, description, camp_id, assigned_to, assigned_by, priority, due_date, task_type, distribution_item, distribution_qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssiiissssd", $task_name, $description, $camp_id, $assignee, $user_id, $priority, $due_date, $task_type, $dist_item, $dist_qty);
        
        if ($stmt->execute()) {
            $_SESSION['success_msg'] = "Task created successfully!";
            header("Location: volunteer_dashboard.php?page=tasks");
            exit();

        } else {
            $error_msg = "Error creating task: " . $conn->error;
        }
    } elseif ($_POST['action'] === 'send_chat_message') {
        $receiver_id = intval($_POST['receiver_id']);
        $msg = sanitize($_POST['message'] ?? '');
        if ($msg && $receiver_id) {
            $conn->query("INSERT INTO messages (sender_id, receiver_id, message_text) VALUES ($user_id, $receiver_id, '$msg')");
            header("Location: volunteer_dashboard.php?page=chat&user_id=$receiver_id");
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Volunteer Dashboard - DisasterRelief</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f6fa;
            color: #333;
        }

        .wrapper {
            display: flex;
            height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: white;
            color: #334155;
            padding: 1.5rem 1rem;
            border-right: 1px solid #f1f5f9;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
        }

        .sidebar-logo {
            padding: 0.5rem 1rem;
            margin-bottom: 2.5rem;
        }

        .logo-title {
            font-size: 1.4rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.5px;
            margin-bottom: 2px;
        }

        .logo-subtitle {
            font-size: 0.9rem;
            font-weight: 500;
            color: #64748b;
        }

        .sidebar-menu {
            list-style: none;
        }

        .sidebar-menu li {
            margin-bottom: 0.25rem;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0.75rem 1rem;
            color: #475569;
            text-decoration: none;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            font-size: 0.95rem;
            font-weight: 500;
            border-radius: 10px;
        }

        .sidebar-menu a svg {
            width: 20px;
            height: 20px;
            color: #64748b;
            transition: color 0.2s;
        }

        .sidebar-menu a:hover {
            background: #f8fafc;
            color: #1e293b;
        }

        .sidebar-menu a:hover svg {
            color: #1e293b;
        }

        .sidebar-menu a.active {
            background: #eff6ff;
            color: #3b82f6;
        }

        .sidebar-menu a.active svg {
            color: #3b82f6;
        }

        .menu-badge {
            background: #ef4444;
            color: white;
            border-radius: 20px;
            padding: 2px 8px;
            font-size: 0.7rem;
            font-weight: 700;
            margin-left: auto;
            box-shadow: 0 2px 4px rgba(239, 68, 68, 0.2);
        }

        /* Header */
        .header {
            background: white;
            padding: 1.5rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-bottom: 1px solid #e0e0e0;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .header-right {
            display: flex;
            align-items: center;
            gap: 1.5rem;
        }

        .notification-bell {
            position: relative;
            font-size: 1.2rem;
            cursor: pointer;
        }

        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ff6b6b;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .profile-dropdown {
            position: relative;
        }

        .dropdown-button {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.65rem 0.9rem;
            border-radius: 16px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            cursor: pointer;
            transition: background 0.2s, border-color 0.2s;
        }

        .dropdown-button:hover {
            background: #eef2ff;
            border-color: #cbd5e1;
        }

        .dropdown-menu {
            position: absolute;
            right: 0;
            top: calc(100% + 10px);
            width: 220px;
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 18px 60px rgba(15, 23, 42, 0.12);
            padding: 0.5rem 0;
            display: none;
            z-index: 20;
        }

        .dropdown-menu.show {
            display: block;
        }

        .dropdown-menu a {
            display: block;
            padding: 0.85rem 1rem;
            color: #334155;
            text-decoration: none;
            transition: background 0.2s;
        }

        .dropdown-menu a:hover {
            background: #f8fafc;
        }

        .dropdown-menu a.danger {
            color: #dc2626;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #3b82f6;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .header-user-info {
            text-align: left;
            display: flex;
            flex-direction: column;
        }

        .header-user-info .name {
            font-weight: 600;
            font-size: 0.9rem;
            color: #1e293b;
            line-height: 1.2;
        }

        .header-user-info .role {
            font-size: 0.75rem;
            color: #64748b;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .content-area {
            flex: 1;
            overflow-y: auto;
            padding: 2rem;
        }

        .page-title {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: #1a1a1a;
        }

        .page-subtitle {
            color: #666;
            margin-bottom: 2rem;
            font-size: 0.95rem;
        }

        /* Dashboard Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border-left: 4px solid;
        }

        .stat-card.pending { border-left-color: #ffa500; }
        .stat-card.progress { border-left-color: #2196f3; }
        .stat-card.completed { border-left-color: #4caf50; }
        .stat-card.total { border-left-color: #9c27b0; }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: bold;
            color: #1a1a1a;
            margin-bottom: 0.3rem;
        }

        .stat-label {
            color: #666;
            font-size: 0.9rem;
        }

        /* Task Board */
        .task-board {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
        }

        .task-column {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            min-height: 500px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .column-header {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            margin-bottom: 1.5rem;
            font-weight: 600;
            font-size: 1rem;
        }

        .column-badge {
            background: #f0f0f0;
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .column-badge.pending { background: #fff3cd; color: #856404; }
        .column-badge.progress { background: #d1ecf1; color: #0c5460; }
        .column-badge.completed { background: #d4edda; color: #155724; }

        .task-card {
            background: #f9f9f9;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            border: 1px solid #eee;
            cursor: pointer;
            transition: all 0.3s;
        }

        .task-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .task-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #1a1a1a;
        }

        .task-description {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 0.8rem;
        }

        .task-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            color: #999;
        }

        .priority-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .priority-high { background: #ffcdd2; color: #c62828; }
        .priority-medium { background: #fff3cd; color: #856404; }
        .priority-low { background: #c8e6c9; color: #2e7d32; }

        .task-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .btn {
            padding: 0.6rem 1rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s;
            text-align: center;
        }

        .btn-primary {
            background: #1a73e8;
            color: white;
        }

        .btn-primary:hover {
            background: #1557b0;
        }

        .btn-secondary {
            background: #f0f0f0;
            color: #333;
        }

        .btn-secondary:hover {
            background: #ddd;
        }

        .btn-success {
            background: #4caf50;
            color: white;
        }

        .btn-success:hover {
            background: #45a049;
        }

        .btn-danger {
            background: #ff6b6b;
            color: white;
        }

        /* Tasks List */
        .tasks-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .task-item {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-left: 4px solid #667eea;
        }

        .task-item-content {
            flex: 1;
        }

        .task-item-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 0.5rem;
        }

        .task-item-title {
            font-weight: 600;
            font-size: 1rem;
            color: #1a1a1a;
        }

        .task-item-description {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }

        .task-item-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.85rem;
            color: #999;
        }

        .task-item-actions {
            display: flex;
            gap: 0.5rem;
        }

        .task-item-actions .btn,
        .task-buttons .btn {
            flex: 1;
        }

        /* Chat Interface */
        .chat-container {
            display: grid;
            grid-template-columns: 250px 1fr;
            gap: 1rem;
            height: calc(100vh - 200px);
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .chat-list {
            border-right: 1px solid #e0e0e0;
            overflow-y: auto;
        }

        .chat-item {
            padding: 1rem;
            border-bottom: 1px solid #f0f0f0;
            cursor: pointer;
            transition: background 0.3s;
        }

        .chat-item:hover {
            background: #f5f5f5;
        }

        .chat-item.active {
            background: #e8f4ff;
            border-left: 3px solid #1a73e8;
        }

        .chat-item-name {
            font-weight: 600;
            font-size: 0.95rem;
            color: #1a1a1a;
        }

        .chat-item-status {
            font-size: 0.8rem;
            color: #999;
        }

        .chat-window {
            display: flex;
            flex-direction: column;
        }

        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .message {
            display: flex;
            gap: 0.8rem;
            margin-bottom: 0.5rem;
        }

        .message.sent {
            justify-content: flex-end;
        }

        .message-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #667eea;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            flex-shrink: 0;
        }

        .message-content {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .message-text {
            background: #f0f0f0;
            padding: 0.8rem 1rem;
            border-radius: 8px;
            max-width: 400px;
            word-wrap: break-word;
        }

        .message.sent .message-text {
            background: #1a73e8;
            color: white;
        }

        .message-time {
            font-size: 0.75rem;
            color: #999;
        }

        .chat-input {
            padding: 1rem;
            border-top: 1px solid #e0e0e0;
            display: flex;
            gap: 0.8rem;
        }

        .chat-input input {
            flex: 1;
            padding: 0.8rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.95rem;
        }

        .chat-input input:focus {
            outline: none;
            border-color: #1a73e8;
        }

        .chat-input button {
            background: #1a73e8;
            color: white;
            border: none;
            padding: 0.8rem 1.5rem;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
        }

        /* Emergency Form */
        .form-container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            max-width: 700px;
            margin: 0 auto;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .form-icon {
            text-align: center;
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .form-section {
            margin-bottom: 1.5rem;
        }

        .form-section h3 {
            color: #ff6b6b;
            font-size: 1rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #1a1a1a;
        }

        .required {
            color: #ff6b6b;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: inherit;
            font-size: 0.95rem;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #1a73e8;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }

        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
        }

        .alert-danger {
            background: #ffebee;
            color: #c62828;
            border-left: 4px solid #c62828;
        }

        .btn-report {
            width: 100%;
            padding: 1rem;
            background: #ff4444;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }

        .btn-report:hover {
            background: #cc0000;
        }

        .form-note {
            background: #fff3cd;
            padding: 1rem;
            border-radius: 4px;
            font-size: 0.9rem;
            color: #856404;
            margin-top: 1rem;
        }

        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .task-board {
                grid-template-columns: 1fr;
            }

            .sidebar {
                width: 150px;
            }
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal-overlay.active {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            background: white;
            width: 90%;
            max-width: 480px;
            border-radius: 20px;
            padding: 1.8rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            position: relative;
            transform: scale(0.9);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }


        .modal-overlay.active .modal-content {
            transform: scale(1);
        }

        .modal-close {
            position: absolute;
            top: 1.5rem;
            right: 1.5rem;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #64748b;
            font-size: 1.2rem;
            transition: all 0.2s;
        }

        .modal-close:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        .modal-header {
            margin-bottom: 1.5rem;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem;
        }

        .modal-body {
            color: #475569;
            line-height: 1.6;
        }

        .modal-info-row {
            display: flex;
            gap: 2rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #f1f5f9;
        }

        .modal-info-item label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: #94a3b8;
            text-transform: uppercase;
            margin-bottom: 0.25rem;
        }

        .modal-info-item span {
            font-weight: 600;
            color: #1e293b;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-logo">
                <div class="logo-title">Relief System</div>
                <div class="logo-subtitle">Volunteer</div>
            </div>
            <ul class="sidebar-menu">
                <li>
                    <a href="volunteer_dashboard.php?page=dashboard" class="<?php echo ($page === 'dashboard') ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                        Dashboard
                    </a>
                </li>
                <li>
                    <a href="volunteer_dashboard.php?page=tasks" class="<?php echo ($page === 'tasks') ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect><line x1="9" y1="12" x2="15" y2="12"></line><line x1="9" y1="16" x2="15" y2="16"></line><line x1="9" y1="8" x2="15" y2="8"></line></svg>
                        My Tasks
                        <?php if ($stats['pending'] > 0 || $stats['in_progress'] > 0): ?>
                            <span class="menu-badge"><?php echo ($stats['pending'] + $stats['in_progress']); ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li>
                    <a href="volunteer_dashboard.php?page=supplies" class="<?php echo ($page === 'supplies') ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
                        Supplies Delivered
                    </a>
                </li>
                <li>
                    <a href="volunteer_dashboard.php?page=field_reports" class="<?php echo ($page === 'field_reports') ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                        Field Reports
                    </a>
                </li>
                <li>
                    <a href="volunteer_dashboard.php?page=chat" class="<?php echo ($page === 'chat') ? 'active' : ''; ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                        Messages
                        <?php if ($unread_chat > 0): ?>
                            <span class="menu-badge" style="background: #ef4444;"><?php echo $unread_chat; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            </ul>
        </aside>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <header class="header">
                <div class="header-left">
                    <h2 style="color: #1a1a1a;">DisasterRelief</h2>
                </div>
                <div class="header-right">
                    <div class="notification-bell" onclick="document.getElementById('notifDropdownVol').classList.toggle('show')">
                        🔔
                        <?php if ($unread_count > 0): ?>
                            <span class="notification-badge"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                        
                        <div id="notifDropdownVol" class="dropdown-menu" style="width: 320px; right: -50px; padding: 0;">
                            <div style="padding: 1.25rem; border-bottom: 1px solid #f3f4f6; background: #f8fafc; text-align: left;">
                                <p style="font-weight: 700; font-size: 0.95rem; color: #0f172a; margin: 0;">Notifications</p>
                            </div>
                            <?php if (empty($all_notifications)): ?>
                                <div style="padding: 1.5rem; text-align: center; color: #6b7280; font-size: 0.9rem;">No new notifications</div>
                            <?php else: ?>
                                <div style="max-height: 300px; overflow-y: auto; text-align: left;">
                                    <?php foreach ($all_notifications as $notif): ?>
                                        <div style="padding: 1rem; border-bottom: 1px solid #f3f4f6; background: <?php echo $notif['is_read'] ? '#ffffff' : '#eff6ff'; ?>;">
                                            <div style="font-weight: 700; font-size: 0.85rem; color: #111827;"><?php echo htmlspecialchars($notif['title']); ?></div>
                                            <div style="font-size: 0.8rem; color: #4b5563; margin-top: 0.25rem;"><?php echo htmlspecialchars($notif['message']); ?></div>
                                            <div style="font-size: 0.7rem; color: #9ca3af; margin-top: 0.5rem;"><?php echo date('M d, H:i', strtotime($notif['created_at'])); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div style="padding: 0.75rem; text-align: center; border-top: 1px solid #e5e7eb; background: #f9fafb;">
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="action" value="mark_notifications_read">
                                        <button type="submit" style="background: none; border: none; color: #2563eb; font-size: 0.85rem; font-weight: 700; cursor: pointer;">Mark all as read</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="profile-dropdown">
                        <button type="button" class="dropdown-button" onclick="toggleProfileMenu()">
                            <div class="user-avatar"><?php echo strtoupper(substr($user['full_name'], 0, 1)); ?></div>
                            <div class="header-user-info">
                                <div class="name"><?php echo htmlspecialchars($user['full_name']); ?></div>
                                <div class="role"><?php echo ucfirst($user['role']); ?></div>
                            </div>
                        </button>
                        <div class="dropdown-menu" id="volProfileMenu">
                            <a href="volunteer_dashboard.php?page=profile">Profile</a>
                            <a href="logout.php" class="danger">Logout</a>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Content Area -->
            <div class="content-area">
                <?php if ($page === 'dashboard'): ?>
                    <!-- Dashboard Page -->
                    <h1 class="page-title">Volunteer Dashboard</h1>
                    <p class="page-subtitle">Assigned to: <?php echo htmlspecialchars($camp_name); ?></p>

                    <!-- Stats -->
                    <div class="stats-grid">
                        <div class="stat-card pending">
                            <div class="stat-icon">📋</div>
                            <div class="stat-number"><?php echo $stats['pending'] ?? 0; ?></div>
                            <div class="stat-label">Task Assigned</div>
                        </div>
                        <div class="stat-card progress">
                            <div class="stat-icon">⚙️</div>
                            <div class="stat-number"><?php echo $stats['in_progress'] ?? 0; ?></div>
                            <div class="stat-label">In Progress</div>
                        </div>
                        <div class="stat-card completed">
                            <div class="stat-icon">✅</div>
                            <div class="stat-number"><?php echo $stats['completed_today'] ?? 0; ?></div>
                            <div class="stat-label">Completed Today</div>
                        </div>
                        <div class="stat-card total">
                            <div class="stat-icon">🏆</div>
                            <div class="stat-number"><?php echo $stats['completed'] ?? 0; ?></div>
                            <div class="stat-label">Total Completed</div>
                        </div>
                    </div>

                    <!-- Task Board -->
                    <h2 style="font-size: 1.5rem; margin-bottom: 1.5rem; margin-top: 2rem;">Task Board</h2>
                    <div class="task-board">
                        <!-- Pending -->
                            <?php
                            $pending_tasks = $conn->query("SELECT * FROM tasks WHERE assigned_to = $user_id AND status = 'pending' AND DATE(due_date) = CURDATE()");
                            $pending_count = $pending_tasks->num_rows;
                            ?>
                            <div class="column-header">
                                <span>🟡 Pending Today</span>
                                <span class="column-badge pending"><?php echo $pending_count; ?></span>
                            </div>
                            <?php if ($pending_count === 0): ?>
                                <div style="text-align: center; color: #94a3b8; padding: 2rem; font-size: 0.9rem;">No pending tasks for today</div>
                            <?php endif; ?>
                            <?php while ($task = $pending_tasks->fetch_assoc()): ?>
                                <div class="task-card">
                                    <div class="task-title"><?php echo htmlspecialchars($task['task_name']); ?></div>
                                    <div class="task-description"><?php echo htmlspecialchars(substr($task['description'] ?? '', 0, 80)) . '...'; ?></div>

                                    <div class="task-meta">
                                        <span>📍 <?php echo htmlspecialchars($camp_name); ?></span>
                                        <span class="priority-badge priority-<?php echo strtolower($task['priority']); ?>"><?php echo strtoupper($task['priority']); ?></span>
                                    </div>
                                    <?php if ($task['task_type'] === 'distribution'): ?>
                                        <div style="margin-top: 0.8rem; padding: 0.5rem; background: #eff6ff; border-radius: 6px; border-left: 3px solid #3b82f6;">
                                            <p style="font-size: 0.75rem; font-weight: 700; color: #1e40af; margin-bottom: 2px;">📦 DISTRIBUTION TASK</p>
                                            <p style="font-size: 0.85rem; color: #334155;"><?php echo htmlspecialchars($task['distribution_item']); ?> (<?php echo $task['distribution_qty']; ?>)</p>
                                        </div>
                                    <?php endif; ?>
                                    <div style="margin-top: 1rem; font-size: 0.8rem; color: #3b82f6; font-weight: 600;">
                                        Manage in "My Tasks" section
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>

                        <!-- In Progress -->
                            <?php
                            $progress_tasks = $conn->query("SELECT * FROM tasks WHERE assigned_to = $user_id AND status = 'in_progress' AND DATE(due_date) = CURDATE()");
                            $progress_count = $progress_tasks->num_rows;
                            ?>
                            <div class="column-header">
                                <span>🔵 In Progress Today</span>
                                <span class="column-badge progress"><?php echo $progress_count; ?></span>
                            </div>
                            <?php if ($progress_count === 0): ?>
                                <div style="text-align: center; color: #94a3b8; padding: 2rem; font-size: 0.9rem;">No tasks in progress</div>
                            <?php endif; ?>
                            <?php while ($task = $progress_tasks->fetch_assoc()): ?>
                                <div class="task-card">
                                    <div class="task-title"><?php echo htmlspecialchars($task['task_name']); ?></div>
                                    <div class="task-description"><?php echo htmlspecialchars(substr($task['description'] ?? '', 0, 80)) . '...'; ?></div>

                                    <div class="task-meta">
                                        <span>📍 <?php echo htmlspecialchars($camp_name); ?></span>
                                        <span class="priority-badge priority-<?php echo strtolower($task['priority']); ?>"><?php echo strtoupper($task['priority']); ?></span>
                                    </div>
                                    <div style="margin-top: 1rem; font-size: 0.8rem; color: #3b82f6; font-weight: 600;">
                                        Manage in "My Tasks" section
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>

                        <!-- Completed -->
                            <?php
                            $completed_tasks = $conn->query("SELECT * FROM tasks WHERE assigned_to = $user_id AND status = 'completed' AND DATE(completed_date) = CURDATE()");
                            $completed_count = $completed_tasks->num_rows;
                            ?>
                            <div class="column-header">
                                <span>✅ Completed Today</span>
                                <span class="column-badge completed"><?php echo $completed_count; ?></span>
                            </div>
                            <?php if ($completed_count === 0): ?>
                                <div style="text-align: center; color: #94a3b8; padding: 2rem; font-size: 0.9rem;">No tasks completed today</div>
                            <?php endif; ?>
                            <?php while ($task = $completed_tasks->fetch_assoc()): ?>
                                <div class="task-card">
                                    <div class="task-title"><?php echo htmlspecialchars($task['task_name']); ?></div>
                                    <div class="task-description"><?php echo htmlspecialchars(substr($task['description'] ?? '', 0, 80)) . '...'; ?></div>

                                    <div class="task-meta">
                                        <span>📍 <?php echo htmlspecialchars($camp_name); ?></span>
                                        <span class="priority-badge priority-<?php echo strtolower($task['priority']); ?>"><?php echo strtoupper($task['priority']); ?></span>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>

                <?php elseif ($page === 'tasks'): ?>
                    <!-- My Tasks Page -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <div>
                            <h1 class="page-title">My Tasks</h1>
                            <p class="page-subtitle">Manage all your assigned tasks</p>
                        </div>
                        <button class="btn btn-primary" style="padding: 0.5rem 1rem; border-radius: 8px; background: #2563eb; font-size: 0.85rem;" onclick="openCreateTaskModal()">+ Create New Task</button>
                    </div>

                    <?php if (isset($_SESSION['success_msg'])): ?>
                        <div style="background: #dcfce7; color: #15803d; padding: 1rem; border-radius: 12px; margin-bottom: 2rem; border: 1px solid #bcf0da;">
                            ✅ <?php echo $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Task Stats -->
                    <div class="stats-grid" style="grid-template-columns: repeat(5, 1fr); margin-bottom: 2rem;">
                        <div class="stat-card total">
                            <div class="stat-icon">📋</div>
                            <div class="stat-number"><?php echo $stats['total'] ?? 0; ?></div>
                            <div class="stat-label">Total Tasks</div>
                        </div>
                        <div class="stat-card pending">
                            <div class="stat-icon">🟡</div>
                            <div class="stat-number"><?php echo $stats['pending'] ?? 0; ?></div>
                            <div class="stat-label">Pending</div>
                        </div>
                        <div class="stat-card progress">
                            <div class="stat-icon">🔵</div>
                            <div class="stat-number"><?php echo $stats['in_progress'] ?? 0; ?></div>
                            <div class="stat-label">In Progress</div>
                        </div>
                        <div class="stat-card completed">
                            <div class="stat-icon">✅</div>
                            <div class="stat-number"><?php echo $stats['completed'] ?? 0; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                    </div>

                    <!-- All Tasks -->
                    <h2 style="font-size: 1.3rem; margin-bottom: 1.5rem;">All Tasks</h2>
                    <div class="tasks-list">
                        <?php
                        $all_tasks = $conn->query("SELECT tasks.*, users.full_name as manager_name 
                            FROM tasks 
                            LEFT JOIN users ON tasks.assigned_by = users.id 
                            WHERE tasks.assigned_to = $user_id 
                            ORDER BY status, priority DESC, due_date ASC");
                        while ($task = $all_tasks->fetch_assoc()):
                        ?>
                            <div class="task-item">
                                <div class="task-item-content">
                                    <div class="task-item-header">
                                        <span class="task-item-title"><?php echo htmlspecialchars($task['task_name']); ?></span>
                                        <span class="priority-badge priority-<?php echo strtolower($task['priority']); ?>"><?php echo strtoupper($task['priority']); ?></span>
                                        <span class="column-badge 
                                            <?php 
                                            if ($task['status'] === 'pending') echo 'pending';
                                            elseif ($task['status'] === 'in_progress') echo 'progress';
                                            else echo 'completed';
                                            ?>
                                        "><?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?></span>
                                    </div>
                                    <div class="task-item-description"><?php echo htmlspecialchars($task['description'] ?? ''); ?></div>

                                    <div class="task-item-meta">
                                        <span>📍 <?php echo htmlspecialchars($camp_name); ?></span>
                                        <span>📅 <?php echo $task['due_date'] ? date('d M, h:i A', strtotime($task['due_date'])) : 'No due date'; ?></span>

                                        <span>👤 Assigned by: <?php echo htmlspecialchars($task['manager_name'] ?: 'System'); ?></span>
                                    </div>
                                </div>
                                <div class="task-item-actions">
                                    <?php if ($task['status'] === 'pending'): ?>
                                        <button class="btn btn-primary" onclick="updateTaskStatus(<?php echo $task['id']; ?>, 'in_progress')">Start Task</button>
                                    <?php elseif ($task['status'] === 'in_progress'): ?>
                                        <button class="btn btn-success" onclick="updateTaskStatus(<?php echo $task['id']; ?>, 'completed')">Complete Task</button>
                                    <?php else: ?>
                                        <button class="btn btn-secondary" disabled>✓ Completed</button>
                                    <?php endif; ?>
                                    <button class="btn btn-secondary" onclick="showTaskDetails('<?php echo addslashes($task['task_name'] ?? ''); ?>', '<?php echo addslashes($task['description'] ?? ''); ?>', '<?php echo strtoupper($task['priority'] ?? 'MEDIUM'); ?>', '<?php echo ucfirst($task['status'] ?? 'pending'); ?>', '<?php echo addslashes($task['manager_name'] ?? 'System'); ?>', '<?php echo $task['task_type'] ?? 'standard'; ?>', '<?php echo addslashes($task['distribution_item'] ?? ''); ?>', '<?php echo $task['distribution_qty'] ?? 0; ?>')">View Details</button>


                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>

                <?php elseif ($page === 'profile'): ?>
                    <div style="max-width: 800px; margin: 0 auto;">
                        <h1 class="page-title">My Profile</h1>
                        <p class="page-subtitle">Manage your personal information and preferences</p>
                        
                        <div style="background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">
                            <!-- Profile Header -->
                            <div style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); padding: 3rem 2rem; text-align: center; color: white;">
                                <div style="width: 100px; height: 100px; border-radius: 50%; background: white; color: #2563eb; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; font-weight: 700; margin: 0 auto 1.5rem; border: 4px solid rgba(255,255,255,0.2);">
                                    <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                </div>
                                <h2 style="font-size: 1.5rem; font-weight: 700; margin-bottom: 0.5rem;"><?php echo htmlspecialchars($user['full_name']); ?></h2>
                                <p style="opacity: 0.9; font-size: 1rem;">Volunteer • <?php echo htmlspecialchars($camp_name); ?></p>
                            </div>
                            
                            <!-- Profile Details -->
                            <div style="padding: 2.5rem;">
                                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 2rem;">
                                    <div>
                                        <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #64748b; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.5px;">Email Address</label>
                                        <div style="font-size: 1rem; color: #1e293b; font-weight: 500; background: #f8fafc; padding: 1rem; border-radius: 10px; border: 1px solid #e2e8f0;">
                                            <?php echo htmlspecialchars($user['email']); ?>
                                        </div>
                                    </div>
                                    <div>
                                        <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #64748b; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.5px;">Phone Number</label>
                                        <div style="font-size: 1rem; color: #1e293b; font-weight: 500; background: #f8fafc; padding: 1rem; border-radius: 10px; border: 1px solid #e2e8f0;">
                                            <?php echo htmlspecialchars($user['phone'] ?: 'Not provided'); ?>
                                        </div>
                                    </div>
                                    <div>
                                        <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #64748b; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.5px;">Location</label>
                                        <div style="font-size: 1rem; color: #1e293b; font-weight: 500; background: #f8fafc; padding: 1rem; border-radius: 10px; border: 1px solid #e2e8f0;">
                                            <?php echo htmlspecialchars($user['location'] ?: 'Not set'); ?>
                                        </div>
                                    </div>
                                    <div>
                                        <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #64748b; margin-bottom: 0.5rem; text-transform: uppercase; letter-spacing: 0.5px;">Join Date</label>
                                        <div style="font-size: 1rem; color: #1e293b; font-weight: 500; background: #f8fafc; padding: 1rem; border-radius: 10px; border: 1px solid #e2e8f0;">
                                            <?php echo date('F d, Y', strtotime($user['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                                
                                <div style="margin-top: 2.5rem; padding-top: 2rem; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 1rem;">
                                    <button class="btn btn-secondary" style="padding: 0.75rem 1.5rem; border-radius: 10px;">Edit Profile</button>
                                    <button class="btn btn-primary" style="padding: 0.75rem 1.5rem; border-radius: 10px; background: #2563eb;">Change Password</button>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($page === 'supplies'): ?>
                    <!-- Supplies Delivered Page -->
                    <h1 class="page-title">Supplies Delivered</h1>
                    <p class="page-subtitle">Track your supply distribution history</p>
                    
                    <div class="card" style="padding: 2rem; background: white; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
                        <div style="overflow-x: auto;">
                            <table style="width: 100%; border-collapse: collapse; margin-top: 1rem;">
                                <thead>
                                    <tr style="border-bottom: 2px solid #f1f5f9; text-align: left;">
                                        <th style="padding: 1rem; color: #64748b; font-weight: 600;">Date</th>
                                        <th style="padding: 1rem; color: #64748b; font-weight: 600;">Item</th>
                                        <th style="padding: 1rem; color: #64748b; font-weight: 600;">Quantity</th>
                                        <th style="padding: 1rem; color: #64748b; font-weight: 600;">Location</th>
                                        <th style="padding: 1rem; color: #64748b; font-weight: 600;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $dist_history = $conn->query("SELECT * FROM distributions WHERE distributed_by = $user_id ORDER BY distributed_at DESC");
                                    if ($dist_history->num_rows === 0):
                                    ?>
                                        <tr>
                                            <td colspan="5" style="padding: 3rem; text-align: center; color: #94a3b8;">
                                                <div style="font-size: 2.5rem; margin-bottom: 1rem;">📦</div>
                                                No supplies delivered yet.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php while ($row = $dist_history->fetch_assoc()): ?>
                                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                                <td style="padding: 1rem;"><?php echo date('M d, Y', strtotime($row['distributed_at'])); ?></td>
                                                <td style="padding: 1rem; font-weight: 500;"><?php echo htmlspecialchars($row['items']); ?></td>
                                                <td style="padding: 1rem;"><?php echo $row['quantity']; ?> units</td>
                                                <td style="padding: 1rem;"><?php echo htmlspecialchars($row['recipient_name']); ?></td>
                                                <td style="padding: 1rem;"><span style="background: #dcfce7; color: #15803d; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.85rem;">Delivered</span></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php endif; ?>
                                </tbody>

                            </table>
                        </div>
                    </div>

                <?php elseif ($page === 'field_reports'): ?>
                    <!-- Field Reports Page -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
                        <div>
                            <h1 class="page-title">Field Reports</h1>
                            <p class="page-subtitle">Your submitted activity and incident reports</p>
                        </div>
                        <button class="btn btn-primary" style="padding: 0.8rem 1.5rem; border-radius: 12px; background: #2563eb;" onclick="location.href='volunteer_dashboard.php?page=report'">+ Create New Report</button>
                    </div>

                    <?php if (isset($_SESSION['success_msg'])): ?>
                        <div style="background: #dcfce7; color: #15803d; padding: 1rem; border-radius: 12px; margin-bottom: 2rem; border: 1px solid #bcf0da;">
                            ✅ <?php echo $_SESSION['success_msg']; unset($_SESSION['success_msg']); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 1.5rem;">
                        <?php
                        $reports_query = $conn->query("SELECT * FROM emergency_reports WHERE reported_by = $user_id ORDER BY created_at DESC");
                        if ($reports_query->num_rows === 0):
                        ?>
                            <div style="grid-column: 1/-1; text-align: center; padding: 4rem; background: white; border-radius: 16px; border: 2px dashed #e2e8f0;">
                                <div style="font-size: 3rem; margin-bottom: 1rem;">📋</div>
                                <h3 style="color: #1e293b; margin-bottom: 0.5rem;">No reports found</h3>
                                <p style="color: #64748b;">You haven't submitted any activity or incident reports yet.</p>
                            </div>
                        <?php else: ?>
                            <?php while ($report = $reports_query->fetch_assoc()): ?>
                                <div class="card" style="padding: 1.5rem; background: white; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border-top: 4px solid <?php echo $report['report_category'] === 'activity' ? '#3b82f6' : '#f59e0b'; ?>;">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 1rem; align-items: center;">
                                        <span style="color: #64748b; font-size: 0.8rem; font-weight: 600; text-transform: uppercase;">REP-<?php echo str_pad($report['id'], 4, '0', STR_PAD_LEFT); ?></span>
                                        <span style="background: <?php echo $report['report_category'] === 'activity' ? '#eff6ff' : '#fffbeb'; ?>; color: <?php echo $report['report_category'] === 'activity' ? '#2563eb' : '#d97706'; ?>; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.75rem; font-weight: 700;">
                                            <?php echo ucfirst($report['report_category']); ?>
                                        </span>
                                    </div>
                                    <h3 style="font-size: 1.15rem; margin-bottom: 0.75rem; color: #1e293b;"><?php echo htmlspecialchars($report['issue_type'] ?: 'General Report'); ?></h3>
                                    <p style="color: #475569; font-size: 0.95rem; margin-bottom: 1.5rem; line-height: 1.5;"><?php echo htmlspecialchars($report['description']); ?></p>
                                    <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f1f5f9; pt: 1rem; margin-top: auto; padding-top: 1rem;">
                                        <div style="display: flex; align-items: center; gap: 0.5rem; color: #64748b; font-size: 0.85rem;">
                                            📍 <?php echo htmlspecialchars($report['location']); ?>
                                        </div>
                                        <div style="color: #94a3b8; font-size: 0.8rem;">
                                            <?php 
                                            $diff = time() - strtotime($report['created_at']);
                                            if ($diff < 3600) echo floor($diff/60) . " mins ago";
                                            elseif ($diff < 86400) echo floor($diff/3600) . " hours ago";
                                            else echo date('d M, Y', strtotime($report['created_at']));
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </div>

                <?php elseif ($page === 'chat'): ?>
                    <!-- Chat Page (Messages) -->
                    <h1 class="page-title">Messages</h1>
                    <p class="page-subtitle">Communicate with System Admins and your Camp Manager</p>

                    <?php
                    // Get list of contacts: all admins and the manager of the assigned camp (if any)
                    $contact_users_query = "
                        SELECT u.id, u.full_name, u.role,
                            (SELECT message_text FROM messages WHERE (sender_id=u.id OR receiver_id=u.id) AND (sender_id=$user_id OR receiver_id=$user_id) ORDER BY created_at DESC LIMIT 1) as last_msg,
                            (SELECT created_at FROM messages WHERE (sender_id=u.id OR receiver_id=u.id) AND (sender_id=$user_id OR receiver_id=$user_id) ORDER BY created_at DESC LIMIT 1) as last_msg_time,
                            (SELECT COUNT(*) FROM messages WHERE sender_id=u.id AND receiver_id=$user_id AND is_read=0) as unread_count
                        FROM users u
                        LEFT JOIN camps c ON u.id = c.manager_id
                        WHERE u.role = 'admin'
                           " . ($camp_id ? "OR (u.role = 'camp_manager' AND c.id = $camp_id)" : "") . "
                        ORDER BY COALESCE(last_msg_time, '1970-01-01') DESC, u.full_name ASC
                    ";
                    $chat_users = $conn->query($contact_users_query);
                    $contacts_list = [];
                    if ($chat_users) {
                        while ($cu = $chat_users->fetch_assoc()) {
                            $contacts_list[] = $cu;
                        }
                    }

                    $chat_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
                    if ($chat_user_id == 0 && !empty($contacts_list)) {
                        $chat_user_id = $contacts_list[0]['id'];
                    }

                    $target_user = null;
                    if ($chat_user_id > 0) {
                        $target_user_query = $conn->query("SELECT * FROM users WHERE id = $chat_user_id");
                        if ($target_user_query) {
                            $target_user = $target_user_query->fetch_assoc();
                        }
                    }
                    ?>

                    <div class="chat-container">
                        <!-- Chat List -->
                        <div class="chat-list">
<<<<<<< HEAD
                            <div class="chat-item active">
                                <div class="chat-item-name">Karim Ahmed</div>
                                <div class="chat-item-status">🟢 Online - Camp Manager</div>
                            </div>
=======
                            <?php if (!empty($contacts_list)): ?>
                                <?php foreach ($contacts_list as $cu): ?>
                                    <div class="chat-item <?php echo ($cu['id'] == $chat_user_id) ? 'active' : ''; ?>" onclick="location.href='volunteer_dashboard.php?page=chat&user_id=<?php echo $cu['id']; ?>'">
                                        <div class="chat-item-name"><?php echo htmlspecialchars($cu['full_name']); ?></div>
                                        <div class="chat-item-status" style="font-size: 0.75rem; color: #64748b; margin-top: 2px;">
                                            <?php echo ucfirst(str_replace('_', ' ', $cu['role'])); ?>
                                            <?php if ($cu['unread_count'] > 0): ?>
                                                <span style="background: #ef4444; color: white; padding: 1px 5px; border-radius: 999px; font-size: 0.65rem; font-weight: bold; margin-left: 5px; float: right;"><?php echo $cu['unread_count']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($cu['last_msg']): ?>
                                            <div style="font-size: 0.8rem; color: #94a3b8; margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?php echo htmlspecialchars($cu['last_msg']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="padding: 1.5rem; text-align: center; color: #94a3b8; font-size: 0.9rem;">
                                    No contacts available.
                                </div>
                            <?php endif; ?>
>>>>>>> Shoron
                        </div>

                        <!-- Chat Window -->
                        <div class="chat-window">
<<<<<<< HEAD
                            <!-- Messages -->
                            <div class="chat-messages">
                                <div class="message">
                                    <div class="message-avatar">KA</div>
                                    <div class="message-content">
                                        <div class="message-text">Great work on today's distribution!</div>
                                        <div class="message-time">11:00 AM</div>
                                    </div>
                                </div>

                                <div class="message">
                                    <div class="message-avatar">KA</div>
                                    <div class="message-content">
                                        <div class="message-text">I have a new task for you</div>
                                        <div class="message-time">11:10 AM</div>
                                    </div>
=======
                            <?php if ($target_user): 
                                // Mark messages as read
                                $conn->query("UPDATE messages SET is_read = 1 WHERE sender_id = $chat_user_id AND receiver_id = $user_id AND is_read = 0");
                                
                                // Fetch messages
                                $messages = $conn->query("SELECT * FROM messages WHERE (sender_id = $user_id AND receiver_id = $chat_user_id) OR (sender_id = $chat_user_id AND receiver_id = $user_id) ORDER BY created_at ASC");
                            ?>
                                <div style="padding: 1rem; border-bottom: 1px solid #e0e0e0; display: flex; align-items: center; justify-content: space-between; background: #fff;">
                                    <div>
                                        <h3 style="font-size: 1rem; font-weight: 700; color: #0f172a;"><?php echo htmlspecialchars($target_user['full_name']); ?></h3>
                                        <span class="badge active" style="font-size:0.7rem; padding: 2px 8px; background: #eff6ff; color: #3b82f6; border-radius: 999px; font-weight: 600;"><?php echo ucfirst(str_replace('_', ' ', $target_user['role'])); ?></span>
                                    </div>
                                </div>

                                <!-- Messages -->
                                <div class="chat-messages" id="chatContainer">
                                    <?php if ($messages && $messages->num_rows > 0): ?>
                                        <?php while ($msg = $messages->fetch_assoc()): 
                                            $is_me = ($msg['sender_id'] == $user_id);
                                        ?>
                                            <div class="message <?php echo $is_me ? 'sent' : ''; ?>">
                                                <?php if (!$is_me): ?>
                                                    <div class="message-avatar">
                                                        <?php echo strtoupper(substr($target_user['full_name'], 0, 1)); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="message-content">
                                                    <div class="message-text">
                                                        <?php echo htmlspecialchars($msg['message_text']); ?>
                                                    </div>
                                                    <div class="message-time" style="<?php echo $is_me ? 'text-align: right;' : ''; ?>">
                                                        <?php echo date('M d, h:i A', strtotime($msg['created_at'])); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <div style="text-align: center; color: #94a3b8; font-size: 0.9rem; margin-top: auto; margin-bottom: auto;">
                                            No messages yet. Start the conversation.
                                        </div>
                                    <?php endif; ?>
>>>>>>> Shoron
                                </div>

                                <!-- Chat Input -->
                                <form method="POST" style="margin: 0;">
                                    <input type="hidden" name="action" value="send_chat_message">
                                    <input type="hidden" name="receiver_id" value="<?php echo $chat_user_id; ?>">
                                    <div class="chat-input">
                                        <input type="text" name="message" placeholder="Type your message..." required autocomplete="off" autofocus>
                                        <button type="submit">Send</button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #94a3b8;">
                                    Select a user to start chatting.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($page === 'report'): ?>
                    <!-- Report Issue Page -->
                    <div style="text-align: center; margin-bottom: 2rem;">
                        <div class="form-icon">📋</div>
                        <h1 class="page-title">Submit Field Report</h1>
                        <p class="page-subtitle">Report an activity completion or an emergency issue to the camp manager</p>
                    </div>

                    <div class="form-container" style="border-radius: 16px; padding: 2.5rem; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);">
                        <form method="POST" action="volunteer_dashboard.php?page=field_reports">
                            <input type="hidden" name="action" value="submit_report">
                            
                            <div class="form-group">
                                <label>Report Category <span class="required">*</span></label>
                                <select name="report_category" required style="padding: 0.8rem; border-radius: 10px;">
                                    <option value="activity">Regular Activity Report (e.g. Distribution done)</option>
                                    <option value="issue">Emergency/Issue Report (e.g. Medical, Security)</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Report Title / Issue Type <span class="required">*</span></label>
                                <input type="text" name="issue_type" placeholder="e.g. Food Distribution, Medical Emergency" required style="padding: 0.8rem; border-radius: 10px;">
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                                <div class="form-group">
                                    <label>Priority Level <span class="required">*</span></label>
                                    <select name="priority" required style="padding: 0.8rem; border-radius: 10px;">
                                        <option value="low">Low</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="high">High</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Location <span class="required">*</span></label>
                                    <input type="text" name="location" placeholder="Section/Area" required style="padding: 0.8rem; border-radius: 10px;">
                                </div>
                            </div>

                            <div class="form-group">
                                <label>People Affected (if any)</label>
                                <input type="number" name="people_affected" placeholder="Number of people" min="0" style="padding: 0.8rem; border-radius: 10px;">
                            </div>

                            <div class="form-group">
                                <label>Report Description <span class="required">*</span></label>
                                <textarea name="description" placeholder="Provide detailed information..." required style="padding: 0.8rem; border-radius: 10px; min-height: 120px;"></textarea>
                            </div>

                            <div class="form-group">
                                <label>Action Taken / Remarks</label>
                                <textarea name="immediate_action" placeholder="What was done or any additional notes" style="padding: 0.8rem; border-radius: 10px; min-height: 80px;"></textarea>
                            </div>

                            <div style="margin-top: 2rem;">
                                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 1rem; border-radius: 12px; font-size: 1rem; background: #2563eb;">🚀 Submit Report</button>
                            </div>
                        </form>
                    </div>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function updateTaskStatus(taskId, status) {
            let actionText = '';
            if (status === 'in_progress') actionText = 'start this task?';
            else if (status === 'completed') actionText = 'mark this task as completed?';
            else if (status === 'pending') actionText = 'move this task back to pending?';

            if (confirm('Are you sure you want to ' + actionText)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = window.location.href;

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'update_task_status';
                form.appendChild(actionInput);

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'task_id';
                idInput.value = taskId;
                form.appendChild(idInput);

                const statusInput = document.createElement('input');
                statusInput.type = 'hidden';
                statusInput.name = 'status';
                statusInput.value = status;
                form.appendChild(statusInput);

                document.body.appendChild(form);
                form.submit();
            }
        }

        function sendMessage() {
            const input = document.querySelector('.chat-input input');
            if (input.value.trim()) {
                alert('Message sent: ' + input.value);
                input.value = '';
            }
        }

        function toggleProfileMenu() {
            document.getElementById('volProfileMenu').classList.toggle('show');
        }

        function showTaskDetails(title, description, priority, status, manager, taskType, distItem, distQty) {

            document.getElementById('modalTitle').innerText = title;
            document.getElementById('modalDesc').innerText = description;
            document.getElementById('modalPriority').innerText = priority;
            document.getElementById('modalStatus').innerText = status;
            document.getElementById('modalManager').innerText = manager;
            
            if (taskType === 'distribution') {
                document.getElementById('modalDistInfo').style.display = 'block';
                document.getElementById('modalItem').innerText = distItem;
                document.getElementById('modalQty').innerText = distQty;
            } else {
                document.getElementById('modalDistInfo').style.display = 'none';
            }

            
            const overlay = document.getElementById('taskDetailsModal');
            overlay.classList.add('active');
        }

        function closeModal() {
            document.getElementById('taskDetailsModal').classList.remove('active');
            document.getElementById('createTaskModal').classList.remove('active');
        }

        function openCreateTaskModal() {
            document.getElementById('createTaskModal').classList.add('active');
            // Reset fields
            document.getElementById('task_type').value = 'standard';
            toggleTaskFields();
        }

        function toggleTaskFields() {
            const taskType = document.getElementById('task_type').value;
            const distFields = document.getElementById('distribution_fields');
            if (taskType === 'distribution') {
                distFields.style.display = 'block';
                distFields.querySelectorAll('select, input').forEach(el => el.required = true);
            } else {
                distFields.style.display = 'none';
                distFields.querySelectorAll('select, input').forEach(el => el.required = false);
            }
        }


        document.addEventListener('click', function(event) {
            const menu = document.getElementById('volProfileMenu');
            if (menu) {
                const dropdown = menu.closest('.profile-dropdown');
                if (dropdown && !dropdown.contains(event.target)) {
                    menu.classList.remove('show');
                }
            }

            const overlay = document.getElementById('taskDetailsModal');
            if (event.target === overlay) {
                closeModal();
            }
        });

    </script>
    <!-- Task Details Modal -->
    <div class="modal-overlay" id="taskDetailsModal">
        <div class="modal-content">
            <div class="modal-close" onclick="closeModal()">×</div>
            <div class="modal-header">
                <div class="modal-title" id="modalTitle">Task Title</div>
                <div id="modalStatusBadge" style="display: inline-block;">
                    <span class="column-badge" id="modalStatus">Pending</span>
                </div>
            </div>
            <div class="modal-body">
                <div id="modalDesc">Task description goes here...</div>
                
                <div class="modal-info-row">
                    <div class="modal-info-item">
                        <label>Priority</label>
                        <span id="modalPriority">HIGH</span>
                    </div>
                    <div class="modal-info-item">
                        <label>Assigned By</label>
                        <span id="modalManager">Manager Name</span>
                    </div>
                </div>

                <div id="modalDistInfo" style="display: none; margin-top: 1.5rem; padding: 1rem; background: #f0f9ff; border-radius: 12px; border: 1px solid #bae6fd;">
                    <p style="font-size: 0.75rem; font-weight: 700; color: #0369a1; text-transform: uppercase; margin-bottom: 0.5rem;">📦 Distribution Details</p>
                    <div style="display: flex; gap: 2rem;">
                        <div class="modal-info-item">
                            <label>Item</label>
                            <span id="modalItem">Food</span>
                        </div>
                        <div class="modal-info-item">
                            <label>Quantity</label>
                            <span id="modalQty">10</span>
                        </div>
                    </div>
                </div>

            </div>
            <div style="margin-top: 2rem; display: flex; justify-content: flex-end;">
                <button class="btn btn-primary" onclick="closeModal()" style="padding: 0.8rem 2rem; border-radius: 12px; background: #2563eb;">Got it</button>
            </div>
        </div>
    </div>
    <!-- Create Task Modal -->
    <div class="modal-overlay" id="createTaskModal">
        <div class="modal-content">
            <div class="modal-close" onclick="closeModal()">×</div>
            <div class="modal-header">
                <div class="modal-title">Create New Task</div>
                <p style="color: #64748b; font-size: 0.9rem;">Assign a new task to yourself</p>
            </div>
            <div class="modal-body">
                <form method="POST" action="volunteer_dashboard.php?page=tasks">
                    <input type="hidden" name="action" value="create_task">
                    
                    <div class="form-group">
                        <label>Task Name <span class="required">*</span></label>
                        <input type="text" name="task_name" placeholder="e.g. Clean distribution area" required style="padding: 0.8rem; border-radius: 10px;">
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                        <div class="form-group">
                            <label>Assignment Type <span class="required">*</span></label>
                            <select name="assignment_type" required style="padding: 0.8rem; border-radius: 10px;">
                                <option value="self">Self-Assign (Assign to me)</option>
                                <option value="normal">Normal Task (Unassigned/Pool)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Task Type <span class="required">*</span></label>
                            <select name="task_type" id="task_type" onchange="toggleTaskFields()" required style="padding: 0.8rem; border-radius: 10px;">
                                <option value="standard">Standard Task</option>
                                <option value="distribution">Aid Distribution</option>
                            </select>
                        </div>
                    </div>

                    <!-- Distribution Specific Fields -->
                    <div id="distribution_fields" style="display: none; background: #f8fafc; padding: 1.5rem; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 1.5rem;">
                        <h4 style="font-size: 0.85rem; color: #3b82f6; text-transform: uppercase; margin-bottom: 1rem; font-weight: 700;">Distribution Details</h4>
                        <div class="form-group">
                            <label>Select Aid Item <span class="required">*</span></label>
                            <select name="distribution_item" style="padding: 0.8rem; border-radius: 10px;">
                                <?php if (empty($inventory_items)): ?>
                                    <option value="">-- No items available in inventory --</option>
                                <?php else: ?>
                                    <option value="">-- Select Item --</option>
                                    <?php foreach ($inventory_items as $item): ?>
                                        <option value="<?php echo htmlspecialchars($item['item_name']); ?>"><?php echo htmlspecialchars($item['item_name']); ?> (Available: <?php echo $item['quantity']; ?> <?php echo $item['unit']; ?>)</option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>

                        </div>
                        <div class="form-group">
                            <label>Amount / Quantity <span class="required">*</span></label>
                            <input type="number" name="distribution_qty" step="0.01" placeholder="Enter amount" style="padding: 0.8rem; border-radius: 10px;">
                        </div>
                    </div>


                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" placeholder="Provide task details..." style="padding: 0.8rem; border-radius: 10px; min-height: 100px;"></textarea>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                        <div class="form-group">
                            <label>Priority <span class="required">*</span></label>
                            <select name="priority" required style="padding: 0.8rem; border-radius: 10px;">
                                <option value="low">Low</option>
                                <option value="medium" selected>Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Due Date <span class="required">*</span></label>
                            <input type="datetime-local" name="due_date" required style="padding: 0.8rem; border-radius: 10px;">
                        </div>
                    </div>

                    <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeModal()" style="padding: 0.8rem 1.5rem; border-radius: 12px;">Cancel</button>
                        <button type="submit" class="btn btn-primary" style="padding: 0.8rem 1.5rem; border-radius: 12px; background: #2563eb;">Create Task</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Real-time-ish check for new tasks/notifications
        let lastNotificationCount = <?php echo $unread_count; ?>;
        setInterval(() => {
            fetch('volunteer_dashboard.php?api=check_notifications')
                .then(res => res.json())
                .then(data => {
                    if (data.count > lastNotificationCount) {
                        const bell = document.querySelector('.notification-badge');
                        if (bell) {
                            bell.innerText = data.count;
                            bell.style.display = 'flex';
                        } else {
                            const bellIcon = document.querySelector('.notification-bell');
                            if (bellIcon) {
                                bellIcon.innerHTML += `<span class="notification-badge">${data.count}</span>`;
                            }
                        }
                        lastNotificationCount = data.count;
                        
                        if (window.Notification && Notification.permission === "granted") {
                            new Notification("New Task Assigned", { body: "You have received a new assignment from the Camp Manager." });
                        }
                    }
                });
        }, 5000);

        if (window.Notification && Notification.permission !== "denied") {
            Notification.requestPermission();
        }

        function toggleProfileMenu() {
            const menu = document.getElementById('volProfileMenu');
            if (menu) menu.classList.toggle('show');
        }
    </script>
</body>
</html>
<?php
$conn->close();
?>