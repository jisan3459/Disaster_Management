<?php
include base_path('../config.php');

if (!isLoggedIn()) {
    php_redirect('/signin');
}

$user_role = $_SESSION['role'] ?? '';
if ($user_role !== 'camp_manager') {
    if ($user_role === 'admin') {
        php_redirect('/admin_dashboard');
    } elseif ($user_role === 'volunteer') {
        php_redirect('/volunteer_dashboard');
    }
    php_redirect('/index');
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

// Fetch the camp managed by this user or matching their location
$user_location = $user['location'] ?? '';
$camp_res = $conn->query("SELECT * FROM camps WHERE manager_id = $user_id LIMIT 1");
if ($camp_res && $camp_res->num_rows === 0 && !empty($user_location)) {
    $camp_res = $conn->query("SELECT * FROM camps WHERE location = '$user_location' LIMIT 1");
}
$camp = $camp_res ? $camp_res->fetch_assoc() : null;
if (!$camp) {
    $camp = [
        'id' => 0,
        'camp_name' => 'Unassigned',
        'location' => $user_location ?: 'Not set',
        'capacity' => 500,
        'current_occupancy' => 0
    ];
}
$camp_id = $camp['id'];
$camp_name = $camp['camp_name'];
$camp_location = $camp['location'];

// Ensure distributions table exists with extended fields
$conn->query("CREATE TABLE IF NOT EXISTS distributions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    camp_id INT,
    recipient_name VARCHAR(255) NOT NULL,
    items TEXT NOT NULL,
    quantity FLOAT DEFAULT 1.0,
    distributed_by INT,
    distributed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (camp_id) REFERENCES camps(id),
    FOREIGN KEY (distributed_by) REFERENCES users(id)
)");

// Migration for existing table
$d_cols = $conn->query("SHOW COLUMNS FROM distributions");
$existing_d_cols = [];
while($row = $d_cols->fetch_assoc()) { $existing_d_cols[] = $row['Field']; }
if (!in_array('quantity', $existing_d_cols)) { $conn->query("ALTER TABLE distributions ADD COLUMN quantity FLOAT DEFAULT 1.0 AFTER items"); }
if (!in_array('distributed_by', $existing_d_cols)) { $conn->query("ALTER TABLE distributions ADD COLUMN distributed_by INT AFTER quantity"); }

// Handle Actions
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'mark_notifications_read') {
            $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id");
            php_redirect("/camp_manager_dashboard?page=" . ($_GET['page'] ?? 'dashboard'));
        }
        
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
            $vol_id = intval($_POST['volunteer_id']);
            $priority = sanitize($_POST['priority']);
            $type = sanitize($_POST['task_type'] ?? 'standard');
            $item = sanitize($_POST['distribution_item'] ?? '');
            $qty = floatval($_POST['distribution_qty'] ?? 0);
            $due_date = sanitize($_POST['due_date'] ?? '');
            
            $insert = $conn->query("INSERT INTO tasks (task_name, camp_id, assigned_to, assigned_by, priority, task_type, distribution_item, distribution_qty, status, due_date) VALUES ('$task_name', $camp_id, $vol_id, $user_id, '$priority', '$type', '$item', $qty, 'pending', '$due_date')");

            if ($insert) { 
                $success_msg = "Task assigned successfully.";
                $conn->query("INSERT INTO notifications (user_id, notification_type, title, message) VALUES ($vol_id, 'task', 'New Task Assigned', 'You have a new task: $task_name')");
            } 
            else { $error_msg = "Failed to assign task: " . $conn->error; }
        }

        if ($action === 'approve_affected') {
            $affected_id = intval($_POST['affected_id']);
            $update = $conn->query("UPDATE affected_persons SET registration_status = 'assigned', camp_id = $camp_id WHERE id = $affected_id");
            if ($update) { $success_msg = "Person approved and assigned to camp successfully."; } 
            else { $error_msg = "Failed to assign person."; }
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

        if ($action === 'send_chat_message') {
            $msg = sanitize($_POST['message'] ?? '');
            if ($msg) {
                $admin_q = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
                $admin_id = ($admin_q && $admin_q->num_rows > 0) ? $admin_q->fetch_assoc()['id'] : 1;
                $conn->query("INSERT INTO messages (sender_id, receiver_id, message_text) VALUES ($user_id, $admin_id, '$msg')");
                php_redirect("/camp_manager_dashboard?page=chat");
            }
        }

        if ($action === 'create_task_from_request') {
            $req_id = intval($_POST['request_id']);
            $vol_id = intval($_POST['volunteer_id']);
            $task_name = sanitize($_POST['task_name']);
            $priority = sanitize($_POST['priority']);
            $desc = sanitize($_POST['description']);
            $type = sanitize($_POST['task_type'] ?? 'standard');
            $item = sanitize($_POST['distribution_item'] ?? '');
            $qty = floatval($_POST['distribution_qty'] ?? 0);
            $due_date = sanitize($_POST['due_date'] ?? '');

            $conn->query("UPDATE affected_help_requests SET status = 'assigned' WHERE id = $req_id");
            $insert = $conn->query("INSERT INTO tasks (task_name, description, camp_id, assigned_to, assigned_by, priority, task_type, distribution_item, distribution_qty, status, due_date) VALUES ('$task_name', '$desc', $camp_id, $vol_id, $user_id, '$priority', '$type', '$item', $qty, 'pending', '$due_date')");

            
            if ($insert) { 
                $success_msg = "Task created and assigned to volunteer.";
                $conn->query("INSERT INTO notifications (user_id, notification_type, title, message) VALUES ($vol_id, 'task', 'New Aid Request Task', 'You have been assigned a distribution task: $task_name')");
            }
            else { $error_msg = "Failed to create task."; }
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

        if ($action === 'assign_volunteer_to_camp') {
            $vol_id = intval($_POST['volunteer_id']);
            $check = $conn->query("SELECT * FROM volunteer_assignments WHERE volunteer_id = $vol_id AND status = 'active'");
            if ($check->num_rows == 0) {
                $insert = $conn->query("INSERT INTO volunteer_assignments (volunteer_id, camp_id, status) VALUES ($vol_id, $camp_id, 'active')");
                if ($insert) { 
                    $success_msg = "Volunteer assigned to camp successfully.";
                    $conn->query("INSERT INTO notifications (user_id, notification_type, title, message) VALUES ($vol_id, 'system', 'Camp Assignment', 'You have been assigned to " . ($camp['camp_name'] ?? 'a new camp') . "')");
                }
                else { $error_msg = "Failed to assign volunteer."; }
            } else {
                $error_msg = "Volunteer is already assigned to a camp.";
            }
        }
        if ($action === 'record_distribution') {
            $recipient = sanitize($_POST['recipient_name']);
            $items = sanitize($_POST['items']);
            $qty = floatval($_POST['quantity']);
            $dist_by = intval($_POST['distributed_by']);
            
            $insert = $conn->query("INSERT INTO distributions (camp_id, recipient_name, items, quantity, distributed_by) VALUES ($camp_id, '$recipient', '$items', $qty, $dist_by)");
            if ($insert) { $success_msg = "Distribution recorded successfully."; }
            else { $error_msg = "Failed to record distribution: " . $conn->error; }
        }
    }
}

// API Endpoint for Camp Manager Updates
if (isset($_GET['api']) && $_GET['api'] === 'check_updates') {
    header('Content-Type: application/json');
    $last_check = $_GET['last_check'] ?? date('Y-m-d H:i:s');
    
    $new_tasks = $conn->query("SELECT COUNT(*) FROM tasks WHERE camp_id = $camp_id AND updated_at > '$last_check'")->fetch_row()[0];
    $new_volunteers = $conn->query("SELECT COUNT(*) FROM users u JOIN volunteer_assignments va ON u.id = va.volunteer_id WHERE va.camp_id = $camp_id AND (va.created_at > '$last_check' OR u.last_login > '$last_check')")->fetch_row()[0];
    $new_distributions = $conn->query("SELECT COUNT(*) FROM distributions WHERE camp_id = $camp_id AND distributed_at > '$last_check'")->fetch_row()[0];
    
    echo json_encode([
        'new_tasks' => intval($new_tasks),
        'new_volunteers' => intval($new_volunteers),
        'new_distributions' => intval($new_distributions),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    exit();
}

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// Fetch Global Data
$stats = [
    ['label' => 'Current Occupancy', 'value' => ($camp['current_occupancy'] ?? 0), 'meta' => 'of ' . ($camp['capacity'] ?? 500), 'icon' => 'users', 'color' => '#6366f1'],
    ['label' => 'Supply Items', 'value' => $conn->query("SELECT COUNT(*) FROM inventory WHERE camp_id = $camp_id")->fetch_row()[0], 'meta' => 'Total items', 'icon' => 'package', 'color' => '#10b981'],
    ['label' => 'Active Tasks', 'value' => $conn->query("SELECT COUNT(*) FROM tasks WHERE camp_id = $camp_id AND status = 'pending'")->fetch_row()[0], 'meta' => 'Pending', 'icon' => 'clipboard-list', 'color' => '#f97316'],
    ['label' => 'Distributions', 'value' => $conn->query("SELECT COUNT(*) FROM distributions WHERE camp_id = $camp_id")->fetch_row()[0], 'meta' => 'Recent', 'icon' => 'trending-up', 'color' => '#8b5cf6'],
];

$unread_query = $conn->query("SELECT COUNT(*) AS count FROM notifications WHERE user_id = $user_id AND is_read = 0");
$unread_count = $unread_query ? $unread_query->fetch_assoc()['count'] : 0;

$unread_chat_query = $conn->query("SELECT COUNT(*) FROM messages WHERE receiver_id = $user_id AND is_read = 0");
$unread_chat = ($unread_chat_query && $unread_chat_query->num_rows > 0) ? $unread_chat_query->fetch_row()[0] : 0;

$all_notifications_query = $conn->query("SELECT * FROM notifications WHERE user_id = $user_id ORDER BY created_at DESC LIMIT 5");
$all_notifications = [];
if ($all_notifications_query) {
    while ($n = $all_notifications_query->fetch_assoc()) {
        $all_notifications[] = $n;
    }
}

// Fetch Recent Data for Dashboard
$chart_inventory_res = $conn->query("SELECT item_name, quantity FROM inventory WHERE camp_id = $camp_id ORDER BY quantity DESC LIMIT 5");
$chart_data = []; while($row = $chart_inventory_res->fetch_assoc()) { $chart_data[] = $row; }

$recent_dist_res = $conn->query("SELECT * FROM distributions WHERE camp_id = $camp_id ORDER BY distributed_at DESC LIMIT 5");
$recent_distributions = []; while($row = $recent_dist_res->fetch_assoc()) { $recent_distributions[] = $row; }

$assigned_affected_res = $conn->query("SELECT * FROM affected_persons WHERE camp_id = $camp_id ORDER BY created_at DESC");
$assigned_affected = []; while($row = $assigned_affected_res->fetch_assoc()) { $assigned_affected[] = $row; }

$pending_affected_res = $conn->query("SELECT * FROM affected_persons WHERE location = '$camp_location' AND registration_status = 'pending' ORDER BY created_at ASC");
$pending_affected = []; while($row = $pending_affected_res->fetch_assoc()) { $pending_affected[] = $row; }

$inventory_res = $conn->query("SELECT * FROM inventory WHERE camp_id = $camp_id ORDER BY item_name ASC");
$inventory = []; while($row = $inventory_res->fetch_assoc()) { $inventory[] = $row; }

// Fetch Volunteers for Management
// Assigned Volunteers to this camp
$assigned_vols_res = $conn->query("SELECT u.* FROM users u JOIN volunteer_assignments va ON u.id = va.volunteer_id WHERE va.camp_id = $camp_id AND va.status = 'active'");
$assigned_volunteers = []; while($row = $assigned_vols_res->fetch_assoc()) { $assigned_volunteers[] = $row; }

// Unassigned Volunteers (Not assigned to ANY camp currently)
$unassigned_vols_res = $conn->query("SELECT * FROM users WHERE role = 'volunteer' AND id NOT IN (SELECT volunteer_id FROM volunteer_assignments WHERE status = 'active')");
$unassigned_volunteers = []; while($row = $unassigned_vols_res->fetch_assoc()) { $unassigned_volunteers[] = $row; }

// For backward compatibility and dropdowns
$volunteers = array_merge($assigned_volunteers, $unassigned_volunteers);

$tasks_res = $conn->query("SELECT t.*, u.full_name as assigned_name FROM tasks t LEFT JOIN users u ON t.assigned_to = u.id WHERE t.camp_id = $camp_id ORDER BY t.created_at DESC");
$tasks = []; while($row = $tasks_res->fetch_assoc()) { $tasks[] = $row; }

$help_requests_res = $conn->query("SELECT ahr.*, ap.full_name as requester_name FROM affected_help_requests ahr JOIN affected_persons ap ON ahr.affected_id = ap.id WHERE ap.camp_id = $camp_id AND ahr.status = 'pending' ORDER BY ahr.created_at ASC");
$help_requests = []; if($help_requests_res) while($row = $help_requests_res->fetch_assoc()) { $help_requests[] = $row; }

// Distribution Specific Data
$all_distributions_res = $conn->query("SELECT d.*, u.full_name as distributor_name FROM distributions d LEFT JOIN users u ON d.distributed_by = u.id WHERE d.camp_id = $camp_id ORDER BY d.distributed_at DESC");
$all_distributions = []; while($row = $all_distributions_res->fetch_assoc()) { $all_distributions[] = $row; }

$dist_stats = [
    'total' => count($all_distributions),
    'this_week' => $conn->query("SELECT COUNT(*) FROM distributions WHERE camp_id = $camp_id AND distributed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_row()[0],
    'today' => $conn->query("SELECT COUNT(*) FROM distributions WHERE camp_id = $camp_id AND DATE(distributed_at) = CURDATE()")->fetch_row()[0]
];

// Fetch Field Reports from Volunteers
$field_reports_res = $conn->query("SELECT er.*, u.full_name as volunteer_name FROM emergency_reports er JOIN users u ON er.reported_by = u.id WHERE er.camp_id = $camp_id ORDER BY er.created_at DESC");
$field_reports = []; while($row = $field_reports_res->fetch_assoc()) { $field_reports[] = $row; }

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
        .modal-content { background: white; padding: 1.8rem; border-radius: var(--radius-lg); width: 90%; max-width: 480px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
        .cm-dropdown.show { display: block !important; }


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
                <a href="?page=distribution" class="menu-link <?php echo $page === 'distribution' ? 'active' : ''; ?>"><i data-lucide="trending-up"></i> <span>Aid Distribution</span></a>
                <a href="?page=report" class="menu-link <?php echo $page === 'report' ? 'active' : ''; ?>"><i data-lucide="file-text"></i> <span>Reports</span></a>
                <a href="?page=chat" class="menu-link <?php echo $page === 'chat' ? 'active' : ''; ?>"><i data-lucide="message-square"></i> <span>Messages</span><?php if($unread_chat > 0): ?><span style="margin-left:auto; background:#ef4444; color:white; border-radius:999px; padding:2px 6px; font-size:0.7rem; font-weight:bold;"><?php echo $unread_chat; ?></span><?php endif; ?></a>
                <a href="?page=settings" class="menu-link <?php echo $page === 'settings' ? 'active' : ''; ?>"><i data-lucide="settings"></i> <span>Settings</span></a>
            </nav>
            <div class="sidebar-footer">
                <div style="display: flex; align-items: center; gap: 10px; padding: 10px; background: #f8fafc; border-radius: 12px; cursor: pointer;" onclick="location.href='/logout'">
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
                <div class="header-actions" style="display: flex; align-items: center; gap: 0.5rem;">
                    <div style="position: relative;">
                        <button class="btn btn-outline" onclick="document.getElementById('cmNotifDropdown').classList.toggle('show')">
                            <i data-lucide="bell" style="width:18px;"></i>
                            <?php if ($unread_count > 0): ?>
                                <span style="position: absolute; top: -5px; right: -5px; background: var(--danger); color: white; width: 18px; height: 18px; border-radius: 50%; font-size: 0.65rem; display: grid; place-items: center; font-weight: bold;"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </button>
                        <div id="cmNotifDropdown" style="display: none; position: absolute; right: 0; top: calc(100% + 10px); width: 320px; background: white; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1); border: 1px solid var(--border); z-index: 50; overflow: hidden;" class="cm-dropdown">
                            <div style="padding: 1rem; border-bottom: 1px solid var(--border); background: var(--background);">
                                <h4 style="margin: 0; font-size: 0.95rem; font-weight: 700;">Notifications</h4>
                            </div>
                            <?php if (empty($all_notifications)): ?>
                                <div style="padding: 1.5rem; text-align: center; color: var(--text-muted); font-size: 0.85rem;">No new notifications</div>
                            <?php else: ?>
                                <div style="max-height: 300px; overflow-y: auto;">
                                    <?php foreach ($all_notifications as $notif): ?>
                                        <div style="padding: 1rem; border-bottom: 1px solid var(--border); background: <?php echo $notif['is_read'] ? '#ffffff' : 'var(--primary-light)'; ?>;">
                                            <div style="font-weight: 700; font-size: 0.85rem; color: var(--text-main);"><?php echo htmlspecialchars($notif['title']); ?></div>
                                            <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.25rem;"><?php echo htmlspecialchars($notif['message']); ?></div>
                                            <div style="font-size: 0.7rem; color: #94a3b8; margin-top: 0.5rem;"><?php echo date('M d, H:i', strtotime($notif['created_at'])); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div style="padding: 0.75rem; text-align: center; border-top: 1px solid var(--border); background: var(--background);">
                                    <form method="POST" style="margin: 0;">
                                        @csrf
                                        <input type="hidden" name="action" value="mark_notifications_read">
                                        <button type="submit" style="background: none; border: none; color: var(--primary); font-size: 0.85rem; font-weight: 700; cursor: pointer;">Mark all as read</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($page === 'families'): ?>
                        <button class="btn btn-primary" style="background: #0f172a;" onclick="toggleModal('registerModal')"><i data-lucide="plus" style="width:18px;"></i> Register Manually</button>
                    <?php elseif ($page === 'volunteers'): ?>
                        <button class="btn btn-primary" style="background: #0f172a;" onclick="toggleModal('addVolunteerModal')"><i data-lucide="user-plus" style="width:18px;"></i> Add Volunteer</button>
                    <?php elseif ($page === 'distribution'): ?>
                        <button class="btn btn-primary" style="background: #0f172a;" onclick="toggleModal('recordDistModal')"><i data-lucide="plus" style="width:18px;"></i> Record Distribution</button>
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
                        <div class="panel">
                            <div class="panel-header"><h3 class="panel-title">Recent Distributions</h3><button class="btn btn-outline btn-sm" onclick="location.href='?page=distribution'">Log New</button></div>
                            <div class="panel-content">
                                <?php foreach (array_slice($recent_distributions, 0, 4) as $dist): ?>
                                    <div style="padding: 1rem; background: #f8fafc; border-radius: 12px; margin-bottom: 10px; display: flex; justify-content: space-between;"><div><p style="font-weight: 700; font-size: 0.9rem;"><?php echo htmlspecialchars($dist['recipient_name']); ?></p><p style="font-size: 0.8rem; color: var(--text-muted);"><?php echo htmlspecialchars($dist['items']); ?></p></div><p style="font-size: 0.75rem; color: #94a3b8; font-weight: 600;"><?php echo date('M d', strtotime($dist['distributed_at'])); ?></p></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <div class="panel">
                        <div class="panel-header"><h3 class="panel-title">Assigned People</h3><button class="btn btn-outline btn-sm" onclick="location.href='?page=families'">View All</button></div>
                        <div class="table-container"><table><thead><tr><th>Name</th><th>Members</th><th>Location</th><th>Needs</th><th>Date</th></tr></thead><tbody><?php foreach (array_slice($assigned_affected, 0, 5) as $f): ?><tr><td><span style="font-weight: 600;"><?php echo htmlspecialchars($f['full_name']); ?></span></td><td><span class="badge" style="background:#f1f5f9;"><?php echo $f['family_members']; ?></span></td><td><?php echo htmlspecialchars($f['location']); ?></td><td><?php echo htmlspecialchars(substr($f['needs'] ?? '', 0, 40)); ?>...</td><td style="color: #94a3b8;"><?php echo date('M d', strtotime($f['created_at'])); ?></td></tr><?php endforeach; ?></tbody></table></div>
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
                    $total_reg = $conn->query("SELECT COUNT(*) FROM affected_persons WHERE camp_id = $camp_id")->fetch_row()[0];
                    $total_ind = $conn->query("SELECT SUM(family_members) FROM affected_persons WHERE camp_id = $camp_id")->fetch_row()[0] ?? 0;
                    $new_week = $conn->query("SELECT COUNT(*) FROM affected_persons WHERE camp_id = $camp_id AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_row()[0];
                    ?>
                    <div class="ap-stats-grid">
                        <div class="ap-stat-card">
                            <p class="ap-stat-label">Total Assigned</p>
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

                    <?php if (count($pending_affected) > 0): ?>
                    <div class="ap-table-panel" style="margin-bottom: 2rem; border-color: #fcd34d; box-shadow: 0 4px 6px -1px rgba(245, 158, 11, 0.1);">
                        <div class="ap-table-header" style="color: #d97706; display: flex; align-items: center; gap: 8px;">
                            <i data-lucide="alert-circle" style="width: 20px;"></i> Pending Requests in <?php echo htmlspecialchars($camp_location); ?>
                        </div>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Family Members</th>
                                        <th>Location</th>
                                        <th>Requested Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_affected as $p): ?>
                                        <tr>
                                            <td><span style="font-weight: 600;"><?php echo htmlspecialchars($p['full_name']); ?></span></td>
                                            <td><?php echo $p['family_members']; ?></td>
                                            <td><?php echo htmlspecialchars($p['location']); ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($p['created_at'])); ?></td>
                                            <td>
                                                <form method="POST" style="display:inline;">
                                                    <input type="hidden" name="action" value="approve_affected">
                                                    <input type="hidden" name="affected_id" value="<?php echo $p['id']; ?>">
                                                    <button type="submit" class="btn btn-primary btn-sm" style="background: #10b981; border:none;">Approve & Assign</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="ap-table-panel">
                        <div class="ap-table-header">Assigned People & Families</div>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Family Members</th>
                                        <th>Location</th>
                                        <th>Needs</th>
                                        <th>Registered Date</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($assigned_affected)): ?>
                                        <tr><td colspan="6" style="text-align:center; padding: 3rem; color: var(--text-muted);">No assigned residents yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($assigned_affected as $f): ?>
                                            <tr>
                                                <td><span style="font-weight: 600;"><?php echo htmlspecialchars($f['full_name']); ?></span></td>
                                                <td><?php echo $f['family_members']; ?></td>
                                                <td><?php echo htmlspecialchars($f['location']); ?></td>
                                                <td><span style="color: var(--text-muted);"><?php echo htmlspecialchars($f['needs'] ?? 'N/A'); ?></span></td>
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
                    <div style="display: grid; gap: 2rem;">
                        <!-- Assigned Volunteers -->
                        <div class="ap-table-panel">
                            <div class="ap-table-header" style="background: #eff6ff; color: #1e40af; border-bottom: 1px solid #dbeafe;">
                                <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                                    <span>Team Members (Assigned to this Camp)</span>
                                    <span style="font-size: 0.75rem; background: #1e40af; color: white; padding: 2px 8px; border-radius: 999px;"><?php echo count($assigned_volunteers); ?></span>
                                </div>
                            </div>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Assigned Tasks</th>
                                            <th>Last Active</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($assigned_volunteers)): ?>
                                            <tr><td colspan="5" style="text-align:center; padding: 2rem; color: var(--text-muted);">No volunteers assigned to this camp.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($assigned_volunteers as $vol): 
                                                $v_id = $vol['id'];
                                                $t_count = $conn->query("SELECT COUNT(*) FROM tasks WHERE assigned_to = $v_id AND status != 'completed'")->fetch_row()[0];
                                            ?>
                                                <tr>
                                                    <td><span style="font-weight: 700;"><?php echo htmlspecialchars($vol['full_name']); ?></span></td>
                                                    <td style="color: var(--text-muted); font-size: 0.85rem;"><?php echo htmlspecialchars($vol['email']); ?></td>
                                                    <td><span class="task-pill"><?php echo $t_count; ?> active tasks</span></td>
                                                    <td style="font-size: 0.85rem; color: var(--text-muted);"><?php echo $vol['last_login'] ? date('M j, H:i', strtotime($vol['last_login'])) : 'Never'; ?></td>
                                                    <td>
                                                        <button class="btn btn-outline btn-sm" onclick="location.href='?page=tasks&vol=<?php echo $v_id; ?>'">Manage Tasks</button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Unassigned Volunteers -->
                        <div class="ap-table-panel">
                            <div class="ap-table-header" style="background: #f8fafc; color: #475569; border-bottom: 1px solid #e2e8f0;">
                                <div style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                                    <span>Available Volunteers (Unassigned)</span>
                                    <span style="font-size: 0.75rem; background: #64748b; color: white; padding: 2px 8px; border-radius: 999px;"><?php echo count($unassigned_volunteers); ?></span>
                                </div>
                            </div>
                            <div class="table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Location</th>
                                            <th>Joined</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($unassigned_volunteers)): ?>
                                            <tr><td colspan="5" style="text-align:center; padding: 2rem; color: var(--text-muted);">No unassigned volunteers found.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($unassigned_volunteers as $vol): ?>
                                                <tr>
                                                    <td><span style="font-weight: 700;"><?php echo htmlspecialchars($vol['full_name']); ?></span></td>
                                                    <td style="color: var(--text-muted); font-size: 0.85rem;"><?php echo htmlspecialchars($vol['email']); ?></td>
                                                    <td style="font-size: 0.85rem;"><?php echo htmlspecialchars($vol['location'] ?? 'Not set'); ?></td>
                                                    <td style="font-size: 0.85rem; color: var(--text-muted);"><?php echo date('M j, Y', strtotime($vol['created_at'])); ?></td>
                                                    <td>
                                                        <form method="POST" style="margin: 0;">
                                                            <input type="hidden" name="action" value="assign_volunteer_to_camp">
                                                            <input type="hidden" name="volunteer_id" value="<?php echo $vol['id']; ?>">
                                                            <button type="submit" class="btn btn-primary btn-sm" style="background: #4f46e5;">Assign to Camp</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
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
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                        <h2 style="font-size: 1.5rem; font-weight: 700;">Task Management</h2>
                        <button class="btn btn-primary" onclick="toggleModal('addTaskModal')"><i data-lucide="plus"></i> New Manual Task</button>
                    </div>

                    <?php if (count($help_requests) > 0): ?>
                    <div class="ap-table-panel" style="margin-bottom: 2rem; border-left: 4px solid var(--warning);">
                        <div class="ap-table-header" style="color: var(--warning); display: flex; align-items: center; gap: 8px;">
                            <i data-lucide="alert-circle" style="width: 20px;"></i> Incoming Aid Requests
                        </div>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Requester</th>
                                        <th>Category</th>
                                        <th>Urgency</th>
                                        <th>Description</th>
                                        <th>Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($help_requests as $req): ?>
                                        <tr>
                                            <td><span style="font-weight: 600;"><?php echo htmlspecialchars($req['requester_name']); ?></span></td>
                                            <td><span class="badge" style="background: #f1f5f9;"><?php echo htmlspecialchars($req['category']); ?></span></td>
                                            <td>
                                                <span class="badge" style="background: <?php echo $req['urgency'] === 'High' ? '#fef2f2; color: #dc2626;' : '#fff7ed; color: #ea580c;'; ?>">
                                                    <?php echo htmlspecialchars($req['urgency']); ?>
                                                </span>
                                            </td>
                                            <td style="max-width: 300px;"><?php echo htmlspecialchars($req['description']); ?></td>
                                            <td style="font-size: 0.85rem; color: var(--text-muted);"><?php echo date('M d, H:i', strtotime($req['created_at'])); ?></td>
                                            <td>
                                                <button class="btn btn-primary btn-sm" onclick="openAssignModal(<?php echo htmlspecialchars(json_encode($req)); ?>)">Assign to Volunteer</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="ap-table-panel">
                        <div class="ap-table-header">Ongoing & Recent Tasks</div>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Task Name</th>
                                        <th>Assigned To</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Created At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($tasks)): ?>
                                        <tr><td colspan="5" style="text-align:center; padding: 3rem; color: var(--text-muted);">No tasks found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($tasks as $t): ?>
                                            <tr>
                                                <td><span style="font-weight: 600;"><?php echo htmlspecialchars($t['task_name']); ?></span></td>
                                                <td><?php echo htmlspecialchars($t['assigned_name'] ?? 'Unassigned'); ?></td>
                                                <td><span class="badge" style="background: #f1f5f9;"><?php echo ucfirst($t['priority']); ?></span></td>
                                                <td>
                                                    <span class="badge" style="background: <?php 
                                                        echo $t['status'] === 'completed' ? '#ecfdf5; color: #166534;' : ($t['status'] === 'in_progress' ? '#eff6ff; color: #1e40af;' : '#f8fafc; color: #64748b;'); 
                                                    ?>">
                                                        <?php echo str_replace('_', ' ', $t['status']); ?>
                                                    </span>
                                                </td>
                                                <td style="font-size: 0.85rem; color: var(--text-muted);"><?php echo date('Y-m-d', strtotime($t['created_at'])); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Assign Request Modal -->
                    <div id="assignRequestModal" class="modal">
                        <div class="modal-content">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                                <h3 style="font-size: 1.25rem; font-weight: 700;">Assign Aid Request</h3>
                                <button onclick="toggleModal('assignRequestModal')" style="background: none; border: none; cursor: pointer;"><i data-lucide="x"></i></button>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="create_task_from_request">
                                <input type="hidden" name="request_id" id="assign_req_id">
                                <div class="form-group">
                                    <label>Task Title</label>
                                    <input type="text" name="task_name" id="assign_task_name" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label>Volunteer</label>
                                    <select name="volunteer_id" class="form-control" required>
                                        <option value="">Select a volunteer</option>
                                        <?php foreach ($volunteers as $v): ?>
                                            <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['full_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Priority</label>
                                    <select name="priority" id="assign_priority" class="form-control">
                                        <option value="low">Low</option>
                                        <option value="medium">Medium</option>
                                        <option value="high">High</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Task Type</label>
                                    <select name="task_type" id="assign_task_type" class="form-control" onchange="toggleDistFields('assign')">
                                        <option value="standard">Standard Task</option>
                                        <option value="distribution">Aid Distribution</option>
                                    </select>
                                </div>
                                <div id="assign_dist_fields" style="display:none; background: #f8fafc; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid var(--border);">
                                    <div class="form-group">
                                        <label>Distribution Item</label>
                                        <select name="distribution_item" id="assign_dist_item" class="form-control">
                                            <option value="">-- Select Item --</option>
                                            <?php foreach ($inventory as $inv): ?>
                                                <option value="<?php echo htmlspecialchars($inv['item_name']); ?>"><?php echo htmlspecialchars($inv['item_name']); ?> (<?php echo $inv['quantity']; ?> <?php echo $inv['unit']; ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Quantity</label>
                                        <input type="number" name="distribution_qty" id="assign_dist_qty" class="form-control" value="1" min="1">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Due Date</label>
                                    <input type="datetime-local" name="due_date" id="assign_due_date" class="form-control" required>
                                </div>
                                <div class="form-group">
                                    <label>Instructions for Volunteer</label>
                                    <textarea name="description" id="assign_desc" class="form-control" style="min-height: 100px;"></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; margin-top: 1rem;">Assign Task</button>
                            </form>
                        </div>
                    </div>

                    <!-- Add Manual Task Modal -->
                    <div id="addTaskModal" class="modal">
                        <div class="modal-content">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                                <h3 style="font-size: 1.25rem; font-weight: 700;">Create Manual Task</h3>
                                <button onclick="toggleModal('addTaskModal')" style="background: none; border: none; cursor: pointer;"><i data-lucide="x"></i></button>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="assign_task">
                                <div class="form-group"><label>Task Name</label><input type="text" name="task_name" class="form-control" placeholder="e.g. Unload truck, Check Section B" required></div>
                                <div class="form-group">
                                    <label>Assign To</label>
                                    <select name="volunteer_id" class="form-control" required>
                                        <?php foreach ($volunteers as $v): ?>
                                            <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['full_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Task Type</label>
                                    <select name="task_type" class="form-control" onchange="toggleDistFields('add')">
                                        <option value="standard">Standard Task</option>
                                        <option value="distribution">Aid Distribution</option>
                                    </select>
                                </div>
                                <div id="add_dist_fields" style="display:none; background: #f8fafc; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; border: 1px solid var(--border);">
                                    <div class="form-group">
                                        <label>Distribution Item</label>
                                        <select name="distribution_item" class="form-control">
                                            <option value="">-- Select Item --</option>
                                            <?php foreach ($inventory as $inv): ?>
                                                <option value="<?php echo htmlspecialchars($inv['item_name']); ?>"><?php echo htmlspecialchars($inv['item_name']); ?> (<?php echo $inv['quantity']; ?> <?php echo $inv['unit']; ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label>Quantity</label>
                                        <input type="number" name="distribution_qty" class="form-control" value="1" min="1">
                                    </div>
                                </div>
                                <div class="form-group"><label>Priority</label><select name="priority" class="form-control"><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select></div>
                                <div class="form-group">
                                    <label>Due Date</label>
                                    <input type="datetime-local" name="due_date" class="form-control" required>
                                </div>
                                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; margin-top: 1rem;">Create Task</button>

                            </form>
                        </div>
                    </div>

                    <script>
                    function openAssignModal(req) {
                        document.getElementById('assign_req_id').value = req.id;
                        document.getElementById('assign_task_name').value = "Aid Request: " + req.category;
                        document.getElementById('assign_desc').value = "Request from " + req.requester_name + ":\n" + req.description + "\n\nContact: " + (req.contact || 'N/A');
                        document.getElementById('assign_priority').value = req.urgency.toLowerCase();
                        
                        // Auto-fill distribution fields if it looks like one
                        if (['Food', 'Medicine', 'Supplies'].includes(req.category)) {
                            document.getElementById('assign_task_type').value = 'distribution';
                            document.getElementById('assign_dist_item').value = req.category;
                            document.getElementById('assign_dist_qty').value = 1;
                            document.getElementById('assign_dist_fields').style.display = 'block';
                        }
                        
                        toggleModal('assignRequestModal');
                    }
                    function toggleDistFields(prefix) {
                        const modal = document.getElementById(prefix === 'assign' ? 'assignRequestModal' : 'addTaskModal');
                        const type = modal.querySelector('select[name="task_type"]').value;
                        const fields = document.getElementById(prefix + '_dist_fields');
                        if (fields) {
                            fields.style.display = (type === 'distribution') ? 'block' : 'none';
                            const inputs = fields.querySelectorAll('select, input');
                            inputs.forEach(input => input.required = (type === 'distribution'));
                        }
                    }

                    </script>

                <?php elseif ($page === 'distribution'): ?>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div style="text-align: center; width: 100%;">
                                <i data-lucide="trending-up" style="color: #4f46e5; margin-bottom: 0.5rem;"></i>
                                <p style="font-size: 1.75rem; font-weight: 800;"><?php echo $dist_stats['total']; ?></p>
                                <p style="color: var(--text-muted); font-size: 0.85rem; font-weight: 600;">Total Distributions</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div style="text-align: center; width: 100%;">
                                <i data-lucide="trending-up" style="color: #10b981; margin-bottom: 0.5rem;"></i>
                                <p style="font-size: 1.75rem; font-weight: 800;"><?php echo $dist_stats['this_week']; ?></p>
                                <p style="color: var(--text-muted); font-size: 0.85rem; font-weight: 600;">This Week</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <div style="text-align: center; width: 100%;">
                                <i data-lucide="trending-up" style="color: #8b5cf6; margin-bottom: 0.5rem;"></i>
                                <p style="font-size: 1.75rem; font-weight: 800;"><?php echo $dist_stats['today']; ?></p>
                                <p style="color: var(--text-muted); font-size: 0.85rem; font-weight: 600;">Today</p>
                            </div>
                        </div>
                    </div>

                    <div class="ap-table-panel">
                        <div class="ap-table-header">Distribution Logs</div>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Recipient</th>
                                        <th>Items</th>
                                        <th>Quantity</th>
                                        <th>Distributed By</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($all_distributions)): ?>
                                        <tr><td colspan="5" style="text-align:center; padding: 3rem; color: var(--text-muted);">No distribution logs yet.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($all_distributions as $d): ?>
                                            <tr>
                                                <td style="color: var(--text-muted); font-weight: 500;"><?php echo date('Y-m-d', strtotime($d['distributed_at'])); ?></td>
                                                <td><span style="font-weight: 700;"><?php echo htmlspecialchars($d['recipient_name']); ?></span></td>
                                                <td><?php echo htmlspecialchars($d['items']); ?></td>
                                                <td><?php echo $d['quantity']; ?></td>
                                                <td><?php echo htmlspecialchars($d['distributor_name'] ?? 'System'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Record Distribution Modal -->
                    <div id="recordDistModal" class="modal">
                        <div class="modal-content">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                                <h3 style="font-size: 1.25rem; font-weight: 700;">Record New Distribution</h3>
                                <button onclick="toggleModal('recordDistModal')" style="background: none; border: none; cursor: pointer; color: var(--text-muted);"><i data-lucide="x"></i></button>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="record_distribution">
                                <div class="form-group">
                                    <label>Recipient Name</label>
                                    <input type="text" name="recipient_name" class="form-control" placeholder="e.g. Robert Martinez" required>
                                </div>
                                <div class="form-group">
                                    <label>Items Distributed</label>
                                    <input type="text" name="items" class="form-control" placeholder="e.g. Food packets, Water" required>
                                </div>
                                <div class="form-group">
                                    <label>Quantity</label>
                                    <input type="number" name="quantity" class="form-control" value="1" min="1" step="0.1" required>
                                </div>
                                <div class="form-group">
                                    <label>Distributed By</label>
                                    <select name="distributed_by" class="form-control" required>
                                        <option value="<?php echo $user_id; ?>">Me (<?php echo htmlspecialchars($user['full_name']); ?>)</option>
                                        <?php foreach ($volunteers as $v): ?>
                                            <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['full_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; background: #0f172a; margin-top: 1rem;">Log Distribution</button>
                            </form>
                        </div>
                    </div>

                <?php elseif ($page === 'report'): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                        <h2 style="font-size: 1.5rem; font-weight: 700;">Volunteer Field Reports</h2>
                        <div style="display: flex; gap: 10px;">
                            <span class="badge" style="background: #eff6ff; color: #1e40af;"><?php echo count($field_reports); ?> Total Reports</span>
                        </div>
                    </div>

                    <div class="ap-table-panel">
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Volunteer</th>
                                        <th>Category</th>
                                        <th>Priority</th>
                                        <th>Description</th>
                                        <th>Location</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($field_reports)): ?>
                                        <tr><td colspan="6" style="text-align:center; padding: 4rem; color: var(--text-muted);">
                                            <div style="font-size: 2.5rem; margin-bottom: 1rem;">📋</div>
                                            No field reports submitted yet.
                                        </td></tr>
                                    <?php else: ?>
                                        <?php foreach ($field_reports as $fr): ?>
                                            <tr>
                                                <td style="font-size: 0.85rem; color: var(--text-muted);">
                                                    <?php echo date('M d, Y', strtotime($fr['created_at'])); ?><br>
                                                    <?php echo date('h:i A', strtotime($fr['created_at'])); ?>
                                                </td>
                                                <td><span style="font-weight: 700;"><?php echo htmlspecialchars($fr['volunteer_name']); ?></span></td>
                                                <td>
                                                    <span class="badge" style="background: <?php echo $fr['report_category'] === 'incident' ? '#fff7ed; color: #ea580c;' : '#f0fdf4; color: #16a34a;'; ?>">
                                                        <?php echo ucfirst($fr['report_category']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge" style="background: <?php 
                                                        echo $fr['priority'] === 'Critical' ? '#fef2f2; color: #dc2626;' : ($fr['priority'] === 'High' ? '#fff1f2; color: #e11d48;' : '#f8fafc; color: #64748b;'); 
                                                    ?>">
                                                        <?php echo htmlspecialchars($fr['priority']); ?>
                                                    </span>
                                                </td>
                                                <td style="max-width: 300px;">
                                                    <p style="font-weight: 600; font-size: 0.9rem; margin-bottom: 4px;"><?php echo htmlspecialchars($fr['issue_type']); ?></p>
                                                    <p style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.4;"><?php echo htmlspecialchars($fr['description']); ?></p>
                                                    <?php if(!empty($fr['immediate_action'])): ?>
                                                        <p style="font-size: 0.75rem; color: #10b981; font-weight: 600; margin-top: 5px;">Action: <?php echo htmlspecialchars($fr['immediate_action']); ?></p>
                                                    <?php endif; ?>
                                                </td>
                                                <td style="font-size: 0.85rem;"><?php echo htmlspecialchars($fr['location']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>


                <?php elseif ($page === 'chat'): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                        <h2 style="font-size: 1.5rem; font-weight: 700;">Support Chat</h2>
                        <div style="display: flex; gap: 10px;">
                            <span class="badge" style="background: #eff6ff; color: #1e40af;">Admin Contact</span>
                        </div>
                    </div>
                    <div class="panel" style="max-width: 800px;">
                        <div style="height: 400px; background: #f8fafc; border-radius: 18px; padding: 1.5rem; overflow-y: auto; display: flex; flex-direction: column; gap: 1rem; margin-bottom: 1.5rem;" id="chatContainer">
                            <?php 
                            $admin_query = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
                            $admin_id = ($admin_query && $admin_query->num_rows > 0) ? $admin_query->fetch_assoc()['id'] : 1;
                            
                            $conn->query("UPDATE messages SET is_read = 1 WHERE sender_id = $admin_id AND receiver_id = $user_id AND is_read = 0");

                            $messages = $conn->query("SELECT * FROM messages WHERE (sender_id = $user_id AND receiver_id = $admin_id) OR (sender_id = $admin_id AND receiver_id = $user_id) ORDER BY created_at ASC");
                            if ($messages && $messages->num_rows > 0):
                                while ($msg = $messages->fetch_assoc()):
                                    $is_me = ($msg['sender_id'] == $user_id);
                            ?>
                                <div style="background: <?php echo $is_me ? '#2563eb' : 'white'; ?>; color: <?php echo $is_me ? 'white' : '#111827'; ?>; padding: 0.8rem 1.2rem; border-radius: 14px; max-width: 80%; <?php echo $is_me ? 'align-self: flex-end; border-bottom-right-radius: 4px;' : 'align-self: flex-start; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); border-bottom-left-radius: 4px;'; ?>">
                                    <div style="font-size: 0.95rem; line-height: 1.4;"><?php echo htmlspecialchars($msg['message_text']); ?></div>
                                    <div style="font-size: 0.7rem; color: <?php echo $is_me ? '#93c5fd' : '#9ca3af'; ?>; margin-top: 0.4rem; text-align: right;">
                                        <?php echo date('M d, g:i a', strtotime($msg['created_at'])); ?>
                                    </div>
                                </div>
                            <?php endwhile; else: ?>
                                <div style="text-align: center; color: #6b7280; font-size: 0.9rem; margin-top: auto; margin-bottom: auto;">
                                    Start a conversation with the central admin team.
                                </div>
                            <?php endif; ?>
                        </div>
                        <form method="POST">
                            @csrf
                            <input type="hidden" name="action" value="send_chat_message">
                            <div style="display: flex; gap: 1rem;">
                                <input type="text" name="message" placeholder="Type a message..." style="flex: 1; border: 1px solid #d1d5db; border-radius: 14px; padding: 0.95rem 1rem;" required autocomplete="off" autofocus>
                                <button type="submit" class="btn btn-primary" style="background: #2563eb;">Send</button>
                            </div>
                        </form>
                        <script>
                            const chatContainer = document.getElementById('chatContainer');
                            if (chatContainer) chatContainer.scrollTop = chatContainer.scrollHeight;
                        </script>
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
        
        // Real-time updates for Camp Manager
        let lastUpdateCheck = '<?php echo date('Y-m-d H:i:s'); ?>';
        setInterval(() => {
            fetch(`/camp_manager_dashboard?api=check_updates&last_check=${encodeURIComponent(lastUpdateCheck)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.new_tasks > 0 || data.new_volunteers > 0 || data.new_distributions > 0) {
                        // For simplicity, we'll just show a subtle refresh indicator or auto-reload 
                        // if the user is on the dashboard/task/distribution pages
                        const currentPage = '<?php echo $page; ?>';
                        if (['dashboard', 'tasks', 'distribution', 'volunteers'].includes(currentPage)) {
                            // You could do a partial AJAX reload here, but for now a toast/reload is easier
                            console.log("New updates available:", data);
                            // We can use a small notification badge on the sidebar or a toast
                            showToast("Updates available. Data has been refreshed.");
                            // Refresh data by reloading (user said "updated in realtime", 
                            // so maybe silent reload or updating specific elements)
                            setTimeout(() => location.reload(), 2000); 
                        }
                    }
                    lastUpdateCheck = data.timestamp;
                });
        }, 8000);

        function showToast(msg) {
            const toast = document.createElement('div');
            toast.style.cssText = "position: fixed; bottom: 20px; right: 20px; background: #0f172a; color: white; padding: 12px 24px; border-radius: 12px; z-index: 9999; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); font-size: 0.9rem; font-weight: 600; border-left: 4px solid #4f46e5;";
            toast.innerHTML = `✨ ${msg}`;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 5000);
        }

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
