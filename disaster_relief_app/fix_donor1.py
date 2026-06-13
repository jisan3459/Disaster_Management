import re

filepath = r'c:\xamppsoft\htdocs\hello\hello\disaster_relief_app\resources\views\donor_dashboard.blade.php'

with open(filepath, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Update Notification backend
old_notif = """$notifications_query = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0");
$notifications = $notifications_query->fetch_assoc();
$unread_count = $notifications['count'];

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
if (!in_array($page, ['dashboard', 'donate', 'history', 'campaigns', 'chat', 'profile', 'settings'])) {"""

new_notif = """$notifications_query = $conn->query("SELECT COUNT(*) as count FROM notifications WHERE user_id = $user_id AND is_read = 0");
$notifications = $notifications_query->fetch_assoc();
$unread_count = $notifications['count'] ?? 0;

$all_notifications_query = $conn->query("SELECT * FROM notifications WHERE user_id = $user_id ORDER BY created_at DESC LIMIT 5");
$all_notifications = [];
if ($all_notifications_query) {
    while ($n = $all_notifications_query->fetch_assoc()) {
        $all_notifications[] = $n;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_notifications_read') {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id = $user_id");
    php_redirect("/donor_dashboard?page=" . ($_GET['page'] ?? 'dashboard'));
}

$page = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
if (!in_array($page, ['dashboard', 'donate', 'history', 'track', 'campaigns', 'chat', 'profile', 'settings'])) {"""

content = content.replace(old_notif, new_notif)

# 2. Update donation post and chat post
old_post = """if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'donate') {
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
}"""

new_post = """// Database Migration for items_description
$d_cols = $conn->query("SHOW COLUMNS FROM donations");
$existing_d_cols = [];
while($row = $d_cols->fetch_assoc()) { $existing_d_cols[] = $row['Field']; }
if (!in_array('items_description', $existing_d_cols)) { $conn->query("ALTER TABLE donations ADD COLUMN items_description TEXT AFTER donation_type"); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'donate') {
    $donation_type = sanitize($_POST['donation_type'] ?? 'money');
    $campaign_id = intval($_POST['campaign_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = sanitize($_POST['payment_method'] ?? '');
    $items_description = sanitize($_POST['items_description'] ?? '');
    $message = sanitize($_POST['message'] ?? '');

    if ($donation_type !== 'money') {
        $payment_method = 'N/A';
        if ($amount <= 0) $amount = 0;
    }

    if (!$campaign_id || ($donation_type === 'money' && (!$payment_method || $amount <= 0)) || ($donation_type !== 'money' && empty($items_description))) {
        $error = 'Please complete the donation form before submitting.';
    } else {
        $transaction_id = 'DR-' . strtoupper(uniqid());
        $donation_type = in_array($donation_type, ['money', 'supplies', 'other']) ? $donation_type : 'money';
        $status = 'pending';

        $insert = $conn->query("INSERT INTO donations (donor_id, campaign_id, amount, donation_type, items_description, status, payment_method, transaction_id) VALUES ($user_id, $campaign_id, $amount, '$donation_type', '$items_description', '$status', '$payment_method', '$transaction_id')");
        if ($insert) {
            $success = 'Thank you! Your donation has been recorded and is pending verification.';
        } else {
            $error = 'Unable to process the donation. Please try again.';
        }
    }
}

// Chat Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'chat') {
    $msg = sanitize($_POST['message'] ?? '');
    if ($msg) {
        $admin_q = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
        $admin_id = ($admin_q && $admin_q->num_rows > 0) ? $admin_q->fetch_assoc()['id'] : 1;
        $conn->query("INSERT INTO messages (sender_id, receiver_id, message_text) VALUES ($user_id, $admin_id, '$msg')");
        php_redirect("/donor_dashboard?page=chat");
    }
}"""

content = content.replace(old_post, new_post)

# 3. Add unread chat count
old_format = "function formatCurrency($amount) {"
new_format = """$unread_chat_query = $conn->query("SELECT COUNT(*) FROM messages WHERE receiver_id = $user_id AND is_read = 0");
$unread_chat = ($unread_chat_query && $unread_chat_query->num_rows > 0) ? $unread_chat_query->fetch_row()[0] : 0;

function formatCurrency($amount) {"""

content = content.replace(old_format, new_format)

# 4. Replace side menu chat unread badge
old_chat_menu = """<a href="?page=chat" class="menu-link <?php echo $page === 'chat' ? 'active' : ''; ?>"><i data-lucide="message-square"></i> <span>Support Chat</span></a>"""
new_chat_menu = """<a href="?page=chat" class="menu-link <?php echo $page === 'chat' ? 'active' : ''; ?>"><i data-lucide="message-square"></i> <span>Support Chat</span><?php if($unread_chat > 0): ?><span style="margin-left:auto; background:#ef4444; color:white; border-radius:999px; padding:2px 6px; font-size:0.7rem; font-weight:bold;"><?php echo $unread_chat; ?></span><?php endif; ?></a>"""
content = content.replace(old_chat_menu, new_chat_menu)

# 5. Add notification dropdown to header
old_header_actions = """<div class="header-actions">
                    <button class="btn btn-primary" onclick="location.href='?page=donate'">Make a Donation</button>
                </div>"""
new_header_actions = """<div class="header-actions" style="display: flex; align-items: center; gap: 0.5rem;">
                    <div style="position: relative;">
                        <button class="btn btn-outline" style="border:none; background:transparent;" onclick="document.getElementById('donorNotifDropdown').classList.toggle('show')">
                            <i data-lucide="bell" style="width:20px;"></i>
                            <?php if ($unread_count > 0): ?>
                                <span style="position: absolute; top: -2px; right: -2px; background: var(--danger); color: white; width: 16px; height: 16px; border-radius: 50%; font-size: 0.65rem; display: grid; place-items: center; font-weight: bold;"><?php echo $unread_count; ?></span>
                            <?php endif; ?>
                        </button>
                        <div id="donorNotifDropdown" style="display: none; position: absolute; right: 0; top: calc(100% + 10px); width: 320px; background: white; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; z-index: 50; overflow: hidden;" class="cm-dropdown">
                            <div style="padding: 1rem; border-bottom: 1px solid #e2e8f0; background: #f8fafc;">
                                <h4 style="margin: 0; font-size: 0.95rem; font-weight: 700;">Notifications</h4>
                            </div>
                            <?php if (empty($all_notifications)): ?>
                                <div style="padding: 1.5rem; text-align: center; color: #64748b; font-size: 0.85rem;">No new notifications</div>
                            <?php else: ?>
                                <div style="max-height: 300px; overflow-y: auto;">
                                    <?php foreach ($all_notifications as $notif): ?>
                                        <div style="padding: 1rem; border-bottom: 1px solid #e2e8f0; background: <?php echo $notif['is_read'] ? '#ffffff' : '#f0f9ff'; ?>;">
                                            <div style="font-weight: 700; font-size: 0.85rem; color: #0f172a;"><?php echo htmlspecialchars($notif['title']); ?></div>
                                            <div style="font-size: 0.8rem; color: #475569; margin-top: 0.25rem;"><?php echo htmlspecialchars($notif['message']); ?></div>
                                            <div style="font-size: 0.7rem; color: #94a3b8; margin-top: 0.5rem;"><?php echo date('M d, H:i', strtotime($notif['created_at'])); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div style="padding: 0.75rem; text-align: center; border-top: 1px solid #e2e8f0; background: #f8fafc;">
                                    <form method="POST" style="margin: 0;">
                                        @csrf
                                        <input type="hidden" name="action" value="mark_notifications_read">
                                        <button type="submit" style="background: none; border: none; color: var(--primary); font-size: 0.85rem; font-weight: 700; cursor: pointer;">Mark all as read</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <button class="btn btn-primary" onclick="location.href='?page=donate'">Make a Donation</button>
                </div>"""
content = content.replace(old_header_actions, new_header_actions)

with open(filepath, 'w', encoding='utf-8') as f:
    f.write(content)
print("Updated donor dashboard phase 1")
