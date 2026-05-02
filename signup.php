<?php
session_start();
include 'config.php';

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = sanitize($_POST['role'] ?? '');
    $fullname = sanitize($_POST['fullname'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $terms = isset($_POST['terms']) ? 1 : 0;

    // Validation
    if (!$role || !$fullname || !$email || !$password || !$terms) {
        $error = 'Please fill in all required fields and accept terms';
    } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address';
    } else if (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters';
    } else {
        // Check if email already exists
        $check = $conn->query("SELECT id FROM users WHERE email = '$email'");
        if ($check->num_rows > 0) {
            $error = 'Email already registered';
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);
            
            // Insert user
            $insert_query = "INSERT INTO users (role, full_name, email, phone, password, status) 
                           VALUES ('$role', '$fullname', '$email', '$phone', '$hashed_password', 'active')";
            
            if ($conn->query($insert_query)) {
                $user_id = $conn->insert_id;
                $_SESSION['user_id'] = $user_id;
                $_SESSION['role'] = $role;
                $_SESSION['full_name'] = $fullname;
                
                $success = 'Account created successfully!';
                // Redirect to the correct dashboard based on role
                if ($role === 'donor') {
                    redirect('donor_dashboard.php');
                } elseif ($role === 'camp_manager') {
                    redirect('camp_manager_dashboard.php');
                } else {
                    redirect('volunteer_dashboard.php');
                }
            } else {
                $error = 'Error creating account. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - DisasterRelief</title>
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
            background: #f8f9fa;
        }

        .container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 100vh;
        }

        /* Left Side - Mission Section */
        .mission-section {
            background: linear-gradient(135deg, #ff6b35 0%, #ff8c42 100%);
            color: white;
            padding: 4rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
        }

        .mission-section h1 {
            font-size: 2.5rem;
            margin-bottom: 1.5rem;
            font-weight: 700;
        }

        .mission-section p {
            font-size: 1.1rem;
            margin-bottom: 3rem;
            line-height: 1.5;
            opacity: 0.95;
            max-width: 400px;
        }

        .benefits {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .benefit-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }

        .benefit-icon {
            font-size: 1.5rem;
            margin-top: 0.2rem;
            flex-shrink: 0;
        }

        .benefit-content h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.3rem;
        }

        .benefit-content p {
            font-size: 0.9rem;
            opacity: 0.9;
            margin: 0;
        }

        /* Right Side - Form Section */
        .form-section {
            background: white;
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            overflow-y: auto;
        }

        .form-container {
            width: 100%;
            max-width: 400px;
        }

        .form-header {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            margin-bottom: 2rem;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #1a73e8 0%, #4285f4 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
            font-weight: bold;
        }

        .form-header h2 {
            font-size: 1rem;
            font-weight: 600;
            color: #1a1a1a;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-size: 0.9rem;
            font-weight: 500;
            color: #1a1a1a;
            margin-bottom: 0.5rem;
        }

        .required {
            color: #ff6b35;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.9rem 1rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #1a73e8;
            box-shadow: 0 0 0 3px rgba(26, 115, 232, 0.1);
        }

        .form-group input::placeholder {
            color: #999;
        }

        .form-group select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%23333' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 1rem center;
            padding-right: 2.5rem;
        }

        .form-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 0.5rem;
        }

        .form-subtitle {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 2rem;
        }

        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 0.8rem;
            margin-bottom: 1.5rem;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-top: 0.3rem;
            cursor: pointer;
            accent-color: #ff6b35;
        }

        .checkbox-group label {
            font-size: 0.9rem;
            color: #666;
            margin: 0;
            cursor: pointer;
            margin-bottom: 0;
        }

        .checkbox-group a {
            color: #1a73e8;
            text-decoration: none;
            transition: color 0.3s;
        }

        .checkbox-group a:hover {
            color: #1557b0;
            text-decoration: underline;
        }

        .btn-create-account {
            width: 100%;
            padding: 1rem;
            background-color: #ff6b35;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s, transform 0.2s;
            margin-bottom: 1rem;
        }

        .btn-create-account:hover {
            background-color: #e55a25;
            transform: translateY(-2px);
        }

        .btn-create-account:active {
            transform: translateY(0);
        }

        .signin-link {
            text-align: center;
            font-size: 0.9rem;
            color: #666;
        }

        .signin-link a {
            color: #ff6b35;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }

        .signin-link a:hover {
            color: #e55a25;
            text-decoration: underline;
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            border: 1px solid #f5c6cb;
        }

        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            border: 1px solid #c3e6cb;
        }

        @media (max-width: 768px) {
            .container {
                grid-template-columns: 1fr;
            }

            .mission-section {
                padding: 2rem;
                min-height: 400px;
            }

            .mission-section h1 {
                font-size: 2rem;
            }

            .mission-section p {
                font-size: 1rem;
            }

            .form-section {
                padding: 2rem;
                min-height: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Left Side - Mission Section -->
        <div class="mission-section">
            <h1>Join Our Mission</h1>
            <p>Whether you want to help, donate, or seek assistance, we're here for you 24/7</p>
            
            <div class="benefits">
                <div class="benefit-item">
                    <div class="benefit-icon">✓</div>
                    <div class="benefit-content">
                        <h3>Quick Registration</h3>
                        <p>Get started in less than 2 minutes</p>
                    </div>
                </div>

                <div class="benefit-item">
                    <div class="benefit-icon">✓</div>
                    <div class="benefit-content">
                        <h3>Secure & Safe</h3>
                        <p>Your data is encrypted and protected</p>
                    </div>
                </div>

                <div class="benefit-item">
                    <div class="benefit-icon">✓</div>
                    <div class="benefit-content">
                        <h3>24/7 Support</h3>
                        <p>Help available whenever you need it</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Side - Form Section -->
        <div class="form-section">
            <div class="form-container">
                <!-- Header -->
                <div class="form-header">
                    <div class="logo-icon">🛡️</div>
                    <h2>DisasterRelief</h2>
                </div>

                <!-- Form Title -->
                <div class="form-title">Create Account</div>
                <div class="form-subtitle">Join our disaster relief network</div>

                <!-- Error/Success Messages -->
                <?php if ($error): ?>
                    <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <!-- Form -->
                <form method="POST">
                    <!-- Role Selection -->
                    <div class="form-group">
                        <label>I am a <span class="required">*</span></label>
                        <select name="role" required>
                            <option value="">Select your role</option>
                            <option value="volunteer">Volunteer</option>
                            <option value="donor">Donor</option>
                            <option value="organization">Organization</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <!-- Full Name -->
                    <div class="form-group">
                        <label>Full Name <span class="required">*</span></label>
                        <input type="text" name="fullname" placeholder="Enter your full name" required>
                    </div>

                    <!-- Email Address -->
                    <div class="form-group">
                        <label>Email Address <span class="required">*</span></label>
                        <input type="email" name="email" placeholder="you@example.com" required>
                    </div>

                    <!-- Phone Number -->
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" placeholder="+880 1712-345678">
                    </div>

                    <!-- Password -->
                    <div class="form-group">
                        <label>Password <span class="required">*</span></label>
                        <input type="password" name="password" placeholder="Create a strong password" required>
                    </div>

                    <!-- Terms & Conditions -->
                    <div class="checkbox-group">
                        <input type="checkbox" id="terms" name="terms" required>
                        <label for="terms">I agree to the <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a></label>
                    </div>

                    <!-- Submit Button -->
                    <button type="submit" class="btn-create-account">Create Account</button>

                    <!-- Sign In Link -->
                    <div class="signin-link">
                        Already have an Account? <a href="signin.php">Sign in</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
<?php
$conn->close();
?>
