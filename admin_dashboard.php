<?php
include 'config.php';

if (!isLoggedIn()) {
    redirect('signin.php');
}

$user_role = $_SESSION['role'] ?? '';

if ($user_role !== 'admin') {
    if ($user_role === 'camp_manager') {
        redirect('camp_manager_dashboard.php');
    } elseif ($user_role === 'volunteer') {
        redirect('volunteer_dashboard.php');
    } elseif ($user_role === 'donor') {
        redirect('donor_dashboard.php');
    } else {
        redirect('index.php');
    }
}

$user_id = $_SESSION['user_id'];
$user_query = $conn->query("SELECT * FROM users WHERE id = $user_id");
$user = $user_query->fetch_assoc();

// Handle Actions (Approve/Reject Volunteer, Delete Camp, etc.)
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        // Volunteer Actions
        if ($action === 'approve_volunteer' || $action === 'reject_volunteer') {
            $vol_id = intval($_POST['volunteer_id']);
            $new_status = ($action === 'approve_volunteer') ? 'active' : 'inactive';
            $update = $conn->query("UPDATE users SET status = '$new_status' WHERE id = $vol_id AND role = 'volunteer'");
            if ($update) {
                $success_msg = "Volunteer successfully " . ($action === 'approve_volunteer' ? "approved" : "rejected") . ".";
            } else {
                $error_msg = "Failed to update volunteer status.";
            }
        }
        
        // Camp Actions
        if ($action === 'delete_camp') {
            $camp_id = intval($_POST['camp_id']);
            $delete = $conn->query("DELETE FROM camps WHERE id = $camp_id");
            if ($delete) {
                $success_msg = "Camp successfully removed.";
            } else {
                $error_msg = "Failed to remove camp.";
            }
        }

        // Add Camp Action
        if ($action === 'add_camp') {
            $name = sanitize($_POST['camp_name']);
            $loc = sanitize($_POST['location']);
            $cap = intval($_POST['capacity']);
            $mgr = intval($_POST['manager_id']);
            
            $insert = $conn->query("INSERT INTO camps (camp_name, location, manager_id, capacity) VALUES ('$name', '$loc', $mgr, $cap)");
            if ($insert) {
                $success_msg = "New camp added successfully.";
            } else {
                $error_msg = "Failed to add new camp.";
            }
        }
        // Edit Camp Action
        if ($action === 'edit_camp') {
            $camp_id = intval($_POST['camp_id']);
            $name = sanitize($_POST['camp_name']);
            $loc = sanitize($_POST['location']);
            $cap = intval($_POST['capacity']);
            $mgr = intval($_POST['manager_id']);
            
            $update = $conn->query("UPDATE camps SET camp_name = '$name', location = '$loc', manager_id = $mgr, capacity = $cap WHERE id = $camp_id");
            if ($update) {
                $success_msg = "Camp updated successfully.";
            } else {
                $error_msg = "Failed to update camp.";
            }
        }
    }
}

$unread_query = $conn->query("SELECT COUNT(*) AS count FROM notifications WHERE user_id = $user_id AND is_read = 0");
$unread = $unread_query ? $unread_query->fetch_assoc() : ['count' => 0];
$unread_count = $unread['count'];

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// Fetch Real Data
$stats_query = [
    'active_camps' => $conn->query("SELECT COUNT(*) FROM camps WHERE status = 'active'")->fetch_row()[0],
    'total_volunteers' => $conn->query("SELECT COUNT(*) FROM users WHERE role = 'volunteer'")->fetch_row()[0],
    'affected_people' => $conn->query("SELECT SUM(current_occupancy) FROM camps")->fetch_row()[0] ?? 0,
    'total_donations' => $conn->query("SELECT SUM(amount) FROM donations WHERE status = 'completed'")->fetch_row()[0] ?? 0,
];

$stats = [
    ['label' => 'Active Camps', 'value' => $stats_query['active_camps'], 'meta' => 'Real-time data', 'icon' => '⛺', 'color' => '#eff6ff'],
    ['label' => 'Total Volunteers', 'value' => $stats_query['total_volunteers'], 'meta' => 'Registered team', 'icon' => '👥', 'color' => '#ecfdf5'],
    ['label' => 'Affected People', 'value' => number_format($stats_query['affected_people']), 'meta' => 'Across all camps', 'icon' => '❤️', 'color' => '#ffefef'],
    ['label' => 'Total Donations', 'value' => '৳' . number_format($stats_query['total_donations'] / 100000, 1) . 'L', 'meta' => 'Total funds raised', 'icon' => '💰', 'color' => '#f5f3ff'],
];

// Fetch Camps
$camps_res = $conn->query("SELECT c.*, u.full_name as manager_name FROM camps c LEFT JOIN users u ON c.manager_id = u.id");
$camps = [];
while($row = $camps_res->fetch_assoc()) {
    $camps[] = $row;
}

// Fetch Pending Volunteers
$volunteers_res = $conn->query("SELECT * FROM users WHERE role = 'volunteer' AND status = 'active' ORDER BY created_at DESC");
// Note: In a real app, 'status' would be 'pending' for new registrations. 
// For now, let's show all volunteers but focus on those who might need approval if we had a pending status.
// Let's assume we have a 'pending' status in the ENUM if we want real approval logic.
// Based on schema: ENUM('active', 'inactive', 'blocked'). 
// I'll use 'inactive' as 'pending' for this demo or just show all and allow toggling.
$volunteers_res = $conn->query("SELECT * FROM users WHERE role = 'volunteer'");
$volunteers = [];
while($row = $volunteers_res->fetch_assoc()) {
    $volunteers[] = $row;
}

// Fetch Camp Managers for the "Add Camp" dropdown
$managers_res = $conn->query("SELECT id, full_name FROM users WHERE role = 'camp_manager'");
$managers = [];
while($row = $managers_res->fetch_assoc()) {
    $managers[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - DisasterRelief</title>
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
        .btn-secondary { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; border-radius: 12px; padding: 0.6rem 1.2rem; cursor: pointer; font-weight: 600; font-size: 0.85rem; transition: all 0.2s; }
        .btn-secondary:hover { background: #e2e8f0; color: #1e293b; }
        .btn-primary { background: #2563eb; color: white; border: none; border-radius: 12px; padding: 0.6rem 1.2rem; cursor: pointer; font-weight: 600; font-size: 0.85rem; transition: all 0.2s; }
        .btn-primary:hover { background: #1d4ed8; box-shadow: 0 10px 24px rgba(37, 99, 235, 0.18); transform: translateY(-1px); }
        .btn-danger { background: #fff1f2; color: #be123c; border: 1px solid #fecaca; border-radius: 12px; padding: 0.6rem 1.2rem; cursor: pointer; font-weight: 600; font-size: 0.85rem; transition: all 0.2s; }
        .btn-danger:hover { background: #be123c; color: white; }
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
        .dashboard-grid { display: grid; grid-template-columns: 1.3fr 0.9fr; gap: 1.5rem; margin-bottom: 1.5rem; }
        .panel { background: white; border-radius: 24px; padding: 1.5rem; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05); }
        .panel-heading { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .panel-heading h3 { font-size: 1.05rem; font-weight: 700; color: #111827; }
        .panel-heading small { color: #6b7280; }
        .chart-card { min-height: 280px; }
        .chart-inner { height: 220px; padding: 1rem; display: grid; gap: 1rem; }
        .line-chart { background: #f8fafc; border-radius: 20px; padding: 1rem; display: grid; gap: 1rem; }
        .line-chart svg { width: 100%; height: 120px; }
        .pie-chart { display: grid; place-items: center; gap: 0.85rem; }
        .pie-circle { width: 160px; height: 160px; border-radius: 999px; background: conic-gradient(#2563eb 0 40%, #f97316 0 65%, #22c55e 0 85%, #a855f7 0 100%); display: grid; place-items: center; color: white; font-weight: 700; }
        .legend { display: grid; gap: 0.75rem; }
        .legend-item { display: flex; align-items: center; gap: 0.75rem; color: #374151; }
        .legend-marker { width: 12px; height: 12px; border-radius: 999px; }
        .legend-food { background: #2563eb; }
        .legend-medicine { background: #f97316; }
        .legend-clothing { background: #22c55e; }
        .legend-shelter { background: #a855f7; }
        .activity-panel { margin-top: 1rem; }
        .activity-bar { display: grid; gap: 1rem; }
        .activity-row { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
        .activity-label { color: #6b7280; font-size: 0.95rem; min-width: 110px; }
        .progress-bg { background: #f3f4f6; flex: 1; border-radius: 999px; height: 12px; overflow: hidden; }
        .progress-fill { height: 100%; border-radius: 999px; }
        .progress-alpha { width: 90%; background: #2563eb; }
        .progress-beta { width: 75%; background: #22c55e; }
        .progress-gamma { width: 85%; background: #f97316; }
        .progress-delta { width: 50%; background: #a855f7; }
        .page-header { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-bottom: 1rem; }
        .page-title { font-size: 1.6rem; font-weight: 700; color: #111827; }
        .page-subtitle { color: #6b7280; font-size: 0.95rem; }
        .search-box { width: 100%; max-width: 480px; position: relative; }
        .search-box input { width: 100%; border-radius: 16px; border: 1px solid #d1d5db; padding: 0.95rem 1rem; background: #f8fafc; }
        .cards-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 1.5rem; margin-bottom: 1.5rem; }
        .camp-card { background: white; border-radius: 24px; padding: 1.4rem; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.04); }
        .camp-card-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 1rem; }
        .camp-icon { width: 40px; height: 40px; border-radius: 14px; background: #eff6ff; display: grid; place-items: center; font-size: 1.1rem; }
        .camp-title { font-weight: 700; font-size: 1rem; color: #111827; }
        .camp-location { color: #6b7280; font-size: 0.88rem; margin-top: 0.35rem; }
        .status-pill { font-size: 0.8rem; font-weight: 700; padding: 0.35rem 0.75rem; border-radius: 999px; color: #2563eb; background: #eef6ff; }
        .occupancy-label { display: flex; justify-content: space-between; color: #6b7280; font-size: 0.88rem; margin-top: 1rem; }
        .occupancy-bar { width: 100%; height: 10px; border-radius: 999px; background: #f3f4f6; margin-top: 0.5rem; }
        .occupancy-fill { height: 10px; border-radius: 999px; background: #ef4444; }
        .camp-meta { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 0.75rem; margin-top: 1rem; font-size: 0.88rem; color: #6b7280; }
        .camp-actions { display: flex; gap: 0.75rem; margin-top: 1.25rem; }
        .table { width: 100%; border-collapse: collapse; background: white; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05); }
        .table thead { background: #f8fafc; }
        .table th, .table td { padding: 1rem 1.1rem; text-align: left; color: #374151; font-size: 0.95rem; }
        .table tbody tr { border-bottom: 1px solid #e5e7eb; }
        .table tbody tr:last-child { border-bottom: none; }
        .badge { display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; padding: 0.35rem 0.75rem; font-size: 0.78rem; font-weight: 700; }
        .badge.active { background: #eff6ff; color: #2563eb; }
        .badge.inactive { background: #f3f4f6; color: #6b7280; }
        .table-actions button { border: none; border-radius: 12px; padding: 0.55rem 0.9rem; cursor: pointer; font-size: 0.85rem; }
        .btn-approve { background: #22c55e; color: white; }
        .btn-reject { background: #ef4444; color: white; }
        .form-field { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1rem; }
        .form-field label { font-size: 0.9rem; color: #374151; }
        .form-field input, .form-field textarea, .form-field select { width: 100%; border: 1px solid #d1d5db; border-radius: 14px; padding: 0.95rem 1rem; font-size: 0.95rem; background: #f8fafc; }
        .form-field textarea { min-height: 120px; resize: vertical; }
        @media (max-width: 1080px) { .stats-grid, .dashboard-grid, .cards-grid { grid-template-columns: 1fr; } .sidebar { width: 100%; } .topbar { flex-wrap: wrap; gap: 1rem; } }
        @media (max-width: 760px) { .layout { flex-direction: column; } .sidebar { order: 2; } .topbar, .content { padding: 1.25rem; } }
        .profile-dropdown { position: absolute; top: 100%; right: 0; margin-top: 0.75rem; width: 220px; background: white; border-radius: 16px; border: 1px solid #e5e7eb; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); display: none; z-index: 100; overflow: hidden; }
        .profile-dropdown.show { display: block; animation: dropdownSlide 0.2s ease-out; }
        .dropdown-header { padding: 1.25rem; border-bottom: 1px solid #f3f4f6; background: #f9fafb; text-align: left; }
        .dropdown-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.85rem 1.25rem; color: #374151; text-decoration: none; font-size: 0.9rem; font-weight: 500; transition: all 0.2s; }
        .dropdown-item:hover { background: #eff6ff; color: #2563eb; }
        .dropdown-item.logout { color: #ef4444; }
        .dropdown-item.logout:hover { background: #fef2f2; color: #ef4444; }
        @keyframes dropdownSlide { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

        @media (max-width: 1080px) { .stats-grid, .dashboard-grid { grid-template-columns: 1fr; } .sidebar { width: 100%; } .topbar { flex-wrap: wrap; gap: 1rem; } }
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
                <li class="menu-item"><a href="admin_dashboard.php?page=dashboard" class="menu-link <?php echo $page === 'dashboard' ? 'active' : ''; ?>"><span class="menu-icon">📊</span>Dashboard</a></li>
                <li class="menu-item"><a href="admin_dashboard.php?page=camps" class="menu-link <?php echo $page === 'camps' ? 'active' : ''; ?>"><span class="menu-icon">⛺</span>Camps</a></li>
                <li class="menu-item"><a href="admin_dashboard.php?page=volunteers" class="menu-link <?php echo $page === 'volunteers' ? 'active' : ''; ?>"><span class="menu-icon">🧑‍🤝‍🧑</span>Volunteers<span class="menu-badge">6</span></a></li>
                <li class="menu-item"><a href="admin_dashboard.php?page=donations" class="menu-link <?php echo $page === 'donations' ? 'active' : ''; ?>"><span class="menu-icon">💵</span>Donations</a></li>
                <li class="menu-item"><a href="admin_dashboard.php?page=inventory" class="menu-link <?php echo $page === 'inventory' ? 'active' : ''; ?>"><span class="menu-icon">📦</span>Inventory</a></li>
                <li class="menu-item"><a href="admin_dashboard.php?page=alerts" class="menu-link <?php echo $page === 'alerts' ? 'active' : ''; ?>"><span class="menu-icon">⚠️</span>Alerts<span class="menu-badge">3</span></a></li>
                <li class="menu-item"><a href="admin_dashboard.php?page=reports" class="menu-link <?php echo $page === 'reports' ? 'active' : ''; ?>"><span class="menu-icon">📈</span>Reports</a></li>
                <li class="menu-item"><a href="admin_dashboard.php?page=settings" class="menu-link <?php echo $page === 'settings' ? 'active' : ''; ?>"><span class="menu-icon">⚙️</span>Settings</a></li>
            </ul>
            <div class="sidebar-footer">Admin console for managing camps, volunteers, donations, and alerts.</div>
        </aside>
        <main class="main">
            <div class="topbar">
                <div class="topbar-left">
                    <div class="topbar-title"><?php echo $page === 'dashboard' ? 'Admin Dashboard' : ucfirst($page); ?></div>
                    <div class="topbar-subtitle">Manage operations safely from a single control center</div>
                </div>
                <div class="topbar-actions">
                    <?php if ($page === 'camps'): ?>
                        <button type="button" class="btn-primary" onclick="openAddCampModal()">+ Add New Camp</button>
                    <?php elseif ($page === 'volunteers'): ?>
                        <button type="button" class="btn-primary" onclick="alert('Invite functionality coming soon');">+ Invite Volunteer</button>
                    <?php elseif ($page === 'donations'): ?>
                        <button type="button" class="btn-primary" onclick="alert('Record new donation');">+ New Donation</button>
                    <?php elseif ($page === 'reports'): ?>
                        <button type="button" class="btn-primary" onclick="alert('Generating report');">Generate Report</button>
                    <?php endif; ?>
                    <div class="notification">🔔 <?php if ($unread_count > 0): ?>
                        <span class="notification-badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?></div>
                    <div style="position: relative;">
                        <button class="profile-button" onclick="toggleProfileMenu()">
                            <div class="profile-avatar"><?php echo strtoupper(substr(trim($user['full_name']), 0, 1)); ?></div>
                            <div class="profile-details">
                                <span class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></span>
                                <span class="profile-role">System Administrator</span>
                            </div>
                        </button>
                        <div id="profileDropdown" class="profile-dropdown">
                            <div class="dropdown-header">
                                <p style="font-weight: 700; font-size: 0.9rem;"><?php echo htmlspecialchars($user['full_name']); ?></p>
                                <p style="font-size: 0.75rem; color: #6b7280;"><?php echo htmlspecialchars($user['email']); ?></p>
                            </div>
                            <a href="admin_dashboard.php?page=profile" class="dropdown-item">👤 My Profile</a>
                            <a href="admin_dashboard.php?page=settings" class="dropdown-item">⚙️ Settings</a>
                            <div style="border-top: 1px solid #f3f4f6;"></div>
                            <a href="logout.php" class="dropdown-item logout">🚪 Log Out</a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="content">
                <?php if ($success_msg): ?>
                    <div style="background: #ecfdf5; color: #065f46; padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; border: 1px solid #a7f3d0;">
                        ✅ <?php echo $success_msg; ?>
                    </div>
                <?php endif; ?>
                <?php if ($error_msg): ?>
                    <div style="background: #fef2f2; color: #991b1b; padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; border: 1px solid #fecaca;">
                        ❌ <?php echo $error_msg; ?>
                    </div>
                <?php endif; ?>

                <?php if ($page === 'dashboard'): ?>
                    <div class="stats-grid">
                        <?php foreach ($stats as $stat): ?>
                            <div class="stat-card" style="background: <?php echo $stat['color']; ?>;">
                                <div class="stat-text">
                                    <span class="stat-label"><?php echo $stat['label']; ?></span>
                                    <span class="stat-value"><?php echo $stat['value']; ?></span>
                                    <span class="stat-meta"><?php echo $stat['meta']; ?></span>
                                </div>
                                <div class="stat-icon"><?php echo $stat['icon']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="dashboard-grid">
                        <div class="panel chart-card">
                            <div class="panel-heading">
                                <div>
                                    <h3>Donation Trends</h3>
                                    <small>Monthly fundraising performance</small>
                                </div>
                            </div>
                            <div class="chart-inner">
                                <div style="display: flex; align-items: center; justify-content: center; height: 100%; color: #6b7280; font-style: italic;">
                                    Real-time donation trends being calculated...
                                </div>
                            </div>
                        </div>
                        <div class="panel chart-card">
                            <div class="panel-heading">
                                <div>
                                    <h3>Supply Distribution</h3>
                                    <small>Allocation by category</small>
                                </div>
                            </div>
                            <div class="chart-inner pie-chart">
                                <div style="color: #6b7280; font-style: italic;">
                                    Awaiting distribution data...
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="panel activity-panel">
                        <div class="panel-heading">
                            <div>
                                <h3>Camp-wise Activity</h3>
                                <small>Families and volunteers engaged by camp</small>
                            </div>
                        </div>
                        <div class="activity-bar">
                            <div style="padding: 2rem; text-align: center; color: #6b7280; font-style: italic;">
                                Live camp activity tracking will appear here once camps are operational.
                            </div>
                        </div>
                    </div>
                <?php elseif ($page === 'camps'): ?>
                    <div class="page-header">
                        <div>
                            <div class="page-title">Relief Camps Management</div>
                            <div class="page-subtitle">Manage and monitor all active relief camps</div>
                        </div>
                        <button type="button" class="btn-primary" onclick="openAddCampModal()">+ Add New Camp</button>
                    </div>
                    <div class="search-box"><input id="campSearch" type="text" placeholder="Search camps by name or location..."></div>
                    <div class="cards-grid">
                        <?php if (empty($camps)): ?>
                            <div style="grid-column: 1 / -1; text-align: center; padding: 3rem; background: white; border-radius: 24px; color: #6b7280;">
                                No camps found. Create your first camp to get started.
                            </div>
                        <?php endif; ?>
                        <?php foreach ($camps as $camp): ?>
                            <?php $percentage = $camp['capacity'] ? round($camp['current_occupancy'] / $camp['capacity'] * 100) : 0; ?>
                            <div class="camp-card">
                                <div class="camp-card-header">
                                    <div>
                                        <div class="camp-title"><?php echo htmlspecialchars($camp['camp_name']); ?></div>
                                        <div class="camp-location">📍 <?php echo htmlspecialchars($camp['location']); ?></div>
                                    </div>
                                    <span class="status-pill"><?php echo htmlspecialchars($camp['status']); ?></span>
                                </div>
                                <div class="occupancy-label"><span>Occupancy</span><span><?php echo $camp['current_occupancy'] . '/' . $camp['capacity']; ?></span></div>
                                <div class="occupancy-bar"><div class="occupancy-fill" style="width: <?php echo $percentage; ?>%;"></div></div>
                                <div class="camp-meta"><div>Manager<br><strong><?php echo htmlspecialchars($camp['manager_name'] ?: 'Unassigned'); ?></strong></div><div>Capacity<br><strong><?php echo htmlspecialchars($camp['capacity']); ?></strong></div></div>
                                <div class="camp-actions">
                                    <button class="btn-secondary" type="button" onclick='openEditCampModal(<?php echo json_encode($camp); ?>)'>Edit</button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to remove this camp?');">
                                        <input type="hidden" name="action" value="delete_camp">
                                        <input type="hidden" name="camp_id" value="<?php echo $camp['id']; ?>">
                                        <button class="btn-danger" type="submit">Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="panel" style="margin-top:1.5rem;">
                        <table class="table" id="campsTable">
                            <thead>
                                <tr><th>Camp Name</th><th>Location</th><th>Manager</th><th>Capacity</th><th>Occupancy</th><th>Status</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($camps as $camp): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($camp['camp_name']); ?></td>
                                        <td><?php echo htmlspecialchars($camp['location']); ?></td>
                                        <td><?php echo htmlspecialchars($camp['manager_name'] ?: 'Unassigned'); ?></td>
                                        <td><?php echo htmlspecialchars($camp['capacity']); ?></td>
                                        <td><?php echo htmlspecialchars($camp['current_occupancy']) . ' (' . ($camp['capacity'] ? round($camp['current_occupancy'] / $camp['capacity'] * 100) : 0) . '%)'; ?></td>
                                        <td><span class="badge <?php echo strtolower($camp['status']); ?>"><?php echo htmlspecialchars($camp['status']); ?></span></td>
                                        <td class="table-actions">
                                            <form method="POST" onsubmit="return confirm('Delete this camp?');">
                                                <input type="hidden" name="action" value="delete_camp">
                                                <input type="hidden" name="camp_id" value="<?php echo $camp['id']; ?>">
                                                <button class="btn-danger" type="submit" style="padding: 0.4rem 0.8rem;">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ($page === 'volunteers'): ?>
                    <div class="page-header">
                        <div>
                            <div class="page-title">Volunteers Management</div>
                            <div class="page-subtitle">Approve registrations and manage volunteers</div>
                        </div>
                        <button type="button" class="btn-primary" onclick="alert('Invite functionality coming soon');">Invite Volunteer</button>
                    </div>
                    <div class="panel" style="margin-bottom: 1.5rem;">
                        <div class="page-header" style="padding:0; margin-bottom:1rem; gap:0.75rem;">
                            <div style="display:flex; align-items:center; gap:0.75rem;"><span class="badge active">Volunteer List</span><span style="color:#6b7280;"><?php echo count($volunteers); ?></span></div>
                        </div>
                        <table class="table">
                            <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Location</th><th>Experience</th><th>Skills</th><th>Status</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($volunteers as $vol): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($vol['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($vol['email']); ?></td>
                                        <td><?php echo htmlspecialchars($vol['phone']); ?></td>
                                        <td><?php echo htmlspecialchars($vol['location'] ?: 'Not set'); ?></td>
                                        <td><?php echo htmlspecialchars($vol['experience'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($vol['skills'] ?: 'General'); ?></td>
                                        <td><span class="badge <?php echo $vol['status'] === 'active' ? 'active' : 'inactive'; ?>"><?php echo ucfirst($vol['status']); ?></span></td>
                                        <td class="table-actions">
                                            <?php if ($vol['status'] !== 'active'): ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="approve_volunteer">
                                                    <input type="hidden" name="volunteer_id" value="<?php echo $vol['id']; ?>">
                                                    <button class="btn btn-approve" type="submit">Approve</button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="reject_volunteer">
                                                    <input type="hidden" name="volunteer_id" value="<?php echo $vol['id']; ?>">
                                                    <button class="btn btn-reject" type="submit">Deactivate</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ($page === 'donations'): ?>
                    <div class="page-header">
                        <div>
                            <div class="page-title">Donations</div>
                            <div class="page-subtitle">Track contributions and funding status</div>
                        </div>
                        <button type="button" class="btn-primary" onclick="alert('Record new donation');">Record Donation</button>
                    </div>
                    <div class="panel">
                        <p>Donation analytics and recent contributions are displayed here.</p>
                    </div>
                <?php elseif ($page === 'inventory'): ?>
                    <div class="page-header">
                        <div>
                            <div class="page-title">Inventory</div>
                            <div class="page-subtitle">Manage supplies across camps</div>
                        </div>
                        <button type="button" class="btn-primary" onclick="alert('Add inventory item');">Add Item</button>
                    </div>
                    <div class="panel"><p>Inventory levels, stock alerts, and restocking actions can be managed here.</p></div>
                <?php elseif ($page === 'alerts'): ?>
                    <div class="page-header">
                        <div>
                            <div class="page-title">Alerts</div>
                            <div class="page-subtitle">Review and respond to camp alerts</div>
                        </div>
                    </div>
                    <div class="panel"><p>Emergency notifications and incident reports are available for review.</p></div>
                <?php elseif ($page === 'reports'): ?>
                    <div class="page-header">
                        <div>
                            <div class="page-title">Reports</div>
                            <div class="page-subtitle">Generate operational summaries and analytics</div>
                        </div>
                        <button type="button" class="btn-primary" onclick="alert('Generating report');">Generate Report</button>
                    </div>
                    <div class="panel"><p>Reports for camp operations, fundraising, and volunteer engagement appear here.</p></div>
                <?php elseif ($page === 'settings'): ?>
                    <div class="page-header">
                        <div>
                            <div class="page-title">Settings</div>
                            <div class="page-subtitle">Configure admin preferences and system behavior</div>
                        </div>
                    </div>
                    <div class="panel" style="max-width:700px;">
                        <div class="form-field"><label>Notification Preferences</label><select><option>Email & push</option><option>Email only</option><option>Push only</option></select></div>
                        <div class="form-field"><label>Theme</label><select><option>Light</option><option>Dark</option></select></div>
                        <div class="form-field"><label>System Status</label><input type="text" value="All systems operational" disabled></div>
                        <button class="btn-secondary" type="button" onclick="alert('Settings saved');">Save Settings</button>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <div class="dropdown-menu" id="adminProfileMenu" style="position:fixed; top:70px; right:40px; display:none; background:white; border:1px solid #e5e7eb; border-radius:18px; box-shadow:0 18px 60px rgba(15,23,42,0.12); width:220px; z-index:50;">
        <a href="admin_dashboard.php?page=profile" style="display:block; padding:0.9rem 1rem; color:#111827; text-decoration:none;">Profile</a>
        <a href="admin_dashboard.php?page=settings" style="display:block; padding:0.9rem 1rem; color:#111827; text-decoration:none;">Settings</a>
        <a href="logout.php" style="display:block; padding:0.9rem 1rem; color:#dc2626; text-decoration:none;">Logout</a>
    </div>
    <script>
        function toggleProfileMenu() {
            const menu = document.getElementById('adminProfileMenu');
            menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
        }
        document.addEventListener('click', function(event) {
            const menu = document.getElementById('adminProfileMenu');
            if (!menu) return;
            const button = event.target.closest('.profile-button');
            if (button) return;
            if (!menu.contains(event.target)) {
                menu.style.display = 'none';
            }
        });
        document.getElementById('campSearch')?.addEventListener('input', function() {
            const filter = this.value.toLowerCase();
            document.querySelectorAll('.cards-grid .camp-card').forEach(function(card) {
                card.style.display = card.textContent.toLowerCase().includes(filter) ? 'block' : 'none';
            });
        });

        function openAddCampModal() {
            document.getElementById('addCampModal').style.display = 'grid';
        }

        function closeAddCampModal() {
            document.getElementById('addCampModal').style.display = 'none';
        }
    </script>

    <!-- Add Camp Modal -->
    <div id="addCampModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:100; place-items:center; padding:2rem;">
        <div class="panel" style="width:100%; max-width:500px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
            <div class="panel-heading">
                <h3>Add New Relief Camp</h3>
                <button type="button" onclick="closeAddCampModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_camp">
                <div class="form-field">
                    <label>Camp Name</label>
                    <input type="text" name="camp_name" placeholder="e.g. Camp Sigma" required>
                </div>
                <div class="form-field">
                    <label>Location</label>
                    <input type="text" name="location" placeholder="e.g. Dhaka, Bangladesh" required>
                </div>
                <div class="form-field">
                    <label>Manager</label>
                    <select name="manager_id" required>
                        <option value="">Select a manager</option>
                        <?php foreach ($managers as $mgr): ?>
                            <option value="<?php echo $mgr['id']; ?>"><?php echo htmlspecialchars($mgr['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label>Capacity</label>
                    <input type="number" name="capacity" placeholder="e.g. 500" required>
                </div>
                <div style="display:flex; gap:1rem; margin-top:1.5rem;">
                    <button type="button" class="btn-secondary" style="flex:1;" onclick="closeAddCampModal()">Cancel</button>
                    <button type="submit" class="btn-primary" style="flex:1;">Create Camp</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function openEditCampModal(camp) {
            document.getElementById('edit_camp_id').value = camp.id;
            document.getElementById('edit_camp_name').value = camp.camp_name;
            document.getElementById('edit_location').value = camp.location;
            document.getElementById('edit_capacity').value = camp.capacity;
            document.getElementById('edit_manager_id').value = camp.manager_id;
            document.getElementById('editCampModal').style.display = 'grid';
        }

        function closeEditCampModal() {
            document.getElementById('editCampModal').style.display = 'none';
        }

        function toggleProfileMenu() {
            const dropdown = document.getElementById('profileDropdown');
            dropdown.classList.toggle('show');
            
            document.addEventListener('click', function closeMenu(e) {
                if (!e.target.closest('.profile-button') && !e.target.closest('.profileDropdown')) {
                    if (!e.target.closest('.profile-button') && !e.target.closest('.profile-dropdown')) {
                        dropdown.classList.remove('show');
                        document.removeEventListener('click', closeMenu);
                    }
                }
            });
        }
    </script>
    <!-- Edit Camp Modal -->
    <div id="editCampModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:100; place-items:center; padding:2rem;">
        <div class="panel" style="width:100%; max-width:500px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
            <div class="panel-heading">
                <h3>Edit Relief Camp</h3>
                <button type="button" onclick="closeEditCampModal()" style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="edit_camp">
                <input type="hidden" name="camp_id" id="edit_camp_id">
                <div class="form-field"><label>Camp Name</label><input type="text" name="camp_name" id="edit_camp_name" required></div>
                <div class="form-field"><label>Location</label><input type="text" name="location" id="edit_location" required></div>
                <div class="form-field">
                    <label>Manager</label>
                    <select name="manager_id" id="edit_manager_id" required>
                        <?php foreach ($managers as $mgr): ?>
                            <option value="<?php echo $mgr['id']; ?>"><?php echo htmlspecialchars($mgr['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field"><label>Capacity</label><input type="number" name="capacity" id="edit_capacity" required></div>
                <div style="display:flex; gap:1rem; margin-top:1.5rem;">
                    <button type="button" class="btn-secondary" style="flex:1;" onclick="closeEditCampModal()">Cancel</button>
                    <button type="submit" class="btn-primary" style="flex:1;">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
<?php $conn->close(); ?>
