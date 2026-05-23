<?php
// Include database configuration and establish connection
require_once '/config';

// Check if user is logged in
$is_logged_in = isLoggedIn();
$user_role = $_SESSION['role'] ?? 'guest';
$user_name = $_SESSION['full_name'] ?? 'Guest';

// Fetch all active campaigns
$campaigns_query = "
    SELECT 
        c.id,
        c.campaign_name,
        c.description,
        c.location,
        c.goal_amount,
        c.raised_amount,
        c.urgency,
        c.status,
        c.start_date,
        c.end_date,
        COUNT(DISTINCT d.donor_id) as donor_count
    FROM campaigns c
    LEFT JOIN donations d ON c.id = d.campaign_id AND d.status = 'completed'
    WHERE c.status = 'active'
    GROUP BY c.id, c.campaign_name, c.description, c.location, c.goal_amount, c.raised_amount, c.urgency, c.status, c.start_date, c.end_date
    ORDER BY c.raised_amount DESC, c.created_at DESC
";

$campaigns_result = $conn->query($campaigns_query);

if (!$campaigns_result) {
    die("Query Error: " . $conn->error);
}

$campaigns = [];
while ($row = $campaigns_result->fetch_assoc()) {
    $campaigns[] = $row;
}

// Calculate funding percentage
function getFundingPercentage($raised, $goal) {
    if ($goal == 0) return 0;
    $percentage = ($raised / $goal) * 100;
    return min($percentage, 100); // Cap at 100%
}

// Get campaign icon based on name/urgency
function getCampaignIcon($campaign_name, $urgency) {
    $name_lower = strtolower($campaign_name);
    
    if (strpos($name_lower, 'flood') !== false || strpos($name_lower, 'water') !== false) {
        return array('icon' => 'fa-cloud-showers-water', 'class' => 'icon-blue');
    } elseif (strpos($name_lower, 'medical') !== false || strpos($name_lower, 'medicine') !== false) {
        return array('icon' => 'fa-prescription-bottle-medical', 'class' => 'icon-purple');
    } elseif (strpos($name_lower, 'shelter') !== false || strpos($name_lower, 'house') !== false) {
        return array('icon' => 'fa-house-chimney-crack', 'class' => 'icon-orange');
    } elseif (strpos($name_lower, 'education') !== false || strpos($name_lower, 'school') !== false) {
        return array('icon' => 'fa-book-open', 'class' => 'icon-yellow');
    } elseif (strpos($name_lower, 'clothing') !== false || strpos($name_lower, 'clothes') !== false) {
        return array('icon' => 'fa-shirt', 'class' => 'icon-grey');
    } elseif (strpos($name_lower, 'food') !== false || strpos($name_lower, 'meal') !== false) {
        return array('icon' => 'fa-utensils', 'class' => 'icon-green');
    } else {
        return array('icon' => 'fa-heart', 'class' => 'icon-blue');
    }
}

// Format currency
function formatCurrency($amount) {
    return '৳' . number_format($amount, 0);
}

// Format date
function formatEndDate($end_date) {
    if ($end_date) {
        return date('Y-m-d', strtotime($end_date));
    }
    return 'Ongoing';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relief System - Active Campaigns</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-blue: #2563eb;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --bg-light: #f8fafc;
            --border-color: #e2e8f0;
            --footer-bg: #0b1329;
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

        /* --- Main Content Container --- */
        main {
            max-width: 1200px;
            margin: 3.5rem auto;
            padding: 0 1.5rem;
            text-align: center;
        }

        .section-title {
            font-size: 2.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .section-subtitle {
            color: var(--text-muted);
            font-size: 1rem;
            max-width: 600px;
            margin: 0 auto 3.5rem auto;
        }

        /* --- Campaign Grid --- */
        .campaign-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
            margin-bottom: 4rem;
        }

        .campaign-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2rem;
            text-align: left;
            display: flex;
            flex-direction: column;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        .card-icon {
            font-size: 1.75rem;
            margin-bottom: 1rem;
        }
        
        /* Custom coloring for different campaign icons based on the UI */
        .icon-blue { color: #06b6d4; }
        .icon-purple { color: #8b5cf6; }
        .icon-orange { color: #f97316; }
        .icon-yellow { color: #eab308; }
        .icon-grey { color: #64748b; }
        .icon-green { color: #10b981; }

        .campaign-card h3 {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
        }

        .campaign-card p {
            color: var(--text-muted);
            font-size: 0.870rem;
            min-height: 4rem;
            margin-bottom: 1.5rem;
            line-height: 1.45;
        }

        .funding-info {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
        }

        .funding-info .label {
            color: var(--text-muted);
        }

        .funding-info .amount {
            font-weight: 700;
        }

        /* Progress Bar Setup */
        .progress-bar-container {
            background-color: #f1f5f9;
            border-radius: 9999px;
            height: 6px;
            width: 100%;
            margin-bottom: 0.5rem;
            overflow: hidden;
        }

        .progress-bar {
            background-color: #000000;
            height: 100%;
            border-radius: 9999px;
        }

        .meta-info {
            display: flex;
            justify-content: space-between;
            color: var(--text-muted);
            font-size: 0.8rem;
            margin-bottom: 1.5rem;
        }

        .btn-donate {
            background-color: #000000;
            color: #ffffff;
            text-align: center;
            padding: 0.75rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: auto;
            transition: background-color 0.2s;
        }

        .btn-donate:hover {
            background-color: #1e293b;
        }

        /* --- "Why Support Us" Feature Section --- */
        .features-section {
            background-color: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 2.5rem;
            margin-top: 4rem;
        }

        .features-section h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 2.5rem;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
        }

        .feature-item {
            text-align: center;
        }

        .feature-item i {
            color: #000000;
            font-size: 1.25rem;
            margin-bottom: 0.75rem;
        }

        .feature-item h4 {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .feature-item p {
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        /* --- Footer Styles --- */
        footer {
            background-color: var(--footer-bg);
            color: #94a3b8;
            padding: 4rem 2rem 2rem 2rem;
            font-size: 0.85rem;
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

        /* Responsive Breakpoints */
        @media (max-width: 1024px) {
            .campaign-grid, .features-grid, .footer-container {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 640px) {
            .campaign-grid, .features-grid, .footer-container {
                grid-template-columns: 1fr;
            }
            header {
                flex-direction: column;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>

    <header>
        <a href="/index" class="logo">
            <i class="fa-solid fa-shield-halved"></i> Relief System
        </a>
        <nav>
            <a href="/index">Home</a>
            <?php if ($is_logged_in): ?>
                <?php if ($user_role === 'admin'): ?>
                    <a href="/admin_dashboard">Dashboard</a>
                <?php elseif ($user_role === 'camp_manager'): ?>
                    <a href="/camp_manager_dashboard">Dashboard</a>
                <?php elseif ($user_role === 'volunteer'): ?>
                    <a href="/volunteer_dashboard">Dashboard</a>
                <?php elseif ($user_role === 'donor'): ?>
                    <a href="/donor_dashboard">Dashboard</a>
                <?php endif; ?>
            <?php endif; ?>
            <a href="/index#about">About</a>
            <a href="/campaigns" class="active">Campaigns</a>
            <a href="/index#donate">Donate</a>
            <a href="/index#emergency">Emergency</a>
            <a href="/index#contact">Contact</a>
        </nav>
        <?php if ($is_logged_in): ?>
            <div style="display: flex; align-items: center; gap: 1rem;">
                <span style="color: var(--text-dark); font-size: 0.9rem; font-weight: 500;">Welcome, <?php echo htmlspecialchars(explode(' ', $user_name)[0]); ?></span>
                <a href="/logout" class="btn-login">Logout</a>
            </div>
        <?php else: ?>
            <div style="display: flex; gap: 0.5rem;">
                <a href="/signin" class="btn-login">Login</a>
                <a href="/signup" style="background-color: var(--primary-blue); color: white; padding: 0.5rem 1.2rem; border-radius: 6px; text-decoration: none; font-size: 0.85rem; font-weight: 600;">Register</a>
            </div>
        <?php endif; ?>
    </header>

    <main>
        <h1 class="section-title">Active Campaigns</h1>
        <p class="section-subtitle">Support our ongoing relief campaigns and make a direct impact on affected communities</p>

        <div class="campaign-grid">
            
            <?php
            if (count($campaigns) > 0) {
                foreach ($campaigns as $campaign) {
                    $funding_percent = getFundingPercentage($campaign['raised_amount'], $campaign['goal_amount']);
                    $icon_data = getCampaignIcon($campaign['campaign_name'], $campaign['urgency']);
                    $end_date = formatEndDate($campaign['end_date']);
                    $description = $campaign['description'] ?? 'No description available';
                    $description_preview = substr($description, 0, 100) . (strlen($description) > 100 ? '...' : '');
                    
                    echo "
                    <div class=\"campaign-card\">
                        <div class=\"card-icon {$icon_data['class']}\"><i class=\"fa-solid {$icon_data['icon']}\"></i></div>
                        <h3>" . htmlspecialchars($campaign['campaign_name']) . "</h3>
                        <p>" . htmlspecialchars($description_preview) . "</p>
                        <div class=\"funding-info\">
                            <span class=\"label\">Raised</span>
                            <span class=\"amount\">" . formatCurrency($campaign['raised_amount']) . " of " . formatCurrency($campaign['goal_amount']) . "</span>
                        </div>
                        <div class=\"progress-bar-container\">
                            <div class=\"progress-bar\" style=\"width: " . $funding_percent . "%;\" ></div>
                        </div>
                        <div class=\"meta-info\">
                            <span>" . round($funding_percent) . "% funded</span>
                            <span>Ends $end_date</span>
                        </div>
                        <div class=\"meta-info\" style=\"margin-top: -1rem; margin-bottom: 1.5rem;\">
                            <span>" . $campaign['donor_count'] . " donors</span>
                        </div>
                        <a href=\"#\" class=\"btn-donate\"><i class=\"fa-regular fa-heart\"></i> Donate to Campaign</a>
                    </div>
                    ";
                }
            } else {
                echo "<p style=\"text-align: center; padding: 2rem; color: var(--text-muted);\">No active campaigns at the moment.</p>";
            }
            ?>

        </div>

        <div class="features-section">
            <h2>Why Support Our Campaigns?</h2>
            <div class="features-grid">
                <div class="feature-item">
                    <i class="fa-solid fa-check"></i>
                    <h4>100% Utilization</h4>
                    <p>Every dollar goes directly to relief efforts</p>
                </div>
                <div class="feature-item">
                    <i class="fa-solid fa-check"></i>
                    <h4>Full Transparency</h4>
                    <p>Track exactly where your donation goes</p>
                </div>
                <div class="feature-item">
                    <i class="fa-solid fa-check"></i>
                    <h4>Direct Impact</h4>
                    <p>See the real difference you're making</p>
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
                    <li><a href="#">Home</a></li>
                    <li><a href="#">Live Dashboard</a></li>
                    <li><a href="#">About Us</a></li>
                    <li><a href="#">Campaigns</a></li>
                    <li><a href="#">Contact</a></li>
                </ul>
            </div>
            <div class="footer-column">
                <h4>Get Involved</h4>
                <ul>
                    <li><a href="#">Make a Donation</a></li>
                    <li><a href="#">Volunteer</a></li>
                    <li><a href="#">Need Help?</a></li>
                    <li><a href="#">Partner With Us</a></li>
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