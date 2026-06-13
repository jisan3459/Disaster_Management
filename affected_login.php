<?php
include 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = strtoupper(trim($_POST['access_key'] ?? ''));

    if (!$key) {
        $error = 'Please enter your access key.';
    } else {
        $stmt = $conn->prepare("SELECT * FROM affected_persons WHERE access_key = ?");
        $stmt->bind_param("s", $key);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $person = $result->fetch_assoc();
            $_SESSION['affected_id']   = $person['id'];
            $_SESSION['affected_name'] = $person['full_name'];
            $_SESSION['affected_key']  = $person['access_key'];
            redirect('affected_dashboard.php');
        } else {
            $error = 'Invalid access key. Please check and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Affected Person Login - DisasterRelief</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f4ff;min-height:100vh;display:flex;align-items:center;justify-content:center;}
.card{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,0.10);padding:2.5rem 2rem;width:100%;max-width:420px;}
.logo{text-align:center;margin-bottom:1.5rem;}
.logo h2{font-size:1.3rem;color:#1a1a2e;font-weight:700;}
.logo span{font-size:2.2rem;}
.logo p{color:#666;font-size:.9rem;margin-top:.3rem;}
h1{font-size:1.4rem;font-weight:700;color:#1a1a2e;margin-bottom:.3rem;}
.sub{color:#666;font-size:.9rem;margin-bottom:1.8rem;}
label{display:block;font-size:.88rem;font-weight:600;color:#333;margin-bottom:.4rem;}
input[type=text]{width:100%;padding:.85rem 1rem;border:1.5px solid #ddd;border-radius:8px;font-size:1rem;letter-spacing:.1em;font-family:monospace;transition:border-color .2s;}
input[type=text]:focus{outline:none;border-color:#4361ee;}
.error{background:#fff0f0;color:#c0392b;border:1px solid #f5c6cb;border-radius:6px;padding:.8rem 1rem;margin-bottom:1rem;font-size:.9rem;}
.btn{width:100%;padding:.95rem;background:#4361ee;color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:700;cursor:pointer;margin-top:1.2rem;transition:background .2s;}
.btn:hover{background:#3251d4;}
.back{text-align:center;margin-top:1.2rem;font-size:.9rem;color:#666;}
.back a{color:#4361ee;text-decoration:none;font-weight:600;}
.key-hint{background:#f0f4ff;border-radius:8px;padding:.9rem 1rem;margin-top:1rem;font-size:.85rem;color:#444;}
.key-hint strong{color:#4361ee;}
</style>
</head>
<body>
<div class="card">
    <div class="logo">
        <span>🛡️</span>
        <h2>DisasterRelief</h2>
        <p>Affected Person Portal</p>
    </div>
    <h1>Enter Your Access Key</h1>
    <p class="sub">Use the key you received when you registered for help.</p>

    <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <label for="access_key">Access Key</label>
        <input type="text" id="access_key" name="access_key" placeholder="e.g. AB12CD34EF" maxlength="10" required autofocus>

        <div class="key-hint">
            🔑 Your access key was shown when you submitted your help request from the homepage. It looks like <strong>AB12CD34EF</strong>.
        </div>

        <button type="submit" class="btn">Access My Dashboard →</button>
    </form>

    <div class="back"><a href="signin.php">← Back to Sign In</a> &nbsp;|&nbsp; <a href="index.php">Home</a></div>
</div>
</body>
</html>
<?php $conn->close(); ?>
