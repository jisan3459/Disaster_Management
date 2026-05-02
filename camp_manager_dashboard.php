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
    }
}

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

$unread_query = $conn->query("SELECT COUNT(*) AS count FROM notifications WHERE user_id = $user_id AND is_read = 0");
$unread = $unread_query ? $unread_query->fetch_assoc() : ['count' => 0];
$unread_count = $unread['count'];

// Real Stats
$stats = [
    ['label' => 'Registered Families', 'value' => $conn->query("SELECT COUNT(*) FROM families WHERE camp_id = $camp_id")->fetch_row()[0], 'meta' => 'In this camp', 'icon' => '👥', 'color' => '#eef6ff'],
    ['label' => 'Total People', 'value' => $camp['current_occupancy'] ?? 0, 'meta' => 'Occupancy', 'icon' => '🏠', 'color' => '#f0fdf4'],
    ['label' => 'Active Tasks', 'value' => $conn->query("SELECT COUNT(*) FROM tasks WHERE camp_id = $camp_id AND status != 'completed'")->fetch_row()[0], 'meta' => 'In progress/pending', 'icon' => '📋', 'color' => '#fffbeb'],
    ['label' => 'Active Volunteers', 'value' => $conn->query("SELECT COUNT(DISTINCT assigned_to) FROM tasks WHERE camp_id = $camp_id AND status != 'completed'")->fetch_row()[0], 'meta' => 'Assigned', 'icon' => '🧑‍🤝‍🧑', 'color' => '#f5f3ff'],
];

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
        ($camp_id, 'Water Bottles', 'Supplies', 240, 'pcs', 'In Stock')");
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
        .dashboard-grid { display: grid; grid-template-columns: 1.3fr 0.9fr; gap: 1.5rem; margin-bottom: 1.5rem; }
        .panel { background: white; border-radius: 24px; padding: 1.5rem; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05); }
        .panel-heading { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .panel-heading h3 { font-size: 1.05rem; font-weight: 700; color: #111827; }
        .panel-heading small { color: #6b7280; }
        .table { width: 100%; border-collapse: collapse; background: white; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05); }
        .table thead { background: #f8fafc; }
        .table th, .table td { padding: 1rem 1.1rem; text-align: left; color: #374151; font-size: 0.95rem; }
        .table tbody tr { border-bottom: 1px solid #e5e7eb; }
        .table tbody tr:last-child { border-bottom: none; }
        .badge { display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; padding: 0.35rem 0.75rem; font-size: 0.78rem; font-weight: 700; }
        .status-chip { border-radius: 999px; padding: 0.45rem 0.75rem; font-size: 0.78rem; font-weight: 700; }
        .status-instock { background: #ecfdf5; color: #166534; }
        .status-limited { background: #fffbeb; color: #92400e; }
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
                <div class="brand">Disaster Relief</div>
            </div>
            <ul class="menu">
                <li class="menu-item"><a href="camp_manager_dashboard.php?page=dashboard" class="menu-link <?php echo $page === 'dashboard' ? 'active' : ''; ?>"><span class="menu-icon">📊</span>Dashboard</a></li>
                <li class="menu-item"><a href="camp_manager_dashboard.php?page=families" class="menu-link <?php echo $page === 'families' ? 'active' : ''; ?>"><span class="menu-icon">👨‍👩‍👧‍👦</span>Families</a></li>
                <li class="menu-item"><a href="camp_manager_dashboard.php?page=inventory" class="menu-link <?php echo $page === 'inventory' ? 'active' : ''; ?>"><span class="menu-icon">📦</span>Inventory</a></li>
                <li class="menu-item"><a href="camp_manager_dashboard.php?page=volunteers" class="menu-link <?php echo $page === 'volunteers' ? 'active' : ''; ?>"><span class="menu-icon">🧑‍🤝‍🧑</span>Volunteers</a></li>
                <li class="menu-item"><a href="camp_manager_dashboard.php?page=distribution" class="menu-link <?php echo $page === 'distribution' ? 'active' : ''; ?>"><span class="menu-icon">🚚</span>Distribution</a></li>
                <li class="menu-item"><a href="camp_manager_dashboard.php?page=chat" class="menu-link <?php echo $page === 'chat' ? 'active' : ''; ?>"><span class="menu-icon">💬</span>Chat <?php if ($unread_count > 0): ?><span class="menu-badge"><?php echo $unread_count; ?></span><?php endif; ?></a></li>
            </ul>
            <div class="sidebar-footer">Camp Manager interface for field operations and resource tracking.</div>
        </aside>
        <main class="main">
            <div class="topbar">
                <div class="topbar-left">
                    <div class="topbar-title"><?php echo htmlspecialchars($camp_name); ?> Dashboard</div>
                    <div class="topbar-subtitle">Location: <?php echo htmlspecialchars($camp_location); ?></div>
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
                                <div class="stat-icon"><?php echo $stat['icon']; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="dashboard-grid">
                        <div class="panel">
                            <div class="panel-heading"><h3>Register Affected Family</h3><small>Quickly add new families to the relief list</small></div>
                            <form id="familyForm" method="POST">
                                <input type="hidden" name="action" value="register_family">
                                <div class="form-field"><label>Head of Family</label><input type="text" name="head_name" placeholder="Full name" required></div>
                                <div class="form-field"><label>Family Members</label><input type="number" name="family_members" placeholder="Number" required></div>
                                <div class="form-field"><label>Village/Area</label><input type="text" name="village" placeholder="Village name"></div>
                                <div class="form-field"><label>Immediate Needs</label><textarea name="needs" placeholder="Food, medicine, shelter..."></textarea></div>
                                <button type="submit" class="btn-primary" style="width: 100%;">+ Register Family</button>
                            </form>
                        </div>
                        <div class="panel">
                            <div class="panel-heading"><h3>Assign Volunteer Task</h3><small>Dispatch team members for field work</small></div>
                            <form id="taskForm" method="POST">
                                <input type="hidden" name="action" value="assign_task">
                                <div class="form-field"><label>Task Name</label><input type="text" name="task_name" placeholder="e.g. Food Distribution" required></div>
                                <div class="form-field"><label>Select Volunteer</label>
                                    <select name="volunteer_id" required>
                                        <option value="">Choose volunteer</option>
                                        <?php foreach ($volunteers as $vol): ?>
                                            <option value="<?php echo $vol['id']; ?>"><?php echo htmlspecialchars($vol['full_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-field"><label>Priority Level</label>
                                    <select name="priority">
                                        <option value="low">Low</option>
                                        <option value="medium">Medium</option>
                                        <option value="high">High</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn-primary" style="width: 100%; background:#22c55e;">Assign Task</button>
                            </form>
                        </div>
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
