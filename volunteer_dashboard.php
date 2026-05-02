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

// Redirect camp managers and admins to their dashboards
if ($user_role === 'camp_manager') {
    redirect('camp_manager_dashboard.php');
}
if ($user_role === 'admin') {
    redirect('admin_dashboard.php');
}

// Verify user is volunteer
if ($user_role !== 'volunteer') {
    redirect('index.php');
}

// Get user details
$user_query = $conn->query("SELECT * FROM users WHERE id = $user_id");
$user = $user_query->fetch_assoc();

// Handle Actions
$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if ($action === 'update_task_status') {
            $task_id = intval($_POST['task_id']);
            $new_status = sanitize($_POST['status']);
            $completed_clause = ($new_status === 'completed') ? ", completed_date = CURRENT_TIMESTAMP" : ", completed_date = NULL";
            $update = $conn->query("UPDATE tasks SET status = '$new_status' $completed_clause WHERE id = $task_id AND assigned_to = $user_id");
            if ($update) {
                $success_msg = "Task status updated to " . str_replace('_', ' ', $new_status) . ".";
            } else {
                $error_msg = "Failed to update task status.";
            }
        }
        
        if ($action === 'submit_emergency_report') {
            $type = sanitize($_POST['issue_type']);
            $priority = sanitize($_POST['priority']);
            $loc = sanitize($_POST['location']);
            $affected = intval($_POST['people_affected']);
            $desc = sanitize($_POST['description']);
            $action_taken = sanitize($_POST['immediate_action']);
            
            // Get camp_id for this volunteer
            $camp_q = $conn->query("SELECT camp_id FROM volunteer_assignments WHERE volunteer_id = $user_id AND status = 'active' LIMIT 1");
            $camp_id = ($camp_q && $camp_q->num_rows > 0) ? $camp_q->fetch_assoc()['camp_id'] : 0;
            
            $insert = $conn->query("INSERT INTO emergency_reports (reported_by, camp_id, issue_type, priority, location, people_affected, description, immediate_action) 
                                   VALUES ($user_id, $camp_id, '$type', '$priority', '$loc', $affected, '$desc', '$action_taken')");
            if ($insert) {
                $success_msg = "Emergency report submitted successfully. Camp manager notified.";
            } else {
                $error_msg = "Failed to submit report.";
            }
        }
    }
}

// Get notifications count
$notifications_query = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0");
$notifications = $notifications_query->fetch_assoc();
$unread_count = $notifications['count'];

// Get task statistics
$stats_query = $conn->query("SELECT 
    (SELECT COUNT(*) FROM tasks WHERE assigned_to = $user_id) as total_assigned,
    (SELECT COUNT(*) FROM tasks WHERE assigned_to = $user_id AND status = 'in_progress') as in_progress,
    (SELECT COUNT(*) FROM tasks WHERE assigned_to = $user_id AND status = 'completed' AND DATE(completed_date) = CURDATE()) as completed_today,
    (SELECT COUNT(*) FROM tasks WHERE assigned_to = $user_id AND status = 'completed') as total_completed,
    (SELECT COUNT(*) FROM tasks WHERE assigned_to = $user_id AND status = 'pending') as pending
");
$stats = $stats_query->fetch_assoc();

// Get current page
$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';

// Get camp assignment
$camp_query = $conn->query("SELECT camps.* FROM camps 
    JOIN volunteer_assignments ON camps.id = volunteer_assignments.camp_id 
    WHERE volunteer_assignments.volunteer_id = $user_id AND volunteer_assignments.status = 'active' LIMIT 1");
$camp = ($camp_query && $camp_query->num_rows > 0) ? $camp_query->fetch_assoc() : null;
$camp_name = $camp ? $camp['camp_name'] : 'Not Assigned';
$camp_id = $camp ? $camp['id'] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Volunteer Dashboard - DisasterRelief</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
        
        :root {
            --primary: #2563eb;
            --primary-light: #eff6ff;
            --success: #22c55e;
            --success-light: #f0fdf4;
            --warning: #f59e0b;
            --warning-light: #fffbeb;
            --danger: #ef4444;
            --danger-light: #fef2f2;
            --info: #3b82f6;
            --info-light: #eff6ff;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-500: #6b7280;
            --gray-700: #374151;
            --gray-900: #111827;
            --radius-xl: 24px;
            --radius-lg: 16px;
            --shadow: 0 10px 30px rgba(0, 0, 0, 0.04);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f8fafc; color: var(--gray-900); }
        
        .layout { display: flex; min-height: 100vh; }
        
        /* Sidebar */
        .sidebar { width: 260px; background: white; border-right: 1px solid var(--gray-200); display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh; }
        .sidebar-top { padding: 2rem 1.5rem; display: flex; align-items: center; gap: 0.75rem; }
        .logo { width: 40px; height: 40px; background: var(--primary); color: white; border-radius: 12px; display: grid; place-items: center; font-weight: 800; font-size: 1.2rem; }
        .brand { font-weight: 700; font-size: 1.1rem; letter-spacing: -0.02em; }
        
        .menu { list-style: none; padding: 0 1rem; }
        .menu-item { margin-bottom: 0.5rem; }
        .menu-link { display: flex; align-items: center; gap: 0.85rem; padding: 0.85rem 1rem; color: var(--gray-500); text-decoration: none; border-radius: 12px; font-weight: 500; transition: all 0.2s; }
        .menu-link:hover { background: var(--primary-light); color: var(--primary); }
        .menu-link.active { background: var(--primary); color: white; box-shadow: 0 10px 20px rgba(37, 99, 235, 0.2); }
        .menu-badge { margin-left: auto; background: var(--primary-light); color: var(--primary); padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }
        .menu-link.active .menu-badge { background: rgba(255, 255, 255, 0.2); color: white; }

        /* Topbar */
        .main { flex: 1; display: flex; flex-direction: column; }
        .topbar { background: white; padding: 1.25rem 2.5rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--gray-200); position: sticky; top: 0; z-index: 50; }
        .topbar-right { display: flex; align-items: center; gap: 1.5rem; }
        .notification-btn { position: relative; width: 40px; height: 40px; border-radius: 12px; border: 1px solid var(--gray-200); background: white; cursor: pointer; display: grid; place-items: center; font-size: 1.2rem; transition: all 0.2s; }
        .notification-btn:hover { background: var(--gray-50); }
        .notification-dot { position: absolute; top: -5px; right: -5px; width: 20px; height: 20px; background: #f97316; color: white; border-radius: 50%; font-size: 0.7rem; display: grid; place-items: center; font-weight: 700; border: 2px solid white; }
        
        .profile-trigger { display: flex; align-items: center; gap: 0.75rem; background: none; border: none; cursor: pointer; padding: 0.5rem; border-radius: 14px; transition: all 0.2s; }
        .profile-trigger:hover { background: var(--gray-50); }
        .avatar { width: 42px; height: 42px; border-radius: 12px; background: var(--primary); color: white; display: grid; place-items: center; font-weight: 700; font-size: 1.1rem; }
        .profile-info { text-align: left; }
        .profile-name { display: block; font-weight: 700; font-size: 0.95rem; }
        .profile-role { display: block; font-size: 0.8rem; color: var(--gray-500); font-weight: 500; }

        /* Content Area */
        .content { padding: 2.5rem; }
        .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2.5rem; }
        .page-title { font-size: 1.75rem; font-weight: 800; letter-spacing: -0.02em; margin-bottom: 0.25rem; }
        .page-subtitle { color: var(--gray-500); font-weight: 500; }
        
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.75rem 1.5rem; border-radius: 12px; font-weight: 600; font-size: 0.9rem; cursor: pointer; transition: all 0.2s; border: none; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(37, 99, 235, 0.2); }
        .btn-orange { background: #f97316; color: white; }
        .btn-orange:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(249, 115, 22, 0.2); }
        .btn-secondary { background: white; border: 1px solid var(--gray-200); color: var(--gray-700); }
        .btn-secondary:hover { background: var(--gray-50); }
        .btn-success { background: var(--success); color: white; }
        .btn-success:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(34, 197, 94, 0.2); }

        /* Stats Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem; }
        .stat-card { background: white; padding: 1.5rem; border-radius: var(--radius-lg); border: 1px solid var(--gray-100); display: flex; justify-content: space-between; align-items: center; box-shadow: var(--shadow); }
        .stat-info h4 { font-size: 0.9rem; color: var(--gray-500); font-weight: 600; margin-bottom: 0.5rem; }
        .stat-info .value { font-size: 1.75rem; font-weight: 800; }
        .stat-icon { width: 48px; height: 48px; border-radius: 14px; display: grid; place-items: center; font-size: 1.25rem; }

        /* Task Board (Grid) */
        .board { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; }
        .board-column { display: flex; flex-direction: column; gap: 1rem; }
        .column-header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; border-radius: 12px; font-weight: 700; font-size: 0.95rem; }
        .column-badge { background: rgba(255,255,255,0.5); padding: 0.15rem 0.5rem; border-radius: 6px; font-size: 0.8rem; }
        
        .header-pending { background: var(--warning-light); color: #92400e; }
        .header-progress { background: var(--info-light); color: #1e40af; }
        .header-completed { background: var(--success-light); color: #166534; }

        .task-card { background: white; padding: 1.5rem; border-radius: var(--radius-lg); border: 1px solid var(--gray-100); box-shadow: var(--shadow); display: flex; flex-direction: column; gap: 1rem; transition: transform 0.2s; }
        .task-card:hover { transform: translateY(-3px); border-color: var(--primary); }
        .task-header { display: flex; justify-content: space-between; align-items: flex-start; }
        .task-title { font-weight: 700; font-size: 1rem; line-height: 1.4; color: var(--gray-900); }
        
        .badge { padding: 0.25rem 0.6rem; border-radius: 6px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; }
        .badge-high { background: var(--danger-light); color: var(--danger); }
        .badge-medium { background: var(--warning-light); color: var(--warning); }
        .badge-low { background: var(--info-light); color: var(--info); }
        .badge-status { background: var(--gray-100); color: var(--gray-500); }
        .badge-progress { background: #dbeafe; color: #1d4ed8; }
        .badge-completed { background: #dcfce7; color: #15803d; }

        .task-meta { display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.85rem; color: var(--gray-500); }
        .meta-item { display: flex; align-items: center; gap: 0.4rem; }
        .task-actions { display: grid; grid-template-columns: 1.5fr 1fr; gap: 0.75rem; margin-top: 0.5rem; }
        .task-actions .btn { padding: 0.6rem; font-size: 0.85rem; }

        /* Task List View */
        .task-list { display: flex; flex-direction: column; gap: 1rem; }
        .list-card { background: white; padding: 1.5rem 2rem; border-radius: var(--radius-lg); border: 1px solid var(--gray-100); display: flex; justify-content: space-between; align-items: center; gap: 2rem; box-shadow: var(--shadow); }
        .list-main { flex: 1; }
        .list-header { display: flex; align-items: center; gap: 1rem; margin-bottom: 0.75rem; }
        .list-title { font-size: 1.1rem; font-weight: 700; }
        .list-desc { color: var(--gray-500); font-size: 0.9rem; margin-bottom: 1rem; }
        .list-meta { display: flex; align-items: center; gap: 2rem; font-size: 0.85rem; color: var(--gray-500); }
        .list-actions { display: flex; flex-direction: column; gap: 0.5rem; width: 140px; }
        
        /* Modal Style */
        .modal { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.4); backdrop-filter: blur(4px); z-index: 100; display: grid; place-items: center; padding: 2rem; }
        .modal-content { background: white; width: 100%; max-width: 500px; border-radius: var(--radius-xl); padding: 2rem; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); }
        .modal-header { margin-bottom: 1.5rem; }
        .modal-header h3 { font-size: 1.5rem; font-weight: 800; letter-spacing: -0.02em; }
        
        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; font-size: 0.9rem; font-weight: 600; color: var(--gray-700); margin-bottom: 0.5rem; }
        .form-control { width: 100%; padding: 0.85rem 1rem; border: 1px solid var(--gray-200); border-radius: 12px; font-size: 0.95rem; transition: all 0.2s; }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px var(--primary-light); }

        /* Profile Dropdown */
        .profile-dropdown { position: absolute; top: 100%; right: 0; margin-top: 0.75rem; width: 220px; background: white; border-radius: 16px; border: 1px solid var(--gray-200); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); display: none; z-index: 100; overflow: hidden; }
        .profile-dropdown.show { display: block; animation: dropdownSlide 0.2s ease-out; }
        @keyframes dropdownSlide { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        
        .dropdown-header { padding: 1.25rem; border-bottom: 1px solid var(--gray-100); background: var(--gray-50); }
        .dropdown-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.85rem 1.25rem; color: var(--gray-700); text-decoration: none; font-size: 0.9rem; font-weight: 500; transition: all 0.2s; }
        .dropdown-item:hover { background: var(--primary-light); color: var(--primary); }
        .dropdown-item.logout { color: var(--danger); }
        .dropdown-item.logout:hover { background: var(--danger-light); color: var(--danger); }

        @media (max-width: 1024px) { .board { grid-template-columns: 1fr; } .sidebar { width: 80px; } .brand, .profile-info { display: none; } .menu-link span:not(.menu-icon) { display: none; } }
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
                <li class="menu-item"><a href="volunteer_dashboard.php?page=dashboard" class="menu-link <?php echo $page === 'dashboard' ? 'active' : ''; ?>"><span class="menu-icon">📊</span><span>Dashboard</span></a></li>
                <li class="menu-item"><a href="volunteer_dashboard.php?page=tasks" class="menu-link <?php echo $page === 'tasks' ? 'active' : ''; ?>"><span class="menu-icon">📋</span><span>My Tasks</span><?php if($stats['pending'] > 0): ?><span class="menu-badge"><?php echo $stats['pending']; ?></span><?php endif; ?></a></li>
                <li class="menu-item"><a href="volunteer_dashboard.php?page=chat" class="menu-link <?php echo $page === 'chat' ? 'active' : ''; ?>"><span class="menu-icon">💬</span><span>Chat</span></a></li>
                <li class="menu-item"><a href="volunteer_dashboard.php?page=report" class="menu-link <?php echo $page === 'report' ? 'active' : ''; ?>"><span class="menu-icon">🚨</span><span>Report Issue</span></a></li>
                <li class="menu-item"><a href="volunteer_dashboard.php?page=settings" class="menu-link <?php echo $page === 'settings' ? 'active' : ''; ?>"><span class="menu-icon">⚙️</span><span>Settings</span></a></li>
            </ul>
        </aside>
        <main class="main">
            <header class="topbar">
                <div class="topbar-left">
                    <h2 class="page-title"><?php echo $page === 'dashboard' ? 'Volunteer Dashboard' : ($page === 'tasks' ? 'My Tasks' : ucfirst($page)); ?></h2>
                    <p class="page-subtitle"><?php echo $camp ? "Assigned to: " . htmlspecialchars($camp['camp_name']) . ", " . htmlspecialchars($camp['location']) : "No camp assigned yet"; ?></p>
                </div>
                <div class="topbar-right">
                    <button class="notification-btn">
                        🔔
                        <?php if ($unread_count > 0): ?><span class="notification-dot"><?php echo $unread_count; ?></span><?php endif; ?>
                    </button>
                    <div style="position: relative;">
                        <div class="profile-trigger" onclick="toggleProfileMenu()">
                            <div class="avatar"><?php echo strtoupper(substr($user['full_name'], 0, 1)); ?></div>
                            <div class="profile-info">
                                <span class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></span>
                                <span class="profile-role">Volunteer</span>
                            </div>
                        </div>
                        <div id="profileDropdown" class="profile-dropdown">
                            <div class="dropdown-header">
                                <p style="font-weight: 700; font-size: 0.9rem;"><?php echo htmlspecialchars($user['full_name']); ?></p>
                                <p style="font-size: 0.75rem; color: var(--gray-500);"><?php echo htmlspecialchars($user['email']); ?></p>
                            </div>
                            <a href="volunteer_dashboard.php?page=profile" class="dropdown-item">👤 My Profile</a>
                            <a href="volunteer_dashboard.php?page=settings" class="dropdown-item">⚙️ Settings</a>
                            <div style="border-top: 1px solid var(--gray-100);"></div>
                            <a href="logout.php" class="dropdown-item logout">🚪 Log Out</a>
                        </div>
                    </div>
                </div>
            </header>

            <div class="content">
                <?php if ($success_msg): ?>
                    <div style="background: #ecfdf5; color: #065f46; padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; border: 1px solid #a7f3d0; font-weight: 500;">
                        ✅ <?php echo $success_msg; ?>
                    </div>
                <?php endif; ?>
                <?php if ($error_msg): ?>
                    <div style="background: #fef2f2; color: #991b1b; padding: 1rem; border-radius: 12px; margin-bottom: 1.5rem; border: 1px solid #fecaca; font-weight: 500;">
                        ❌ <?php echo $error_msg; ?>
                    </div>
                <?php endif; ?>

                <?php if ($page === 'dashboard'): ?>
                    <div style="display: flex; justify-content: flex-end; margin-bottom: 1.5rem;">
                        <a href="volunteer_dashboard.php?page=report" class="btn btn-orange">Report Issue</a>
                    </div>
                    
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-info">
                                <h4>Tasks Assigned</h4>
                                <div class="value"><?php echo $stats['total_assigned']; ?></div>
                            </div>
                            <div class="stat-icon" style="background: #fff7ed; color: #f97316;">📋</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info">
                                <h4>In Progress</h4>
                                <div class="value"><?php echo $stats['in_progress']; ?></div>
                            </div>
                            <div class="stat-icon" style="background: #eff6ff; color: #2563eb;">⚙️</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info">
                                <h4>Completed Today</h4>
                                <div class="value"><?php echo $stats['completed_today']; ?></div>
                            </div>
                            <div class="stat-icon" style="background: #f0fdf4; color: #22c55e;">✅</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info">
                                <h4>Total Completed</h4>
                                <div class="value"><?php echo $stats['total_completed']; ?></div>
                            </div>
                            <div class="stat-icon" style="background: #f5f3ff; color: #7c3aed;">🏆</div>
                        </div>
                    </div>

                    <h3 style="font-size: 1.25rem; font-weight: 800; margin-bottom: 1.5rem;">Task Board</h3>
                    <div class="board">
                        <!-- Pending Column -->
                        <div class="board-column">
                            <div class="column-header header-pending">
                                <span>Pending</span>
                                <span class="column-badge"><?php echo $stats['pending']; ?></span>
                            </div>
                            <?php
                            $pending = $conn->query("SELECT * FROM tasks WHERE assigned_to = $user_id AND status = 'pending' ORDER BY priority='high' DESC, priority='medium' DESC");
                            while ($task = $pending->fetch_assoc()):
                            ?>
                                <div class="task-card">
                                    <div class="task-header">
                                        <h4 class="task-title"><?php echo htmlspecialchars($task['task_name']); ?></h4>
                                        <span class="badge badge-<?php echo strtolower($task['priority']); ?>"><?php echo $task['priority']; ?></span>
                                    </div>
                                    <p style="font-size: 0.85rem; color: var(--gray-500); line-height: 1.5;"><?php echo htmlspecialchars($task['description']); ?></p>
                                    <div class="task-meta">
                                        <div class="meta-item">📍 <?php echo htmlspecialchars($camp['camp_name'] ?? 'Not set'); ?></div>
                                        <div class="meta-item">📅 <?php echo $task['due_date'] ? date('M d, h:i A', strtotime($task['due_date'])) : 'No deadline'; ?></div>
                                    </div>
                                    <div class="task-actions">
                                        <form method="POST">
                                            <input type="hidden" name="action" value="update_task_status">
                                            <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                            <input type="hidden" name="status" value="in_progress">
                                            <button type="submit" class="btn btn-primary" style="width: 100%;">Start Task</button>
                                        </form>
                                        <button class="btn btn-secondary">Details</button>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>

                        <!-- In Progress Column -->
                        <div class="board-column">
                            <div class="column-header header-progress">
                                <span>In Progress</span>
                                <span class="column-badge"><?php echo $stats['in_progress']; ?></span>
                            </div>
                            <?php
                            $progress = $conn->query("SELECT * FROM tasks WHERE assigned_to = $user_id AND status = 'in_progress' ORDER BY priority='high' DESC");
                            while ($task = $progress->fetch_assoc()):
                            ?>
                                <div class="task-card">
                                    <div class="task-header">
                                        <h4 class="task-title"><?php echo htmlspecialchars($task['task_name']); ?></h4>
                                        <span class="badge badge-<?php echo strtolower($task['priority']); ?>"><?php echo $task['priority']; ?></span>
                                    </div>
                                    <p style="font-size: 0.85rem; color: var(--gray-500); line-height: 1.5;"><?php echo htmlspecialchars($task['description']); ?></p>
                                    <div class="task-meta">
                                        <div class="meta-item">📍 <?php echo htmlspecialchars($camp['camp_name'] ?? 'Not set'); ?></div>
                                        <div class="meta-item">📅 Started: <?php echo date('h:i A'); ?></div>
                                    </div>
                                    <div class="task-actions">
                                        <form method="POST">
                                            <input type="hidden" name="action" value="update_task_status">
                                            <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                            <input type="hidden" name="status" value="completed">
                                            <button type="submit" class="btn btn-success" style="width: 100%;">Complete</button>
                                        </form>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="update_task_status">
                                            <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                            <input type="hidden" name="status" value="pending">
                                            <button type="submit" class="btn btn-secondary" style="width: 100%;">Move Back</button>
                                        </form>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>

                        <!-- Completed Column -->
                        <div class="board-column">
                            <div class="column-header header-completed">
                                <span>Completed Today</span>
                                <span class="column-badge"><?php echo $stats['completed_today']; ?></span>
                            </div>
                            <?php
                            $completed = $conn->query("SELECT * FROM tasks WHERE assigned_to = $user_id AND status = 'completed' AND DATE(completed_date) = CURDATE() ORDER BY completed_date DESC");
                            while ($task = $completed->fetch_assoc()):
                            ?>
                                <div class="task-card" style="opacity: 0.8;">
                                    <div class="task-header">
                                        <h4 class="task-title"><?php echo htmlspecialchars($task['task_name']); ?></h4>
                                        <span class="badge badge-completed">✓ Done</span>
                                    </div>
                                    <p style="font-size: 0.85rem; color: var(--gray-500); line-height: 1.5;"><?php echo htmlspecialchars($task['description']); ?></p>
                                    <div class="task-meta">
                                        <div class="meta-item">📍 <?php echo htmlspecialchars($camp['camp_name'] ?? 'Not set'); ?></div>
                                        <div class="meta-item">✅ <?php echo date('h:i A', strtotime($task['completed_date'])); ?></div>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    </div>

                <?php elseif ($page === 'tasks'): ?>
                    <div class="stats-grid" style="margin-bottom: 2rem;">
                        <div class="stat-card">
                            <div class="stat-info"><h4>Total Tasks</h4><div class="value"><?php echo $stats['total_assigned']; ?></div></div>
                            <div class="stat-icon" style="background: var(--primary-light); color: var(--primary);">📋</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info"><h4>Pending</h4><div class="value"><?php echo $stats['pending']; ?></div></div>
                            <div class="stat-icon" style="background: var(--warning-light); color: var(--warning);">⏳</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info"><h4>In Progress</h4><div class="value"><?php echo $stats['in_progress']; ?></div></div>
                            <div class="stat-icon" style="background: var(--info-light); color: var(--info);">⚙️</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-info"><h4>Completed</h4><div class="value"><?php echo $stats['total_completed']; ?></div></div>
                            <div class="stat-icon" style="background: var(--success-light); color: var(--success);">✅</div>
                        </div>
                    </div>

                    <h3 style="font-size: 1.25rem; font-weight: 800; margin-bottom: 1.5rem;">All Assigned Tasks</h3>
                    <div class="task-list">
                        <?php
                        $all_tasks = $conn->query("SELECT t.*, u.full_name as manager_name FROM tasks t LEFT JOIN users u ON t.assigned_by = u.id WHERE t.assigned_to = $user_id ORDER BY t.status='pending' DESC, t.status='in_progress' DESC, t.due_date ASC");
                        while ($task = $all_tasks->fetch_assoc()):
                        ?>
                            <div class="list-card">
                                <div class="list-main">
                                    <div class="list-header">
                                        <span class="list-title"><?php echo htmlspecialchars($task['task_name']); ?></span>
                                        <span class="badge badge-<?php echo strtolower($task['priority']); ?>"><?php echo $task['priority']; ?></span>
                                        <span class="badge badge-status <?php echo 'badge-' . ($task['status'] === 'in_progress' ? 'progress' : ($task['status'] === 'completed' ? 'completed' : 'status')); ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $task['status'])); ?>
                                        </span>
                                    </div>
                                    <p class="list-desc"><?php echo htmlspecialchars($task['description']); ?></p>
                                    <div class="list-meta">
                                        <span>📍 <?php echo htmlspecialchars($camp['camp_name'] ?? 'Not set'); ?></span>
                                        <span>📅 <?php echo $task['due_date'] ? date('M d, h:i A', strtotime($task['due_date'])) : 'No deadline'; ?></span>
                                        <span>👤 Assigned by: <?php echo htmlspecialchars($task['manager_name'] ?: 'Rajesh Kumar'); ?></span>
                                    </div>
                                </div>
                                <div class="list-actions">
                                    <?php if ($task['status'] === 'pending'): ?>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="update_task_status">
                                            <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                            <input type="hidden" name="status" value="in_progress">
                                            <button type="submit" class="btn btn-primary" style="width: 100%;">Start Task</button>
                                        </form>
                                    <?php elseif ($task['status'] === 'in_progress'): ?>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="update_task_status">
                                            <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                            <input type="hidden" name="status" value="completed">
                                            <button type="submit" class="btn btn-success" style="width: 100%;">Complete Task</button>
                                        </form>
                                    <?php endif; ?>
                                    <button class="btn btn-secondary" style="width: 100%;">View Details</button>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>

                <?php elseif ($page === 'profile'): ?>
                    <div style="text-align:center; margin-bottom:2rem;">
                        <h1 class="page-title">My Profile</h1>
                        <p class="page-subtitle">Review your volunteer account details</p>
                    </div>
                    <div class="card" style="padding:2rem;">
                        <div class="stats-grid" style="grid-template-columns: repeat(2, 1fr); gap:1.5rem; margin-bottom:1.5rem;">
                            <div class="stat-card total"><div class="stat-icon">👤</div><div class="stat-number"><?php echo htmlspecialchars($user['full_name']); ?></div><div class="campaign-stat-label">Full Name</div></div>
                            <div class="stat-card progress"><div class="stat-icon">✉️</div><div class="stat-number"><?php echo htmlspecialchars($user['email']); ?></div><div class="campaign-stat-label">Email</div></div>
                            <div class="stat-card pending"><div class="stat-icon">📞</div><div class="stat-number"><?php echo htmlspecialchars($user['phone'] ?: 'Not set'); ?></div><div class="campaign-stat-label">Phone</div></div>
                            <div class="stat-card completed"><div class="stat-icon">🏷️</div><div class="stat-number"><?php echo ucfirst($user['role']); ?></div><div class="campaign-stat-label">Role</div></div>
                        </div>
                        <div class="form-container" style="box-shadow:none; padding:0;">
                            <div class="form-section"><h3>Profile overview</h3><p>Manage your account information and see your current role.</p></div>
                            <div class="note-box">This area can be extended later to allow profile editing.</div>
                        </div>
                    </div>
                <?php elseif ($page === 'settings'): ?>
                    <div style="text-align:center; margin-bottom:2rem;">
                        <h1 class="page-title">Settings</h1>
                        <p class="page-subtitle">Configure your volunteer account preferences</p>
                    </div>
                    <div class="form-container">
                        <div class="form-section"><h3>Notification Preferences</h3><p>Receive alerts when new tasks or messages arrive.</p></div>
                        <div class="form-section"><h3>Privacy</h3><p>Your profile information remains protected.</p></div>
                        <div class="form-section"><h3>Account</h3><p>Logout will return you safely to the sign in page.</p></div>
                    </div>
                <?php elseif ($page === 'chat'): ?>
                    <!-- Chat Page -->
                    <h1 class="page-title">Chat with Camp Manager</h1>
                    <p class="page-subtitle">Direct communication with your supervisor</p>

                    <div class="chat-container">
                        <!-- Chat List -->
                        <div class="chat-list">
                            <div class="chat-item active">
                                <div class="chat-item-name">Rajesh Kumar</div>
                                <div class="chat-item-status">🟢 Online - Camp Manager</div>
                            </div>
                        </div>

                        <!-- Chat Window -->
                        <div class="chat-window">
                            <!-- Messages -->
                            <div class="chat-messages">
                                <div class="message">
                                    <div class="message-avatar">RK</div>
                                    <div class="message-content">
                                        <div class="message-text">Great work on today's distribution!</div>
                                        <div class="message-time">11:00 AM</div>
                                    </div>
                                </div>

                                <div class="message">
                                    <div class="message-avatar">RK</div>
                                    <div class="message-content">
                                        <div class="message-text">I have a new task for you</div>
                                        <div class="message-time">11:10 AM</div>
                                    </div>
                                </div>

                                <div class="message sent">
                                    <div class="message-content">
                                        <div class="message-text">Thank you! Completed 15 families</div>
                                        <div class="message-time">11:25 AM</div>
                                    </div>
                                </div>

                                <div class="message sent">
                                    <div class="message-content">
                                        <div class="message-text">Sure, I'm ready for the next task!</div>
                                        <div class="message-time">11:35 AM</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Chat Input -->
                            <div class="chat-input">
                                <input type="text" placeholder="Type your message...">
                                <button onclick="sendMessage()">📤</button>
                            </div>
                        </div>
                    </div>

                <?php elseif ($page === 'report'): ?>
                    <div style="text-align: center; margin-bottom: 2.5rem;">
                        <h2 class="page-title">Report Emergency Issue</h2>
                        <p class="page-subtitle">Alert the camp manager about urgent problems or emergencies</p>
                    </div>

                    <div style="background: white; border-radius: var(--radius-xl); padding: 2.5rem; max-width: 650px; margin: 0 auto; box-shadow: var(--shadow); border: 1px solid var(--gray-100);">
                        <form method="POST">
                            <input type="hidden" name="action" value="submit_emergency_report">
                            
                            <div class="board-column" style="gap: 1.25rem;">
                                <div class="form-group">
                                    <label>Issue Type <span style="color: var(--danger);">*</span></label>
                                    <select name="issue_type" class="form-control" required>
                                        <option value="">Select Issue type</option>
                                        <option value="medical">Medical Emergency</option>
                                        <option value="security">Security Issue</option>
                                        <option value="supply">Supply Shortage</option>
                                        <option value="infrastructure">Infrastructure Problem</option>
                                        <option value="sanitation">Sanitation Issue</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Priority Level <span style="color: var(--danger);">*</span></label>
                                    <select name="priority" class="form-control" required>
                                        <option value="high">High Priority</option>
                                        <option value="critical">Critical / Life Threatening</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Location / Area <span style="color: var(--danger);">*</span></label>
                                    <input type="text" name="location" class="form-control" placeholder="e.g. Section B, Medical Wing" required>
                                </div>

                                <div class="form-group">
                                    <label>People Affected</label>
                                    <input type="number" name="people_affected" class="form-control" placeholder="Number of people (optional)" min="0">
                                </div>

                                <div class="form-group">
                                    <label>Issue Description <span style="color: var(--danger);">*</span></label>
                                    <textarea name="description" class="form-control" style="min-height: 120px;" placeholder="Describe the situation in detail..." required></textarea>
                                </div>

                                <div class="form-group">
                                    <label>Immediate Action Taken</label>
                                    <textarea name="immediate_action" class="form-control" style="min-height: 80px;" placeholder="What steps have already been taken?"></textarea>
                                </div>

                                <div style="background: #fff1f2; color: #be123c; padding: 1.25rem; border-radius: 12px; font-size: 0.9rem; margin-top: 1rem; border: 1px solid #fecaca; line-height: 1.5;">
                                    <strong>⚠️ Critical Issues:</strong> For life-threatening emergencies, please call the camp manager directly or the emergency hotline at 1800-123-4567.
                                </div>

                                <button type="submit" class="btn btn-orange" style="width: 100%; padding: 1rem; margin-top: 1rem; font-size: 1rem;">
                                    📢 Submit Emergency Report
                                </button>
                            </div>
                        </form>
                    </div>

                <?php endif; ?>
            </div>
            </div>
        </main>
    </div>
    <div class="dropdown-menu" id="volProfileMenu" style="position:fixed; top:70px; right:40px; display:none; background:white; border:1px solid #e5e7eb; border-radius:18px; box-shadow:0 18px 60px rgba(15,23,42,0.12); width:220px; z-index:50;">
        <a href="volunteer_dashboard.php?page=profile" style="display:block; padding:0.9rem 1rem; color:#111827; text-decoration:none;">Profile</a>
        <a href="volunteer_dashboard.php?page=settings" style="display:block; padding:0.9rem 1rem; color:#111827; text-decoration:none;">Settings</a>
        <a href="logout.php" style="display:block; padding:0.9rem 1rem; color:#dc2626; text-decoration:none;">Logout</a>
    </div>
    <script>
        function startTask(taskId) {
            if (confirm('Start this task?')) {
                alert('Task started! Status updated to "In Progress"');
                location.reload();
            }
        }

        function completeTask(taskId) {
            if (confirm('Mark this task as completed?')) {
                alert('Task completed successfully!');
                location.reload();
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
            const menu = document.getElementById('volProfileMenu');
            menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
        }

        document.addEventListener('click', function(event) {
            const menu = document.getElementById('volProfileMenu');
            if (!menu) return;
            const button = event.target.closest('.profile-button');
            if (button) return;
            if (!menu.contains(event.target)) {
                menu.style.display = 'none';
            }
        });

        document.getElementById('reportForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            alert('Emergency report submitted! The camp manager has been notified.');
            this.reset();
        });
    </script>
    <script>
        function toggleProfileMenu() {
            const dropdown = document.getElementById('profileDropdown');
            dropdown.classList.toggle('show');
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function closeMenu(e) {
                if (!e.target.closest('.profile-trigger') && !e.target.closest('.profile-dropdown')) {
                    dropdown.classList.remove('show');
                    document.removeEventListener('click', closeMenu);
                }
            });
        }
    </script>
</body>
</html>
<?php $conn->close(); ?>
