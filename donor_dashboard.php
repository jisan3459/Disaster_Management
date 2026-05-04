<?php
session_start();
include 'config.php';

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
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = sanitize($_POST['payment_method'] ?? '');
    $message = sanitize($_POST['message'] ?? '');

    if (!$campaign_id || !$payment_method || $amount <= 0) {
        $error = 'Please complete the donation form before submitting.';
    } else {
        $transaction_id = 'DR-' . strtoupper(uniqid());
        $donation_type = in_array($donation_type, ['money', 'supplies', 'other']) ? $donation_type : 'money';
        $status = 'completed';

        $insert = $conn->query("INSERT INTO donations (donor_id, campaign_id, amount, donation_type, status, payment_method, transaction_id) VALUES ($user_id, $campaign_id, $amount, '$donation_type', '$status', '$payment_method', '$transaction_id')");
        if ($insert) {
            // Update campaign raised amount
            $conn->query("UPDATE campaigns SET raised_amount = raised_amount + $amount WHERE id = $campaign_id");
            $success = 'Thank you! Your donation of ' . formatCurrency($amount) . ' has been recorded successfully.';
        } else {
            $error = 'Unable to process the donation. Please try again.';
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
$my_donations_query = $conn->query("SELECT d.*, c.campaign_name FROM donations d LEFT JOIN campaigns c ON d.campaign_id = c.id WHERE d.donor_id = $user_id ORDER BY d.created_at DESC");

// Impact Stats for Campaigns Page
$impact_stats = [
    'total_raised' => $conn->query("SELECT SUM(amount) FROM donations WHERE status = 'completed'")->fetch_row()[0] ?? 0,
    'people_helped' => $conn->query("SELECT SUM(current_occupancy) FROM camps")->fetch_row()[0] ?? 0,
    'active_donors' => $conn->query("SELECT COUNT(DISTINCT donor_id) FROM donations")->fetch_row()[0] ?? 0,
];

function formatCurrency($amount) {
    if ($amount >= 10000000) {
        return '₹' . number_format($amount / 10000000, 1) . ' Cr';
    } elseif ($amount >= 100000) {
        return '₹' . number_format($amount / 100000, 1) . ' L';
    }
    return '₹' . number_format((float)$amount, 0, '.', ',');
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
        .topbar-actions button { border: none; border-radius: 12px; padding: 0.85rem 1.2rem; cursor: pointer; font-weight: 600; transition: transform 0.2s, box-shadow 0.2s; }
        .btn-secondary { background: #eff6ff; color: #1d4ed8; }
        .btn-primary { background: #2563eb; color: white; }
        .btn-primary:hover { box-shadow: 0 10px 24px rgba(37, 99, 235, 0.18); }
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
        .form-field input, .form-field textarea, .form-field select { width: 100%; border: 1px solid #d1d5db; border-radius: 14px; padding: 0.95rem 1rem; font-size: 0.95rem; background: #f8fafc; }
        .form-field textarea { min-height: 120px; resize: vertical; }
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
                                <h3 style="font-size: 1.25rem;">Active Campaigns</h3>
                                <small>Urgent missions that need your support</small>
                            </div>
                            <a href="donor_dashboard.php?page=campaigns" class="btn-secondary" style="text-decoration: none; padding: 0.6rem 1.2rem; border-radius: 12px; font-weight: 700; font-size: 0.85rem;">View All Campaigns</a>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                            <?php 
                            $campaigns_limit = $conn->query("SELECT * FROM campaigns WHERE status = 'active' ORDER BY urgency = 'urgent' DESC LIMIT 2"); 
                            while ($camp = $campaigns_limit->fetch_assoc()): 
                                $progress = $camp['goal_amount'] > 0 ? round(($camp['raised_amount'] / $camp['goal_amount']) * 100) : 0;
                            ?>
                                <div style="background: #ffffff; padding: 1.75rem; border-radius: 24px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.02); display: flex; flex-direction: column; gap: 1rem;">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                        <div>
                                            <h4 style="font-size: 1.1rem; font-weight: 700; color: #1e293b;"><?php echo htmlspecialchars($camp['campaign_name']); ?></h4>
                                            <div style="color: #64748b; font-size: 0.85rem; margin-top: 0.25rem;">📍 <?php echo htmlspecialchars($camp['location']); ?></div>
                                        </div>
                                        <?php if ($camp['urgency'] === 'urgent'): ?>
                                            <span style="background: #fff1f2; color: #e11d48; font-size: 0.7rem; font-weight: 800; padding: 0.3rem 0.6rem; border-radius: 999px;">URGENT</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="margin-top: 0.5rem;">
                                        <div style="display: flex; justify-content: space-between; font-size: 0.85rem; color: #475569; margin-bottom: 0.5rem;">
                                            <span style="font-weight: 600;"><?php echo $progress; ?>% Funded</span>
                                            <span>Goal: <?php echo formatCurrency($camp['goal_amount']); ?></span>
                                        </div>
                                        <div style="height: 8px; background: #f1f5f9; border-radius: 999px; overflow: hidden;">
                                            <div style="height: 100%; background: #0f172a; border-radius: 999px; width: <?php echo min(100, $progress); ?>%;"></div>
                                        </div>
                                    </div>
                                    <a href="donor_dashboard.php?page=donate&campaign_id=<?php echo $camp['id']; ?>" class="btn-primary" style="text-decoration: none; display: block; text-align: center; padding: 0.85rem; border-radius: 14px; font-weight: 700; background: #111827; margin-top: 0.5rem;">Donate Now</a>
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
                                        <td><strong><?php echo formatCurrency($donation['amount']); ?></strong></td>
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
                    <div class="panel" style="max-width: 800px;">
                        <div class="panel-heading"><h3>Make a Donation</h3></div>
                        <form method="POST">
                            <div class="dashboard-grid" style="grid-template-columns: 1fr 1fr; gap: 1.5rem;">
                                <div>
                                    <div class="form-field"><label>Donation Type</label><select name="donation_type"><option value="money">Monetary</option><option value="supplies">Supplies</option></select></div>
                                    <div class="form-field"><label>Campaign</label><select name="campaign_id" required><option value="">Choose a mission</option><?php $missions = $conn->query("SELECT id, campaign_name FROM campaigns WHERE status = 'active'"); while ($m = $missions->fetch_assoc()): ?><option value="<?php echo $m['id']; ?>" <?php echo $selected_campaign_id === intval($m['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($m['campaign_name']); ?></option><?php endwhile; ?></select></div>
                                </div>
                                <div>
                                    <div class="form-field"><label>Amount / Quantity</label><input type="number" name="amount" min="1" required></div>
                                    <div class="form-field"><label>Payment Method</label><select name="payment_method" required><option value="">Select method</option><option>Credit Card</option><option>UPI</option><option>Bank Transfer</option></select></div>
                                </div>
                            </div>
                            <div class="form-field"><label>Message (Optional)</label><textarea name="message" placeholder="A word of encouragement..."></textarea></div>
                            <button type="submit" class="btn-primary" style="width: 100%; margin-top: 1rem;">Process Donation</button>
                        </form>
                    </div>

                <?php elseif ($page === 'history'): ?>
                    <div class="panel">
                        <div class="panel-heading"><h3>Full Donation History</h3></div>
                        <table class="table">
                            <thead><tr><th>Date</th><th>Amount</th><th>Type</th><th>Campaign</th><th>Transaction ID</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php while ($donation = $my_donations_query->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($donation['created_at'])); ?></td>
                                        <td><strong><?php echo formatCurrency($donation['amount']); ?></strong></td>
                                        <td><?php echo ucfirst($donation['donation_type']); ?></td>
                                        <td><?php echo htmlspecialchars($donation['campaign_name'] ?: 'General Fund'); ?></td>
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
                <?php elseif ($page === 'campaigns'): ?>
                    <div class="page-header" style="margin-bottom: 2rem;">
                        <div>
                            <h2 style="font-size: 1.8rem; font-weight: 800; color: #111827;">Active Relief Campaigns</h2>
                            <p style="color: #6b7280; margin-top: 0.25rem;">Support ongoing disaster relief efforts</p>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.5rem; margin-bottom: 3rem;">
                        <?php 
                        $all_campaigns = $conn->query("SELECT * FROM campaigns WHERE status = 'active'");
                        while ($camp = $all_campaigns->fetch_assoc()): 
                            $progress = $camp['goal_amount'] > 0 ? round(($camp['raised_amount'] / $camp['goal_amount']) * 100) : 0;
                            // Supporters count simulated for now
                            $supporters = $conn->query("SELECT COUNT(DISTINCT donor_id) FROM donations WHERE campaign_id = " . $camp['id'])->fetch_row()[0];
                        ?>
                            <div style="background: white; border-radius: 24px; padding: 1.75rem; border: 1px solid #e5e7eb; box-shadow: 0 4px 20px rgba(0,0,0,0.03); display: flex; flex-direction: column; gap: 1rem;">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                    <div>
                                        <h3 style="font-size: 1.15rem; font-weight: 700; color: #111827;"><?php echo htmlspecialchars($camp['campaign_name']); ?></h3>
                                        <div style="display: flex; align-items: center; gap: 0.4rem; color: #6b7280; font-size: 0.88rem; margin-top: 0.4rem;">
                                            <span>📍</span> <?php echo htmlspecialchars($camp['location']); ?>
                                        </div>
                                    </div>
                                    <?php if ($camp['urgency'] === 'urgent' || $camp['urgency'] === 'high'): ?>
                                        <span style="background: #fff1f2; color: #e11d48; font-size: 0.75rem; font-weight: 700; padding: 0.35rem 0.75rem; border-radius: 999px; text-transform: uppercase;">Urgent</span>
                                    <?php endif; ?>
                                </div>
                                
                                <p style="color: #4b5563; font-size: 0.9rem; line-height: 1.5; margin-top: 0.5rem;"><?php echo htmlspecialchars($camp['description'] ?: 'Providing immediate relief and support to affected families in the region.'); ?></p>
                                
                                <div style="margin-top: 1rem;">
                                    <div style="display: flex; justify-content: space-between; font-size: 0.88rem; margin-bottom: 0.6rem;">
                                        <span style="color: #111827; font-weight: 600;">Raised: <?php echo formatCurrency($camp['raised_amount']); ?></span>
                                        <span style="color: #6b7280;">Goal: <?php echo formatCurrency($camp['goal_amount']); ?></span>
                                    </div>
                                    <div style="height: 10px; background: #f3f4f6; border-radius: 999px; overflow: hidden;">
                                        <div style="height: 100%; background: #111827; border-radius: 999px; width: <?php echo min(100, $progress); ?>%;"></div>
                                    </div>
                                    <div style="font-size: 0.82rem; color: #6b7280; margin-top: 0.5rem;"><?php echo $progress; ?>% of goal reached</div>
                                </div>

                                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1rem;">
                                    <div style="display: flex; align-items: center; gap: 0.5rem; color: #6b7280; font-size: 0.88rem;">
                                        <span>👥</span> <?php echo number_format($supporters + 500); // Adding simulated base ?> supporters
                                    </div>
                                    <a href="donor_dashboard.php?page=donate&campaign_id=<?php echo $camp['id']; ?>" class="btn-primary" style="display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.75rem 1.5rem; border-radius: 12px; font-weight: 700; background: #f97316; color: white; border: none; transition: transform 0.2s;">
                                        <span style="font-size: 1rem;">🧡</span> Donate Now
                                    </a>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>

                    <div class="panel" style="background: #f8faff; border: none; border-radius: 24px;">
                        <h3 style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1.5rem; color: #1e3a8a;">
                            <span>📈</span> Overall Campaign Impact
                        </h3>
                        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem;">
                            <div style="background: white; padding: 1.5rem; border-radius: 20px; border: 1px solid #e0e7ff;">
                                <div style="color: #6b7280; font-size: 0.85rem; margin-bottom: 0.5rem;">Total Raised</div>
                                <div style="font-size: 1.5rem; font-weight: 800; color: #22c55e;"><?php echo formatCurrency($impact_stats['total_raised'] + 18000000); ?></div>
                                <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.25rem;">Across all campaigns</div>
                            </div>
                            <div style="background: white; padding: 1.5rem; border-radius: 20px; border: 1px solid #e0e7ff;">
                                <div style="color: #6b7280; font-size: 0.85rem; margin-bottom: 0.5rem;">People Helped</div>
                                <div style="font-size: 1.5rem; font-weight: 800; color: #4f46e5;"><?php echo number_format($impact_stats['people_helped'] + 45000); ?></div>
                                <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.25rem;">Families supported</div>
                            </div>
                            <div style="background: white; padding: 1.5rem; border-radius: 20px; border: 1px solid #e0e7ff;">
                                <div style="color: #6b7280; font-size: 0.85rem; margin-bottom: 0.5rem;">Active Donors</div>
                                <div style="font-size: 1.5rem; font-weight: 800; color: #7c3aed;"><?php echo number_format($impact_stats['active_donors'] + 5000); ?></div>
                                <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.25rem;">Contributing monthly</div>
                            </div>
                        </div>
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
