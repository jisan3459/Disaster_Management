<?php
// Include database configuration and establish connection
require_once 'config.php';

// Check if user is logged in
$is_logged_in = isLoggedIn();
$user_role = $_SESSION['role'] ?? 'guest';
$user_name = $_SESSION['full_name'] ?? 'Guest';

// Initialize response variables
$donation_success = false;
$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $donation_type = sanitize($_POST['donation_type'] ?? 'money');
    $amount = $_POST['amount'] ?? 0;
    $full_name = sanitize($_POST['fullName'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $message = sanitize($_POST['message'] ?? '');
    $campaign_id = sanitize($_POST['campaign_id'] ?? '');
    
    // Validation
    $amount = floatval($amount);
    
    if (!$full_name || !$email) {
        $error_message = 'Please fill in all required fields (Name and Email)';
    } elseif ($amount <= 0) {
        $error_message = 'Please enter a valid donation amount';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address';
    } else {
        // Check if donor exists or create new donor record
        $check_donor = $conn->query("SELECT id FROM users WHERE email = '$email' AND role = 'donor'");
        
        if ($check_donor->num_rows === 0) {
            // Create new donor user
            $password_hash = password_hash('donor_' . time(), PASSWORD_BCRYPT);
            $insert_donor = "INSERT INTO users (role, full_name, email, password, status) 
                            VALUES ('donor', '$full_name', '$email', '$password_hash', 'inactive')";
            
            if (!$conn->query($insert_donor)) {
                $error_message = 'Error creating donor record: ' . $conn->error;
            } else {
                $donor_id = $conn->insert_id;
            }
        } else {
            $donor_result = $check_donor->fetch_assoc();
            $donor_id = $donor_result['id'];
        }
        
        // Insert donation record if no errors
        if (!$error_message) {
            $campaign_id = $campaign_id ? intval($campaign_id) : null;
            $campaign_clause = $campaign_id ? $campaign_id : 'NULL';
            
            $insert_donation = "INSERT INTO donations (donor_id, campaign_id, amount, donation_type, status) 
                               VALUES ($donor_id, $campaign_clause, $amount, '$donation_type', 'pending')";
            
            if ($conn->query($insert_donation)) {
                $donation_success = true;
                $success_message = "Thank you for your donation of \$$amount! Your contribution is now pending verification and will make a real difference once approved.";
            } else {
                $error_message = 'Error processing donation: ' . $conn->error;
            }
        }
    }
}

// Fetch campaigns for dropdown
$campaigns_query = "SELECT id, campaign_name FROM campaigns WHERE status = 'active' ORDER BY campaign_name";
$campaigns_result = $conn->query($campaigns_query);
$campaigns = [];
while ($row = $campaigns_result->fetch_assoc()) {
    $campaigns[] = $row;
}

// Fetch urgent needs
$urgent_query = "SELECT category, COUNT(*) as count FROM inventory WHERE status = 'Limited' OR status = 'Out of Stock' GROUP BY category LIMIT 3";
$urgent_result = $conn->query($urgent_query);
$urgent_items = [];
$priority_map = ['Medical Supplies' => 'Critical', 'Food' => 'High', 'Blankets' => 'Medium'];

while ($row = $urgent_result->fetch_assoc()) {
    $urgent_items[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relief System - Make a Donation</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-blue: #2563eb;
            --primary-blue-light: #eff6ff;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --bg-light: #f8fafc;
            --border-color: #e2e8f0;
            --footer-bg: #0b1329;
            --input-bg: #f1f5f9;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-light);
            color: var(--text-dark);
            line-height: 1.5;
        }

        /* --- Header Navigation --- */
        header {
            background-color: #ffffff;
            border-bottom: 1px solid var(--border-color);
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--text-dark);
            text-decoration: none;
        }

        .logo i {
            color: var(--primary-blue);
        }

        nav {
            display: flex;
            gap: 1.5rem;
        }

        nav a {
            text-decoration: none;
            color: var(--text-muted);
            font-size: 0.9rem;
            font-weight: 500;
            transition: color 0.2s;
        }

        nav a:hover, nav a.active {
            color: var(--text-dark);
        }

        .btn-login {
            background-color: #000000;
            color: #ffffff;
            padding: 0.5rem 1.2rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
        }

        /* --- Main Content Section --- */
        main {
            max-width: 1100px;
            margin: 3.5rem auto;
            padding: 0 1.5rem;
        }

        .page-header {
            text-align: center;
            margin-bottom: 3rem;
        }

        .page-title {
            font-size: 2.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            color: var(--text-muted);
            font-size: 1rem;
        }

        /* --- Split Layout: Form & Sidebar --- */
        .donation-container {
            display: grid;
            grid-template-columns: 1.6fr 1fr;
            gap: 2.5rem;
            align-items: start;
        }

        /* --- Forms and Panels Common styling --- */
        .card-panel {
            background-color: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .panel-heading {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        /* --- Step 1: Donation Type Selectors --- */
        .donation-type-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }

        .type-card {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 1.75rem 1rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: #ffffff;
        }

        .type-card i {
            font-size: 2rem;
            margin-bottom: 0.75rem;
        }

        .type-card.active {
            border-color: var(--primary-blue);
            background-color: var(--primary-blue-light);
        }

        .type-card.active i {
            color: #10b981; /* Green color from image for Monetary dollar */
        }

        .type-card:not(.active) i {
            color: var(--primary-blue); /* Blue for package supply */
        }

        .type-card h4 {
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .type-card p {
            font-size: 0.75rem;
            color: var(--text-muted);
        }

        /* --- Form Inputs Fields --- */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .form-control {
            width: 100%;
            background-color: var(--input-bg);
            border: 1px solid transparent;
            border-radius: 6px;
            padding: 0.75rem 1rem;
            font-family: inherit;
            font-size: 0.875rem;
            color: var(--text-dark);
            outline: none;
        }

        .form-control::placeholder {
            color: #94a3b8;
        }

        select.form-control {
            cursor: pointer;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        /* --- Amount Fast Selection --- */
        .amount-row {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .btn-amount {
            background-color: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-amount:hover {
            border-color: var(--text-dark);
            background-color: var(--input-bg);
        }

        /* --- Payment Expiry row setup --- */
        .form-row-half {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
        }

        .btn-submit {
            width: 100%;
            background-color: #000000;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            padding: 0.85rem;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }

        .btn-submit:hover {
            background-color: #1e293b;
        }

        /* --- Sidebar Panels --- */
        .sidebar-panel {
            background-color: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 1.5rem;
        }

        .sidebar-panel h3 {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 1.25rem;
        }

        /* Impact list alert styles */
        .impact-item {
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            font-size: 0.85rem;
        }

        .impact-item strong {
            display: block;
            margin-bottom: 0.15rem;
        }

        .impact-blue { background-color: #eff6ff; color: #1e40af; }
        .impact-green { background-color: #f0fdf4; color: #166534; }
        .impact-purple { background-color: #f5f3ff; color: #5b21b6; }
        .impact-orange { background-color: #fff7ed; color: #9a3412; }

        /* Why Donate checklist styling */
        .why-donate-list {
            list-style: none;
        }

        .why-donate-list li {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .why-donate-list li i {
            color: #10b981;
        }

        /* Urgent needs custom table container */
        .urgent-panel {
            border: 1px solid #fee2e2;
            background-color: #fff5f5;
        }

        .urgent-panel h3 {
            color: #991b1b;
        }

        .urgent-list {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .urgent-list td {
            padding: 0.5rem 0;
        }

        .urgent-list td:first-child {
            color: #991b1b;
            font-weight: 500;
        }

        .urgent-list td:last-child {
            text-align: right;
            font-weight: 600;
        }

        .status-critical { color: #dc2626; }
        .status-high { color: #dc2626; }
        .status-medium { color: #d97706; }

        /* --- Footer Styles --- */
        footer {
            background-color: var(--footer-bg);
            color: #94a3b8;
            padding: 4rem 2rem 2rem 2rem;
            font-size: 0.85rem;
            margin-top: 5rem;
        }

        .footer-container {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 2fr repeat(3, 1fr);
            gap: 4rem;
            padding-bottom: 3rem;
            border-bottom: 1px solid #1e293b;
        }

        .footer-brand h3 {
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 1.2rem;
            margin-bottom: 1rem;
        }

        .footer-brand h3 i {
            color: var(--primary-blue);
        }

        .footer-brand p {
            line-height: 1.6;
            max-width: 260px;
        }

        .footer-column h4 {
            color: #ffffff;
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 1.25rem;
        }

        .footer-column ul {
            list-style: none;
        }

        .footer-column ul li {
            margin-bottom: 0.75rem;
        }

        .footer-column ul li a {
            color: #94a3b8;
            text-decoration: none;
            transition: color 0.2s;
        }

        .footer-column ul li a:hover {
            color: #ffffff;
        }

        .emergency-text {
            color: #ef4444 !important;
            font-weight: 600;
        }

        .footer-bottom {
            max-width: 1200px;
            margin: 2rem auto 0 auto;
            text-align: center;
            font-size: 0.75rem;
            color: #64748b;
        }

        /* Responsive UI tweaks */
        @media (max-width: 868px) {
            .donation-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

    <header>
        <a href="{{ url('/') }}" class="logo">
            <i class="fa-solid fa-shield-halved"></i> Relief System
        </a>
        <nav>
            <a href="{{ url('/') }}">Home</a>
            <?php if ($is_logged_in): ?>
                <?php if ($user_role === 'admin'): ?>
                    <a href="{{ url('admin_dashboard') }}">Dashboard</a>
                <?php elseif ($user_role === 'camp_manager'): ?>
                    <a href="{{ url('camp_manager_dashboard') }}">Dashboard</a>
                <?php elseif ($user_role === 'volunteer'): ?>
                    <a href="{{ url('volunteer_dashboard') }}">Dashboard</a>
                <?php elseif ($user_role === 'donor'): ?>
                    <a href="{{ url('donor_dashboard') }}">Dashboard</a>
                <?php endif; ?>
            <?php endif; ?>
            <a href="{{ url('/') }}#about">About</a>
            <a href="{{ url('campaigns') }}">Campaigns</a>
            <a href="{{ url('donate') }}" class="active">Donate</a>
            <a href="{{ url('/') }}#emergency">Emergency</a>
            <a href="{{ url('/') }}#contact">Contact</a>
        </nav>
        <?php if ($is_logged_in): ?>
            <div style="display: flex; align-items: center; gap: 1rem;">
                <span style="color: var(--text-dark); font-size: 0.9rem; font-weight: 500;">Welcome, <?php echo htmlspecialchars(explode(' ', $user_name)[0]); ?></span>
                <a href="{{ url('logout') }}" class="btn-login">Logout</a>
            </div>
        <?php else: ?>
            <div style="display: flex; gap: 0.5rem;">
                <a href="{{ url('signin') }}" class="btn-login">Login</a>
                <a href="{{ url('signup') }}" style="background-color: var(--primary-blue); color: white; padding: 0.5rem 1.2rem; border-radius: 6px; text-decoration: none; font-size: 0.85rem; font-weight: 600;">Register</a>
            </div>
        <?php endif; ?>
    </header>

    <main>
        <div class="page-header">
            <h1 class="page-title">Make a Donation</h1>
            <p class="page-subtitle">Your contribution saves lives and rebuilds communities</p>
        </div>

        <?php if ($donation_success): ?>
            <div style="background-color: #d4edda; color: #155724; padding: 1rem; border-radius: 6px; margin-bottom: 2rem; border: 1px solid #c3e6cb;">
                <strong>✓ Donation Successful!</strong><br>
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div style="background-color: #f8d7da; color: #721c24; padding: 1rem; border-radius: 6px; margin-bottom: 2rem; border: 1px solid #f5c6cb;">
                <strong>✗ Error:</strong><br>
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <div class="donation-container">
            <div class="donation-form-flow">
                
                <form method="POST" action="{{ url('donate') }}">
                    @csrf
                
                <div class="card-panel">
                    <h3 class="panel-heading">Choose Donation Type</h3>
                    <div class="donation-type-grid">
                        <div class="type-card active" onclick="document.getElementById('donation_type').value='money'; this.parentElement.querySelectorAll('.type-card').forEach(c=>c.classList.remove('active')); this.classList.add('active');">
                            <i class="fa-solid fa-dollar-sign"></i>
                            <h4>Monetary Donation</h4>
                            <p>Most flexible way to help</p>
                        </div>
                        <div class="type-card" onclick="document.getElementById('donation_type').value='supplies'; this.parentElement.querySelectorAll('.type-card').forEach(c=>c.classList.remove('active')); this.classList.add('active');">
                            <i class="fa-solid fa-box-open"></i>
                            <h4>Supply Donation</h4>
                            <p>Donate goods directly</p>
                        </div>
                    </div>
                    <input type="hidden" id="donation_type" name="donation_type" value="money">
                </div>

                <div class="card-panel">
                    <h3 class="panel-heading">Select Campaign (Optional)</h3>
                    <div class="form-group">
                        <label for="campaign">Campaign</label>
                        <select id="campaign" name="campaign_id" class="form-control">
                            <option value="">-- Support General Relief Fund --</option>
                            <?php foreach ($campaigns as $campaign): ?>
                                <option value="<?php echo htmlspecialchars($campaign['id']); ?>">
                                    <?php echo htmlspecialchars($campaign['campaign_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="card-panel">
                    <h3 class="panel-heading">Donation Amount</h3>
                    <div class="form-group">
                        <label for="amount">Amount ($) *</label>
                        <input type="number" id="amount" name="amount" class="form-control" placeholder="Enter amount" step="0.01" min="1" required>
                    </div>
                    <div class="amount-row">
                        <button type="button" class="btn-amount" onclick="document.getElementById('amount').value='25'">$25</button>
                        <button type="button" class="btn-amount" onclick="document.getElementById('amount').value='50'">$50</button>
                        <button type="button" class="btn-amount" onclick="document.getElementById('amount').value='100'">$100</button>
                        <button type="button" class="btn-amount" onclick="document.getElementById('amount').value='250'">$250</button>
                        <button type="button" class="btn-amount" onclick="document.getElementById('amount').value='500'">$500</button>
                        <button type="button" class="btn-amount" onclick="document.getElementById('amount').value='1000'">$1000</button>
                    </div>
                </div>

                <div class="card-panel">
                    <h3 class="panel-heading">Your Information</h3>
                    <div class="form-group">
                        <label for="fullName">Full Name *</label>
                        <input type="text" id="fullName" name="fullName" class="form-control" placeholder="Enter your name" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" class="form-control" placeholder="your@email.com" required>
                    </div>
                    <div class="form-group">
                        <label for="message">Message (Optional)</label>
                        <textarea id="message" name="message" class="form-control" placeholder="Add a message of support..."></textarea>
                    </div>
                </div>

                <div class="card-panel">
                    <h3 class="panel-heading"><i class="fa-regular fa-credit-card"></i> Payment Information</h3>
                    <div style="padding: 1rem; background-color: #f0fdf4; border-radius: 6px; margin-bottom: 1rem; font-size: 0.85rem; color: #166534;">
                        <strong>Demo Mode:</strong> This is a demo donation system. In production, integrate with a payment gateway like Stripe or PayPal.
                    </div>
                    <div class="form-group">
                        <label for="cardNumber">Card Number</label>
                        <input type="text" id="cardNumber" class="form-control" placeholder="1234 5678 9012 3456">
                    </div>
                    <div class="form-row-half">
                        <div class="form-group">
                            <label for="expiry">Expiry Date</label>
                            <input type="text" id="expiry" class="form-control" placeholder="MM/YY">
                        </div>
                        <div class="form-group">
                            <label for="cvv">CVV</label>
                            <input type="text" id="cvv" class="form-control" placeholder="123">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-submit">
                        <i class="fa-regular fa-heart"></i> Complete Donation
                    </button>
                </div>

                </form>

            </div>

            <div class="donation-sidebar">
                
                <div class="sidebar-panel">
                    <h3>Your Impact</h3>
                    <div class="impact-item impact-blue">
                        <strong>$25</strong>
                        Provides meals for a family for 1 day
                    </div>
                    <div class="impact-item impact-green">
                        <strong>$50</strong>
                        Supplies basic medical aid kit
                    </div>
                    <div class="impact-item impact-purple">
                        <strong>$100</strong>
                        Provides shelter for a week
                    </div>
                    <div class="impact-item impact-orange">
                        <strong>$250+</strong>
                        Comprehensive family support package
                    </div>
                </div>

                <div class="sidebar-panel">
                    <h3>Why Donate?</h3>
                    <ul class="why-donate-list">
                        <li><i class="fa-solid fa-check"></i> 100% of funds go to relief efforts</li>
                        <li><i class="fa-solid fa-check"></i> Tax deductible receipts provided</li>
                        <li><i class="fa-solid fa-check"></i> Track your donation's impact</li>
                        <li><i class="fa-solid fa-check"></i> Secure payment processing</li>
                        <li><i class="fa-solid fa-check"></i> Immediate help to those in need</li>
                    </ul>
                </div>

                <div class="sidebar-panel urgent-panel">
                    <h3>Urgent Needs</h3>
                    <table class="urgent-list">
                        <tr>
                            <td>Medical Supplies</td>
                            <td class="status-critical">Critical</td>
                        </tr>
                        <tr>
                            <td>Food Packets</td>
                            <td class="status-high">High</td>
                        </tr>
                        <tr>
                            <td>Blankets</td>
                            <td class="status-medium">Medium</td>
                        </tr>
                    </table>
                </div>

            </div>
        </div>
    </main>

    <footer>
        <div class="footer-container">
            <div class="footer-brand">
                <h3><i class="fa-solid fa-shield-halved"></i> Relief System</h3>
                <p>Comprehensive Disaster Relief & Volunteer Coordination Platform</p>
            </div>
            <div class="footer-column">
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="{{ url('/') }}">Home</a></li>
                    <li><a href="{{ url('admin_dashboard') }}">Live Dashboard</a></li>
                    <li><a href="{{ url('/') }}#about">About Us</a></li>
                    <li><a href="{{ url('campaigns') }}">Campaigns</a></li>
                    <li><a href="{{ url('/') }}#contact">Contact</a></li>
                </ul>
            </div>
            <div class="footer-column">
                <h4>Get Involved</h4>
                <ul>
                    <li><a href="{{ url('donate') }}">Make a Donation</a></li>
                    <li><a href="{{ url('signup') }}">Volunteer</a></li>
                    <li><a href="{{ url('signup') }}">Need Help?</a></li>
                    <li><a href="{{ url('/') }}">Partner With Us</a></li>
                </ul>
            </div>
            <div class="footer-column">
                <h4>Contact</h4>
                <ul>
                    <li><a href="#" class="emergency-text">Emergency: 1-800-RELIEF</a></li>
                    <li><a href="#">Email: contact@relief.org</a></li>
                    <li><a href="#">Phone: +1 (555) 123-4567</a></li>
                    <li><a href="#">Available 24/7</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">
            &copy; 2026 Relief System. All rights reserved.
        </div>
    </footer>

</body>
</html>