<?php
session_start();
include 'config.php';

// Auto-migrate database table columns for upgraded donations if not exist
$check_camp_id = $conn->query("SHOW COLUMNS FROM donations LIKE 'camp_id'");
if ($check_camp_id->num_rows === 0) {
    $conn->query("ALTER TABLE donations ADD COLUMN camp_id INT NULL DEFAULT NULL");
    $conn->query("ALTER TABLE donations ADD CONSTRAINT fk_donations_camp_id FOREIGN KEY (camp_id) REFERENCES camps(id) ON DELETE SET NULL");
}
$check_pickup_address = $conn->query("SHOW COLUMNS FROM donations LIKE 'pickup_address'");
if ($check_pickup_address->num_rows === 0) {
    $conn->query("ALTER TABLE donations ADD COLUMN pickup_address TEXT NULL DEFAULT NULL");
}
$check_pickup_phone = $conn->query("SHOW COLUMNS FROM donations LIKE 'pickup_phone'");
if ($check_pickup_phone->num_rows === 0) {
    $conn->query("ALTER TABLE donations ADD COLUMN pickup_phone VARCHAR(20) NULL DEFAULT NULL");
}
$check_delivery_method = $conn->query("SHOW COLUMNS FROM donations LIKE 'supply_delivery_method'");
if ($check_delivery_method->num_rows === 0) {
    $conn->query("ALTER TABLE donations ADD COLUMN supply_delivery_method VARCHAR(50) NULL DEFAULT NULL");
}
$check_message = $conn->query("SHOW COLUMNS FROM donations LIKE 'message'");
if ($check_message->num_rows === 0) {
    $conn->query("ALTER TABLE donations ADD COLUMN message TEXT NULL DEFAULT NULL");
}
$check_item_name = $conn->query("SHOW COLUMNS FROM donations LIKE 'item_name'");
if ($check_item_name->num_rows === 0) {
    $conn->query("ALTER TABLE donations ADD COLUMN item_name VARCHAR(255) NULL DEFAULT NULL");
}

if (!isset($_SESSION['user_id'])) {
    redirect('signin.php');
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

if ($user_role === 'admin') {
    redirect('admin_dashboard.php');
}
if ($user_role !== 'donor') {
    redirect('index.php');
}

$user_query = $conn->query("SELECT * FROM users WHERE id = $user_id");
$user = $user_query->fetch_assoc();

$notifications_query = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0");
$notifications = $notifications_query->fetch_assoc();
$unread_count = $notifications['count'];

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
if (!in_array($page, ['dashboard', 'donate', 'history', 'campaigns', 'chat', 'profile', 'settings'])) {
    $page = 'dashboard';
}

$selected_campaign_id = intval($_GET['campaign_id'] ?? 0);
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'donate') {
    $donation_type = sanitize($_POST['donation_type'] ?? 'money');
    $campaign_id = intval($_POST['campaign_id'] ?? 0);
    $camp_id = intval($_POST['camp_id'] ?? 0);
    $message = sanitize($_POST['message'] ?? '');

    if ($donation_type === 'money') {
        $amount = floatval($_POST['amount'] ?? 0);
        $payment_method = sanitize($_POST['payment_method'] ?? '');
        $txn_id_input = sanitize($_POST['transaction_id_input'] ?? '');

        if ($amount <= 0) {
            $error = 'Please enter a valid donation amount.';
        } elseif (!$payment_method) {
            $error = 'Please select a payment/donation method.';
        } else {
            $transaction_id = $txn_id_input ? $txn_id_input : 'DRM-' . strtoupper(uniqid());
            $status = 'completed';

            $campaign_clause = $campaign_id ? $campaign_id : "NULL";
            $camp_clause = $camp_id ? $camp_id : "NULL";

            $insert = $conn->query("INSERT INTO donations 
                (donor_id, campaign_id, camp_id, amount, donation_type, status, payment_method, transaction_id, message) 
                VALUES 
                ($user_id, $campaign_clause, $camp_clause, $amount, 'money', '$status', '$payment_method', '$transaction_id', '$message')");
            if ($insert) {
                // Update campaign raised amount if selected
                if ($campaign_id) {
                    $conn->query("UPDATE campaigns SET raised_amount = raised_amount + $amount WHERE id = $campaign_id");
                }
                $success = 'Thank you! Your monetary donation of ' . formatCurrency($amount) . ' has been recorded successfully.';
            } else {
                $error = 'Unable to process the donation. Please try again. Error: ' . $conn->error;
            }
        }
    } elseif ($donation_type === 'supplies') {
        $supply_amount = floatval($_POST['supply_amount'] ?? 0);
        $item_name = sanitize($_POST['item_name'] ?? '');
        $supply_delivery_method = sanitize($_POST['supply_delivery_method'] ?? 'camp');
        $pickup_address = sanitize($_POST['pickup_address'] ?? '');
        $pickup_phone = sanitize($_POST['pickup_phone'] ?? '');

        if ($supply_amount <= 0) {
            $error = 'Please enter a valid quantity for the supply item.';
        } elseif (!$item_name) {
            $error = 'Please enter the item name or supply type.';
        } else {
            $transaction_id = 'DRS-' . strtoupper(uniqid());
            $status = 'pending';
            $payment_method = ($supply_delivery_method === 'pickup') ? 'Pick up Service' : 'Camp Dropoff';

            $campaign_clause = $campaign_id ? $campaign_id : "NULL";
            $camp_clause = $camp_id ? $camp_id : "NULL";

            $pickup_address_clause = $pickup_address ? "'$pickup_address'" : "NULL";
            $pickup_phone_clause = $pickup_phone ? "'$pickup_phone'" : "NULL";
            $delivery_method_clause = "'$supply_delivery_method'";

            $insert = $conn->query("INSERT INTO donations 
                (donor_id, campaign_id, camp_id, amount, donation_type, status, payment_method, transaction_id, message, pickup_address, pickup_phone, supply_delivery_method, item_name) 
                VALUES 
                ($user_id, $campaign_clause, $camp_clause, $supply_amount, 'supplies', '$status', '$payment_method', '$transaction_id', '$message', $pickup_address_clause, $pickup_phone_clause, $delivery_method_clause, '$item_name')");
            if ($insert) {
                $success = 'Thank you! Your supply donation request of ' . number_format($supply_amount, 0) . ' ' . htmlspecialchars($item_name) . ' has been recorded. Our team will coordinate details shortly.';
            } else {
                $error = 'Unable to process the donation. Please try again. Error: ' . $conn->error;
            }
        }
    }
}

// Simulated Chat Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'chat') {
    $msg = sanitize($_POST['message'] ?? '');
    if ($msg) {
        $conn->query("INSERT INTO messages (sender_id, receiver_id, message_text) VALUES ($user_id, 1, '$msg')"); // Send to Admin
        $success = 'Message sent to support team.';
    }
}

$donation_stats_query = $conn->query("SELECT COUNT(*) as total_donations, COALESCE(SUM(amount),0) as total_amount, COUNT(DISTINCT campaign_id) as campaigns_supported FROM donations WHERE donor_id = $user_id AND status = 'completed'");
$donation_stats = $donation_stats_query->fetch_assoc();

$totals = $donation_stats ?: ['total_donations' => 0, 'total_amount' => 0, 'campaigns_supported' => 0];
$total_amount = number_format($totals['total_amount'], 2);
$active_campaigns = $totals['campaigns_supported'];
$families_helped = max(10, floor($totals['total_amount'] / 200));
$impact_score = min(100, 85 + floor($totals['total_amount'] / 2000));

$campaigns_query = $conn->query("SELECT * FROM campaigns WHERE status = 'active' ORDER BY urgency = 'urgent' DESC, raised_amount / goal_amount DESC");
$camps_query = $conn->query("SELECT * FROM camps WHERE status = 'active' ORDER BY camp_name ASC");
$my_donations_query = $conn->query("SELECT d.*, c.campaign_name, camp.camp_name FROM donations d LEFT JOIN campaigns c ON d.campaign_id = c.id LEFT JOIN camps camp ON d.camp_id = camp.id WHERE d.donor_id = $user_id ORDER BY d.created_at DESC");

function formatCurrency($amount) {
    return '৳' . number_format((float)$amount, 0, '.', ',');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Donor Dashboard - DisasterRelief</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; background: #f3f4f6; color: #111827; }
        .layout { display: flex; min-height: 100vh; }
        .sidebar { width: 240px; background: #ffffff; border-right: 1px solid #e5e7eb; display: flex; flex-direction: column; }
        .sidebar-top { display: flex; align-items: center; gap: 0.75rem; padding: 1.75rem 1.5rem 1rem; }
        .logo { width: 36px; height: 36px; border-radius: 12px; background: #2563eb; color: white; display: grid; place-items: center; font-weight: 800; }
        .brand { font-weight: 700; font-size: 1rem; color: #111827; }
        .menu { list-style: none; padding: 0 0 1rem; margin: 0; }
        .menu-item { margin: 0; }
        .menu-link { display: flex; align-items: center; gap: 0.85rem; padding: 0.95rem 1.5rem; color: #4b5563; text-decoration: none; border-radius: 12px; transition: background 0.25s, color 0.25s; }
        .menu-link:hover, .menu-link.active { background: #eff6ff; color: #1d4ed8; }
        .menu-icon { font-size: 1rem; }
        .menu-badge { margin-left: auto; background: #f97316; color: white; border-radius: 999px; font-size: 0.75rem; padding: 0.25rem 0.6rem; }
        .sidebar-footer { margin-top: auto; padding: 1.5rem; font-size: 0.9rem; color: #6b7280; }
        .main { flex: 1; display: flex; flex-direction: column; }
        .topbar { background: white; padding: 1.25rem 2rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e5e7eb; position: sticky; top: 0; z-index: 10; }
        .topbar-left { display: flex; flex-direction: column; gap: 0.25rem; }
        .topbar-title { font-size: 1.5rem; font-weight: 700; color: #111827; }
        .topbar-subtitle { color: #6b7280; font-size: 0.95rem; }
        .topbar-actions { display: flex; gap: 0.75rem; align-items: center; }
        .btn-primary, .btn-secondary { 
            border: none; 
            border-radius: 14px; 
            padding: 0.9rem 1.5rem; 
            cursor: pointer; 
            font-weight: 600; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            display: inline-flex; 
            align-items: center; 
            justify-content: center; 
            gap: 0.6rem;
            font-family: inherit;
            font-size: 0.95rem;
            text-decoration: none;
        }
        .btn-primary { 
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); 
            color: white; 
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }
        .btn-primary:hover { 
            transform: translateY(-2px);
            box-shadow: 0 10px 28px rgba(37, 99, 235, 0.35); 
            filter: brightness(1.05);
        }
        .btn-primary:active { transform: translateY(0); }
        .btn-secondary { background: #eff6ff; color: #1d4ed8; }
        .btn-secondary:hover { background: #dbeafe; transform: translateY(-1px); }
        .topbar-right { display: flex; align-items: center; gap: 1rem; }
        .notification { position: relative; font-size: 1.15rem; cursor: pointer; }
        .notification-badge { position: absolute; top: -6px; right: -8px; width: 18px; height: 18px; border-radius: 999px; background: #ef4444; color: white; display: grid; place-items: center; font-size: 0.75rem; }
        .profile-button { display: inline-flex; align-items: center; gap: 0.85rem; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 999px; padding: 0.7rem 1rem; cursor: pointer; }
        .profile-avatar { width: 36px; height: 36px; border-radius: 999px; background: #2563eb; color: white; display: grid; place-items: center; font-weight: 700; }
        .profile-details { display: flex; flex-direction: column; gap: 0.15rem; }
        .profile-name { font-weight: 700; font-size: 0.95rem; color: #111827; }
        .profile-role { font-size: 0.82rem; color: #6b7280; }
        .content { padding: 2rem; overflow-y: auto; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 1.5rem; margin-bottom: 1.75rem; }
        .stat-card { background: white; border-radius: 24px; padding: 1.5rem; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06); display: flex; justify-content: space-between; align-items: center; }
        .stat-text { display: flex; flex-direction: column; gap: 0.65rem; }
        .stat-label { color: #6b7280; font-size: 0.92rem; }
        .stat-value { font-size: 1.8rem; font-weight: 700; color: #111827; }
        .stat-meta { color: #16a34a; font-size: 0.85rem; }
        .stat-icon { width: 44px; height: 44px; border-radius: 16px; background: #eef2ff; display: grid; place-items: center; font-size: 1.2rem; }
        .dashboard-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; }
        .panel { background: white; border-radius: 24px; padding: 1.5rem; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05); }
        .panel-heading { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .panel-heading h3 { font-size: 1.05rem; font-weight: 700; color: #111827; }
        .panel-heading small { color: #6b7280; }
        .table { width: 100%; border-collapse: collapse; background: white; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05); }
        .table thead { background: #f8fafc; }
        .table th, .table td { padding: 1rem 1.1rem; text-align: left; color: #374151; font-size: 0.95rem; }
        .table tbody tr { border-bottom: 1px solid #e5e7eb; }
        .table tbody tr:last-child { border-bottom: none; }
        .status-pill { display: inline-flex; gap: 0.5rem; align-items: center; justify-content: center; padding: 0.45rem 0.85rem; border-radius: 999px; font-size: 0.82rem; font-weight: 700; color: white; }
        .status-completed { background: #16a34a; }
        .status-pending { background: #f59e0b; }
        .status-failed { background: #ef4444; }
        .form-field { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1rem; }
        .form-field label { font-size: 0.9rem; color: #374151; font-weight: 600; }
        .form-field input, .form-field textarea, .form-field select { width: 100%; border: 1px solid #e5e7eb; border-radius: 14px; padding: 0.95rem 1rem; font-size: 0.95rem; background: #f8fafc; transition: all 0.2s; }
        .form-field input:focus, .form-field textarea:focus, .form-field select:focus { outline: none; border-color: #2563eb; background: #ffffff; box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1); }
        .form-field textarea { min-height: 120px; resize: vertical; }
        html { scroll-behavior: smooth; }
        /* Profile Dropdown */
        .profile-dropdown { position: absolute; top: 100%; right: 0; margin-top: 0.75rem; width: 220px; background: white; border-radius: 16px; border: 1px solid #e5e7eb; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); display: none; z-index: 100; overflow: hidden; }
        .profile-dropdown.show { display: block; animation: dropdownSlide 0.2s ease-out; }
        .dropdown-header { padding: 1.25rem; border-bottom: 1px solid #f3f4f6; background: #f9fafb; text-align: left; }
        .dropdown-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.85rem 1.25rem; color: #374151; text-decoration: none; font-size: 0.9rem; font-weight: 500; transition: all 0.2s; }
        .dropdown-item:hover { background: #eff6ff; color: #2563eb; }
        .dropdown-item.logout { color: #ef4444; }
        .dropdown-item.logout:hover { background: #fef2f2; color: #ef4444; }
        @keyframes dropdownSlide { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

        @media (max-width: 1080px) { .stats-grid, .dashboard-grid { grid-template-columns: 1fr; } .sidebar { width: 100%; } .topbar { flex-wrap: wrap; gap: 1rem; } }
        @media (max-width: 760px) { .layout { flex-direction: column; } .sidebar { order: 2; } .topbar, .content { padding: 1.25rem; } }
    </style>
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <div class="sidebar-top">
                <div class="logo">DR</div>
                <div class="brand">Disaster Relief</div>
            </div>
            <ul class="menu">
                <li class="menu-item"><a href="donor_dashboard.php?page=dashboard" class="menu-link <?php echo $page === 'dashboard' ? 'active' : ''; ?>"><span class="menu-icon">📊</span>Dashboard</a></li>
                <li class="menu-item"><a href="donor_dashboard.php?page=donate" class="menu-link <?php echo $page === 'donate' ? 'active' : ''; ?>"><span class="menu-icon">💰</span>Donate Now</a></li>
                <li class="menu-item"><a href="donor_dashboard.php?page=history" class="menu-link <?php echo $page === 'history' ? 'active' : ''; ?>"><span class="menu-icon">📜</span>History</a></li>
                <li class="menu-item"><a href="donor_dashboard.php?page=campaigns" class="menu-link <?php echo $page === 'campaigns' ? 'active' : ''; ?>"><span class="menu-icon">📣</span>Campaigns</a></li>
                <li class="menu-item"><a href="donor_dashboard.php?page=chat" class="menu-link <?php echo $page === 'chat' ? 'active' : ''; ?>"><span class="menu-icon">💬</span>Support Chat</a></li>
                <li class="menu-item"><a href="donor_dashboard.php?page=settings" class="menu-link <?php echo $page === 'settings' ? 'active' : ''; ?>"><span class="menu-icon">⚙️</span>Settings</a></li>
            </ul>
            <div class="sidebar-footer">Donor portal for supporting relief missions worldwide.</div>
        </aside>
        <main class="main">
            <div class="topbar">
                <div class="topbar-left">
                    <div class="topbar-title"><?php echo $page === 'dashboard' ? 'Donor Dashboard' : ucfirst($page); ?></div>
                    <div class="topbar-subtitle">Every contribution makes a real difference</div>
                </div>
                <div class="topbar-actions">
                    <div class="notification">🔔 <?php if ($unread_count > 0): ?>
                        <span class="notification-badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?></div>
                    <div style="position: relative;">
                        <button class="profile-button" onclick="toggleProfileMenu()">
                            <div class="profile-avatar"><?php echo strtoupper(substr(trim($user['full_name']), 0, 1)); ?></div>
                            <div class="profile-details">
                                <span class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></span>
                                <span class="profile-role">Verified Donor</span>
                            </div>
                        </button>
                        <div id="profileDropdown" class="profile-dropdown">
                            <div class="dropdown-header">
                                <p style="font-weight: 700; font-size: 0.9rem;"><?php echo htmlspecialchars($user['full_name']); ?></p>
                                <p style="font-size: 0.75rem; color: #6b7280;"><?php echo htmlspecialchars($user['email']); ?></p>
                            </div>
                            <a href="donor_dashboard.php?page=profile" class="dropdown-item">👤 My Profile</a>
                            <a href="donor_dashboard.php?page=settings" class="dropdown-item">⚙️ Settings</a>
                            <div style="border-top: 1px solid #f3f4f6;"></div>
                            <a href="logout.php" class="dropdown-item logout">🚪 Log Out</a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="content">
                <?php if ($success): ?><div style="background: #ecfdf5; color: #065f46; padding: 1rem; border-radius: 14px; margin-bottom: 1.5rem; border: 1px solid #a7f3d0;"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
                <?php if ($error): ?><div style="background: #fef2f2; color: #991b1b; padding: 1rem; border-radius: 14px; margin-bottom: 1.5rem; border: 1px solid #fecaca;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

                <?php if ($page === 'dashboard'): ?>
                    <div class="stats-grid">
                        <div class="stat-card" style="background: #eff6ff;">
                            <div class="stat-text">
                                <span class="stat-label">Total Donated</span>
                                <span class="stat-value"><?php echo formatCurrency($totals['total_amount']); ?></span>
                                <span class="stat-meta">Generous contributions</span>
                            </div>
                            <div class="stat-icon">💰</div>
                        </div>
                        <div class="stat-card" style="background: #ecfdf5;">
                            <div class="stat-text">
                                <span class="stat-label">Families Helped</span>
                                <span class="stat-value"><?php echo $families_helped; ?></span>
                                <span class="stat-meta">Impact created</span>
                            </div>
                            <div class="stat-icon">🏠</div>
                        </div>
                        <div class="stat-card" style="background: #f5f3ff;">
                            <div class="stat-text">
                                <span class="stat-label">Campaigns</span>
                                <span class="stat-value"><?php echo $active_campaigns; ?></span>
                                <span class="stat-meta">Supported missions</span>
                            </div>
                            <div class="stat-icon">🎯</div>
                        </div>
                        <div class="stat-card" style="background: #eff6ff;">
                            <div class="stat-text">
                                <span class="stat-label">Impact Score</span>
                                <span class="stat-value"><?php echo $impact_score; ?>%</span>
                                <span class="stat-meta">Community rating</span>
                            </div>
                            <div class="stat-icon">⭐</div>
                        </div>
                    </div>

                    <div class="panel" style="margin-bottom: 1.5rem;">
                        <div class="panel-heading">
                            <div>
                                <h3>Active Campaigns</h3>
                                <small>Urgent missions that need your support</small>
                            </div>
                            <a href="donor_dashboard.php?page=campaigns" class="btn-secondary">View All</a>
                        </div>
                        <div class="dashboard-grid">
                            <?php $campaigns_limit = $conn->query("SELECT * FROM campaigns WHERE status = 'active' LIMIT 2"); while ($campaign = $campaigns_limit->fetch_assoc()): $progress = $campaign['goal_amount'] > 0 ? round(($campaign['raised_amount'] / $campaign['goal_amount']) * 100) : 0; ?>
                                <div style="background: #f8fafc; padding: 1.5rem; border-radius: 20px; border: 1px solid #e5e7eb;">
                                    <h4 style="margin-bottom: 0.5rem;"><?php echo htmlspecialchars($campaign['campaign_name']); ?></h4>
                                    <p style="color: #6b7280; font-size: 0.9rem; margin-bottom: 1rem;"><?php echo htmlspecialchars($campaign['location']); ?></p>
                                    <div style="height: 8px; background: #e5e7eb; border-radius: 999px; margin-bottom: 0.5rem;"><div style="height: 100%; background: #2563eb; border-radius: 999px; width: <?php echo min(100, $progress); ?>%;"></div></div>
                                    <div style="display:flex; justify-content:space-between; font-size: 0.85rem; color: #4b5563; margin-bottom: 1.25rem;"><span><?php echo $progress; ?>% Funded</span><span>Goal: <?php echo formatCurrency($campaign['goal_amount']); ?></span></div>
                                    <a href="donor_dashboard.php?page=donate&campaign_id=<?php echo $campaign['id']; ?>" class="btn-primary" style="display: flex; width: 100%;">Donate Now</a>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-heading">
                            <h3>Recent Donations</h3>
                        </div>
                        <table class="table">
                            <thead><tr><th>Date</th><th>Amount</th><th>Campaign</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php $recent = $conn->query("SELECT d.*, c.campaign_name FROM donations d LEFT JOIN campaigns c ON d.campaign_id = c.id WHERE d.donor_id = $user_id ORDER BY d.created_at DESC LIMIT 5"); if ($recent->num_rows > 0): while ($donation = $recent->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($donation['created_at'])); ?></td>
                                        <td><strong><?php echo $donation['donation_type'] === 'money' ? formatCurrency($donation['amount']) : (number_format($donation['amount'], 0) . ' ' . htmlspecialchars($donation['item_name'] ?: 'Items')); ?></strong></td>
                                        <td><?php echo htmlspecialchars($donation['campaign_name'] ?: 'General Fund'); ?></td>
                                        <td><span class="status-pill status-<?php echo $donation['status']; ?>"><?php echo ucfirst($donation['status']); ?></span></td>
                                    </tr>
                                <?php endwhile; else: ?>
                                    <tr><td colspan="4" style="text-align:center; color:#6b7280;">No donations recorded yet.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($page === 'donate'): ?>
                    <!-- Load Font Awesome CDN for upgraded premium icons -->
                    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
                    
                    <style>
                        /* Custom premium styles for upgraded donation UI */
                        .donation-grid-layout {
                            display: grid;
                            grid-template-columns: 1.7fr 1fr;
                            gap: 2rem;
                            align-items: start;
                            margin-top: 1rem;
                        }
                        .donation-form-panel {
                            background: #ffffff;
                            border-radius: 24px;
                            padding: 2.25rem;
                            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.04);
                            border: 1px solid #e5e7eb;
                        }
                        .donation-sidebar-layout {
                            display: flex;
                            flex-direction: column;
                            gap: 1.5rem;
                        }
                        .donation-sidebar-card {
                            background: #ffffff;
                            border-radius: 20px;
                            padding: 1.75rem;
                            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.03);
                            border: 1px solid #e5e7eb;
                        }
                        .why-donate-title {
                            font-size: 1.1rem;
                            font-weight: 700;
                            margin-bottom: 1.25rem;
                            color: #1f2937;
                            display: flex;
                            align-items: center;
                            gap: 0.5rem;
                        }
                        .why-donate-checklist {
                            list-style: none;
                            display: flex;
                            flex-direction: column;
                            gap: 0.9rem;
                        }
                        .why-donate-item {
                            font-size: 0.9rem;
                            color: #4b5563;
                            display: flex;
                            align-items: center;
                            gap: 0.75rem;
                            font-weight: 500;
                        }
                        .why-donate-item i {
                            color: #10b981;
                            font-size: 1rem;
                        }
                        .urgent-need-title {
                            font-size: 1.1rem;
                            font-weight: 700;
                            margin-bottom: 1.25rem;
                            color: #1f2937;
                            display: flex;
                            align-items: center;
                            gap: 0.5rem;
                        }
                        .urgent-need-list {
                            display: flex;
                            flex-direction: column;
                            gap: 0.85rem;
                        }
                        .urgent-need-item {
                            display: flex;
                            flex-direction: column;
                            padding: 1rem 1.25rem;
                            border-radius: 14px;
                            border: 1px solid transparent;
                        }
                        .urgent-need-item.critical {
                            background: #fef2f2;
                            border-color: #fee2e2;
                            color: #991b1b;
                        }
                        .urgent-need-item.high {
                            background: #fff7ed;
                            border-color: #ffedd5;
                            color: #c2410c;
                        }
                        .urgent-need-item.medium {
                            background: #fefce8;
                            border-color: #fef9c3;
                            color: #854d0e;
                        }
                        .urgent-need-name {
                            font-weight: 700;
                            font-size: 0.95rem;
                        }
                        .urgent-need-status {
                            font-size: 0.8rem;
                            opacity: 0.9;
                            margin-top: 0.15rem;
                            font-weight: 500;
                        }

                        /* Tabs */
                        .type-selector-tabs {
                            display: grid;
                            grid-template-columns: 1fr 1fr;
                            gap: 1.25rem;
                            margin-bottom: 2rem;
                        }
                        .tab-card {
                            border: 1px solid #e5e7eb;
                            border-radius: 16px;
                            padding: 1.5rem 1rem;
                            text-align: center;
                            cursor: pointer;
                            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
                            background: #ffffff;
                            display: flex;
                            flex-direction: column;
                            align-items: center;
                            justify-content: center;
                            gap: 0.5rem;
                        }
                        .tab-card i {
                            font-size: 1.8rem;
                            transition: color 0.25s;
                        }
                        .tab-card h4 {
                            font-size: 0.95rem;
                            font-weight: 700;
                            color: #111827;
                        }
                        .tab-card p {
                            font-size: 0.78rem;
                            color: #6b7280;
                        }
                        .tab-card.active {
                            border-color: #2563eb;
                            background: #eff6ff;
                            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.05);
                        }
                        .tab-card.active .fa-dollar-sign {
                            color: #10b981;
                        }
                        .tab-card.active .fa-box-open {
                            color: #2563eb;
                        }
                        .tab-card:not(.active) i {
                            color: #9ca3af;
                        }

                        /* Form fields */
                        .form-section-title {
                            font-size: 1rem;
                            font-weight: 700;
                            color: #111827;
                            margin-bottom: 1.25rem;
                            margin-top: 2rem;
                            border-bottom: 1px solid #f3f4f6;
                            padding-bottom: 0.5rem;
                        }
                        .form-field-group {
                            margin-bottom: 1.5rem;
                        }
                        .form-field-group label {
                            display: block;
                            font-size: 0.88rem;
                            font-weight: 600;
                            color: #374151;
                            margin-bottom: 0.6rem;
                        }
                        .form-input-custom {
                            width: 100%;
                            border: 1px solid #e5e7eb;
                            border-radius: 12px;
                            padding: 0.9rem 1.1rem;
                            font-size: 0.95rem;
                            background: #f9fafb;
                            outline: none;
                            transition: all 0.2s;
                            font-family: inherit;
                        }
                        .form-input-custom:focus {
                            border-color: #2563eb;
                            background: #ffffff;
                            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
                        }

                        /* Quick amount row */
                        .quick-amount-row {
                            display: flex;
                            gap: 0.5rem;
                            margin-top: 0.75rem;
                            flex-wrap: wrap;
                        }
                        .btn-quick-amount {
                            background: #ffffff;
                            border: 1px solid #e5e7eb;
                            border-radius: 8px;
                            padding: 0.55rem 1.1rem;
                            font-size: 0.85rem;
                            font-weight: 600;
                            cursor: pointer;
                            transition: all 0.2s;
                            color: #374151;
                        }
                        .btn-quick-amount:hover {
                            border-color: #111827;
                            background: #f9fafb;
                            transform: translateY(-1px);
                        }
                        .btn-quick-amount:active {
                            transform: translateY(0);
                        }

                        /* Payment Method Selection */
                        .payment-picker-grid {
                            display: grid;
                            grid-template-columns: repeat(3, 1fr);
                            gap: 1rem;
                            margin-bottom: 1.5rem;
                        }
                        @media (max-width: 600px) {
                            .payment-picker-grid {
                                grid-template-columns: 1fr 1fr;
                            }
                        }
                        .payment-method-card {
                            border: 1px solid #e5e7eb;
                            border-radius: 14px;
                            padding: 1.1rem 0.5rem;
                            text-align: center;
                            cursor: pointer;
                            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
                            background: #ffffff;
                            display: flex;
                            flex-direction: column;
                            align-items: center;
                            justify-content: center;
                            gap: 0.5rem;
                            font-weight: 700;
                            font-size: 0.85rem;
                            color: #4b5563;
                        }
                        .payment-method-card i {
                            font-size: 1.35rem;
                        }
                        .payment-method-card:hover {
                            transform: translateY(-2px);
                            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
                        }
                        .payment-method-card.selected {
                            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
                            font-weight: 800;
                        }
                        /* bkash theme color */
                        .payment-method-card.bkash.selected { border-color: #e2136e; background: #e2136e10; color: #e2136e; }
                        /* nagad theme color */
                        .payment-method-card.nagad.selected { border-color: #f15a22; background: #f15a2210; color: #f15a22; }
                        /* rocket theme color */
                        .payment-method-card.rocket.selected { border-color: #8c3494; background: #8c349410; color: #8c3494; }
                        /* dbbl theme color */
                        .payment-method-card.dbbl.selected { border-color: #0072bc; background: #0072bc10; color: #0072bc; }
                        /* brac theme color */
                        .payment-method-card.brac.selected { border-color: #005691; background: #00569110; color: #005691; }
                        /* general bank theme color */
                        .payment-method-card.bank.selected { border-color: #2563eb; background: #2563eb10; color: #2563eb; }

                        /* Payment Details Area */
                        .payment-details-wrapper {
                            background: #f8fafc;
                            border: 1px dashed #cbd5e1;
                            border-radius: 16px;
                            padding: 1.5rem;
                            margin-bottom: 1.5rem;
                            display: none;
                            animation: slideDown 0.3s ease-out;
                        }
                        @keyframes slideDown {
                            from { opacity: 0; transform: translateY(-10px); }
                            to { opacity: 1; transform: translateY(0); }
                        }

                        /* Supply Method Area */
                        .supply-methods-grid {
                            display: grid;
                            grid-template-columns: 1fr 1fr;
                            gap: 1.25rem;
                            margin-bottom: 1.5rem;
                        }
                        .supply-method-card {
                            border: 1px solid #e5e7eb;
                            border-radius: 14px;
                            padding: 1.5rem 1rem;
                            text-align: center;
                            cursor: pointer;
                            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
                            background: #ffffff;
                            display: flex;
                            flex-direction: column;
                            align-items: center;
                            justify-content: center;
                            gap: 0.5rem;
                            font-weight: 700;
                            font-size: 0.95rem;
                            color: #4b5563;
                        }
                        .supply-method-card i {
                            font-size: 1.6rem;
                            color: #9ca3af;
                            transition: color 0.2s;
                        }
                        .supply-method-card:hover {
                            transform: translateY(-2px);
                            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
                        }
                        .supply-method-card.selected {
                            border-color: #2563eb;
                            background: #eff6ff;
                            color: #1d4ed8;
                        }
                        .supply-method-card.selected i {
                            color: #2563eb;
                        }

                        .pickup-details-wrapper {
                            background: #f8fafc;
                            border: 1px dashed #cbd5e1;
                            border-radius: 16px;
                            padding: 1.5rem;
                            margin-bottom: 1.5rem;
                            display: none;
                            animation: slideDown 0.3s ease-out;
                        }

                        /* Submit Button */
                        .btn-submit-donation {
                            width: 100%;
                            background: #0f172a;
                            color: #ffffff;
                            border: none;
                            border-radius: 14px;
                            padding: 1.1rem;
                            font-size: 1.05rem;
                            font-weight: 700;
                            cursor: pointer;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            gap: 0.6rem;
                            box-shadow: 0 4px 12px rgba(15,23,42,0.15);
                            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                        }
                        .btn-submit-donation:hover {
                            background: #1e293b;
                            transform: translateY(-2px);
                            box-shadow: 0 8px 20px rgba(15,23,42,0.25);
                        }
                        .btn-submit-donation:active {
                            transform: translateY(0);
                        }
                        .btn-submit-donation i {
                            font-size: 1.1rem;
                            transition: transform 0.3s;
                        }
                        .btn-submit-donation:hover i {
                            transform: scale(1.2);
                        }

                        @media (max-width: 900px) {
                            .donation-grid-layout {
                                grid-template-columns: 1fr;
                            }
                        }
                    </style>

                    <div class="donation-grid-layout">
                        <!-- Left Column: Form Details -->
                        <div class="donation-form-panel">
                            <h3 style="font-size: 1.35rem; font-weight: 700; margin-bottom: 0.5rem; color: #111827;">Donation Details</h3>
                            <p style="color: #6b7280; font-size: 0.9rem; margin-bottom: 2rem;">Please fill in the form below to support our relief camps.</p>
                            
                            <form method="POST" id="donationUpgradeForm" onsubmit="return validateDonationForm()">
                                <!-- Hidden input for Donation Type -->
                                <input type="hidden" name="donation_type" id="donation_type_input" value="money">
                                
                                <!-- Donation Type Tabs -->
                                <div class="form-field-group">
                                    <label>Donation Type</label>
                                    <div class="type-selector-tabs">
                                        <div class="tab-card active" id="tab_money" onclick="switchDonationType('money')">
                                            <i class="fa-solid fa-dollar-sign"></i>
                                            <h4>Monetary Donation</h4>
                                            <p>Most flexible way to help</p>
                                        </div>
                                        <div class="tab-card" id="tab_supplies" onclick="switchDonationType('supplies')">
                                            <i class="fa-solid fa-box-open"></i>
                                            <h4>Supply Donation</h4>
                                            <p>Donate goods directly</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- MONETARY DONATION CONTAINER -->
                                <div id="monetary_donation_container">
                                    <div class="form-field-group">
                                        <label for="money_amount">Amount ($) *</label>
                                        <input type="number" name="amount" id="money_amount" class="form-input-custom" placeholder="Enter amount" step="0.01" min="1">
                                        <div class="quick-amount-row">
                                            <button type="button" class="btn-quick-amount" onclick="setQuickAmount(50)">$50</button>
                                            <button type="button" class="btn-quick-amount" onclick="setQuickAmount(100)">$100</button>
                                            <button type="button" class="btn-quick-amount" onclick="setQuickAmount(250)">$250</button>
                                            <button type="button" class="btn-quick-amount" onclick="setQuickAmount(500)">$500</button>
                                            <button type="button" class="btn-quick-amount" onclick="setQuickAmount(1000)">$1000</button>
                                        </div>
                                    </div>

                                    <div class="form-section-title">Pick Donation Method</div>
                                    <input type="hidden" name="payment_method" id="payment_method_input" value="">
                                    <div class="payment-picker-grid">
                                        <div class="payment-method-card bkash" onclick="selectPaymentMethod('bKash', this)">
                                            <i class="fa-solid fa-mobile-screen-button"></i>
                                            <span>bKash</span>
                                        </div>
                                        <div class="payment-method-card nagad" onclick="selectPaymentMethod('Nagad', this)">
                                            <i class="fa-solid fa-mobile-screen-button"></i>
                                            <span>Nagad</span>
                                        </div>
                                        <div class="payment-method-card rocket" onclick="selectPaymentMethod('Rocket', this)">
                                            <i class="fa-solid fa-mobile-screen-button"></i>
                                            <span>Rocket</span>
                                        </div>
                                        <div class="payment-method-card dbbl" onclick="selectPaymentMethod('Dutch-Bangla Bank', this)">
                                            <i class="fa-solid fa-building-columns"></i>
                                            <span>Dutch Bangla</span>
                                        </div>
                                        <div class="payment-method-card brac" onclick="selectPaymentMethod('BRAC Bank', this)">
                                            <i class="fa-solid fa-building-columns"></i>
                                            <span>BRAC Bank</span>
                                        </div>
                                        <div class="payment-method-card bank" onclick="selectPaymentMethod('Other Bank', this)">
                                            <i class="fa-solid fa-credit-card"></i>
                                            <span>Other Bank</span>
                                        </div>
                                    </div>

                                    <!-- Conditional Payment Details Area -->
                                    <div class="payment-details-wrapper" id="payment_details_container">
                                        <!-- Will be filled dynamically by Javascript -->
                                    </div>
                                </div>

                                <!-- SUPPLY DONATION CONTAINER -->
                                <div id="supply_donation_container" style="display: none;">
                                    <div style="display: grid; grid-template-columns: 1fr 1.2fr; gap: 1.25rem; margin-bottom: 1.5rem;">
                                        <div class="form-field-group" style="margin-bottom: 0;">
                                            <label for="supply_amount">Quantity / Amount *</label>
                                            <input type="number" name="supply_amount" id="supply_amount" class="form-input-custom" placeholder="e.g. 200" min="1">
                                        </div>
                                        <div class="form-field-group" style="margin-bottom: 0;">
                                            <label for="item_name">Item Name / Supply *</label>
                                            <input type="text" name="item_name" id="item_name" class="form-input-custom" placeholder="e.g. blanket">
                                        </div>
                                    </div>

                                    <div class="form-section-title">Supply Delivery Method</div>
                                    <input type="hidden" name="supply_delivery_method" id="supply_delivery_method_input" value="camp">
                                    <div class="supply-methods-grid">
                                        <div class="supply-method-card selected" id="method_camp" onclick="selectSupplyMethod('camp')">
                                            <i class="fa-solid fa-house-medical-flag"></i>
                                            <span>1. Donate to Camp</span>
                                        </div>
                                        <div class="supply-method-card" id="method_pickup" onclick="selectSupplyMethod('pickup')">
                                            <i class="fa-solid fa-truck-ramp-box"></i>
                                            <span>2. Pick up Area</span>
                                        </div>
                                    </div>

                                    <!-- Conditional Pickup Area details -->
                                    <div class="pickup-details-wrapper" id="pickup_details_container">
                                        <div style="font-weight: 700; font-size: 0.9rem; color: #1e293b; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
                                            <i class="fa-solid fa-truck-pickup" style="color: #2563eb;"></i> Coordinate Pick-up Details
                                        </div>
                                        <div class="form-field-group">
                                            <label for="pickup_address">Pickup Address / Area Details *</label>
                                            <input type="text" name="pickup_address" id="pickup_address" class="form-input-custom" placeholder="Enter detailed pickup address or coordinate area">
                                        </div>
                                        <div class="form-field-group">
                                            <label for="pickup_phone">Contact Phone Number *</label>
                                            <input type="text" name="pickup_phone" id="pickup_phone" class="form-input-custom" placeholder="Enter contact number for coordination">
                                        </div>
                                    </div>
                                </div>

                                <!-- COMMON SHARED SECTION (Campaign & Support message) -->
                                <div class="form-section-title">Allocation & Message</div>
                                
                                <div class="form-field-group">
                                    <label for="campaign_id">Campaign (Optional)</label>
                                    <select name="campaign_id" id="campaign_id" class="form-input-custom">
                                        <option value="">-- Support General Relief Fund --</option>
                                        <?php 
                                        $missions = $conn->query("SELECT id, campaign_name FROM campaigns WHERE status = 'active'");
                                        while ($m = $missions->fetch_assoc()) {
                                            echo '<option value="' . htmlspecialchars($m['id']) . '"' . ($selected_campaign_id === intval($m['id']) ? ' selected' : '') . '>' . htmlspecialchars($m['campaign_name']) . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div class="form-field-group">
                                    <label for="camp_id">Allocate to Specific Camp (Optional)</label>
                                    <select name="camp_id" id="camp_id" class="form-input-custom">
                                        <option value="">Select camp or leave for admin allocation</option>
                                        <?php 
                                        if ($camps_query && $camps_query->num_rows > 0) {
                                            $camps_query->data_seek(0);
                                            while ($camp = $camps_query->fetch_assoc()) {
                                                echo '<option value="' . htmlspecialchars($camp['id']) . '">' . htmlspecialchars($camp['camp_name']) . ' (' . htmlspecialchars($camp['location']) . ')</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div class="form-field-group">
                                    <label for="message">Message (Optional)</label>
                                    <textarea name="message" id="message" class="form-input-custom" style="min-height: 100px; resize: vertical;" placeholder="Add a message of support..."></textarea>
                                </div>

                                <button type="submit" class="btn-submit-donation">
                                    <i class="fa-regular fa-heart"></i> Complete Donation
                                </button>
                            </form>
                        </div>

                        <!-- Right Column: Info & Impact -->
                        <div class="donation-sidebar-layout">
                            <!-- Why Donate checklist card -->
                            <div class="donation-sidebar-card">
                                <h3 class="why-donate-title"><i class="fa-regular fa-lightbulb" style="color: #2563eb;"></i> Why Donate?</h3>
                                <ul class="why-donate-checklist">
                                    <li class="why-donate-item"><i class="fa-solid fa-check"></i> Directly help affected families</li>
                                    <li class="why-donate-item"><i class="fa-solid fa-check"></i> 100% fund utilization</li>
                                    <li class="why-donate-item"><i class="fa-solid fa-check"></i> Track your donation impact</li>
                                    <li class="why-donate-item"><i class="fa-solid fa-check"></i> Tax-deductible receipts</li>
                                </ul>
                            </div>

                            <!-- Urgent Needs Color-Coded Card -->
                            <div class="donation-sidebar-card" style="border-color: #fee2e2;">
                                <h3 class="urgent-need-title" style="color: #991b1b;"><i class="fa-solid fa-triangle-exclamation"></i> Urgent Needs</h3>
                                <div class="urgent-need-list">
                                    <div class="urgent-need-item critical">
                                        <span class="urgent-need-name">Medical Supplies</span>
                                        <span class="urgent-need-status">Critical shortage</span>
                                    </div>
                                    <div class="urgent-need-item high">
                                        <span class="urgent-need-name">Food Packets</span>
                                        <span class="urgent-need-status">High demand</span>
                                    </div>
                                    <div class="urgent-need-item medium">
                                        <span class="urgent-need-name">Blankets</span>
                                        <span class="urgent-need-status">Needed for new arrivals</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Client-Side Form Dynamics and Validation Scripts -->
                    <script>
                        function switchDonationType(type) {
                            // Update hidden input
                            document.getElementById('donation_type_input').value = type;
                            
                            // Toggle tab card CSS
                            if (type === 'money') {
                                document.getElementById('tab_money').classList.add('active');
                                document.getElementById('tab_supplies').classList.remove('active');
                                
                                document.getElementById('monetary_donation_container').style.display = 'block';
                                document.getElementById('supply_donation_container').style.display = 'none';
                            } else {
                                document.getElementById('tab_supplies').classList.add('active');
                                document.getElementById('tab_money').classList.remove('active');
                                
                                document.getElementById('supply_donation_container').style.display = 'block';
                                document.getElementById('monetary_donation_container').style.display = 'none';
                            }
                        }

                        function setQuickAmount(val) {
                            document.getElementById('money_amount').value = val;
                        }

                        function selectPaymentMethod(method, cardElement) {
                            // Set hidden input
                            document.getElementById('payment_method_input').value = method;
                            
                            // Remove selected class from all method cards
                            document.querySelectorAll('.payment-method-card').forEach(c => {
                                c.classList.remove('selected');
                            });
                            
                            // Add selected class to current card
                            cardElement.classList.add('selected');
                            
                            // Load and show details container
                            const detailsContainer = document.getElementById('payment_details_container');
                            detailsContainer.style.display = 'block';
                            
                            // Load tailored sub-form content
                            let html = '';
                            if (method === 'bKash' || method === 'Nagad' || method === 'Rocket') {
                                const walletName = method;
                                const brandColor = method === 'bKash' ? '#e2136e' : (method === 'Nagad' ? '#f15a22' : '#8c3494');
                                html = `
                                    <div style="font-weight: 700; font-size: 0.9rem; color: ${brandColor}; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                                        <i class="fa-solid fa-mobile-retro"></i> Mobile Banking (${walletName}) details
                                    </div>
                                    <div style="font-size: 0.8rem; color: #475569; background: #e2e8f080; padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem; border-left: 3px solid ${brandColor};">
                                        <strong>Instruction:</strong> Please send money to our personal wallet number <strong>01700-000000</strong> first, then enter your mobile number and the Transaction ID below.
                                    </div>
                                    <div class="form-field-group" style="margin-bottom: 0.75rem;">
                                        <label style="font-size: 0.8rem;">Your ${walletName} Wallet Number *</label>
                                        <input type="text" class="form-input-custom" placeholder="e.g. 01712-345678" id="wallet_number_field" required>
                                    </div>
                                    <div class="form-field-group" style="margin-bottom: 0;">
                                        <label style="font-size: 0.8rem;">Transaction ID (TrxID) *</label>
                                        <input type="text" name="transaction_id_input" class="form-input-custom" placeholder="e.g. A9B8C7D6E5" id="trx_id_field" required>
                                    </div>
                                `;
                            } else if (method === 'Dutch-Bangla Bank' || method === 'BRAC Bank') {
                                html = `
                                    <div style="font-weight: 700; font-size: 0.9rem; color: #0f172a; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                                        <i class="fa-solid fa-building-columns"></i> ${method} Net Banking
                                    </div>
                                    <div class="form-field-group" style="margin-bottom: 0.75rem;">
                                        <label style="font-size: 0.8rem;">Account Holder Name *</label>
                                        <input type="text" class="form-input-custom" placeholder="e.g. Hasan Mahmud" id="bank_acc_name" required>
                                    </div>
                                    <div class="form-field-group" style="margin-bottom: 0.75rem;">
                                        <label style="font-size: 0.8rem;">Account Number *</label>
                                        <input type="text" class="form-input-custom" placeholder="e.g. 102.120.14502" id="bank_acc_num" required>
                                    </div>
                                    <div class="form-field-group" style="margin-bottom: 0;">
                                        <label style="font-size: 0.8rem;">Branch Name *</label>
                                        <input type="text" class="form-input-custom" placeholder="e.g. Dhaka Main Branch" id="bank_branch" required>
                                    </div>
                                `;
                            } else {
                                // Other bank
                                html = `
                                    <div style="font-weight: 700; font-size: 0.9rem; color: #0f172a; margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem;">
                                        <i class="fa-solid fa-credit-card"></i> Card & Other Banking Details
                                    </div>
                                    <div class="form-field-group" style="margin-bottom: 0.75rem;">
                                        <label style="font-size: 0.8rem;">Card / Account Number *</label>
                                        <input type="text" class="form-input-custom" placeholder="e.g. 4321 0987 6543 2109" id="card_num" required>
                                    </div>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                                        <div class="form-field-group" style="margin-bottom: 0;">
                                            <label style="font-size: 0.8rem;">Expiry Date</label>
                                            <input type="text" class="form-input-custom" placeholder="MM/YY" id="card_exp">
                                        </div>
                                        <div class="form-field-group" style="margin-bottom: 0;">
                                            <label style="font-size: 0.8rem;">CVV / Pin</label>
                                            <input type="password" class="form-input-custom" placeholder="***" id="card_cvv">
                                        </div>
                                    </div>
                                `;
                            }
                            detailsContainer.innerHTML = html;
                        }

                        function selectSupplyMethod(method) {
                            document.getElementById('supply_delivery_method_input').value = method;
                            
                            if (method === 'camp') {
                                document.getElementById('method_camp').classList.add('selected');
                                document.getElementById('method_pickup').classList.remove('selected');
                                document.getElementById('pickup_details_container').style.display = 'none';
                                
                                // Disable validation requirement for pickup
                                document.getElementById('pickup_address').required = false;
                                document.getElementById('pickup_phone').required = false;
                            } else {
                                document.getElementById('method_pickup').classList.add('selected');
                                document.getElementById('method_camp').classList.remove('selected');
                                document.getElementById('pickup_details_container').style.display = 'block';
                                
                                // Enable validation requirement for pickup
                                document.getElementById('pickup_address').required = true;
                                document.getElementById('pickup_phone').required = true;
                            }
                        }

                        function validateDonationForm() {
                            const type = document.getElementById('donation_type_input').value;
                            
                            if (type === 'money') {
                                const amount = parseFloat(document.getElementById('money_amount').value);
                                if (isNaN(amount) || amount <= 0) {
                                    alert('Please enter a valid donation amount ($).');
                                    return false;
                                }
                                
                                const paymentMethod = document.getElementById('payment_method_input').value;
                                if (!paymentMethod) {
                                    alert('Please choose a donation method (bKash, Nagad, Rocket, DBBL, BRAC Bank, or Other Bank).');
                                    return false;
                                }
                            } else {
                                const supplyAmount = parseFloat(document.getElementById('supply_amount').value);
                                if (isNaN(supplyAmount) || supplyAmount <= 0) {
                                    alert('Please enter a valid supply amount / quantity.');
                                    return false;
                                }
                                
                                const itemName = document.getElementById('item_name').value.trim();
                                if (!itemName) {
                                    alert('Please enter the supply item name (e.g. blanket).');
                                    return false;
                                }
                                
                                const method = document.getElementById('supply_delivery_method_input').value;
                                if (method === 'pickup') {
                                    const addr = document.getElementById('pickup_address').value.trim();
                                    const phone = document.getElementById('pickup_phone').value.trim();
                                    if (!addr || !phone) {
                                        alert('Please complete the pickup address and contact details.');
                                        return false;
                                    }
                                }
                            }
                            return true;
                        }
                    </script>

                <?php elseif ($page === 'history'): ?>
                    <!-- Load Font Awesome for history icons if not loaded -->
                    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
                    
                    <div class="panel">
                        <div class="panel-heading"><h3>Full Donation History</h3></div>
                        <table class="table">
                            <thead><tr><th>Date</th><th>Amount / Details</th><th>Type</th><th>Campaign</th><th>Allocated Camp</th><th>Payment / Collection</th><th>Transaction ID</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php while ($donation = $my_donations_query->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($donation['created_at'])); ?></td>
                                        <td>
                                            <?php if ($donation['donation_type'] === 'money'): ?>
                                                <strong><?php echo formatCurrency($donation['amount']); ?></strong>
                                            <?php else: ?>
                                                <strong><?php echo number_format($donation['amount'], 0) . ' ' . htmlspecialchars($donation['item_name'] ?: 'Items'); ?></strong>
                                                <?php if ($donation['message']): ?>
                                                    <div style="max-width: 250px; font-size: 0.78rem; color: #64748b; margin-top: 0.25rem; word-break: break-word; line-height: 1.3;">
                                                        <?php echo nl2br(htmlspecialchars($donation['message'])); ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($donation['donation_type'] === 'money'): ?>
                                                <span style="color: #10b981; font-weight: 600;"><i class="fa-solid fa-dollar-sign"></i> Monetary</span>
                                            <?php else: ?>
                                                <span style="color: #2563eb; font-weight: 600;"><i class="fa-solid fa-box-open"></i> Supplies</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($donation['campaign_name'] ?: 'General Fund'); ?></td>
                                        <td><?php echo htmlspecialchars($donation['camp_name'] ?: 'Admin Allocation'); ?></td>
                                        <td>
                                            <span style="font-size: 0.85rem; font-weight: 500;">
                                                <?php if ($donation['donation_type'] === 'money'): ?>
                                                    <i class="fa-solid fa-wallet" style="opacity: 0.7; margin-right: 0.25rem;"></i> <?php echo htmlspecialchars($donation['payment_method']); ?>
                                                <?php else: ?>
                                                    <i class="fa-solid fa-truck" style="opacity: 0.7; margin-right: 0.25rem;"></i> <?php echo htmlspecialchars($donation['payment_method']); ?>
                                                    <?php if ($donation['supply_delivery_method'] === 'pickup' && $donation['pickup_address']): ?>
                                                        <div style="font-size: 0.75rem; color: #64748b; margin-top: 0.25rem; background: #f1f5f9; padding: 0.35rem 0.5rem; border-radius: 6px; font-weight: normal; line-height: 1.3;">
                                                            <strong>Pick-up Address:</strong> <?php echo htmlspecialchars($donation['pickup_address']); ?><br>
                                                            <strong>Phone:</strong> <?php echo htmlspecialchars($donation['pickup_phone']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </span>
                                        </td>
                                        <td><code><?php echo $donation['transaction_id']; ?></code></td>
                                        <td><span class="status-pill status-<?php echo $donation['status']; ?>"><?php echo ucfirst($donation['status']); ?></span></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($page === 'chat'): ?>
                    <div class="panel" style="max-width: 800px;">
                        <div class="panel-heading"><h3>Support & Updates</h3><small>Chat with the relief coordination team</small></div>
                        <div style="height: 350px; background: #f8fafc; border-radius: 18px; padding: 1.5rem; overflow-y: auto; display: flex; flex-direction: column; gap: 1rem; margin-bottom: 1.5rem;">
                            <div style="background: white; padding: 1rem; border-radius: 14px; max-width: 80%; align-self: flex-start; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">Hello! Thank you for your recent donation. We've allocated it to the Flood Relief campaign.</div>
                        </div>
                        <form method="POST">
                            <div style="display: flex; gap: 1rem;">
                                <input type="text" name="message" placeholder="Ask a question..." style="flex: 1; border: 1px solid #d1d5db; border-radius: 14px; padding: 0.95rem 1rem;" required>
                                <button type="submit" class="btn-primary">Send</button>
                            </div>
                        </form>
                    </div>
                <?php elseif ($page === 'settings'): ?>
                    <div class="panel" style="max-width: 600px;">
                        <div class="panel-heading"><h3>Settings</h3></div>
                        <div class="form-field"><label>Display Name</label><input type="text" value="<?php echo htmlspecialchars($user['full_name']); ?>"></div>
                        <div class="form-field"><label>Email Address</label><input type="email" value="<?php echo htmlspecialchars($user['email']); ?>" disabled></div>
                        <button class="btn-primary" onclick="alert('Settings saved!')">Save Preferences</button>
                        <hr style="margin: 2rem 0; border: 0; border-top: 1px solid #e5e7eb;">
                        <a href="logout.php" style="color: #ef4444; text-decoration: none; font-weight: 600;">Log out of portal</a>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <div class="dropdown-menu" id="userProfileMenu" style="position:fixed; top:70px; right:40px; display:none; background:white; border:1px solid #e5e7eb; border-radius:18px; box-shadow:0 18px 60px rgba(15,23,42,0.12); width:220px; z-index:50;">
        <a href="donor_dashboard.php?page=settings" style="display:block; padding:0.9rem 1rem; color:#111827; text-decoration:none;">Settings</a>
        <a href="logout.php" style="display:block; padding:0.9rem 1rem; color:#dc2626; text-decoration:none;">Logout</a>
    </div>
    <script>
        function toggleProfileMenu() {
            const menu = document.getElementById('userProfileMenu');
            menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
        }
        document.addEventListener('click', function(event) {
            const menu = document.getElementById('userProfileMenu');
            if (!menu) return;
            const button = event.target.closest('.profile-button');
            if (button) return;
            if (!menu.contains(event.target)) {
                menu.style.display = 'none';
            }
        });
    </script>
    <script>
        function toggleProfileMenu() {
            const dropdown = document.getElementById('profileDropdown');
            dropdown.classList.toggle('show');
            
            document.addEventListener('click', function closeMenu(e) {
                if (!e.target.closest('.profile-button') && !e.target.closest('.profile-dropdown')) {
                    dropdown.classList.remove('show');
                    document.removeEventListener('click', closeMenu);
                }
            });
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>
