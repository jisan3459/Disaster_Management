<?php
// config file for main connection
session_start();

if (file_exists('config.php')) {
    try {
        require_once 'config.php';
    } catch (Exception $e) {
        // Database connection failed, continue with session only
        error_log("Database connection failed: " . $e->getMessage());
    }
}

// Dummy stats logic - replaces these with DB queries later
$camps = 4;
$helped = "1,200";
$donations = "78K";
$volunteers = 15;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Relief System | Home</title>

    <!-- Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Remix Icons -->
    <link href="https://cdn.jsdelivr.net/npm/remixicon@4.2.0/fonts/remixicon.css" rel="stylesheet">

    <!-- AOS Animation Library -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">

    <style>
        /* ================= BASE STYLES ================= */
        *{
            margin:0;
            padding:0;
            box-sizing:border-box;
        }

        body{
            font-family:'Inter', sans-serif;
            background:#f4f5f7;
            color:#111827;
            overflow-x: hidden;
            scroll-behavior: smooth;
        }

        a{
            text-decoration:none;
            color:inherit;
        }

        .container{
            width:1200px;
            margin:auto;
        }

        /* ================= NAVBAR ================= */
        .navbar{
            width:100%;
            height:70px;
            background:#ffffff;
            display:flex;
            align-items:center;
            border-bottom:1px solid #ececec;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .nav-wrapper{
            width:1200px;
            margin:auto;
            display:flex;
            justify-content:space-between;
            align-items:center;
        }

        .logo{
            display:flex;
            align-items:center;
            gap:10px;
            font-size:18px;
            font-weight:700;
        }

        .logo i{
            color:#2563eb;
            font-size:28px;
        }

        .nav-links{
            display:flex;
            align-items:center;
            gap:35px;
            font-size:15px;
            font-weight:500;
        }

        .nav-links a{
            transition:0.3s;
            position: relative;
        }

        .nav-links a:hover{
            color:#2563eb;
        }

        /* Nav link hover underline effect */
        .nav-links a::after {
            content: '';
            position: absolute;
            width: 0;
            height: 2px;
            bottom: -5px;
            left: 0;
            background-color: #2563eb;
            transition: width 0.3s;
        }

        .nav-links a:hover::after {
            width: 100%;
        }

        .login-btn{
            background:#020617;
            color:white;
            padding:12px 24px;
            border-radius:10px;
            font-size:14px;
            font-weight:600;
            transition: 0.3s;
        }

        .login-btn:hover {
            background: #1e293b;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        /* ================= HERO ================= */
        .hero{
            width:100%;
            height:450px;
            background:linear-gradient(180deg,#2563eb 0%, #1e40af 100%);
            display:flex;
            align-items:center;
            justify-content:center;
            text-align:center;
            position:relative;
            color: white;
        }

        .hero-content h1{
            font-size:58px;
            font-weight:800;
            margin-bottom:22px;
        }

        .hero-content p{
            color:#e5e7eb;
            font-size:20px;
            margin-bottom:35px;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }

        .hero-buttons{
            display:flex;
            justify-content:center;
            gap:16px;
        }

        .primary-btn{
            background:white;
            color:black;
            padding:14px 26px;
            border-radius:10px;
            font-weight:600;
            font-size:15px;
            transition: 0.3s;
        }

        .primary-btn:hover {
            transform: scale(1.05);
            background: #f9fafb;
        }

        .secondary-btn{
            border:1px solid white;
            color:white;
            padding:14px 28px;
            border-radius:10px;
            font-weight:600;
            display:flex;
            align-items:center;
            gap:10px;
            font-size:15px;
            transition: 0.3s;
        }

        .secondary-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        /* ================= STATS ================= */
        .stats{
            position: relative;
            z-index: 10;
            margin-top: -50px;
        }

        .stats-wrapper{
            display: flex;
            justify-content: center;
            gap: 18px;
        }

        .stat-card{
            width:265px;
            background:white;
            border-radius:18px;
            padding:28px 20px;
            text-align:center;
            box-shadow:0 10px 25px rgba(0,0,0,0.05);
            transition: 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        .stat-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
        }

        .stat-card i{
            font-size:36px;
            margin-bottom:10px;
        }

        .blue{ color:#2563eb; }
        .green{ color:#16a34a; }
        .red{ color:#dc2626; }
        .purple{ color:#9333ea; }

        .stat-card h2{
            font-size:28px;
            font-weight:800;
            margin-bottom:4px;
        }

        .stat-card p{
            color:#475569;
            font-size:15px;
        }

        /* ================= HELP SECTION ================= */
        .help-section{
            padding:120px 0 80px;
        }

        .help-title{
            text-align:center;
            font-size:42px;
            font-weight:800;
            margin-bottom:70px;
        }

        .help-grid{
            display:flex;
            justify-content:space-between;
            gap:40px;
        }

        .help-card{
            width:33%;
            text-align:center;
            padding: 20px;
        }

        .step-circle{
            width:64px;
            height:64px;
            border-radius:50%;
            margin:auto;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:32px;
            font-weight:700;
            margin-bottom:26px;
        }

        .step-blue{ background:#dbeafe; color:#2563eb; }
        .step-green{ background:#dcfce7; color:#16a34a; }
        .step-purple{ background:#f3e8ff; color:#9333ea; }

        .help-card h3{
            font-size:24px;
            margin-bottom:18px;
            font-weight:700;
        }

        .help-card p{
            color:#475569;
            line-height:1.7;
            font-size:16px;
        }

        /* ================= RESPONSIVE ================= */
        @media(max-width:1250px){
            .container, .nav-wrapper{ width:95%; }
            .stats-wrapper{ flex-wrap:wrap; }
            .stat-card{ width:45%; }
        }

        @media(max-width:900px){
            .nav-links{ display:none; }
            .hero-content h1{ font-size:38px; }
            .help-grid{ flex-direction:column; align-items: center; }
            .help-card{ width:100%; }
            .stat-card{ width:90%; }
        }
    </style>
</head>

<body>

    <!-- NAVBAR -->
    <nav class="navbar">
        <div class="nav-wrapper">
            <div class="logo">
                <i class="ri-shield-line"></i>
                <span>Relief System</span>
            </div>

            <div class="nav-links">
                <a href="index.php">Home</a>
                <a href="admin_dashboard.php">Dashboard</a>
                <a href="campaigns.php">Campaigns</a>
                <a href="signin.php">Donate</a>
                <a href="signin.php">Emergency</a>
            </div>

            <?php if(isset($_SESSION['user_id'])): ?>
                <a href="logout.php" class="login-btn" style="background:#dc2626;">Logout</a>
            <?php else: ?>
                <a href="signin.php" class="login-btn">Login / Register</a>
            <?php endif; ?>
        </div>
    </nav>

    <!-- HERO -->
    <section class="hero">
        <div class="hero-content" data-aos="fade-up" data-aos-duration="1000">
            <h1>Disaster Relief Camp System</h1>
            <p>
                Coordinating relief efforts, managing resources,
                and supporting affected communities in times of crisis.
            </p>

            <div class="hero-buttons">
                <a href="signin.php" class="primary-btn">Emergency Help</a>
                <a href="signin.php" class="secondary-btn">
                    <i class="ri-heart-line"></i>
                    Donate Now
                </a>
            </div>
        </div>
    </section>

    <!-- STATS -->
    <section class="stats">
        <div class="container">
            <div class="stats-wrapper">
                <div class="stat-card" data-aos="zoom-in" data-aos-delay="100">
                    <i class="ri-map-pin-line blue"></i>
                    <h2><?php echo $camps; ?></h2>
                    <p>Active Camps</p>
                </div>

                <div class="stat-card" data-aos="zoom-in" data-aos-delay="200">
                    <i class="ri-group-line green"></i>
                    <h2><?php echo $helped; ?>+</h2>
                    <p>People Helped</p>
                </div>

                <div class="stat-card" data-aos="zoom-in" data-aos-delay="300">
                    <i class="ri-heart-line red"></i>
                    <h2>$<?php echo $donations; ?></h2>
                    <p>Donations Raised</p>
                </div>

                <div class="stat-card" data-aos="zoom-in" data-aos-delay="400">
                    <i class="ri-arrow-right-up-line purple"></i>
                    <h2><?php echo $volunteers; ?></h2>
                    <p>Active Volunteers</p>
                </div>
            </div>
        </div>
    </section>

    <!-- HELP SECTION -->
    <section class="help-section">
        <div class="container">
            <h2 class="help-title" data-aos="fade-up">How We Help</h2>

            <div class="help-grid">
                <div class="help-card" data-aos="fade-up" data-aos-delay="100">
                    <div class="step-circle step-blue">1</div>
                    <h3>Register & Assess</h3>
                    <p>Affected individuals register through our secure portal, and we immediately assess their medical and dietary needs.</p>
                </div>

                <div class="help-card" data-aos="fade-up" data-aos-delay="200">
                    <div class="step-circle step-green">2</div>
                    <h3>Allocate Resources</h3>
                    <p>We use real-time tracking to allocate shelter, clean water, and medical kits to where they are needed most.</p>
                </div>

                <div class="help-card" data-aos="fade-up" data-aos-delay="300">
                    <div class="step-circle step-purple">3</div>
                    <h3>Ongoing Support</h3>
                    <p>Our volunteers provide continuous support and rehabilitation until families are ready to return home safely.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer style="background: #020617; color: white; padding: 60px 0; text-align: center;">
        <div class="container">
            <p style="opacity: 0.7;">&copy; <?php echo date("Y"); ?> Relief System. All rights reserved.</p>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        // Initialize Animations
        AOS.init({
            duration: 800,
            offset: 100,
            once: true
        });
    </script>
</body>
</html>
