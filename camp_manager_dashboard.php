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

// Database Migration: Ensure columns exist in inventory
$cols = $conn->query("SHOW COLUMNS FROM inventory");
$existing_cols = [];
while($row = $cols->fetch_assoc()) { $existing_cols[] = $row['Field']; }

if (!in_array('min_threshold', $existing_cols)) {
    $conn->query("ALTER TABLE inventory ADD COLUMN min_threshold FLOAT DEFAULT 50.0 AFTER quantity");
}
if (!in_array('updated_at', $existing_cols)) {
    $conn->query("ALTER TABLE inventory ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
}
if (!in_array('unit', $existing_cols)) {
    $conn->query("ALTER TABLE inventory ADD COLUMN unit VARCHAR(50) DEFAULT 'units' AFTER quantity");
}

// User Table Migration
$u_cols = $conn->query("SHOW COLUMNS FROM users");
$existing_u_cols = [];
while($row = $u_cols->fetch_assoc()) { $existing_u_cols[] = $row['Field']; }
if (!in_array('phone', $existing_u_cols)) { $conn->query("ALTER TABLE users ADD COLUMN phone VARCHAR(20) AFTER email"); }
if (!in_array('skills', $existing_u_cols)) { $conn->query("ALTER TABLE users ADD COLUMN skills TEXT AFTER phone"); }
if (!in_array('availability', $existing_u_cols)) { $conn->query("ALTER TABLE users ADD COLUMN availability VARCHAR(100) AFTER skills"); }

// Task Table Migration
$t_cols = $conn->query("SHOW COLUMNS FROM tasks");
$existing_t_cols = [];
while($row = $t_cols->fetch_assoc()) { $existing_t_cols[] = $row['Field']; }
if (!in_array('description', $existing_t_cols)) { $conn->query("ALTER TABLE tasks ADD COLUMN description TEXT AFTER task_name"); }
if (!in_array('due_date', $existing_t_cols)) { $conn->query("ALTER TABLE tasks ADD COLUMN due_date DATE AFTER status"); }

// Update priority ENUM to include urgent
$priority_check = $conn->query("SHOW COLUMNS FROM tasks LIKE 'priority'");
$priority_row = $priority_check->fetch_assoc();
if (strpos($priority_row['Type'], 'urgent') === false) {
    $conn->query("ALTER TABLE tasks MODIFY COLUMN priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium'");
}


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
                $conn->query("UPDATE camps SET current_occupancy = current_occupancy + $members WHERE id = $camp_id");
                $success_msg = "Family registered successfully.";
            } else { $error_msg = "Failed to register family."; }
        }
        
        if ($action === 'assign_task') {
            $task_name = sanitize($_POST['task_name']);
            $description = sanitize($_POST['description']);
            $vol_id = intval($_POST['volunteer_id']);
            $priority = sanitize($_POST['priority']);
            $due_date = sanitize($_POST['due_date']);
            
            $insert = $conn->query("INSERT INTO tasks (task_name, description, camp_id, assigned_to, assigned_by, priority, status, due_date) VALUES ('$task_name', '$description', $camp_id, $vol_id, $user_id, '$priority', 'pending', '$due_date')");
            if ($insert) { $success_msg = "Task assigned successfully."; } 
            else { $error_msg = "Failed to assign task: " . $conn->error; }
        }

        if ($action === 'update_inventory_detailed') {
            $item_id = intval($_POST['item_id']);
            $new_qty = floatval($_POST['quantity']);
            $new_threshold = floatval($_POST['min_threshold']);
            $status = ($new_qty > $new_threshold) ? 'In Stock' : (($new_qty > 0) ? 'Limited' : 'Out of Stock');
            $update = $conn->query("UPDATE inventory SET quantity = $new_qty, min_threshold = $new_threshold, status = '$status', updated_at = NOW() WHERE id = $item_id AND camp_id = $camp_id");
            if ($update) { $success_msg = "Inventory updated successfully."; } 
            else { $error_msg = "Failed to update inventory."; }
        }

        if ($action === 'add_inventory') {
            $name = sanitize($_POST['item_name']);
            $cat = sanitize($_POST['category']);
            $qty = floatval($_POST['quantity']);
            $unit = sanitize($_POST['unit']);
            $status = ($qty > 100) ? 'In Stock' : (($qty > 0) ? 'Limited' : 'Out of Stock');
            $insert = $conn->query("INSERT INTO inventory (camp_id, item_name, category, quantity, unit, status) VALUES ($camp_id, '$name', '$cat', $qty, '$unit', '$status')");
            if ($insert) { $success_msg = "Item added to inventory."; } 
            else { $error_msg = "Failed to add item."; }
        }

        if ($action === 'add_volunteer') {
            $name = sanitize($_POST['full_name']);
            $email = sanitize($_POST['email']);
            $phone = sanitize($_POST['phone']);
            $skills = sanitize($_POST['skills']);
            $avail = sanitize($_POST['availability']);
            $pwd = password_hash('volunteer123', PASSWORD_DEFAULT);

            $insert_user = $conn->query("INSERT INTO users (full_name, email, password, phone, skills, availability, role) VALUES ('$name', '$email', '$pwd', '$phone', '$skills', '$avail', 'volunteer')");
            if ($insert_user) {
                $v_id = $conn->insert_id;
                $conn->query("INSERT INTO volunteer_assignments (volunteer_id, camp_id, status) VALUES ($v_id, $camp_id, 'active')");
                $success_msg = "Volunteer added and assigned to camp.";
            } else { $error_msg = "Failed to add volunteer: " . $conn->error; }
        }
    }
}


$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// Fetch Global Data
$stats = [
    ['label' => 'Current Occupancy', 'value' => ($camp['current_occupancy'] ?? 0), 'meta' => 'of ' . ($camp['capacity'] ?? 500), 'icon' => 'users', 'color' => '#6366f1'],
    ['label' => 'Supply Items', 'value' => $conn->query("SELECT COUNT(*) FROM inventory WHERE camp_id = $camp_id")->fetch_row()[0], 'meta' => 'Total items', 'icon' => 'package', 'color' => '#10b981'],
    ['label' => 'Active Tasks', 'value' => $conn->query("SELECT COUNT(*) FROM tasks WHERE camp_id = $camp_id AND status = 'pending'")->fetch_row()[0], 'meta' => 'Pending', 'icon' => 'clipboard-list', 'color' => '#f97316'],
];


$unread_query = $conn->query("SELECT COUNT(*) AS count FROM notifications WHERE user_id = $user_id AND is_read = 0");
$unread_count = $unread_query ? $unread_query->fetch_assoc()['count'] : 0;

// Fetch Recent Data for Dashboard
$chart_inventory_res = $conn->query("SELECT item_name, quantity FROM inventory WHERE camp_id = $camp_id ORDER BY quantity DESC LIMIT 5");
$chart_data = []; while($row = $chart_inventory_res->fetch_assoc()) { $chart_data[] = $row; }


$families_res = $conn->query("SELECT * FROM families WHERE camp_id = $camp_id ORDER BY created_at DESC");
$families = []; while($row = $families_res->fetch_assoc()) { $families[] = $row; }

$inventory_res = $conn->query("SELECT * FROM inventory WHERE camp_id = $camp_id ORDER BY item_name ASC");
$inventory = []; while($row = $inventory_res->fetch_assoc()) { $inventory[] = $row; }

$volunteers_res = $conn->query("SELECT u.* FROM users u JOIN volunteer_assignments va ON u.id = va.volunteer_id WHERE va.camp_id = $camp_id AND va.status = 'active'");
$volunteers = []; while($row = $volunteers_res->fetch_assoc()) { $volunteers[] = $row; }

$tasks_res = $conn->query("SELECT t.*, u.full_name as assigned_name FROM tasks t LEFT JOIN users u ON t.assigned_to = u.id WHERE t.camp_id = $camp_id ORDER BY t.created_at DESC");
$tasks = []; while($row = $tasks_res->fetch_assoc()) { $tasks[] = $row; }

// Task Stats for the Tasks Page
$task_stats = [
    'total' => $conn->query("SELECT COUNT(*) FROM tasks WHERE camp_id = $camp_id")->fetch_row()[0],
    'pending' => $conn->query("SELECT COUNT(*) FROM tasks WHERE camp_id = $camp_id AND status = 'pending'")->fetch_row()[0],
    'in_progress' => $conn->query("SELECT COUNT(*) FROM tasks WHERE camp_id = $camp_id AND status = 'in_progress'")->fetch_row()[0],
    'completed' => $conn->query("SELECT COUNT(*) FROM tasks WHERE camp_id = $camp_id AND status = 'completed'")->fetch_row()[0],
];


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Camp Manager Dashboard - Relief System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root { --primary: #4f46e5; --primary-light: #eef2ff; --secondary: #64748b; --success: #10b981; --warning: #f59e0b; --danger: #ef4444; --background: #f8fafc; --sidebar-bg: #ffffff; --card-bg: #ffffff; --text-main: #1e293b; --text-muted: #64748b; --border: #e2e8f0; --radius-lg: 16px; --radius-md: 12px; --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05); --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: var(--background); color: var(--text-main); line-height: 1.5; }
        .layout { display: flex; min-height: 100vh; }
        
        /* Sidebar */
        .sidebar { width: 260px; background: var(--sidebar-bg); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh; z-index: 20; }
        .sidebar-header { padding: 2rem 1.5rem; }
        .brand-name { font-size: 1.25rem; font-weight: 800; color: #0f172a; letter-spacing: -0.025em; }
        .brand-sub { font-size: 0.85rem; font-weight: 500; color: var(--text-muted); }
        .menu { list-style: none; padding: 0 0.75rem; flex: 1; }
        .menu-link { display: flex; align-items: center; gap: 12px; padding: 10px 16px; color: var(--text-muted); text-decoration: none; border-radius: var(--radius-md); transition: 0.2s; font-weight: 500; font-size: 0.925rem; margin-bottom: 4px; }
        .menu-link:hover, .menu-link.active { background: var(--primary-light); color: var(--primary); }
        .menu-link.active { font-weight: 600; }
        .menu-badge { margin-left: auto; background: var(--danger); color: white; border-radius: 999px; font-size: 0.7rem; padding: 2px 8px; font-weight: 700; }
        .sidebar-footer { padding: 1.5rem; border-top: 1px solid var(--border); }
        
        /* Main */
        .main { flex: 1; display: flex; flex-direction: column; min-width: 0; }
        .header { background: white; padding: 1.25rem 2rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 10; }
        .header-title { font-size: 1.5rem; font-weight: 700; color: #0f172a; }
        .header-subtitle { font-size: 0.9rem; color: var(--text-muted); font-weight: 500; }
        .content { padding: 2rem; max-width: 1400px; margin: 0 auto; width: 100%; }
        
        /* Components */
        .panel { background: white; border-radius: var(--radius-lg); border: 1px solid var(--border); overflow: hidden; margin-bottom: 1.5rem; box-shadow: var(--shadow-sm); }
        .panel-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .panel-title { font-size: 1.1rem; font-weight: 700; color: #0f172a; }
        .panel-content { padding: 1.5rem; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px; font-size: 0.875rem; font-weight: 600; cursor: pointer; transition: 0.2s; border: 1px solid transparent; text-decoration: none; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-outline { background: white; border-color: var(--border); color: var(--text-main); }
        .btn-sm { padding: 6px 12px; font-size: 0.75rem; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: white; border-radius: var(--radius-lg); padding: 1.5rem; border: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .stat-icon-box { width: 48px; height: 48px; border-radius: 12px; display: grid; place-items: center; }
        
        /* Table & Form */
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { padding: 1rem; background: #f8fafc; font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; border-bottom: 1px solid var(--border); }
        td { padding: 1rem; border-bottom: 1px solid #f1f5f9; font-size: 0.9rem; }
        .badge { padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
        .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 1rem; }
        .form-control { padding: 10px 14px; border-radius: 10px; border: 1px solid var(--border); font-family: inherit; font-size: 0.9rem; background: #f8fafc; }
        
        /* Chart */
        .chart-container { height: 260px; position: relative; padding-top: 20px; }
        .chart-grid { position: absolute; top: 20px; left: 40px; right: 0; bottom: 40px; display: flex; flex-direction: column; justify-content: space-between; }
        .chart-grid-line { width: 100%; border-top: 1px dashed #e2e8f0; position: relative; }
        .chart-grid-line::before { content: attr(data-value); position: absolute; left: -40px; top: -10px; font-size: 0.75rem; color: #94a3b8; font-weight: 600; }
        .chart-bars-area { position: absolute; top: 20px; left: 40px; right: 0; bottom: 40px; display: flex; align-items: flex-end; justify-content: space-around; }
        .chart-bar { width: 36px; background: var(--primary); border-radius: 6px 6px 0 0; position: relative; }
        .chart-bar-labels { position: absolute; left: 40px; right: 0; bottom: 10px; display: flex; justify-content: space-around; }
        .chart-bar-label { flex: 1; text-align: center; font-size: 0.75rem; font-weight: 600; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; padding: 0 5px; }

        /* Overview Specific Styles */
        .overview-grid { display: grid; grid-template-columns: 1fr 320px; gap: 1.5rem; }
        .camp-layout { background: #f1f5f9; border-radius: 12px; height: 400px; display: flex; align-items: center; justify-content: center; position: relative; border: 2px dashed #cbd5e1; }
        .camp-zone { position: absolute; background: white; border: 1px solid var(--border); border-radius: 8px; padding: 10px; box-shadow: var(--shadow-sm); transition: 0.2s; cursor: pointer; }
        .camp-zone:hover { transform: scale(1.05); box-shadow: var(--shadow-md); border-color: var(--primary); }
        .zone-label { font-size: 0.7rem; font-weight: 700; color: var(--text-muted); }
        .zone-value { font-size: 0.9rem; font-weight: 800; color: var(--text-main); }
        
        .resource-card { display: flex; align-items: center; gap: 12px; padding: 12px; background: white; border: 1px solid var(--border); border-radius: 12px; margin-bottom: 12px; }
        .resource-icon { width: 36px; height: 36px; border-radius: 8px; display: grid; place-items: center; }
        .status-dot { width: 8px; height: 8px; border-radius: 50%; margin-left: auto; }
        .dot-success { background: var(--success); box-shadow: 0 0 8px var(--success); }
        .dot-warning { background: var(--warning); box-shadow: 0 0 8px var(--warning); }
        .dot-danger { background: var(--danger); box-shadow: 0 0 8px var(--danger); }

        /* Exact Overview Styles */
        .ov-info-grid { display: grid; grid-template-columns: 1fr 340px; gap: 1.5rem; margin-bottom: 1.5rem; }
        .ov-card { background: white; border-radius: var(--radius-lg); border: 1px solid var(--border); padding: 1.5rem; }
        .ov-info-item { display: flex; align-items: flex-start; gap: 1rem; margin-bottom: 1.5rem; }
        .ov-info-icon { width: 40px; height: 40px; border-radius: 10px; display: grid; place-items: center; background: #f1f5f9; color: var(--primary); }
        .ov-progress-container { margin-top: 2rem; }
        .ov-progress-bar { height: 10px; background: #e2e8f0; border-radius: 5px; overflow: hidden; margin-bottom: 8px; }
        .ov-progress-fill { height: 100%; background: #0f172a; border-radius: 5px; }
        
        .quick-stat-card { border-radius: 12px; padding: 1.25rem; margin-bottom: 1rem; text-align: center; border: 1px solid transparent; }
        .qs-blue { background: #eff6ff; border-color: #dbeafe; color: #1e40af; }
        .qs-green { background: #f0fdf4; border-color: #dcfce7; color: #166534; }
        .qs-purple { background: #faf5ff; border-color: #f3e8ff; color: #6b21a8; }
        .qs-value { font-size: 1.5rem; font-weight: 800; }
        .qs-label { font-size: 0.75rem; font-weight: 600; opacity: 0.8; }

        .facilities-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; }
        .facility-item { background: white; border: 1px solid var(--border); border-radius: 12px; padding: 1.25rem; text-align: center; }
        .facility-name { font-weight: 700; font-size: 0.85rem; color: #1e293b; margin-bottom: 4px; }
        .facility-status { font-size: 0.7rem; font-weight: 700; color: var(--success); }

        /* Affected People Styles */
        .ap-stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; margin-bottom: 2rem; }
        .ap-stat-card { background: white; border-radius: var(--radius-lg); border: 1px solid var(--border); padding: 1.5rem; text-align: center; }
        .ap-stat-label { font-size: 0.8rem; font-weight: 600; color: var(--text-muted); margin-bottom: 8px; }
        .ap-stat-value { font-size: 1.75rem; font-weight: 800; color: #0f172a; }
        
        .ap-table-panel { background: white; border-radius: var(--radius-lg); border: 1px solid var(--border); padding: 1.5rem; }
        .ap-table-header { font-weight: 700; font-size: 1rem; margin-bottom: 1.5rem; color: #1e293b; }
        .status-badge-assigned { background: #f0fdf4; color: #166534; border: 1px solid #dcfce7; }
        
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal.show { display: flex; }
        .modal-content { background: white; padding: 2rem; border-radius: var(--radius-lg); width: 100%; max-width: 500px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }

        /* Supplies Specific Styles */
        .supplies-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2rem; }
        .supplies-stat-card { background: white; border-radius: 12px; border: 1px solid var(--border); padding: 1.25rem; text-align: center; }
        .supplies-stat-icon { width: 32px; height: 32px; border-radius: 8px; display: grid; place-items: center; margin: 0 auto 10px; }
        
        .low-stock-row { background: #fff1f2 !important; }
        .low-stock-text { color: #e11d48; font-weight: 700; }
        .status-ok { color: #10b981; font-weight: 700; }
        .status-low { color: #e11d48; font-weight: 700; }
        
        .category-badge { padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 600; }
        .cat-food { background: #f0fdf4; color: #16a34a; }
        .cat-medicine { background: #eff6ff; color: #2563eb; }
        .cat-shelter { background: #faf5ff; color: #9333ea; }
        .cat-other { background: #f8fafc; color: #64748b; }

        /* Volunteer Styles */
        .skill-badge { padding: 4px 10px; border-radius: 6px; font-size: 0.7rem; font-weight: 600; background: #eff6ff; color: #2563eb; margin-right: 4px; display: inline-block; margin-bottom: 4px; }
        .task-pill { background: #f0fdf4; color: #16a34a; padding: 4px 10px; border-radius: 999px; font-size: 0.75rem; font-weight: 700; border: 1px solid #dcfce7; }

        @media (max-width: 1024px) { .sidebar { width: 80px; } .brand-container, .menu-link span, .menu-badge { display: none; } .menu-link { justify-content: center; } }
    </style>
</head>
<body>
    <div class="layout">
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="brand-container"><span class="brand-name">Relief System</span><br><span class="brand-sub">Camp Manager</span></div>
            </div>
            <nav class="menu">
                <a href="?page=dashboard" class="menu-link <?php echo $page === 'dashboard' ? 'active' : ''; ?>"><i data-lucide="layout-dashboard"></i> <span>Dashboard</span></a>
                <a href="?page=overview" class="menu-link <?php echo $page === 'overview' ? 'active' : ''; ?>"><i data-lucide="map-pin"></i> <span>Camp Overview</span></a>
                <a href="?page=families" class="menu-link <?php echo $page === 'families' ? 'active' : ''; ?>"><i data-lucide="users"></i> <span>Affected People</span></a>
                <a href="?page=inventory" class="menu-link <?php echo $page === 'inventory' ? 'active' : ''; ?>"><i data-lucide="package"></i> <span>Supplies</span></a>
                <a href="?page=volunteers" class="menu-link <?php echo $page === 'volunteers' ? 'active' : ''; ?>"><i data-lucide="user-check"></i> <span>Volunteers</span></a>
                <a href="?page=tasks" class="menu-link <?php echo $page === 'tasks' ? 'active' : ''; ?>"><i data-lucide="clipboard-list"></i> <span>Tasks</span></a>
                <a href="?page=distribution" class="menu-link <?php echo $page === 'distribution' ? 'active' : ''; ?>"><i data-lucide="truck"></i> <span>Aid Distribution</span></a>

                <a href="?page=report" class="menu-link <?php echo $page === 'report' ? 'active' : ''; ?>"><i data-lucide="file-text"></i> <span>Reports</span></a>
                <a href="?page=chat" class="menu-link <?php echo $page === 'chat' ? 'active' : ''; ?>"><i data-lucide="message-square"></i> <span>Messages</span></a>
                <a href="?page=settings" class="menu-link <?php echo $page === 'settings' ? 'active' : ''; ?>"><i data-lucide="settings"></i> <span>Settings</span></a>
            </nav>
            <div class="sidebar-footer">
                <div style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 12px; cursor: pointer;" onclick="location.href='logout.php'">
                    <div style="width: 32px; height: 32px; border-radius: 8px; background: var(--primary); color: white; display: grid; place-items: center; font-weight: 700;"><?php echo strtoupper(substr($user['full_name'], 0, 1)); ?></div>
                    <div style="flex: 1; overflow: hidden;"><p style="font-size: 0.8rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo htmlspecialchars($user['full_name']); ?></p></div>
                    <i data-lucide="log-out" style="width: 14px; color: var(--danger);"></i>
                </div>
            </div>
        </aside>

        <main class="main">
            <header class="header">
                <div><h1 class="header-title"><?php echo ucfirst(str_replace('_', ' ', $page)); ?></h1><span class="header-subtitle"><?php 
                    if($page === 'families') echo 'Register and manage camp residents';
                    elseif($page === 'volunteers') echo 'Volunteers assigned to this camp';
                    elseif($page === 'inventory') echo 'Manage camp inventory';
                    else echo 'Managing: ' . htmlspecialchars($camp_name); 
                ?></span></div>
                <div class="header-actions">
                    <?php if ($page === 'families'): ?>
                        <button class="btn btn-primary" style="background: #0f172a;" onclick="toggleModal('registerModal')"><i data-lucide="plus" style="width:18px;"></i> Register Family</button>
                    <?php elseif ($page === 'volunteers'): ?>
                        <button class="btn btn-primary" style="background: #0f172a;" onclick="toggleModal('addVolunteerModal')"><i data-lucide="user-plus" style="width:18px;"></i> Add Volunteer</button>
                    <?php elseif ($page === 'tasks'): ?>
                        <button class="btn btn-primary" style="background: #0f172a;" onclick="toggleModal('addTaskModal')"><i data-lucide="plus" style="width:18px;"></i> Create Task</button>

                    <?php else: ?>
                        <button class="btn btn-outline" onclick="location.reload()"><i data-lucide="refresh-cw" style="width:18px;"></i></button>
                        <button class="btn btn-primary" onclick="location.href='?page=report'"><i data-lucide="file-text" style="width:18px;"></i> Report</button>
                    <?php endif; ?>
                </div>
            </header>

            <div class="content">
                <?php if ($success_msg): ?><div style="background: #dcfce7; color: #166534; padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; border: 1px solid #bbf7d0;">✅ <?php echo $success_msg; ?></div><?php endif; ?>
                <?php if ($error_msg): ?><div style="background: #fef2f2; color: #991b1b; padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; border: 1px solid #fecaca;">❌ <?php echo $error_msg; ?></div><?php endif; ?>

                <?php if ($page === 'dashboard'): ?>
                    <div class="stats-grid">
                        <?php foreach ($stats as $stat): ?>
                            <div class="stat-card">
                                <div><p style="color: var(--text-muted); font-size: 0.85rem; font-weight: 600;"><?php echo $stat['label']; ?></p><p style="font-size: 1.75rem; font-weight: 800;"><?php echo $stat['value']; ?> <span style="font-size: 0.8rem; color: #94a3b8; font-weight: 500;"><?php echo $stat['meta']; ?></span></p></div>
                                <div class="stat-icon-box" style="background: <?php echo $stat['color']; ?>15; color: <?php echo $stat['color']; ?>;"><i data-lucide="<?php echo $stat['icon']; ?>"></i></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display: grid; grid-template-columns: 1.6fr 1fr; gap: 1.5rem;">
                        <div class="panel">
                            <div class="panel-header"><h3 class="panel-title">Supply Levels</h3><button class="btn btn-outline btn-sm" onclick="location.href='?page=inventory'">Details</button></div>
                            <div class="panel-content"><div class="chart-container"><div class="chart-grid"><?php $max_qty = 0; foreach($chart_data as $item) if($item['quantity'] > $max_qty) $max_qty = $item['quantity']; if($max_qty == 0) $max_qty = 100; foreach([1, 0.75, 0.5, 0.25, 0] as $s): ?><div class="chart-grid-line" data-value="<?php echo round($max_qty * $s); ?>"></div><?php endforeach; ?></div><div class="chart-bars-area"><?php foreach ($chart_data as $item): $h = ($item['quantity'] / $max_qty) * 100; ?><div class="chart-bar" style="height: <?php echo $h; ?>%;"></div><?php endforeach; ?></div><div class="chart-bar-labels"><?php foreach ($chart_data as $item): ?><div class="chart-bar-label"><?php echo htmlspecialchars($item['item_name']); ?></div><?php endforeach; ?></div></div></div>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-header"><h3 class="panel-title">Registered Families</h3><button class="btn btn-outline btn-sm" onclick="location.href='?page=families'">View All</button></div>
                        <div class="table-container"><table><thead><tr><th>Name</th><th>Members</th><th>Village</th><th>Needs</th><th>Date</th></tr></thead><tbody><?php foreach (array_slice($families, 0, 5) as $f): ?><tr><td><span style="font-weight: 600;"><?php echo htmlspecialchars($f['head_name']); ?></span></td><td><span class="badge" style="background:#f1f5f9;"><?php echo $f['family_members']; ?></span></td><td><?php echo htmlspecialchars($f['village']); ?></td><td><?php echo htmlspecialchars(substr($f['needs'], 0, 40)); ?>...</td><td style="color: #94a3b8;"><?php echo date('M d', strtotime($f['created_at'])); ?></td></tr><?php endforeach; ?></tbody></table></div>
                    </div>

                <?php elseif ($page === 'overview'): ?>
                    <?php
                    $occupancy_rate = ($camp['capacity'] > 0) ? round(($camp['current_occupancy'] / $camp['capacity']) * 100, 1) : 0;
                    $available_spaces = ($camp['capacity'] ?? 500) - ($camp['current_occupancy'] ?? 0);
                    ?>
                    <div class="ov-info-grid">
                        <div class="ov-card">
                            <div style="font-weight: 700; font-size: 1rem; margin-bottom: 2rem;">Camp Information</div>
                            
                            <div class="ov-info-item">
                                <div class="ov-info-icon"><i data-lucide="map-pin"></i></div>
                                <div><p style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600;">Location</p><p style="font-weight: 700;"><?php echo htmlspecialchars($camp_location); ?></p></div>
                            </div>
                            
                            <div class="ov-info-item">
                                <div class="ov-info-icon" style="color: #f59e0b;"><i data-lucide="alert-triangle"></i></div>
                                <div><p style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600;">Disaster Type</p><p style="font-weight: 700;">Flood</p></div>
                            </div>
                            
                            <div class="ov-info-item">
                                <div class="ov-info-icon" style="color: #10b981;"><i data-lucide="users"></i></div>
                                <div><p style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600;">Capacity</p><p style="font-weight: 700;"><?php echo ($camp['current_occupancy'] ?? 0); ?> / <?php echo ($camp['capacity'] ?? 500); ?> people</p></div>
                            </div>
                            
                            <div class="ov-progress-container">
                                <div style="display: flex; justify-content: space-between; margin-bottom: 8px;"><span style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted);">Occupancy Rate</span><span style="font-size: 0.8rem; font-weight: 700;"><?php echo $occupancy_rate; ?>%</span></div>
                                <div class="ov-progress-bar"><div class="ov-progress-fill" style="width: <?php echo $occupancy_rate; ?>%;"></div></div>
                            </div>
                            
                            <div style="margin-top: 1.5rem;"><span class="badge" style="background: #ecfdf5; color: #166534; padding: 6px 14px; font-size: 0.75rem;">ACTIVE</span></div>
                        </div>
                        
                        <div>
                            <div class="ov-card" style="height: 100%;">
                                <div style="font-weight: 700; font-size: 1rem; margin-bottom: 1.5rem;">Quick Stats</div>
                                
                                <div class="quick-stat-card qs-blue">
                                    <div class="qs-value"><?php echo ($camp['current_occupancy'] ?? 0); ?></div>
                                    <div class="qs-label">Current Residents</div>
                                </div>
                                
                                <div class="quick-stat-card qs-green">
                                    <div class="qs-value"><?php echo $available_spaces; ?></div>
                                    <div class="qs-label">Available Spaces</div>
                                </div>
                                
                                <div class="quick-stat-card qs-purple">
                                    <div class="qs-value">24/7</div>
                                    <div class="qs-label">Operation Hours</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="ov-card">
                        <div style="font-weight: 700; font-size: 1rem; margin-bottom: 1.5rem;">Facilities & Amenities</div>
                        <div class="facilities-grid">
                            <?php 
                            $facilities = ['Medical Unit', 'Food Distribution', 'Shelter Area', 'Sanitation', 'Children Area', 'Counseling', 'Supply Storage', 'Security'];
                            foreach($facilities as $f): ?>
                                <div class="facility-item">
                                    <div class="facility-name"><?php echo $f; ?></div>
                                    <div class="facility-status">Available</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <?php elseif ($page === 'families'): ?>
                    <?php
                    $total_reg = $conn->query("SELECT COUNT(*) FROM families WHERE camp_id = $camp_id")->fetch_row()[0];
                    $total_ind = $conn->query("SELECT SUM(family_members) FROM families WHERE camp_id = $camp_id")->fetch_row()[0] ?? 0;
                    $new_week = $conn->query("SELECT COUNT(*) FROM families WHERE camp_id = $camp_id AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_row()[0];
                    ?>
                    <div class="ap-stats-grid">
                        <div class="ap-stat-card">
                            <p class="ap-stat-label">Total Registered</p>
                            <p class="ap-stat-value"><?php echo $total_reg; ?></p>
                        </div>
                        <div class="ap-stat-card">
                            <p class="ap-stat-label">Total Individuals</p>
                            <p class="ap-stat-value" style="color: #2563eb;"><?php echo $total_ind; ?></p>
                        </div>
                        <div class="ap-stat-card">
                            <p class="ap-stat-label">New This Week</p>
                            <p class="ap-stat-value" style="color: #10b981;"><?php echo $new_week; ?></p>
                        </div>
                    </div>

                    <div class="ap-table-panel">
                        <div class="ap-table-header">Registered Families & Individuals</div>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Family Members</th>
                                        <th>Needs</th>
                                        <th>Registered Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($families)): ?>
                                        <tr><td colspan="5" style="text-align:center; padding: 3rem; color: var(--text-muted);">No residents registered yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($families as $f): ?>
                                            <tr>
                                                <td><span style="font-weight: 600;"><?php echo htmlspecialchars($f['head_name']); ?></span></td>
                                                <td><?php echo $f['family_members']; ?></td>
                                                <td><span style="color: var(--text-muted);"><?php echo htmlspecialchars($f['needs']); ?></span></td>
                                                <td><?php echo date('Y-m-d', strtotime($f['created_at'])); ?></td>
                                                <td><span class="badge status-badge-assigned">assigned</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Registration Modal -->
                    <div id="registerModal" class="modal">
                        <div class="modal-content">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                                <h3 style="font-size: 1.25rem; font-weight: 700;">Register New Family</h3>
                                <button onclick="toggleModal('registerModal')" style="background: none; border: none; cursor: pointer; color: var(--text-muted);"><i data-lucide="x"></i></button>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="register_family">
                                <div class="form-group"><label>Head of Family</label><input type="text" name="head_name" class="form-control" placeholder="Full name" required></div>
                                <div class="form-group"><label>Family Members</label><input type="number" name="family_members" class="form-control" value="1" min="1" required></div>
                                <div class="form-group"><label>Village / District</label><input type="text" name="village" class="form-control" placeholder="Area of origin"></div>
                                <div class="form-group"><label>Special Needs</label><textarea name="needs" class="form-control" style="min-height: 80px;" placeholder="e.g. Medical, Food, Clothing"></textarea></div>
                                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; background: #0f172a;">Register Resident</button>
                            </form>
                        </div>
                    </div>

                <?php elseif ($page === 'inventory'): ?>
                    <?php
                    $total_items = $conn->query("SELECT COUNT(*) FROM inventory WHERE camp_id = $camp_id")->fetch_row()[0];
                    $low_stock = $conn->query("SELECT COUNT(*) FROM inventory WHERE camp_id = $camp_id AND quantity <= min_threshold")->fetch_row()[0];
                    $food_items = $conn->query("SELECT COUNT(*) FROM inventory WHERE camp_id = $camp_id AND category LIKE '%food%'")->fetch_row()[0];
                    $med_items = $conn->query("SELECT COUNT(*) FROM inventory WHERE camp_id = $camp_id AND category LIKE '%medicine%'")->fetch_row()[0];
                    ?>
                    <div class="supplies-stats">
                        <div class="supplies-stat-card">
                            <div class="supplies-stat-icon" style="background: #eff6ff; color: #2563eb;"><i data-lucide="package" style="width:18px;"></i></div>
                            <p style="font-size: 1.5rem; font-weight: 800;"><?php echo $total_items; ?></p>
                            <p style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600;">Total Items</p>
                        </div>
                        <div class="supplies-stat-card">
                            <div class="supplies-stat-icon" style="background: #fef2f2; color: #dc2626;"><i data-lucide="alert-triangle" style="width:18px;"></i></div>
                            <p style="font-size: 1.5rem; font-weight: 800;"><?php echo $low_stock; ?></p>
                            <p style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600;">Low Stock</p>
                        </div>
                        <div class="supplies-stat-card">
                            <div class="supplies-stat-icon" style="background: #f0fdf4; color: #16a34a;"><i data-lucide="utensils" style="width:18px;"></i></div>
                            <p style="font-size: 1.5rem; font-weight: 800;"><?php echo $food_items; ?></p>
                            <p style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600;">Food Items</p>
                        </div>
                        <div class="supplies-stat-card">
                            <div class="supplies-stat-icon" style="background: #faf5ff; color: #9333ea;"><i data-lucide="heart-pulse" style="width:18px;"></i></div>
                            <p style="font-size: 1.5rem; font-weight: 800;"><?php echo $med_items; ?></p>
                            <p style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600;">Medical Items</p>
                        </div>
                    </div>

                    <div class="ap-table-panel">
                        <div class="ap-table-header">Supply Inventory</div>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Item Name</th>
                                        <th>Category</th>
                                        <th>Quantity</th>
                                        <th>Min Threshold</th>
                                        <th>Status</th>
                                        <th>Last Updated</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inventory as $item): 
                                        $is_low = $item['quantity'] <= $item['min_threshold'];
                                        $cat_class = 'cat-other';
                                        if(stripos($item['category'], 'food') !== false) $cat_class = 'cat-food';
                                        elseif(stripos($item['category'], 'medicine') !== false || stripos($item['category'], 'medical') !== false) $cat_class = 'cat-medicine';
                                        elseif(stripos($item['category'], 'shelter') !== false) $cat_class = 'cat-shelter';
                                    ?>
                                        <tr class="<?php echo $is_low ? 'low-stock-row' : ''; ?>">
                                            <td>
                                                <div style="display: flex; align-items: center; gap: 8px;">
                                                    <?php if($is_low): ?><i data-lucide="alert-triangle" style="width:14px; color: #dc2626;"></i><?php endif; ?>
                                                    <span style="font-weight: 600;"><?php echo htmlspecialchars($item['item_name']); ?></span>
                                                </div>
                                            </td>
                                            <td><span class="category-badge <?php echo $cat_class; ?>"><?php echo htmlspecialchars($item['category']); ?></span></td>
                                            <td><span class="<?php echo $is_low ? 'low-stock-text' : ''; ?>"><?php echo $item['quantity'] . ' ' . $item['unit']; ?></span></td>
                                            <td><?php echo $item['min_threshold'] . ' ' . $item['unit']; ?></td>
                                            <td><span class="<?php echo $is_low ? 'status-low' : 'status-ok'; ?>"><?php echo $is_low ? 'Low Stock' : 'OK'; ?></span></td>
                                            <td style="color: var(--text-muted); font-size: 0.85rem;"><?php echo date('Y-m-d', strtotime($item['updated_at'] ?? $item['created_at'])); ?></td>
                                            <td><button class="btn btn-outline btn-sm" onclick="openUpdateModal(<?php echo htmlspecialchars(json_encode($item)); ?>)">Update</button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div style="margin-top: 1.5rem; text-align: right;">
                            <button class="btn btn-primary" onclick="toggleModal('addItemModal')">+ Add New Item</button>
                        </div>
                    </div>

                    <!-- Update Inventory Modal -->
                    <div id="updateInventoryModal" class="modal">
                        <div class="modal-content">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                                <h3 id="updateItemName" style="font-size: 1.25rem; font-weight: 700;">Update Item</h3>
                                <button onclick="toggleModal('updateInventoryModal')" style="background: none; border: none; cursor: pointer;"><i data-lucide="x"></i></button>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="update_inventory_detailed">
                                <input type="hidden" name="item_id" id="updateItemId">
                                <div class="form-group"><label>Quantity</label><input type="number" name="quantity" id="updateItemQty" class="form-control" step="0.01" required></div>
                                <div class="form-group"><label>Min Threshold</label><input type="number" name="min_threshold" id="updateItemThreshold" class="form-control" step="0.01" required></div>
                                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px;">Save Changes</button>
                            </form>
                        </div>
                    </div>

                    <!-- Add Item Modal -->
                    <div id="addItemModal" class="modal">
                        <div class="modal-content">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                                <h3 style="font-size: 1.25rem; font-weight: 700;">Add New Supply Item</h3>
                                <button onclick="toggleModal('addItemModal')" style="background: none; border: none; cursor: pointer;"><i data-lucide="x"></i></button>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="add_inventory">
                                <div class="form-group"><label>Item Name</label><input type="text" name="item_name" class="form-control" required></div>
                                <div class="form-group"><label>Category</label><select name="category" class="form-control"><option value="Food">Food</option><option value="Medicine">Medicine</option><option value="Shelter">Shelter</option><option value="Other">Other</option></select></div>
                                <div class="form-group"><label>Initial Qty</label><input type="number" name="quantity" class="form-control" step="0.01" required></div>
                                <div class="form-group"><label>Unit</label><input type="text" name="unit" class="form-control" placeholder="e.g. kg, cases, boxes" required></div>
                                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px;">Add Item</button>
                            </form>
                        </div>
                    </div>

                <?php elseif ($page === 'volunteers'): ?>
                    <div class="ap-table-panel">
                        <div class="ap-table-header">Active Volunteers</div>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Skills</th>
                                        <th>Availability</th>
                                        <th>Assigned Tasks</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($volunteers as $vol): 
                                        $v_id = $vol['id'];
                                        $t_count = $conn->query("SELECT COUNT(*) FROM tasks WHERE assigned_to = $v_id AND status != 'completed'")->fetch_row()[0];
                                        $skills_arr = explode(',', $vol['skills'] ?? '');
                                    ?>
                                        <tr>
                                            <td><span style="font-weight: 700;"><?php echo htmlspecialchars($vol['full_name']); ?></span></td>
                                            <td style="color: var(--text-muted); font-size: 0.85rem;"><?php echo htmlspecialchars($vol['email']); ?></td>
                                            <td style="color: var(--text-muted); font-size: 0.85rem;"><?php echo htmlspecialchars($vol['phone'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php foreach($skills_arr as $s): if(trim($s)): ?>
                                                    <span class="skill-badge"><?php echo htmlspecialchars(trim($s)); ?></span>
                                                <?php endif; endforeach; ?>
                                            </td>
                                            <td style="font-size: 0.85rem; font-weight: 500;"><?php echo htmlspecialchars($vol['availability'] ?? 'Full-time'); ?></td>
                                            <td><span class="task-pill"><?php echo $t_count; ?> tasks</span></td>
                                            <td>
                                                <button class="btn btn-outline btn-sm" style="color: #4f46e5; border-color: #e0e7ff; gap: 4px;" onclick="location.href='?page=tasks&vol=<?php echo $v_id; ?>'">
                                                    <i data-lucide="clipboard-list" style="width:14px;"></i> Assign Task
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Add Volunteer Modal -->
                    <div id="addVolunteerModal" class="modal">
                        <div class="modal-content">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                                <h3 style="font-size: 1.25rem; font-weight: 700;">Add New Volunteer</h3>
                                <button onclick="toggleModal('addVolunteerModal')" style="background: none; border: none; cursor: pointer;"><i data-lucide="x"></i></button>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="add_volunteer">
                                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" class="form-control" placeholder="Sarah Johnson" required></div>
                                <div class="form-group"><label>Email</label><input type="email" name="email" class="form-control" placeholder="sarah@example.com" required></div>
                                <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control" placeholder="555-0101"></div>
                                <div class="form-group"><label>Skills (Comma separated)</label><input type="text" name="skills" class="form-control" placeholder="Medical, First Aid, Logistics"></div>
                                <div class="form-group"><label>Availability</label><input type="text" name="availability" class="form-control" placeholder="Full-time, Weekdays"></div>
                                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; background: #0f172a;">Register Volunteer</button>
                                <p style="font-size: 0.7rem; color: var(--text-muted); margin-top: 10px; text-align: center;">* Default password will be 'volunteer123'</p>
                            </form>
                        </div>
                    </div>

                <?php elseif ($page === 'tasks'): ?>
                    <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr);">
                        <div class="stat-card">
                            <div><p style="color: var(--text-muted); font-size: 0.85rem; font-weight: 600;">Total Tasks</p><p style="font-size: 1.75rem; font-weight: 800; text-align: center;"><?php echo $task_stats['total']; ?></p></div>
                        </div>
                        <div class="stat-card">
                            <div><p style="color: var(--text-muted); font-size: 0.85rem; font-weight: 600;">In Progress</p><p style="font-size: 1.75rem; font-weight: 800; color: #f97316; text-align: center;"><?php echo $task_stats['in_progress']; ?></p></div>
                        </div>
                        <div class="stat-card">
                            <div><p style="color: var(--text-muted); font-size: 0.85rem; font-weight: 600;">Pending</p><p style="font-size: 1.75rem; font-weight: 800; color: #6366f1; text-align: center;"><?php echo $task_stats['pending']; ?></p></div>
                        </div>
                        <div class="stat-card">
                            <div><p style="color: var(--text-muted); font-size: 0.85rem; font-weight: 600;">Completed</p><p style="font-size: 1.75rem; font-weight: 800; color: #10b981; text-align: center;"><?php echo $task_stats['completed']; ?></p></div>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-header"><h3 class="panel-title">All Tasks</h3></div>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Task</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Due Date</th>
                                        <th>Assigned To</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($tasks)): ?>
                                        <tr><td colspan="5" style="text-align:center; padding: 3rem; color: var(--text-muted);">No tasks found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($tasks as $t): 
                                            $priority_color = '#64748b';
                                            $priority_bg = '#f1f5f9';
                                            if($t['priority'] === 'urgent') { $priority_color = '#e11d48'; $priority_bg = '#fff1f2'; }
                                            elseif($t['priority'] === 'high') { $priority_color = '#f59e0b'; $priority_bg = '#fffbeb'; }
                                            elseif($t['priority'] === 'medium') { $priority_color = '#2563eb'; $priority_bg = '#eff6ff'; }

                                            $status_color = '#64748b';
                                            $status_bg = '#f1f5f9';
                                            if($t['status'] === 'completed') { $status_color = '#10b981'; $status_bg = '#f0fdf4'; }
                                            elseif($t['status'] === 'in_progress') { $status_color = '#6366f1'; $status_bg = '#eef2ff'; }
                                        ?>
                                            <tr>
                                                <td>
                                                    <div style="font-weight: 700; color: #0f172a;"><?php echo htmlspecialchars($t['task_name']); ?></div>
                                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?php echo htmlspecialchars($t['description'] ?? ''); ?></div>
                                                </td>
                                                <td><span class="badge" style="background: <?php echo $priority_bg; ?>; color: <?php echo $priority_color; ?>; text-transform: capitalize;"><?php echo $t['priority']; ?></span></td>
                                                <td><span class="badge" style="background: <?php echo $status_bg; ?>; color: <?php echo $status_color; ?>; text-transform: capitalize;"><?php echo str_replace('_', ' ', $t['status']); ?></span></td>
                                                <td style="font-size: 0.85rem; font-weight: 500; color: #64748b;"><?php echo $t['due_date'] ? date('Y-m-d', strtotime($t['due_date'])) : 'No date'; ?></td>
                                                <td><span style="font-weight: 600; font-size: 0.85rem;"><?php echo $t['assigned_name'] ? htmlspecialchars($t['assigned_name']) : '<span style="color:#94a3b8">Unassigned</span>'; ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Create Task Modal -->
                    <div id="addTaskModal" class="modal">
                        <div class="modal-content">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                                <h3 style="font-size: 1.25rem; font-weight: 700;">Create New Task</h3>
                                <button onclick="toggleModal('addTaskModal')" style="background: none; border: none; cursor: pointer; color: var(--text-muted);"><i data-lucide="x"></i></button>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="assign_task">
                                <div class="form-group"><label>Task Title</label><input type="text" name="task_name" class="form-control" placeholder="e.g. Distribute meal packets" required></div>
                                <div class="form-group"><label>Description</label><textarea name="description" class="form-control" style="min-height: 80px;" placeholder="Detailed instructions..."></textarea></div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label>Priority</label>
                                        <select name="priority" class="form-control">
                                            <option value="low">Low</option>
                                            <option value="medium" selected>Medium</option>
                                            <option value="high">High</option>
                                            <option value="urgent">Urgent</option>
                                        </select>
                                    </div>
                                    <div class="form-group"><label>Due Date</label><input type="date" name="due_date" class="form-control" required></div>
                                </div>
                                <div class="form-group">
                                    <label>Assign To Volunteer</label>
                                    <select name="volunteer_id" class="form-control" required>
                                        <option value="0">Unassigned</option>
                                        <?php foreach ($volunteers as $vol): ?>
                                            <option value="<?php echo $vol['id']; ?>"><?php echo htmlspecialchars($vol['full_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; background: #0f172a; margin-top: 1rem;">Create Task</button>
                            </form>
                        </div>
                    </div>

                <?php elseif ($page === 'distribution'): ?>
                    <div class="panel" style="padding: 4rem; text-align: center; border-style: dashed; background: transparent; opacity: 0.5;">
                        <i data-lucide="truck" style="width: 48px; color: var(--text-muted); margin-bottom: 1rem;"></i>
                        <h3>Aid Distribution</h3>
                        <p style="color: var(--text-muted);">This module is currently empty and will be updated later.</p>
                    </div>

                <?php elseif ($page === 'report'): ?>


                    <div class="panel" style="padding: 4rem; text-align: center; border-style: dashed; background: transparent; opacity: 0.5;">
                        <i data-lucide="file-text" style="width: 48px; color: var(--text-muted); margin-bottom: 1rem;"></i>
                        <h3>Reports</h3>
                    </div>

                <?php elseif ($page === 'messages'): ?>
                    <div class="panel" style="padding: 4rem; text-align: center; border-style: dashed; background: transparent; opacity: 0.5;">
                        <i data-lucide="message-square" style="width: 48px; color: var(--text-muted); margin-bottom: 1rem;"></i>
                        <h3>Messages</h3>
                    </div>

                <?php elseif ($page === 'settings'): ?>
                    <div class="panel" style="padding: 4rem; text-align: center; border-style: dashed; background: transparent; opacity: 0.5;">
                        <i data-lucide="settings" style="width: 48px; color: var(--text-muted); margin-bottom: 1rem;"></i>
                        <h3>Settings</h3>
                    </div>

                <?php else: ?>
                    <div class="panel" style="padding: 4rem; text-align: center;">
                        <i data-lucide="info" style="width: 48px; color: var(--text-muted); margin-bottom: 1rem;"></i>
                        <h3>Coming Soon</h3>
                        <p style="color: var(--text-muted);">The <strong><?php echo ucfirst($page); ?></strong> module is under development.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script>
        lucide.createIcons();
        function toggleModal(id) {
            const modal = document.getElementById(id);
            if (modal) modal.classList.toggle('show');
        }
        function openUpdateModal(item) {
            document.getElementById('updateItemId').value = item.id;
            document.getElementById('updateItemName').innerText = 'Update: ' + item.item_name;
            document.getElementById('updateItemQty').value = item.quantity;
            document.getElementById('updateItemThreshold').value = item.min_threshold;
            toggleModal('updateInventoryModal');
        }
        window.onclick = function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('show');
            }
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>
