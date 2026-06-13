<?php
ob_start();
session_start();
include 'config.php';

// Ensure the user is logged in as an affected person
if (!isset($_SESSION['affected_id']) || !isset($_SESSION['affected_key'])) {
    redirect('affected_login.php');
}

$affected_id = $_SESSION['affected_id'];

// Fetch affected person details
$stmt = $conn->prepare("SELECT * FROM affected_persons WHERE id = ?");
$stmt->bind_param("i", $affected_id);
$stmt->execute();
$person = $stmt->get_result()->fetch_assoc();

if (!$person) {
    // If not found in DB for some reason, log out
    session_destroy();
    redirect('affected_login.php');
}

$status = $person['registration_status']; // 'pending', 'assigned', 'resolved'

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
$allowed_pages_pending = ['dashboard', 'request_help', 'relief_status'];
$allowed_pages_registered = ['dashboard', 'request_help', 'relief_status', 'camp_info'];

// If person has chat_power, allow messages page
if (!empty($person['chat_power'])) {
    $allowed_pages_pending[] = 'messages';
    $allowed_pages_registered[] = 'messages';
}

$allowed_pages = ($status === 'pending') ? $allowed_pages_pending : $allowed_pages_registered;

if (!in_array($page, $allowed_pages)) {
    $page = 'dashboard';
}

$success = '';
$error = '';

// Handle POST for messages
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'messages') {
    $msg = sanitize($_POST['message'] ?? '');
    $chat_target = $_POST['chat_target'] ?? 'admin'; // 'admin' or 'manager'
    if ($msg) {
        $insert = $conn->prepare("INSERT INTO affected_messages (affected_id, message_text, is_from_admin) VALUES (?, ?, 0)");
        $insert->bind_param("is", $affected_id, $msg);
        if ($insert->execute()) {
            $success = "Message sent.";
        } else {
            $error = "Failed to send message.";
        }
    }
}

// Handle POST for help request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'request_help') {
    $category = sanitize($_POST['category'] ?? '');
    $urgency = sanitize($_POST['urgency'] ?? 'Medium');
    $description = sanitize($_POST['description'] ?? '');
    $contact = sanitize($_POST['contact'] ?? '');

    if ($category && $description) {
        $insert = $conn->prepare("INSERT INTO affected_help_requests (affected_id, category, urgency, description, contact, status) VALUES (?, ?, ?, ?, ?, 'pending')");
        $insert->bind_param("issss", $affected_id, $category, $urgency, $description, $contact);
        if ($insert->execute()) {
            $success = "Help request submitted successfully. Camp staff will review it shortly.";
        } else {
            $error = "Failed to submit help request.";
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

// Fetch all help requests
$req_stmt = $conn->prepare("SELECT * FROM affected_help_requests WHERE affected_id = ? ORDER BY created_at DESC");
$req_stmt->bind_param("i", $affected_id);
$req_stmt->execute();
$req_result = $req_stmt->get_result();
$all_requests = [];
while ($row = $req_result->fetch_assoc()) {
    $all_requests[] = $row;
}
$help_request = count($all_requests) > 0 ? $all_requests[0] : null;

// Fetch aid received (last 5 distributions for this camp)
$camp_id_fetch = $person['camp_id'] ?? 0;
$aid_received = [];
if ($camp_id_fetch > 0) {
    $aid_stmt = $conn->prepare("SELECT * FROM distributions WHERE camp_id = ? ORDER BY distributed_at DESC LIMIT 5");
    $aid_stmt->bind_param("i", $camp_id_fetch);
    $aid_stmt->execute();
    $aid_result = $aid_stmt->get_result();
    while ($row = $aid_result->fetch_assoc()) {
        $aid_received[] = $row;
    }
}

// ── AJAX handlers: return only the content fragment, then exit ──────────────
if (isset($_GET['ajax'])) {
    ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');

    if ($page === 'dashboard') {
        // Re-fetch fresh aid data for this camp (last 5)
        $camp_id_fetch2 = $person['camp_id'] ?? 0;
        $aid_received_fresh = [];
        if ($camp_id_fetch2 > 0) {
            $aid_stmt2 = $conn->prepare("SELECT * FROM distributions WHERE camp_id = ? ORDER BY distributed_at DESC LIMIT 5");
            $aid_stmt2->bind_param("i", $camp_id_fetch2);
            $aid_stmt2->execute();
            $aid_result2 = $aid_stmt2->get_result();
            while ($row = $aid_result2->fetch_assoc()) { $aid_received_fresh[] = $row; }
        }

        if (count($aid_received_fresh) > 0) {
            foreach ($aid_received_fresh as $aid) {
                echo '<div class="aid-item">';
                echo '<div>';
                echo '<div class="aid-name">' . htmlspecialchars($aid['items']) . '</div>';
                echo '<div class="aid-date">' . date('M d, Y h:i A', strtotime($aid['distributed_at'])) . '</div>';
                echo '</div>';
                echo '<div class="aid-qty">Qty: ' . htmlspecialchars($aid['quantity']) . '</div>';
                echo '</div>';
            }
        } else {
            echo '<p style="color:#64748b;font-size:14px;">You have not received any aid yet.</p>';
        }
        exit;
    }

    if ($page === 'relief_status') {
        $req_stmt2 = $conn->prepare("SELECT * FROM affected_help_requests WHERE affected_id = ? ORDER BY created_at DESC");
        $req_stmt2->bind_param("i", $affected_id);
        $req_stmt2->execute();
        $req_result2 = $req_stmt2->get_result();
        $all_requests_fresh = [];
        while ($row = $req_result2->fetch_assoc()) { $all_requests_fresh[] = $row; }

        $active_requests = array_filter($all_requests_fresh, function($req) {
            $s = strtolower(trim($req['status'] ?? ''));
            return $s !== 'resolved' && $s !== 'completed';
        });

        if (count($active_requests) > 0) {
            foreach ($active_requests as $req) {
                $s = strtolower(trim($req['status'] ?? ''));
                $step2done = in_array($s, ['in_progress', 'approved', 'processing', 'resolved', 'completed']);
                $step3done = in_array($s, ['resolved', 'completed']);
                echo '<div class="panel" style="max-width:600px;margin-bottom:24px;">';
                echo '<h4 style="font-weight:600;margin-bottom:20px;">Rescue Request Status</h4>';
                echo '<div class="timeline">';
                // Step 1 always done
                echo '<div class="timeline-item completed">';
                echo '<div class="timeline-icon"><svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg></div>';
                echo '<div class="timeline-content"><div class="timeline-title">Request Submitted</div><div class="timeline-desc">Your request has been received</div>';
                echo '<div class="timeline-time">' . date('M d, Y h:i A', strtotime($req['created_at'])) . '</div></div></div>';
                // Step 2
                echo '<div class="timeline-item ' . ($step2done ? 'completed' : 'active') . '">';
                if ($step2done) echo '<div class="timeline-icon"><svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg></div>';
                else echo '<div class="timeline-icon active-icon"></div>';
                echo '<div class="timeline-content"><div class="timeline-title">Review &amp; Processing</div><div class="timeline-desc">Camp staff is reviewing your request</div>';
                echo '<div class="timeline-time">' . ($step2done ? 'Completed' : 'In Progress') . '</div></div></div>';
                // Step 3
                echo '<div class="timeline-item ' . ($step3done ? 'completed' : ($step2done ? 'active' : '')) . '">';
                if ($step3done) echo '<div class="timeline-icon"><svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg></div>';
                else echo '<div class="timeline-icon ' . ($step2done ? 'active-icon' : '') . '"></div>';
                echo '<div class="timeline-content"><div class="timeline-title">Action / Relief Dispatch</div><div class="timeline-desc">Help or supplies are being coordinated</div>';
                echo '<div class="timeline-time">' . ($step3done ? 'Completed' : ($step2done ? 'In Progress' : 'Pending')) . '</div></div></div>';
                echo '</div></div>';
            }
        } else {
            echo '<div class="panel"><p style="color:#64748b;font-size:15px;">You have no active relief requests at this moment.</p></div>';
        }
        exit;
    }

    exit;
}
// ────────────────────────────────────────────────────────────────────────────

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Affected Person Dashboard - DisasterRelief</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f8fafc;
            color: #0f172a;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            background: #ffffff;
            border-right: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }

        .sidebar-top {
            padding: 24px;
            border-bottom: 1px solid #e2e8f0;
        }

        .sidebar-top h1 {
            font-size: 20px;
            font-weight: 700;
            color: #0f172a;
        }

        .sidebar-top p {
            font-size: 12px;
            color: #64748b;
            margin-top: 4px;
        }

        .menu {
            list-style: none;
            padding: 24px 16px;
            margin: 0;
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .menu-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 8px;
            color: #64748b;
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            transition: background 0.2s, color 0.2s;
        }

        .menu-link:hover {
            background: #f8fafc;
            color: #1e293b;
        }

        .menu-link.active {
            background: #eff6ff;
            color: #3b82f6;
        }

        .menu-icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        .sidebar-footer {
            padding: 24px 16px;
            border-top: 1px solid #e2e8f0;
            margin-top: auto;
        }

        .demo-label {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 8px;
        }

        .demo-input {
            width: 100%;
            padding: 8px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            border-radius: 6px;
            margin-bottom: 16px;
            font-size: 14px;
            color: #64748b;
        }

        .profile-info {
            margin-bottom: 16px;
        }

        .profile-info-name {
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
        }

        .profile-info-email {
            font-size: 12px;
            color: #64748b;
        }

        .logout-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            padding: 10px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            color: #0f172a;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.2s;
            background: #ffffff;
            cursor: pointer;
        }

        .logout-btn:hover {
            background: #f8fafc;
        }

        /* Main Content */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }

        .content {
            padding: 32px 48px;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
        }

        .page-header {
            margin-bottom: 32px;
        }

        .page-title {
            font-size: 24px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 8px;
        }

        .page-subtitle {
            font-size: 15px;
            color: #64748b;
        }

        /* Dashboard Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-bottom: 24px;
        }

        .stat-card {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #ffffff;
            padding: 24px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        }

        .stat-label {
            font-size: 13px;
            color: #64748b;
            margin-bottom: 8px;
        }

        .stat-val {
            font-size: 18px;
            font-weight: 600;
        }

        .main-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .panel {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #ffffff;
            padding: 24px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        }

        .panel-title {
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 24px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding-bottom: 16px;
            border-bottom: 1px solid #f1f5f9;
            margin-bottom: 16px;
        }

        .detail-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .detail-label {
            color: #64748b;
            font-size: 15px;
        }

        .detail-value {
            color: #0f172a;
            font-size: 15px;
            text-align: right;
        }

        .aid-item {
            background: #f8fafc;
            border-radius: 8px;
            padding: 16px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
            border: 1px solid #e2e8f0;
        }

        .aid-item:last-child {
            margin-bottom: 0;
        }

        .aid-name {
            font-size: 14px;
            color: #0f172a;
            margin-bottom: 4px;
            font-weight: 500;
        }

        .aid-date {
            font-size: 12px;
            color: #64748b;
        }

        .aid-qty {
            font-size: 13px;
            color: #475569;
            font-weight: 500;
        }

        /* Forms */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #334155;
            margin-bottom: 8px;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            border-radius: 8px;
            font-size: 15px;
            color: #0f172a;
            outline: none;
            transition: border-color 0.2s, background 0.2s;
        }

        .form-input:focus {
            border-color: #3b82f6;
            background: #ffffff;
        }

        textarea.form-input {
            min-height: 120px;
            resize: vertical;
        }

        .btn-submit {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            background: #0f172a;
            color: white;
            border: none;
            padding: 14px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-submit:hover {
            background: #1e293b;
        }

        .info-box {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            padding: 24px;
        }

        .info-box h4 {
            color: #1e40af;
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .info-box ul {
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .info-box li {
            position: relative;
            padding-left: 16px;
            color: #1e3a8a;
            font-size: 14px;
            margin-bottom: 12px;
            line-height: 1.5;
        }

        .info-box li::before {
            content: "•";
            position: absolute;
            left: 0;
            color: #3b82f6;
            font-weight: bold;
        }

        .emergency-box {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: 24px;
            margin-top: 24px;
        }

        .emergency-box h4 {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #b91c1c;
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 16px;
        }

        .emergency-num {
            font-size: 24px;
            font-weight: 700;
            color: #b91c1c;
            margin-bottom: 4px;
        }

        .emergency-sub {
            font-size: 13px;
            color: #dc2626;
        }

        /* Request Items */
        .req-item {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            background: #ffffff;
        }

        .req-title {
            font-size: 15px;
            color: #0f172a;
            margin-bottom: 4px;
            font-weight: 600;
        }

        .req-desc {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 8px;
        }

        .req-date {
            font-size: 12px;
            color: #94a3b8;
        }

        .req-badges {
            display: flex;
            gap: 8px;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 500;
            text-transform: capitalize;
        }

        .badge-red {
            background: #fee2e2;
            color: #b91c1c;
        }

        .badge-yellow {
            background: #fef9c3;
            color: #a16207;
        }

        .badge-blue {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .badge-green {
            background: #dcfce3;
            color: #15803d;
        }

        /* Timeline CSS */
        .timeline {
            position: relative;
            margin-left: 12px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 11px;
            top: 12px;
            bottom: 12px;
            width: 2px;
            background: #e2e8f0;
            z-index: 1;
        }

        .timeline-item {
            display: flex;
            gap: 24px;
            position: relative;
            margin-bottom: 32px;
            z-index: 2;
        }

        .timeline-item:last-child {
            margin-bottom: 0;
        }

        .timeline-dot {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            flex-shrink: 0;
            border: 2px solid;
            position: relative;
            z-index: 2;
        }

        .dot-completed {
            border-color: #22c55e;
            color: #22c55e;
            background: #dcfce3;
        }

        .dot-active {
            border-color: #3b82f6;
            color: #3b82f6;
            background: #eff6ff;
        }

        .dot-pending {
            border-color: #e2e8f0;
            color: #94a3b8;
            background: #f8fafc;
        }

        .timeline-content {
            padding-top: 2px;
            flex: 1;
        }

        .timeline-title {
            font-size: 15px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .title-completed {
            color: #0f172a;
        }

        .title-active {
            color: #3b82f6;
        }

        .title-pending {
            color: #64748b;
        }

        .timeline-desc {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 6px;
        }

        .timeline-date {
            font-size: 13px;
            color: #94a3b8;
        }

        /* Chat */
        .chat-container {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: white;
            display: flex;
            flex-direction: column;
            height: 600px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
        }

        .chat-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e2e8f0;
        }

        .chat-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #0f172a;
        }

        .chat-messages {
            flex: 1;
            padding: 24px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 16px;
            background: #f8fafc;
        }

        .msg {
            max-width: 80%;
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            line-height: 1.5;
        }

        .msg-admin {
            align-self: flex-start;
            background: white;
            border: 1px solid #e2e8f0;
            color: #0f172a;
            border-bottom-left-radius: 4px;
        }

        .msg-self {
            align-self: flex-end;
            background: #eff6ff;
            color: #1e3a8a;
            border-bottom-right-radius: 4px;
        }

        .chat-input-area {
            padding: 16px 24px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            gap: 12px;
            background: white;
            border-bottom-left-radius: 12px;
            border-bottom-right-radius: 12px;
        }

        .chat-input-area input {
            flex: 1;
            padding: 12px 16px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            border-radius: 8px;
            outline: none;
            font-size: 14px;
            transition: border-color 0.2s;
        }

        .chat-input-area input:focus {
            border-color: #3b82f6;
            background: #ffffff;
        }

        .chat-input-area button {
            padding: 12px 24px;
            background: #0f172a;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }

        .chat-input-area button:hover {
            background: #1e293b;
        }

        @media (max-width: 768px) {
            .layout {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid #e2e8f0;
            }

            .content {
                padding: 24px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }

            .main-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="layout">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="sidebar-top">
                <h1>Relief System</h1>
                <p>Affected Person</p>
            </div>

            <ul class="menu">
                <li>
                    <a href="?page=dashboard" class="menu-link <?= $page === 'dashboard' ? 'active' : '' ?>">
                        <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z">
                            </path>
                        </svg>
                        Dashboard
                    </a>
                </li>
                <li>
                    <a href="?page=request_help" class="menu-link <?= $page === 'request_help' ? 'active' : '' ?>">
                        <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z">
                            </path>
                        </svg>
                        Request Help
                    </a>
                </li>
                <li>
                    <a href="?page=relief_status" class="menu-link <?= $page === 'relief_status' ? 'active' : '' ?>">
                        <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                        </svg>
                        Relief Status
                    </a>
                </li>

                <?php if ($status === 'assigned' || $status === 'registered' || $status === 'resolved'): ?>
                    <li>
                        <a href="?page=camp_info" class="menu-link <?= $page === 'camp_info' ? 'active' : '' ?>">
                            <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z">
                                </path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                            Camp Information
                        </a>
                    </li>
                    <?php if (!empty($person['chat_power'])): ?>
                    <li>
                        <a href="?page=messages" class="menu-link <?= $page === 'messages' ? 'active' : '' ?>">
                            <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z">
                                </path>
                            </svg>
                            Messages
                        </a>
                    </li>
                    <?php endif; ?>
                <?php endif; ?>
                <li>
                    <a href="#" class="menu-link">
                        <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z">
                            </path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                        Settings
                    </a>
                </li>
            </ul>

            <div class="sidebar-footer">

                <a href="logout.php" class="logout-card" style="display: flex; align-items: center; justify-content: space-between; background: #f8fafc; padding: 12px; border-radius: 12px; text-decoration: none; color: #1e293b; margin: 10px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 36px; height: 36px; background: #4f46e5; color: white; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-weight: 600; text-transform: uppercase;"><?= substr($person['full_name'], 0, 1) ?></div>
                        <span style="font-weight: 600; font-size: 15px;"><?= htmlspecialchars($person['full_name']) ?></span>
                    </div>
                    <svg width="20" height="20" fill="none" stroke="#ef4444" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                    </svg>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="main">
            <div class="content">
                <div class="page-header">
                    <h2 class="page-title">
                        <?php
                        if ($page === 'dashboard')
                            echo 'My Dashboard';
                        elseif ($page === 'request_help')
                            echo 'Request Help';
                        elseif ($page === 'relief_status')
                            echo 'Relief Status';
                        elseif ($page === 'camp_info')
                            echo 'Assigned Camp Details';
                        elseif ($page === 'messages')
                            echo 'Messages';
                        ?>
                    </h2>
                    <p class="page-subtitle">
                        <?php if ($page === 'dashboard')
                            echo "Welcome, " . htmlspecialchars($person['full_name']); ?>
                        <?php if ($page === 'request_help')
                            echo "Submit urgent needs and requests"; ?>
                    </p>
                </div>

                <?php if ($success): ?>
                    <div
                        style="background: #dcfce3; color: #15803d; padding: 16px; border-radius: 8px; margin-bottom: 24px; border: 1px solid #bbf7d0; font-size: 14px;">
                        <?= htmlspecialchars($success) ?></div><?php endif; ?>
                <?php if ($error): ?>
                    <div
                        style="background: #fee2e2; color: #b91c1c; padding: 16px; border-radius: 8px; margin-bottom: 24px; border: 1px solid #fecaca; font-size: 14px;">
                        <?= htmlspecialchars($error) ?></div><?php endif; ?>

                <?php if ($page === 'dashboard'): ?>
                    <?php if (isset($_GET['ajax'])) { ob_end_clean(); } ?>
                    <div id="dashboard-content">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div>
                                <p class="stat-label">Registration Status</p>
                                <?php if ($status === 'pending'): ?>
                                    <div class="stat-val" style="color: #10b981;">Pending</div>
                                <?php else: ?>
                                    <div class="stat-val" style="color: #059669;">Assigned</div>
                                <?php endif; ?>
                            </div>
                            <svg style="color: <?= $status === 'pending' ? '#10b981' : '#059669' ?>;" width="24" height="24"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6">
                                </path>
                            </svg>
                        </div>
                        <div class="stat-card">
                            <div>
                                <p class="stat-label">Family Members</p>
                                <div class="stat-val" style="color: #2563eb;">
                                    <?= htmlspecialchars($person['family_members']) ?></div>
                            </div>
                            <svg style="color: #2563eb;" width="24" height="24" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z">
                                </path>
                            </svg>
                        </div>
                        <div class="stat-card">
                            <div>
                                <p class="stat-label">Assigned Camp</p>
                                <?php if ($status === 'pending'): ?>
                                    <div class="stat-val" style="color: #64748b;">No</div>
                                <?php else: ?>
                                    <div class="stat-val" style="color: #9333ea;">Yes</div>
                                <?php endif; ?>
                            </div>
                            <svg style="color: <?= $status === 'pending' ? '#64748b' : '#9333ea' ?>;" width="24" height="24"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z">
                                </path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            </svg>
                        </div>
                        <div class="stat-card">
                            <div>
                                <p class="stat-label">Aid Received</p>
                                <div class="stat-val" style="color: #f59e0b;"><?= count($aid_received) ?> times</div>
                            </div>
                            <svg style="color: #f59e0b;" width="24" height="24" fill="none" stroke="currentColor"
                                viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z">
                                </path>
                            </svg>
                        </div>
                    </div>

                    <div class="main-grid">
                        <div class="panel">
                            <h3 class="panel-title">My Profile</h3>
                            <div class="detail-row">
                                <span class="detail-label">Name</span>
                                <span class="detail-value"><?= htmlspecialchars($person['full_name']) ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Family Members</span>
                                <span class="detail-value"><?= htmlspecialchars($person['family_members']) ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Registered Date</span>
                                <span class="detail-value"><?= date('Y-m-d', strtotime($person['created_at'])) ?></span>
                            </div>
                            <div class="detail-row">
                                <span class="detail-label">Camp</span>
                                <span class="detail-value">
                                    <?php
                                    if (isset($person['camp_id']) && $person['camp_id']) {
                                        $c = $conn->query("SELECT camp_name FROM camps WHERE id = " . $person['camp_id'])->fetch_assoc();
                                        echo $c ? htmlspecialchars($c['camp_name']) : 'N/A';
                                    } else {
                                        echo 'Pending Assignment';
                                    }
                                    ?>
                                </span>
                            </div>
                            <div class="detail-row"
                                style="border: none; flex-direction: column; align-items: flex-start; gap: 8px;">
                                <span class="detail-label">Needs Assessment</span>
                                <span class="detail-value"
                                    style="text-align: left;"><?= $help_request ? htmlspecialchars($help_request['description']) : 'Pending Review' ?></span>
                            </div>
                        </div>

                        <div class="panel">
                            <h3 class="panel-title">Recent Aid Received</h3>
                            <?php if ($status === 'pending'): ?>
                                <p style="color: #64748b; font-size: 14px;">You have not received any aid yet. Please wait for camp assignment.</p>
                            <?php else: ?>
                                <div id="aid-received-content">
                                <?php if (count($aid_received) > 0): ?>
                                    <?php foreach ($aid_received as $aid): ?>
                                        <div class="aid-item">
                                            <div>
                                                <div class="aid-name"><?= htmlspecialchars($aid['items']) ?></div>
                                                <div class="aid-date"><?= date('M d, Y h:i A', strtotime($aid['distributed_at'])) ?></div>
                                            </div>
                                            <div class="aid-qty">Qty: <?= htmlspecialchars($aid['quantity']) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p style="color: #64748b; font-size: 14px;">You have not received any aid yet.</p>
                                <?php endif; ?>
                                </div>
                                <script>
                                    setInterval(function () {
                                        fetch('affected_dashboard.php?page=dashboard&ajax=1')
                                            .then(r => r.text())
                                            .then(html => {
                                                document.getElementById('aid-received-content').innerHTML = html;
                                            });
                                    }, 5000);
                                </script>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($page === 'request_help'): ?>

                    <div class="main-grid" style="grid-template-columns: 2fr 1fr;">
                        <div class="panel">
                            <h3 class="panel-title">New Help Request</h3>
                            <form method="POST">
                                <div class="form-group">
                                    <label class="form-label">Request Category *</label>
                                    <select name="category" class="form-input" required>
                                        <option value="">Select category</option>
                                        <option value="Food">Food & Water</option>
                                        <option value="Medical">Medical</option>
                                        <option value="Shelter">Shelter</option>
                                        <option value="Rescue">Rescue/Evacuation</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Urgency Level *</label>
                                    <select name="urgency" class="form-input" required>
                                        <option value="Low">Low</option>
                                        <option value="Medium" selected>Medium</option>
                                        <option value="High">High</option>
                                        <option value="Critical">Critical</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Description *</label>
                                    <textarea name="description" class="form-input"
                                        placeholder="Describe your need in detail..." required></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Contact Number (Optional)</label>
                                    <input type="text" name="contact" class="form-input" placeholder="For urgent callback">
                                </div>
                                <button type="submit" class="btn-submit">
                                    <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                                    </svg>
                                    Submit Request
                                </button>
                            </form>
                        </div>

                        <div>
                            <div class="info-box">
                                <h4>Important Information</h4>
                                <ul>
                                    <li>All requests are reviewed by camp managers</li>
                                    <li>Urgent requests are prioritized</li>
                                    <li>You will receive updates on your request status</li>
                                    <li>For life-threatening emergencies, contact camp staff immediately</li>
                                </ul>
                            </div>

                            <div class="emergency-box">
                                <h4>
                                    <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z">
                                        </path>
                                    </svg>
                                    Emergency Contact
                                </h4>
                                <div class="emergency-num">1-800-RELIEF</div>
                                <div class="emergency-sub">24/7 Emergency Hotline</div>
                            </div>
                        </div>
                    </div>

                    <div class="panel" style="margin-top: 24px;">
                        <h3 class="panel-title">My Previous Requests</h3>

                        <?php if (count($all_requests) > 0): ?>
                            <?php foreach ($all_requests as $req): ?>
                                <div class="req-item">
                                    <div>
                                        <div class="req-title"><?= htmlspecialchars($req['category']) ?> Request</div>
                                        <div class="req-desc"><?= htmlspecialchars($req['description'] ?? '') ?></div>
                                        <div class="req-date"><?= date('Y-m-d H:i A', strtotime($req['created_at'])) ?></div>
                                    </div>
                                    <div class="req-badges">
                                        <span class="badge <?php
                                        if ($req['urgency'] == 'Critical' || $req['urgency'] == 'High')
                                            echo 'badge-red';
                                        elseif ($req['urgency'] == 'Medium')
                                            echo 'badge-yellow';
                                        else
                                            echo 'badge-blue';
                                        ?>"><?= htmlspecialchars($req['urgency']) ?></span>

                                        <?php if ($req['status'] === 'pending'): ?>
                                            <span class="badge badge-blue">in progress</span>
                                        <?php else: ?>
                                            <span class="badge badge-green"><?= htmlspecialchars($req['status']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="color: #64748b; font-size: 14px;">No requests found.</p>
                        <?php endif; ?>
                    </div>

                <?php elseif ($page === 'relief_status'): ?>

                    <div id="relief-status-content">
                        <?php
                        $active_requests = array_filter($all_requests, function ($req) {
                            $s = strtolower(trim($req['status'] ?? ''));
                            return $s !== 'resolved' && $s !== 'completed' && $s !== '';
                        });
                        ?>

                        <?php if ($status === 'pending'): ?>
                            <div class="panel" style="max-width: 600px; margin-bottom: 24px;">
                                <h3 class="panel-title" style="margin-bottom: 32px;">Registration & Camp Assignment Status</h3>
                                <div class="timeline">
                                    <div class="timeline-item">
                                        <div class="timeline-dot dot-completed">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M5 13l4 4L19 7"></path>
                                            </svg>
                                        </div>
                                        <div class="timeline-content">
                                            <h4 class="timeline-title title-completed">Registration Submitted</h4>
                                            <p class="timeline-desc">Your details have been received</p>
                                            <p class="timeline-date">
                                                <?= date('M d, Y h:i A', strtotime($person['created_at'])) ?></p>
                                        </div>
                                    </div>
                                    <div class="timeline-item">
                                        <div class="timeline-dot dot-active">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                            </svg>
                                        </div>
                                        <div class="timeline-content">
                                            <h4 class="timeline-title title-active">Review & Assignment</h4>
                                            <p class="timeline-desc">Camp managers are reviewing your application</p>
                                            <p class="timeline-date">In Progress</p>
                                        </div>
                                    </div>
                                    <div class="timeline-item">
                                        <div class="timeline-dot dot-pending">
                                            <div style="width: 8px; height: 8px; background: currentColor; border-radius: 50%;">
                                            </div>
                                        </div>
                                        <div class="timeline-content">
                                            <h4 class="timeline-title title-pending">Camp Assigned</h4>
                                            <p class="timeline-desc">Assigned to a specific relief camp location</p>
                                            <p class="timeline-date">Pending</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php foreach ($active_requests as $req): ?>
                            <div class="panel" style="max-width: 600px; margin-bottom: 24px;">
                                <h3 class="panel-title" style="margin-bottom: 32px;"><?= htmlspecialchars($req['category']) ?>
                                    Request Status</h3>
                                <div class="timeline">
                                    <div class="timeline-item">
                                        <div class="timeline-dot dot-completed">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M5 13l4 4L19 7"></path>
                                            </svg>
                                        </div>
                                        <div class="timeline-content">
                                            <h4 class="timeline-title title-completed">Request Submitted</h4>
                                            <p class="timeline-desc">Your request has been received</p>
                                            <p class="timeline-date"><?= date('M d, Y h:i A', strtotime($req['created_at'])) ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="timeline-item">
                                        <div
                                            class="timeline-dot <?= $req['status'] === 'pending' ? 'dot-active' : 'dot-completed' ?>">
                                            <?php if ($req['status'] === 'pending'): ?>
                                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                            <?php else: ?>
                                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M5 13l4 4L19 7"></path>
                                                </svg>
                                            <?php endif; ?>
                                        </div>
                                        <div class="timeline-content">
                                            <h4
                                                class="timeline-title <?= $req['status'] === 'pending' ? 'title-active' : 'title-completed' ?>">
                                                Review & Processing</h4>
                                            <p class="timeline-desc">Camp staff is reviewing your request</p>
                                            <p class="timeline-date">
                                                <?php $s = strtolower(trim($req['status'] ?? '')); ?>
                                                <?= in_array($s, ['in_progress','approved','processing','resolved','completed']) ? 'Completed' : 'In Progress' ?></p>
                                        </div>
                                    </div>
                                    <?php $step3active = in_array($s, ['in_progress','approved','processing']); $step3done = in_array($s, ['resolved','completed']); ?>
                                    <div class="timeline-item">
                                        <div
                                            class="timeline-dot <?= $step3done ? 'dot-completed' : ($step3active ? 'dot-active' : 'dot-pending') ?>">
                                            <?php if ($s === 'pending'): ?>
                                                <div style="width: 8px; height: 8px; background: currentColor; border-radius: 50%;">
                                                </div>
                                            <?php else: ?>
                                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                            <?php endif; ?>
                                        </div>
                                        <div class="timeline-content">
                                            <h4
                                                class="timeline-title <?= $req['status'] === 'pending' ? 'title-pending' : 'title-active' ?>">
                                                Action / Relief Dispatch</h4>
                                            <p class="timeline-desc">Help or supplies are being coordinated</p>
                                            <p class="timeline-date">
                                                <?= $step3done ? 'Completed' : ($step3active ? 'In Progress' : 'Pending') ?></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if ($status !== 'pending' && empty($active_requests)): ?>
                            <div class="panel" style="max-width: 600px; margin-bottom: 24px;">
                                <p style="color: #64748b; font-size: 15px;">You have no active relief requests at this moment.
                                </p>
                            </div>
                        <?php endif; ?>


                    </div>

                    <script>
                        setInterval(function () {
                            fetch('affected_dashboard.php?page=relief_status&ajax=1')
                                .then(r => r.text())
                                .then(html => {
                                    document.getElementById('relief-status-content').innerHTML = html;
                                });
                        }, 5000);
                    </script>

                <?php elseif ($page === 'camp_info' && in_array('camp_info', $allowed_pages)): ?>

                    <div class="panel" style="max-width: 600px;">
                        <h3 class="panel-title">Assigned Camp Information</h3>
                        <?php if ($person['camp_id']): ?>
                            <?php
                            $camp_stmt = $conn->prepare("SELECT * FROM camps WHERE id = ?");
                            $camp_stmt->bind_param("i", $person['camp_id']);
                            $camp_stmt->execute();
                            $camp = $camp_stmt->get_result()->fetch_assoc();
                            ?>
                            <?php if ($camp): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Camp Name</span>
                                    <span class="detail-value"
                                        style="color: #2563eb; font-weight: 500;"><?= htmlspecialchars($camp['camp_name']) ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Location</span>
                                    <span class="detail-value"><?= htmlspecialchars($camp['location']) ?></span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Capacity Status</span>
                                    <span class="detail-value"><?= $camp['current_occupancy'] ?> / <?= $camp['capacity'] ?></span>
                                </div>
                                <div class="detail-row" style="border: none; margin-bottom: 0; padding-bottom: 0;">
                                    <span class="detail-label">Manager</span>
                                    <span class="detail-value">
                                        <?php
                                        $mgr_stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
                                        $mgr_stmt->bind_param("i", $camp['manager_id']);
                                        $mgr_stmt->execute();
                                        $mgr = $mgr_stmt->get_result()->fetch_assoc();
                                        echo $mgr ? htmlspecialchars($mgr['full_name']) : 'N/A';
                                        ?>
                                    </span>
                                </div>
                            <?php else: ?>
                                <p style="padding: 1rem; color: var(--text-muted);">Camp details not found.</p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p style="padding: 1rem; color: var(--text-muted);">You haven't been assigned to a camp yet.</p>
                        <?php endif; ?>
                    </div>

                <?php elseif ($page === 'messages' && in_array('messages', $allowed_pages)): ?>
                    <!-- Camp Manager Chat -->
                    <div class="chat-container" style="border-top: 3px solid #8b5cf6;">
                        <div class="chat-header" style="background: linear-gradient(135deg, #ede9fe 0%, #f5f3ff 100%);">
                            <h3 style="color:#5b21b6; display:flex; align-items:center; gap:8px;">
                                🤝 Chat with Camp Manager
                                <span style="background:#8b5cf6; color:white; padding:2px 8px; border-radius:999px; font-size:0.7rem; font-weight:600;">Chat Enabled by Admin</span>
                            </h3>
                        </div>
                        <div class="chat-messages" id="chatMessages">
                            <div class="msg msg-admin" style="background: #fdf4ff; border: 1px solid #e9d5ff;">
                                You have been granted direct chat access to your camp manager. Use this channel for urgent camp-related inquiries.
                            </div>

                            <?php
                            $msg_stmt2 = $conn->prepare("SELECT * FROM affected_messages WHERE affected_id = ? ORDER BY created_at ASC");
                            $msg_stmt2->bind_param("i", $affected_id);
                            $msg_stmt2->execute();
                            $messages2 = $msg_stmt2->get_result();
                            while ($m2 = $messages2->fetch_assoc()):
                                $is_from_support = ($m2['is_from_admin'] == 1);
                            ?>
                                <div class="msg <?= $is_from_support ? 'msg-admin' : 'msg-self' ?>" 
                                     style="<?= $is_from_support ? 'background:#fdf4ff; border:1px solid #e9d5ff;' : 'background:#ede9fe; color:#4c1d95;' ?>">
                                    <?php if ($is_from_support): ?>
                                        <div style="font-size:0.7rem; font-weight:700; color:#6d28d9; margin-bottom:3px;">
                                            <?= htmlspecialchars($m2['sender_label'] ?? 'Camp Manager') ?>
                                        </div>
                                    <?php endif; ?>
                                    <?= nl2br(htmlspecialchars($m2['message_text'])) ?>
                                </div>
                            <?php endwhile; ?>
                        </div>

                        <form method="POST" class="chat-input-area">
                            <input type="hidden" name="chat_target" value="manager">
                            <input type="text" name="message" placeholder="Type your message to the camp manager..." required autocomplete="off">
                            <button type="submit" style="background:#8b5cf6;">Send</button>
                        </form>
                    </div>

                    <script>
                        const chatMsgs = document.getElementById('chatMessages');
                        if (chatMsgs) chatMsgs.scrollTop = chatMsgs.scrollHeight;
                    </script>

                <?php endif; ?>
            </div>
        </main>
    </div>
</body>

</html>
<?php $conn->close(); ?>