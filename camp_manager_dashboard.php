<?php
include 'config.php';

if (!isLoggedIn()) {
    redirect('signin.php');
}

$user_role = $_SESSION['role'] ?? '';
if ($user_role !== 'camp_manager') {
    if ($user_role === 'admin') {
        redirect('admin_dashboard.php');
    } elseif ($user_role === 'volunteer') {
        redirect('volunteer_dashboard.php');
    }
    redirect('index.php');
}

$user_id = $_SESSION['user_id'];
$user_query = $conn->query("SELECT * FROM users WHERE id = $user_id");
$user = $user_query->fetch_assoc();

// Fetch the camp managed by this user
$camp_res = $conn->query("SELECT * FROM camps WHERE manager_id = $user_id LIMIT 1");
$camp = $camp_res->fetch_assoc();
$camp_id = $camp['id'] ?? 0;
$camp_name = $camp['camp_name'] ?? 'Unassigned';
$camp_location = $camp['location'] ?? 'Not set';

// Ensure distributions table exists
$conn->query("CREATE TABLE IF NOT EXISTS distributions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    camp_id INT,
    recipient_name VARCHAR(255) NOT NULL,
    items TEXT NOT NULL,
    distributed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (camp_id) REFERENCES camps(id)
)");

// Handle Actions
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'register_family') {
            $head = sanitize($_POST['head_name']);
            $members = intval($_POST['family_members']);
            $village = sanitize($_POST['village']);
            $needs = sanitize($_POST['needs']);
            
            $insert = $conn->query("INSERT INTO families (head_name, family_members, village, needs, camp_id) VALUES ('$head', $members, '$village', '$needs', $camp_id)");
            if ($insert) {
                // Update camp occupancy
                $conn->query("UPDATE camps SET current_occupancy = current_occupancy + $members WHERE id = $camp_id");
                $success_msg = "Family registered successfully.";
            } else {
                $error_msg = "Failed to register family.";
            }
        }
        
        if ($action === 'assign_task') {
            $task_name = sanitize($_POST['task_name']);
            $vol_id = intval($_POST['volunteer_id']);
            $priority = sanitize($_POST['priority']);
            
            $insert = $conn->query("INSERT INTO tasks (task_name, camp_id, assigned_to, assigned_by, priority, status) VALUES ('$task_name', $camp_id, $vol_id, $user_id, '$priority', 'pending')");
            if ($insert) {
                $success_msg = "Task assigned successfully.";
            } else {
                $error_msg = "Failed to assign task.";
            }
        }

        if ($action === 'update_inventory') {
            $item_id = intval($_POST['item_id']);
            $new_qty = floatval($_POST['quantity']);
            $status = ($new_qty > 100) ? 'In Stock' : (($new_qty > 0) ? 'Limited' : 'Out of Stock');
            
            $update = $conn->query("UPDATE inventory SET quantity = $new_qty, status = '$status' WHERE id = $item_id AND camp_id = $camp_id");
            if ($update) {
                $success_msg = "Inventory updated successfully.";
            } else {
                $error_msg = "Failed to update inventory.";
            }
        }

        if ($action === 'add_inventory') {
            $name = sanitize($_POST['item_name']);
            $cat = sanitize($_POST['category']);
            $qty = floatval($_POST['quantity']);
            $unit = sanitize($_POST['unit']);
            $status = ($qty > 100) ? 'In Stock' : (($qty > 0) ? 'Limited' : 'Out of Stock');
            
            $insert = $conn->query("INSERT INTO inventory (camp_id, item_name, category, quantity, unit, status) VALUES ($camp_id, '$name', '$cat', $qty, '$unit', '$status')");
            if ($insert) {
                $success_msg = "Item added to inventory.";
            } else {
                $error_msg = "Failed to add item.";
            }
        }

        if ($action === 'assign_volunteer_to_camp') {
            $vol_id = intval($_POST['volunteer_id']);
            // Check if already assigned
            $check = $conn->query("SELECT id FROM volunteer_assignments WHERE volunteer_id = $vol_id AND camp_id = $camp_id AND status = 'active'");
            if ($check && $check->num_rows > 0) {
                $error_msg = "Volunteer is already assigned to this camp.";
            } else {
                $insert = $conn->query("INSERT INTO volunteer_assignments (volunteer_id, camp_id, status, assignment_date) VALUES ($vol_id, $camp_id, 'active', CURRENT_TIMESTAMP)");
                if ($insert) {
                    $success_msg = "Volunteer assigned to camp successfully.";
                } else {
                    $error_msg = "Failed to assign volunteer.";
                }
            }
        }
    }
}

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

$unread_query = $conn->query("SELECT COUNT(*) AS count FROM notifications WHERE user_id = $user_id AND is_read = 0");
$unread = $unread_query ? $unread_query->fetch_assoc() : ['count' => 0];
$unread_count = $unread['count'];

// Real Stats
$stats = [
    ['label' => 'Current Occupancy', 'value' => ($camp['current_occupancy'] ?? 0) . ' of ' . ($camp['capacity'] ?? 500), 'meta' => 'Total capacity', 'icon' => '👥', 'color' => '#3b82f6'],
    ['label' => 'Supply Items', 'value' => $conn->query("SELECT COUNT(*) FROM inventory WHERE camp_id = $camp_id")->fetch_row()[0], 'meta' => 'Total items', 'icon' => '📦', 'color' => '#10b981'],
    ['label' => 'Active Tasks', 'value' => $conn->query("SELECT COUNT(*) FROM tasks WHERE camp_id = $camp_id AND status = 'pending'")->fetch_row()[0], 'meta' => 'Pending', 'icon' => '📋', 'color' => '#f97316'],
    ['label' => 'Distributions', 'value' => $conn->query("SELECT COUNT(*) FROM distributions WHERE camp_id = $camp_id")->fetch_row()[0], 'meta' => 'Recent', 'icon' => '📈', 'color' => '#8b5cf6'],
];

// Fetch Recent Distributions
$recent_distributions_res = $conn->query("SELECT * FROM distributions WHERE camp_id = $camp_id ORDER BY distributed_at DESC LIMIT 3");
$recent_distributions = [];
if ($recent_distributions_res) {
    while($row = $recent_distributions_res->fetch_assoc()) { $recent_distributions[] = $row; }
}

// If no distributions yet, add some samples for the UI look
if (empty($recent_distributions)) {
    $conn->query("INSERT IGNORE INTO distributions (camp_id, recipient_name, items, distributed_at) VALUES 
        ($camp_id, 'Robert Martinez', 'Food packets, Water', '2026-03-29 10:00:00'),
        ($camp_id, 'Maria Garcia', 'Clothing, Blankets', '2026-03-29 14:00:00'),
        ($camp_id, 'Robert Martinez', 'Medical supplies', '2026-03-28 09:00:00')");
    $recent_distributions_res = $conn->query("SELECT * FROM distributions WHERE camp_id = $camp_id ORDER BY distributed_at DESC LIMIT 3");
    while($row = $recent_distributions_res->fetch_assoc()) { $recent_distributions[] = $row; }
}

// Fetch Top Inventory for Chart
$chart_inventory_res = $conn->query("SELECT item_name, quantity FROM inventory WHERE camp_id = $camp_id ORDER BY quantity DESC LIMIT 5");
$chart_data = [];
while($row = $chart_inventory_res->fetch_assoc()) { $chart_data[] = $row; }

// Fetch Families
$families_res = $conn->query("SELECT * FROM families WHERE camp_id = $camp_id ORDER BY created_at DESC");
$families = [];
while($row = $families_res->fetch_assoc()) {
    $families[] = $row;
}

// Fetch Inventory
$inventory_res = $conn->query("SELECT * FROM inventory WHERE camp_id = $camp_id ORDER BY item_name ASC");
$inventory = [];
if ($inventory_res && $inventory_res->num_rows > 0) {
    while($row = $inventory_res->fetch_assoc()) { $inventory[] = $row; }
} else {
    // Ensure 'unit' column exists (fix for schema mismatch)
    try {
        $conn->query("ALTER TABLE inventory ADD COLUMN unit VARCHAR(50) AFTER quantity");
    } catch (Exception $e) {
        // Column probably already exists
    }
    
    // Insert some defaults if empty for this demo
    $conn->query("INSERT INTO inventory (camp_id, item_name, category, quantity, unit, status) VALUES 
        ($camp_id, 'Rice', 'Food', 500, 'kg', 'In Stock'),
        ($camp_id, 'Medicine Kit', 'Medical', 45, 'units', 'Limited'),
        ($camp_id, 'Water Bottles', 'Supplies', 240, 'pcs', 'In Stock'),
        ($camp_id, 'Warm Clothes', 'Clothing', 150, 'sets', 'In Stock'),
        ($camp_id, 'Blankets', 'Supplies', 120, 'pcs', 'In Stock'),
        ($camp_id, 'Packaged Food', 'Food', 300, 'units', 'In Stock'),
        ($camp_id, 'Canned Food', 'Food', 200, 'cans', 'In Stock'),
        ($camp_id, 'Sanitary Items', 'Hygiene', 180, 'kits', 'In Stock')");
    $inventory_res = $conn->query("SELECT * FROM inventory WHERE camp_id = $camp_id ORDER BY item_name ASC");
    while($row = $inventory_res->fetch_assoc()) { $inventory[] = $row; }
}

// Fetch Volunteers for task assignment
$volunteers_res = $conn->query("SELECT id, full_name FROM users WHERE role = 'volunteer' AND status = 'active'");
$volunteers = [];
while($row = $volunteers_res->fetch_assoc()) {
    $volunteers[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Camp Manager Dashboard - DisasterRelief</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; background: #f3f4f6; color: #111827; }
        .layout { display: flex; min-height: 100vh; }
        .sidebar { width: 240px; background: #ffffff; border-right: 1px solid #e5e7eb; display: flex; flex-direction: column; }
        .sidebar-top { display: flex; flex-direction: column; align-items: flex-start; padding: 1.75rem 1.5rem 1rem; }
        .logo { width: 36px; height: 36px; border-radius: 8px; background: #2563eb; color: white; display: grid; place-items: center; font-weight: 800; margin-bottom: 0.5rem; }
        .brand { font-weight: 800; font-size: 1.2rem; color: #1e293b; letter-spacing: -0.02em; }
        .role-tag { font-size: 0.75rem; color: #64748b; font-weight: 600; margin-top: -0.25rem; }
        .menu { list-style: none; padding: 1rem 0.75rem; margin: 0; }
        .menu-item { margin-bottom: 0.25rem; }
        .menu-link { display: flex; align-items: center; gap: 0.85rem; padding: 0.75rem 1rem; color: #64748b; text-decoration: none; border-radius: 10px; transition: all 0.2s; font-weight: 500; font-size: 0.9rem; }
        .menu-link:hover, .menu-link.active { background: #f1f5f9; color: #2563eb; }
        .menu-link.active { background: #eff6ff; color: #2563eb; font-weight: 600; }
        .menu-icon { font-size: 1.1rem; width: 24px; text-align: center; }
        .menu-badge { margin-left: auto; background: #f97316; color: white; border-radius: 999px; font-size: 0.75rem; padding: 0.1rem 0.5rem; }
        .sidebar-footer { margin-top: auto; padding: 1.5rem; font-size: 0.85rem; color: #94a3b8; border-top: 1px solid #f1f5f9; }
        .main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .topbar { background: white; padding: 1.5rem 2.5rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f5f9; }
        .topbar-left { display: flex; flex-direction: column; gap: 0.15rem; }
        .topbar-title { font-size: 1.5rem; font-weight: 800; color: #1e293b; letter-spacing: -0.02em; }
        .topbar-subtitle { color: #64748b; font-size: 0.9rem; font-weight: 500; }
        .topbar-actions { display: flex; gap: 1rem; align-items: center; }
        .content { padding: 2rem 2.5rem; overflow-y: auto; background: #f8fafc; }
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: white; border-radius: 16px; padding: 1.5rem; border: 1px solid #f1f5f9; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); }
        .stat-text { display: flex; flex-direction: column; }
        .stat-label { color: #64748b; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.5rem; }
        .stat-value { font-size: 1.75rem; font-weight: 800; color: #1e293b; line-height: 1.1; margin-bottom: 0.25rem; }
        .stat-meta { color: #94a3b8; font-size: 0.8rem; font-weight: 500; }
        .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: grid; place-items: center; font-size: 1.25rem; }
        .dashboard-main-grid { display: grid; grid-template-columns: 1.5fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; }
        .panel { background: white; border-radius: 16px; padding: 1.75rem; border: 1px solid #f1f5f9; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .panel-heading { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .panel-heading h3 { font-size: 1.1rem; font-weight: 700; color: #1e293b; }
        
        /* Bar Chart Styles */
        .chart-container { height: 260px; display: flex; align-items: flex-end; justify-content: space-between; padding: 1rem 0; gap: 1.5rem; }
        .chart-bar-wrapper { flex: 1; display: flex; flex-direction: column; align-items: center; height: 100%; gap: 0.75rem; }
        .chart-bar-bg { width: 100%; flex: 1; background: #f8fafc; border-radius: 4px; display: flex; align-items: flex-end; overflow: hidden; position: relative; }
        .chart-bar-bg::before { content: ''; position: absolute; left: 0; right: 0; border-top: 1px dashed #e2e8f0; }
        .chart-bar-bg::after { content: ''; position: absolute; left: 0; right: 0; top: 50%; border-top: 1px dashed #e2e8f0; }
        .chart-bar { width: 100%; background: #4f46e5; border-radius: 4px; transition: height 1s ease-out; position: relative; z-index: 1; min-height: 5px; }
        .chart-label { font-size: 0.75rem; color: #64748b; font-weight: 600; text-align: center; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 100%; }
        .chart-value { font-size: 0.75rem; font-weight: 700; color: #1e293b; margin-top: 2px; }

        /* Distribution List Styles */
        .dist-list { display: flex; flex-direction: column; gap: 1rem; }
        .dist-item { padding: 1rem; border-radius: 12px; background: #f8fafc; display: flex; justify-content: space-between; align-items: center; transition: all 0.2s; }
        .dist-item:hover { background: #f1f5f9; }
        .dist-info { display: flex; flex-direction: column; gap: 0.2rem; }
        .dist-name { font-weight: 700; color: #1e293b; font-size: 0.95rem; }
        .dist-details { font-size: 0.8rem; color: #64748b; }
        .dist-date { font-size: 0.75rem; color: #94a3b8; font-weight: 600; }
        .table { width: 100%; border-collapse: collapse; background: white; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05); }
        .table thead { background: #f8fafc; }
        .table th, .table td { padding: 1rem 1.1rem; text-align: left; color: #374151; font-size: 0.95rem; }
        .table tbody tr { border-bottom: 1px solid #e5e7eb; }
        .table tbody tr:last-child { border-bottom: none; }
        .badge { display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; padding: 0.35rem 0.75rem; font-size: 0.78rem; font-weight: 700; }
        .status-chip { border-radius: 999px; padding: 0.45rem 0.75rem; font-size: 0.78rem; font-weight: 700; }
        .status-instock { background: #ecfdf5; color: #166534; }
        .status-limited { background: #fffbeb; color: #92400e; }
        .status-pending { background: #fff7ed; color: #9a3412; }
        .status-inprogress { background: #eff6ff; color: #1e40af; }
        .status-completed { background: #f0fdf4; color: #166534; }
        .status-cancelled { background: #fef2f2; color: #991b1b; }
        .vol-card { border: 1px solid #e5e7eb; border-radius: 16px; padding: 1.25rem; margin-bottom: 1.5rem; }
        .vol-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .vol-info { display: flex; align-items: center; gap: 1rem; }
        .vol-avatar { width: 40px; height: 40px; border-radius: 10px; background: #2563eb; color: white; display: grid; place-items: center; font-weight: 700; }
        .form-field { display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1rem; }
        .form-field label { font-size: 0.9rem; color: #374151; font-weight: 600; }
        .form-field input, .form-field textarea, .form-field select { width: 100%; border: 1px solid #d1d5db; border-radius: 14px; padding: 0.95rem 1rem; font-size: 0.95rem; background: #f8fafc; }
        .form-field textarea { min-height: 120px; resize: vertical; }
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
                <div class="brand">Relief System</div>
                <div class="role-tag">Camp Manager</div>
            </div>
            <ul class="menu">
                <li class="menu-item"><a href="camp_manager_dashboard.php?page=dashboard" class="menu-link <?php echo $page === 'dashboard' ? 'active' : ''; ?>"><span class="menu-icon">📊</span>Dashboard</a></li>
                <li class="menu-item"><a href="camp_manager_dashboard.php?page=overview" class="menu-link <?php echo $page === 'overview' ? 'active' : ''; ?>"><span class="menu-icon">📍</span>Camp Overview</a></li>
                <li class="menu-item"><a href="camp_manager_dashboard.php?page=families" class="menu-link <?php echo $page === 'families' ? 'active' : ''; ?>"><span class="menu-icon">👨‍👩‍👧‍👦</span>Affected People</a></li>
                <li class="menu-item"><a href="camp_manager_dashboard.php?page=inventory" class="menu-link <?php echo $page === 'inventory' ? 'active' : ''; ?>"><span class="menu-icon">📦</span>Supplies</a></li>
                <li class="menu-item"><a href="camp_manager_dashboard.php?page=volunteers" class="menu-link <?php echo $page === 'volunteers' ? 'active' : ''; ?>"><span class="menu-icon">🧑‍🤝‍🧑</span>Volunteers</a></li>
                <li class="menu-item"><a href="camp_manager_dashboard.php?page=tasks" class="menu-link <?php echo $page === 'tasks' ? 'active' : ''; ?>"><span class="menu-icon">📋</span>Tasks</a></li>
                <li class="menu-item"><a href="camp_manager_dashboard.php?page=distribution" class="menu-link <?php echo $page === 'distribution' ? 'active' : ''; ?>"><span class="menu-icon">📈</span>Aid Distribution</a></li>
                <li class="menu-item"><a href="camp_manager_dashboard.php?page=report" class="menu-link <?php echo $page === 'report' ? 'active' : ''; ?>"><span class="menu-icon">📄</span>Reports</a></li>
                <li class="menu-item"><a href="camp_manager_dashboard.php?page=chat" class="menu-link <?php echo $page === 'chat' ? 'active' : ''; ?>"><span class="menu-icon">💬</span>Messages <?php if ($unread_count > 0): ?><span class="menu-badge"><?php echo $unread_count; ?></span><?php endif; ?></a></li>
                <li class="menu-item"><a href="camp_manager_dashboard.php?page=settings" class="menu-link <?php echo $page === 'settings' ? 'active' : ''; ?>"><span class="menu-icon">⚙️</span>Settings</a></li>
            </ul>
            <div class="sidebar-footer">Camp Operations v2.4</div>
        </aside>
        <main class="main">
            <div class="topbar">
                <div class="topbar-left">
                    <div class="topbar-title">Camp Manager Dashboard</div>
                    <div class="topbar-subtitle">Managing: <?php echo htmlspecialchars($camp_name); ?></div>
                </div>
                <div class="topbar-actions">
                    <button type="button" class="btn-secondary" onclick="location.href='camp_manager_dashboard.php?page=report'">Generate Report</button>
                    <div class="notification">🔔 <?php if ($unread_count > 0): ?>
                        <span class="notification-badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?></div>
                    <div style="position: relative;">
                        <button class="profile-button" onclick="toggleProfileMenu()">
                            <div class="profile-avatar"><?php echo strtoupper(substr(trim($user['full_name']), 0, 1)); ?></div>
                            <div class="profile-details">
                                <span class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></span>
                                <span class="profile-role">Camp Manager</span>
                            </div>
                        </button>
                        <div id="profileDropdown" class="profile-dropdown">
                            <div class="dropdown-header">
                                <p style="font-weight: 700; font-size: 0.9rem;"><?php echo htmlspecialchars($user['full_name']); ?></p>
                                <p style="font-size: 0.75rem; color: #6b7280;"><?php echo htmlspecialchars($user['email']); ?></p>
                            </div>
                            <a href="camp_manager_dashboard.php?page=profile" class="dropdown-item">👤 My Profile</a>
                            <a href="camp_manager_dashboard.php?page=settings" class="dropdown-item">⚙️ Settings</a>
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
                            <div class="stat-card">
                                <div class="stat-text">
                                    <span class="stat-label"><?php echo $stat['label']; ?></span>
                                    <span class="stat-value"><?php echo $stat['value']; ?></span>
                                    <?php if ($stat['meta']): ?><span class="stat-meta"><?php echo $stat['meta']; ?></span><?php endif; ?>
                                </div>
                                <div class="stat-icon" style="background: <?php echo $stat['color']; ?>15; color: <?php echo $stat['color']; ?>;"><?php echo $stat['icon']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="dashboard-main-grid">
                        <div class="panel">
                            <div class="panel-heading"><h3>Supply Levels</h3><button class="btn-secondary" style="font-size: 0.8rem; padding: 0.5rem 1rem;" onclick="location.href='camp_manager_dashboard.php?page=inventory'">View Details</button></div>
                            <div class="chart-container">
                                <?php 
                                $max_qty = 0;
                                foreach($chart_data as $item) if($item['quantity'] > $max_qty) $max_qty = $item['quantity'];
                                if($max_qty == 0) $max_qty = 1;
                                
                                foreach ($chart_data as $item): 
                                    $h = ($item['quantity'] / $max_qty) * 100;
                                ?>
                                    <div class="chart-bar-wrapper">
                                        <div class="chart-bar-bg">
                                            <div class="chart-bar" style="height: <?php echo $h; ?>%;"></div>
                                        </div>
                                        <div class="chart-label" title="<?php echo htmlspecialchars($item['item_name']); ?>"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                        <div class="chart-value"><?php echo round($item['quantity']); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <div class="panel">
                            <div class="panel-heading"><h3>Recent Distributions</h3><button class="btn-secondary" style="font-size: 0.8rem; padding: 0.5rem 1rem;" onclick="location.href='camp_manager_dashboard.php?page=distribution'">View All</button></div>
                            <div class="dist-list">
                                <?php if (empty($recent_distributions)): ?>
                                    <p style="text-align: center; color: #94a3b8; padding: 2rem;">No recent distributions.</p>
                                <?php else: ?>
                                    <?php foreach ($recent_distributions as $dist): ?>
                                        <div class="dist-item">
                                            <div class="dist-info">
                                                <span class="dist-name"><?php echo htmlspecialchars($dist['recipient_name']); ?></span>
                                                <span class="dist-details"><?php echo htmlspecialchars($dist['items']); ?></span>
                                            </div>
                                            <span class="dist-date"><?php echo date('Y-m-d', strtotime($dist['distributed_at'])); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-heading"><h3>Registered Families</h3><small>Recent additions to the camp registry</small></div>
                        <table class="table" style="box-shadow: none; border: 1px solid #f1f5f9;">
                            <thead><tr><th>Head of Family</th><th>Members</th><th>Village</th><th>Needs</th><th>Registered</th></tr></thead>
                            <tbody>
                                <?php foreach (array_slice($families, 0, 5) as $family): ?>
                                    <tr>
                                        <td><div style="font-weight: 700; color: #1e293b;"><?php echo htmlspecialchars($family['head_name']); ?></div></td>
                                        <td><strong><?php echo htmlspecialchars($family['family_members']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($family['village']); ?></td>
                                        <td><div style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($family['needs']); ?></div></td>
                                        <td><?php echo date('M d, Y', strtotime($family['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ($page === 'inventory'): ?>
                    <div class="panel" style="margin-bottom: 1.5rem;">
                        <div class="panel-heading"><h3>Add New Item</h3><small>Add new supplies to camp inventory</small></div>
                        <form method="POST" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; align-items: end;">
                            <input type="hidden" name="action" value="add_inventory">
                            <div class="form-field" style="margin-bottom:0;"><label>Item Name</label><input type="text" name="item_name" placeholder="e.g. Blankets" required></div>
                            <div class="form-field" style="margin-bottom:0;"><label>Category</label><input type="text" name="category" placeholder="e.g. Supplies"></div>
                            <div class="form-field" style="margin-bottom:0;"><label>Quantity</label><input type="number" name="quantity" value="0" step="0.01"></div>
                            <div class="form-field" style="margin-bottom:0;"><label>Unit</label><input type="text" name="unit" placeholder="e.g. pcs"></div>
                            <button type="submit" class="btn-primary" style="height: 48px;">+ Add Item</button>
                        </form>
                    </div>
                    <div class="panel">
                        <div class="panel-heading"><h3>Inventory Status</h3><button class="btn-secondary" onclick="alert('Refill requested')">Request Refill</button></div>
                        <table class="table">
                            <thead><tr><th>Item</th><th>Category</th><th>Quantity</th><th>Status</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($inventory as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['item_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['category']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($row['quantity']) . ' ' . htmlspecialchars($row['unit']); ?></strong></td>
                                        <td><span class="status-chip <?php echo str_replace(' ', '', strtolower($row['status'])); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                                        <td>
                                            <form method="POST" style="display:flex; gap:0.5rem;">
                                                <input type="hidden" name="action" value="update_inventory">
                                                <input type="hidden" name="item_id" value="<?php echo $row['id']; ?>">
                                                <input type="number" name="quantity" value="<?php echo $row['quantity']; ?>" style="width:70px; padding:0.3rem;" step="0.01">
                                                <button type="submit" class="btn-secondary" style="padding:0.3rem 0.6rem;">Update</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ($page === 'families'): ?>
                    <div class="panel">
                        <div class="panel-heading"><h3>Registered Families</h3><button class="btn-primary" onclick="alert('Use the dashboard form to register new families')">+ New Family</button></div>
                        <table class="table">
                            <thead><tr><th>Head of Family</th><th>Members</th><th>Village</th><th>Needs</th><th>Registered</th></tr></thead>
                            <tbody>
                                <?php foreach ($families as $family): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($family['head_name']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($family['family_members']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($family['village']); ?></td>
                                        <td><?php echo htmlspecialchars($family['needs']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($family['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php elseif ($page === 'report'): ?>
                    <div class="panel">
                        <div class="panel-heading"><h3>Camp Operational Report</h3><button class="btn-primary" onclick="window.print()">🖨️ Print Report</button></div>
                        <div style="padding: 1rem; border: 1px solid #e5e7eb; border-radius: 12px; background: #f9fafb;">
                            <h4 style="margin-bottom: 1rem;">Summary for <?php echo htmlspecialchars($camp_name); ?></h4>
                            <p><strong>Date:</strong> <?php echo date('F d, Y'); ?></p>
                            <hr style="margin: 1rem 0; border: none; border-top: 1px solid #e5e7eb;">
                            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem;">
                                <div>
                                    <p><strong>Total Families:</strong> <?php echo $stats[0]['value']; ?></p>
                                    <p><strong>Total Occupancy:</strong> <?php echo $stats[1]['value']; ?> people</p>
                                </div>
                                <div>
                                    <p><strong>Pending Tasks:</strong> <?php echo $conn->query("SELECT COUNT(*) FROM tasks WHERE camp_id = $camp_id AND status = 'pending'")->fetch_row()[0]; ?></p>
                                    <p><strong>Completed Tasks:</strong> <?php echo $conn->query("SELECT COUNT(*) FROM tasks WHERE camp_id = $camp_id AND status = 'completed'")->fetch_row()[0]; ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php elseif ($page === 'chat'): ?>
                    <div class="panel" style="max-width: 800px;">
                        <div class="panel-heading"><h3>Camp Communication</h3><small>Team messaging hub</small></div>
                        <div style="height: 350px; background: #f8fafc; border-radius: 18px; padding: 1.5rem; overflow-y: auto; margin-bottom: 1.5rem;">
                            <div style="background: white; padding: 1rem; border-radius: 14px; max-width: 80%; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);">Attention all volunteers: Food supplies have arrived. Please report for distribution.</div>
                        </div>
                        <div style="display: flex; gap: 1rem;"><input type="text" placeholder="Type message..." style="flex: 1; border: 1px solid #d1d5db; border-radius: 14px; padding: 0.95rem 1rem;"><button class="btn-primary">Send</button></div>
                    </div>
                <?php elseif ($page === 'volunteers'): ?>
                    <div class="panel-heading" style="display: flex; justify-content: space-between; align-items: center;">
                        <div><h3>Volunteer Assignments</h3><small>Track tasks and progress for each volunteer</small></div>
                        <button class="btn-secondary" onclick="location.reload()">🔄 Refresh Updates</button>
                    </div>

                    <div class="panel" style="margin-bottom: 1.5rem;">
                        <div class="panel-heading"><h3>Assign New Volunteer</h3><small>Add available volunteers to your camp team</small></div>
                        <form method="POST" style="display: flex; gap: 1rem; align-items: end;">
                            <input type="hidden" name="action" value="assign_volunteer_to_camp">
                            <div class="form-field" style="flex: 1; margin-bottom: 0;">
                                <label>Select Volunteer</label>
                                <select name="volunteer_id" required>
                                    <option value="">Choose a volunteer...</option>
                                    <?php 
                                    $avail_vols = $conn->query("SELECT id, full_name FROM users WHERE role = 'volunteer' AND id NOT IN (SELECT volunteer_id FROM volunteer_assignments WHERE camp_id = $camp_id AND status = 'active')");
                                    while($av = $avail_vols->fetch_assoc()): ?>
                                        <option value="<?php echo $av['id']; ?>"><?php echo htmlspecialchars($av['full_name']); ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn-primary" style="height: 48px;">Assign to Camp</button>
                        </form>
                    </div>

                    <?php
                    $vol_query = $conn->query("SELECT u.id, u.full_name, u.email, u.phone 
                        FROM users u 
                        JOIN volunteer_assignments va ON u.id = va.volunteer_id 
                        WHERE va.camp_id = $camp_id AND va.status = 'active'");
                    
                    if ($vol_query && $vol_query->num_rows > 0):
                        while ($vol = $vol_query->fetch_assoc()):
                            $vol_id = $vol['id'];
                            $tasks_query = $conn->query("SELECT * FROM tasks WHERE assigned_to = $vol_id AND camp_id = $camp_id ORDER BY created_at DESC");
                    ?>
                        <div class="panel vol-card">
                            <div class="vol-header">
                                <div class="vol-info">
                                    <div class="vol-avatar"><?php echo strtoupper(substr($vol['full_name'], 0, 1)); ?></div>
                                    <div>
                                        <div style="font-weight: 700;"><?php echo htmlspecialchars($vol['full_name']); ?></div>
                                        <div style="font-size: 0.85rem; color: #6b7280;"><?php echo htmlspecialchars($vol['email']); ?> | <?php echo htmlspecialchars($vol['phone']); ?></div>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <?php 
                                    $count_res = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as done FROM tasks WHERE assigned_to = $vol_id AND camp_id = $camp_id");
                                    $counts = $count_res->fetch_assoc();
                                    $progress = ($counts['total'] > 0) ? round(($counts['done'] / $counts['total']) * 100) : 0;
                                    ?>
                                    <div style="font-size: 0.85rem; font-weight: 600;">Progress: <?php echo $progress; ?>%</div>
                                    <div style="width: 100px; height: 6px; background: #e5e7eb; border-radius: 3px; margin-top: 4px; overflow: hidden;">
                                        <div style="width: <?php echo $progress; ?>%; height: 100%; background: #22c55e;"></div>
                                    </div>
                                </div>
                            </div>
                            
                            <table class="table" style="box-shadow: none; border: 1px solid #f3f4f6;">
                                <thead><tr><th style="font-size: 0.85rem;">Task Name</th><th style="font-size: 0.85rem;">Priority</th><th style="font-size: 0.85rem;">Status</th><th style="font-size: 0.85rem;">Assigned</th><th style="font-size: 0.85rem;">Finished At</th></tr></thead>
                                <tbody>
                                    <?php if ($tasks_query && $tasks_query->num_rows > 0): ?>
                                        <?php while ($t = $tasks_query->fetch_assoc()): ?>
                                            <tr>
                                                <td><div style="font-weight: 600;"><?php echo htmlspecialchars($t['task_name']); ?></div></td>
                                                <td><span class="badge" style="background: <?php echo $t['priority'] === 'high' ? '#fee2e2' : ($t['priority'] === 'medium' ? '#fef3c7' : '#eff6ff'); ?>; color: <?php echo $t['priority'] === 'high' ? '#991b1b' : ($t['priority'] === 'medium' ? '#92400e' : '#1e40af'); ?>;"><?php echo strtoupper($t['priority']); ?></span></td>
                                                <td><span class="status-chip <?php echo 'status-' . str_replace('_', '', strtolower($t['status'])); ?>"><?php echo ucfirst(str_replace('_', ' ', $t['status'])); ?></span></td>
                                                <td><small><?php echo date('M d, H:i', strtotime($t['created_at'])); ?></small></td>
                                                <td><small><?php echo $t['completed_date'] ? date('M d, H:i', strtotime($t['completed_date'])) : '-'; ?></small></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" style="text-align: center; color: #9ca3af;">No tasks assigned to this volunteer.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endwhile; ?>
                    <?php else: ?>
                        <div class="panel" style="text-align: center; padding: 3rem;">
                            <div style="font-size: 3rem; margin-bottom: 1rem;">🧑‍🤝‍🧑</div>
                            <h3>No Volunteers Assigned</h3>
                            <p style="color: #6b7280;">There are currently no volunteers assigned to this camp.</p>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="panel"><p>Module content for <strong><?php echo ucfirst($page); ?></strong> is being synchronized.</p></div>
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
