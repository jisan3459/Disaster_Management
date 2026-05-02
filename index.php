<?php
// DisasterRelief Landing Page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DisasterRelief - Coordinating Relief. Saving Lives Together.</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
            color: #333;
        }

        /* Header */
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.5rem 3rem;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: bold;
            font-size: 1.5rem;
            color: #1a73e8;
            text-decoration: none;
        }

        .logo::before {
            content: '🛡️';
            font-size: 1.8rem;
        }

        .header-nav {
            display: flex;
            gap: 2rem;
            align-items: center;
        }

        .header-nav a {
            color: #333;
            text-decoration: none;
            font-size: 0.95rem;
            transition: color 0.3s;
        }

        .header-nav a:hover {
            color: #1a73e8;
        }

        .btn-get-started {
            background-color: #ff6b35;
            color: white;
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            transition: background-color 0.3s;
        }

        .btn-get-started:hover {
            background-color: #e55a25;
        }

        /* Hero Section */
        .hero {
            text-align: center;
            padding: 4rem 3rem;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
        }

        .hero h1 {
            font-size: 3rem;
            color: #1a1a1a;
            margin-bottom: 1rem;
            line-height: 1.2;
        }

        .hero p {
            font-size: 1.1rem;
            color: #666;
            margin-bottom: 2rem;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }

        .hero-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-bottom: 3rem;
        }

        .btn-primary {
            background-color: #1a73e8;
            color: white;
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: background-color 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary:hover {
            background-color: #1557b0;
        }

        .btn-secondary {
            background-color: white;
            color: #1a1a1a;
            padding: 0.8rem 2rem;
            border: 2px solid #ddd;
            border-radius: 6px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-secondary:hover {
            border-color: #ff6b35;
            color: #ff6b35;
        }

        /* Stats Section */
        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
            max-width: 1000px;
            margin: 0 auto;
            margin-top: 2rem;
        }

        .stat-card {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.12);
        }

        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: bold;
            color: #1a1a1a;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            color: #666;
            font-size: 0.95rem;
        }

        /* Campaigns Section */
        .campaigns {
            padding: 4rem 3rem;
            background: #f8f9fa;
        }

        .campaigns h2 {
            text-align: center;
            font-size: 2.2rem;
            color: #1a1a1a;
            margin-bottom: 0.5rem;
        }

        .campaigns-subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 3rem;
        }

        .campaigns-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2rem;
            max-width: 1200px;
            margin: 0 auto;
        }

        .campaign-card {
            background: white;
            border-radius: 8px;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .campaign-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.12);
        }

        .campaign-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1.5rem;
        }

        .campaign-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1a1a1a;
        }

        .urgent-badge {
            background-color: #ff4444;
            color: white;
            padding: 0.3rem 0.8rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .campaign-progress {
            margin-bottom: 1.5rem;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 0.5rem;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background-color: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4caf50 0%, #45a049 100%);
            border-radius: 4px;
        }

        .campaign-stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid #e0e0e0;
        }

        .campaign-stat {
            text-align: center;
        }

        .campaign-stat-value {
            font-weight: bold;
            color: #1a1a1a;
            font-size: 0.95rem;
        }

        .campaign-stat-label {
            color: #999;
            font-size: 0.8rem;
        }

        .campaign-footer {
            display: flex;
            justify-content: center;
        }

        .btn-donate {
            background-color: #ff6b35;
            color: white;
            padding: 0.7rem 1.8rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            transition: background-color 0.3s;
        }

        .btn-donate:hover {
            background-color: #e55a25;
        }

        .view-all-campaigns {
            text-align: center;
            margin-top: 2rem;
        }

        .btn-view-all {
            background: white;
            border: 1px solid #ddd;
            color: #333;
            padding: 0.7rem 1.8rem;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-view-all:hover {
            border-color: #333;
            background: #f5f5f5;
        }

        /* Emergency Helpline Section */
        .emergency {
            background: linear-gradient(135deg, #ff0000 0%, #ff6b35 100%);
            color: white;
            padding: 3rem;
            margin: 3rem;
            border-radius: 12px;
            text-align: center;
            max-width: 800px;
            margin-left: auto;
            margin-right: auto;
        }

        .emergency-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .emergency h3 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .emergency-subtitle {
            font-size: 1rem;
            margin-bottom: 2rem;
            opacity: 0.95;
        }

        .emergency-contacts {
            display: flex;
            gap: 3rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .emergency-contact {
            flex: 1;
            min-width: 200px;
        }

        .contact-label {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 0.5rem;
        }

        .contact-number {
            font-size: 1.8rem;
            font-weight: bold;
        }

        /* Footer */
        footer {
            background-color: #0f1419;
            color: #ccc;
            padding: 3rem;
        }

        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .footer-section h4 {
            color: white;
            margin-bottom: 1rem;
            font-size: 0.95rem;
        }

        .footer-section {
            color: #999;
        }

        .footer-logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #1a73e8;
            font-weight: bold;
            margin-bottom: 1rem;
        }

        .footer-logo::before {
            content: '🛡️';
            font-size: 1.5rem;
        }

        .footer-description {
            font-size: 0.9rem;
            color: #999;
            margin-bottom: 1rem;
            line-height: 1.5;
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 0.8rem;
        }

        .footer-links a {
            color: #999;
            text-decoration: none;
            transition: color 0.3s;
            font-size: 0.9rem;
        }

        .footer-links a:hover {
            color: #1a73e8;
        }

        .footer-contact {
            font-size: 0.9rem;
            margin-bottom: 0.8rem;
        }

        .footer-contact span {
            display: inline-block;
            margin-right: 0.5rem;
        }

        .social-links {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .social-links a {
            color: #666;
            font-size: 1.2rem;
            transition: color 0.3s;
            text-decoration: none;
        }

        .social-links a:hover {
            color: #1a73e8;
        }

        .footer-bottom {
            border-top: 1px solid #333;
            padding-top: 2rem;
            text-align: center;
            color: #666;
            font-size: 0.9rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            header {
                flex-direction: column;
                gap: 1rem;
                padding: 1rem;
            }

            .hero h1 {
                font-size: 2rem;
            }

            .hero-buttons {
                flex-direction: column;
                align-items: center;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .campaigns-grid {
                grid-template-columns: 1fr;
            }

            .footer-content {
                grid-template-columns: 1fr;
            }

            .emergency-contacts {
                flex-direction: column;
                gap: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <a href="index.php" class="logo">DisasterRelief</a>
        <nav class="header-nav">
            <a href="signin.php">Sign In</a>
            <a href="signup.php" style="text-decoration: none;"><button class="btn-get-started">Get Started</button></a>
        </nav>
    </header>

    <!-- Hero Section -->
    <section class="hero">
        <h1>Coordinating Relief.<br>Saving Lives Together.</h1>
        <p>Connect volunteers, manage camps, track donations, and provide aid to disaster-affected communities efficiently.</p>
        <div class="hero-buttons">
            <a href="signup.php" style="text-decoration: none;"><button class="btn-primary">Request Help →</button></a>
            <a href="signup.php" style="text-decoration: none;"><button class="btn-secondary">❤️ Donate Now</button></a>
        </div>

        <!-- Stats -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-number">50,000+</div>
                <div class="stat-label">People Helped</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">❤️</div>
                <div class="stat-number">₹5 Cr+</div>
                <div class="stat-label">Donations Received</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📦</div>
                <div class="stat-number">25+</div>
                <div class="stat-label">Active Camps</div>
            </div>
        </div>
    </section>

    <!-- Active Relief Campaigns -->
    <section class="campaigns">
        <h2>Active Relief Campaigns</h2>
        <p class="campaigns-subtitle">Support ongoing disaster relief efforts</p>
        
        <div class="campaigns-grid">
            <!-- Campaign 1 -->
            <div class="campaign-card">
                <div class="campaign-header">
                    <div class="campaign-title">Flood Relief - Dhaka Division</div>
                    <span class="urgent-badge">Urgent</span>
                </div>
                
                <div class="campaign-progress">
                    <div class="progress-label">
                        <span>₹45L</span>
                        <span>of ₹1Cr</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 45%;"></div>
                    </div>
                </div>

                <div class="campaign-stats">
                    <div class="campaign-stat">
                        <div class="campaign-stat-value">1234</div>
                        <div class="campaign-stat-label">supporters</div>
                    </div>
                </div>

                <div class="campaign-footer">
                    <button class="btn-donate">Donate</button>
                </div>
            </div>

            <!-- Campaign 2 -->
            <div class="campaign-card">
                <div class="campaign-header">
                    <div class="campaign-title">Cyclone Recovery - Coastal Areas</div>
                    <span class="urgent-badge">Urgent</span>
                </div>
                
                <div class="campaign-progress">
                    <div class="progress-label">
                        <span>₹78L</span>
                        <span>of ₹1.5Cr</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 52%;"></div>
                    </div>
                </div>

                <div class="campaign-stats">
                    <div class="campaign-stat">
                        <div class="campaign-stat-value">2456</div>
                        <div class="campaign-stat-label">supporters</div>
                    </div>
                </div>

                <div class="campaign-footer">
                    <button class="btn-donate">Donate</button>
                </div>
            </div>

            <!-- Campaign 3 -->
            <div class="campaign-card">
                <div class="campaign-header">
                    <div class="campaign-title">Flood Affected Areas</div>
                </div>
                
                <div class="campaign-progress">
                    <div class="progress-label">
                        <span>₹132L</span>
                        <span>of ₹175L</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: 75%;"></div>
                    </div>
                </div>

                <div class="campaign-stats">
                    <div class="campaign-stat">
                        <div class="campaign-stat-value">891</div>
                        <div class="campaign-stat-label">supporters</div>
                    </div>
                </div>

                <div class="campaign-footer">
                    <button class="btn-donate">Donate</button>
                </div>
            </div>
        </div>

        <div class="view-all-campaigns">
            <a href="#" class="btn-view-all">View All Campaigns →</a>
        </div>
    </section>

    <!-- Emergency Helpline -->
    <div class="emergency">
        <div class="emergency-icon">☎️</div>
        <h3>Emergency Helpline</h3>
        <p class="emergency-subtitle">Available 24/7 for disaster relief assistance</p>
        <div class="emergency-contacts">
            <div class="emergency-contact">
                <div class="contact-label">Toll Free</div>
                <div class="contact-number">16263</div>
            </div>
            <div class="emergency-contact">
                <div class="contact-label">WhatsApp</div>
                <div class="contact-number">+880 1712-345678</div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer>
        <div class="footer-content">
            <!-- About -->
            <div class="footer-section">
                <div class="footer-logo">DisasterRelief</div>
                <p class="footer-description">Coordinating relief efforts to save lives and rebuild communities.</p>
            </div>

            <!-- Quick Links -->
            <div class="footer-section">
                <h4>Quick Links</h4>
                <ul class="footer-links">
                    <li><a href="#">Get Help</a></li>
                    <li><a href="#">Volunteer</a></li>
                    <li><a href="#">Donate</a></li>
                    <li><a href="#">Campaigns</a></li>
                </ul>
            </div>

            <!-- Contact -->
            <div class="footer-section">
                <h4>Contact</h4>
                <div class="footer-contact">
                    <span>☎️</span> 16263
                </div>
                <div class="footer-contact">
                    <span>✉️</span> help@disasterrelief.bd
                </div>
                <div class="footer-contact">
                    <span>📍</span> Dhaka, Bangladesh
                </div>
            </div>

            <!-- Follow Us -->
            <div class="footer-section">
                <h4>Follow Us</h4>
                <div class="social-links">
                    <a href="#">f</a>
                    <a href="#">𝕏</a>
                    <a href="#">📷</a>
                </div>
            </div>
        </div>

        <div class="footer-bottom">
            © 2026 DisasterRelief. All rights reserved.
        </div>
    </footer>
</body>
</html>
