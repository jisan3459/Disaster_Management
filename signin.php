<?php
include 'config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $error = 'Please fill in all fields';
    } else {
        // Hardcoded demo login check
        if (($email === 'admin@gmail.com' && $password === '123456') || ($email === 'camp@gmail.com' && $password === '123456')) {
            $is_admin = ($email === 'admin@gmail.com');
            $_SESSION['user_id'] = $is_admin ? 1 : 2; // Demo IDs
            $_SESSION['role'] = $is_admin ? 'admin' : 'camp_manager';
            $_SESSION['full_name'] = $is_admin ? 'System Admin' : 'Camp Manager';
            redirect($is_admin ? 'admin_dashboard.php' : 'camp_manager_dashboard.php');
        }

        // Check user in database
        $result = $conn->query("SELECT * FROM users WHERE email = '$email'");
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                // Login successful
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                
                // Redirect based on role
                if ($user['role'] === 'admin') {
                    redirect('admin_dashboard.php');
                } elseif ($user['role'] === 'camp_manager') {
                    redirect('camp_manager_dashboard.php');
                } elseif ($user['role'] === 'volunteer') {
                    redirect('volunteer_dashboard.php');
                } elseif ($user['role'] === 'donor') {
                    redirect('donor_dashboard.php');
                } else {
                    redirect('index.php');
                }
            } else {
                $error = 'Incorrect password';
            }
        } else {
            $error = 'Email not found';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - DisasterRelief</title>
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

        /* Left Side - Brand Section */
        .brand-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 4rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
        }

        .brand-section h1 {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            font-weight: 700;
        }

        .brand-section p {
            font-size: 1.1rem;
            margin-bottom: 3rem;
            line-height: 1.5;
            opacity: 0.95;
            max-width: 400px;
        }

        .features {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }

        .feature-icon {
            font-size: 1.5rem;
            margin-top: 0.2rem;
            flex-shrink: 0;
        }

        .feature-content h3 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.3rem;
        }

        .feature-content p {
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
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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

        .form-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1a1a1a;
            margin-bottom: 0.5rem;
        }

        .form-subtitle {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 2rem;
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

        .form-group input {
            width: 100%;
            padding: 0.9rem 1rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.95rem;
            font-family: inherit;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group input::placeholder {
            color: #999;
        }

        .remember-forgot {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }

        .remember-forgot a {
            color: #667eea;
            text-decoration: none;
            transition: color 0.3s;
        }

        .remember-forgot a:hover {
            color: #5568d3;
            text-decoration: underline;
        }

        .btn-signin {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.3s;
            margin-bottom: 1rem;
        }

        .btn-signin:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(102, 126, 234, 0.3);
        }

        .btn-signin:active {
            transform: translateY(0);
        }

        .signup-link {
            text-align: center;
            font-size: 0.9rem;
            color: #666;
        }

        .signup-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s;
        }

        .signup-link a:hover {
            color: #5568d3;
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

        .demo-credentials {
            background: #f0f4ff;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            color: #333;
        }

        .demo-credentials strong {
            display: block;
            margin-bottom: 0.5rem;
            color: #667eea;
        }

        .demo-credentials div {
            margin-bottom: 0.3rem;
        }

        @media (max-width: 768px) {
            .container {
                grid-template-columns: 1fr;
            }

            .brand-section {
                padding: 2rem;
                min-height: 300px;
            }

            .brand-section h1 {
                font-size: 1.8rem;
            }

            .form-section {
                padding: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Left Side - Brand -->
        <div class="brand-section">
            <h1>Welcome Back</h1>
            <p>Continue helping disaster-affected communities and manage your volunteer activities efficiently.</p>
            
            <div class="features">
                <div class="feature-item">
                    <div class="feature-icon">📊</div>
                    <div class="feature-content">
                        <h3>Track Progress</h3>
                        <p>Monitor your tasks and contributions</p>
                    </div>
                </div>

                <div class="feature-item">
                    <div class="feature-icon">💬</div>
                    <div class="feature-content">
                        <h3>Communicate</h3>
                        <p>Chat with camp managers in real-time</p>
                    </div>
                </div>

                <div class="feature-item">
                    <div class="feature-icon">🚨</div>
                    <div class="feature-content">
                        <h3>Report Issues</h3>
                        <p>Alert managers about emergencies</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Side - Sign In Form -->
        <div class="form-section">
            <div class="form-container">
                <!-- Header -->
                <div class="form-header">
                    <div class="logo-icon">🛡️</div>
                    <h2>DisasterRelief</h2>
                </div>

                <!-- Form Title -->
                <div class="form-title">Sign In</div>
                <div class="form-subtitle">Access your volunteer dashboard</div>

                <!-- Error/Success Messages -->
                <?php if ($error): ?>
                    <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <!-- Demo Credentials -->
                <div class="demo-credentials">
                    <strong>Demo Credentials</strong>
                    <div>Admin: <code>admin@gmail.com</code> / <code>123456</code></div>
                    <div>Manager: <code>camp@gmail.com</code> / <code>123456</code></div>
                </div>

                <!-- Sign In Form -->
                <form method="POST">
                    <!-- Email -->
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" placeholder="you@example.com" required>
                    </div>

                    <!-- Password -->
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" placeholder="Enter your password" required>
                    </div>

                    <!-- Remember Me & Forgot Password -->
                    <div class="remember-forgot">
                        <label>
                            <input type="checkbox" style="margin-right: 0.5rem;">
                            Remember me
                        </label>
                        <a href="#">Forgot password?</a>
                    </div>

                    <!-- Sign In Button -->
                    <button type="submit" class="btn-signin">Sign In</button>

                    <!-- Sign Up Link -->
                    <div class="signup-link">
                        Don't have an account? <a href="signup.php">Sign up here</a>
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
