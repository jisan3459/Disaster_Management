<?php
include 'config.php';

// Handle key recovery AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'recover_key') {
    header('Content-Type: application/json');
    $name = sanitize($_POST['name'] ?? '');
    $location = sanitize($_POST['location'] ?? '');
    $members = intval($_POST['family_members'] ?? 0);
    
    if (!$name || !$location || $members <= 0) {
        echo json_encode(['success' => false, 'message' => 'Please fill in all verification fields.']);
        exit;
    }
    
    $stmt = $conn->prepare("SELECT access_key FROM affected_persons WHERE full_name = ? AND location = ? AND family_members = ? LIMIT 1");
    $stmt->bind_param("ssi", $name, $location, $members);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $row = $result->fetch_assoc();
        echo json_encode(['success' => true, 'key' => $row['access_key']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No matching record found. Please verify your details or contact camp management.']);
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
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
input[type=text], select, input[type=number]{width:100%;padding:.85rem 1rem;border:1.5px solid #ddd;border-radius:8px;font-size:1rem;transition:border-color .2s;}
input[type=text]:focus, select:focus, input[type=number]:focus{outline:none;border-color:#4361ee;}
input[type=text]#access_key{letter-spacing:.1em;font-family:monospace;}
select{cursor:pointer;background-color:white;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%23333' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 1rem center;}
.error{background:#fff0f0;color:#c0392b;border:1px solid #f5c6cb;border-radius:6px;padding:.8rem 1rem;margin-bottom:1rem;font-size:.9rem;}
.btn{width:100%;padding:.95rem;background:#4361ee;color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:700;cursor:pointer;margin-top:1.2rem;transition:background .2s;}
.btn:hover{background:#3251d4;}
.back{text-align:center;margin-top:1.2rem;font-size:.9rem;color:#666;}
.back a{color:#4361ee;text-decoration:none;font-weight:600;}
.key-hint{background:#f0f4ff;border-radius:8px;padding:.9rem 1rem;margin-top:1rem;font-size:.85rem;color:#444;}
.key-hint strong{color:#4361ee;}
.recovery-success-box{background:#e8f5e9;border:1px solid #c8e6c9;color:#2e7d32;border-radius:8px;padding:.9rem 1rem;margin-top:1rem;font-size:.9rem;text-align:center;}
</style>
</head>
<body>
<div class="card">
    <div class="logo">
        <span>🛡️</span>
        <h2>DisasterRelief</h2>
        <p>Affected Person Portal</p>
    </div>

    <!-- Login Section -->
    <div id="login-section">
        <h1>Enter Your Access Key</h1>
        <p class="sub">Use the key you received when you registered for help.</p>

        <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: .4rem;">
                <label for="access_key" style="margin-bottom: 0;">Access Key</label>
                <a href="#" onclick="toggleRecovery(true); return false;" style="font-size: .82rem; color: #4361ee; text-decoration: none; font-weight: 600;">Forgot key?</a>
            </div>
            <input type="text" id="access_key" name="access_key" placeholder="e.g. AB12CD34EF" maxlength="10" required autofocus>

            <div class="key-hint">
                🔑 Your access key was shown when you submitted your help request from the homepage. It looks like <strong>AB12CD34EF</strong>.
            </div>

            <button type="submit" class="btn">Access My Dashboard →</button>
        </form>

        <div class="back"><a href="signin.php">← Back to Sign In</a> &nbsp;|&nbsp; <a href="index.php">Home</a></div>
    </div>

    <!-- Recovery Section -->
    <div id="recovery-section" style="display: none;">
        <h1>Recover Access Key</h1>
        <p class="sub">Enter your details to retrieve your access key.</p>

        <div id="recovery-error" class="error" style="display: none;"></div>
        <div id="recovery-success" class="recovery-success-box" style="display: none;"></div>

        <form id="recoveryForm" onsubmit="submitRecovery(event)">
            <div style="margin-bottom: 1.2rem;">
                <label for="recovery_name">Full Name <span style="color: #ff6b35;">*</span></label>
                <input type="text" id="recovery_name" required placeholder="Enter registered name">
            </div>

            <div style="margin-bottom: 1.2rem;">
                <label for="recovery_location">Location (Division) <span style="color: #ff6b35;">*</span></label>
                <select id="recovery_location" required>
                    <option value="">Select Division</option>
                    <option value="Dhaka">Dhaka</option>
                    <option value="Chattogram">Chattogram</option>
                    <option value="Khulna">Khulna</option>
                    <option value="Rajshahi">Rajshahi</option>
                    <option value="Barishal">Barishal</option>
                    <option value="Sylhet">Sylhet</option>
                    <option value="Rangpur">Rangpur</option>
                    <option value="Mymensingh">Mymensingh</option>
                </select>
            </div>

            <div style="margin-bottom: 1.2rem;">
                <label for="recovery_members">Family Members <span style="color: #ff6b35;">*</span></label>
                <input type="number" id="recovery_members" required min="1" placeholder="Number of registered family members">
            </div>

            <button type="submit" class="btn">Retrieve Access Key →</button>
        </form>

        <div class="back"><a href="#" onclick="toggleRecovery(false); return false;">← Back to Login</a></div>
    </div>
</div>

<script>
function toggleRecovery(show) {
    const loginSec = document.getElementById('login-section');
    const recoverSec = document.getElementById('recovery-section');
    
    // Clear message alerts
    document.getElementById('recovery-error').style.display = 'none';
    document.getElementById('recovery-success').style.display = 'none';
    
    if (show) {
        loginSec.style.display = 'none';
        recoverSec.style.display = 'block';
    } else {
        loginSec.style.display = 'block';
        recoverSec.style.display = 'none';
    }
}

function submitRecovery(e) {
    e.preventDefault();
    const name = document.getElementById('recovery_name').value;
    const location = document.getElementById('recovery_location').value;
    const members = document.getElementById('recovery_members').value;
    
    const errorDiv = document.getElementById('recovery-error');
    const successDiv = document.getElementById('recovery-success');
    
    errorDiv.style.display = 'none';
    successDiv.style.display = 'none';
    
    const formData = new FormData();
    formData.append('action', 'recover_key');
    formData.append('name', name);
    formData.append('location', location);
    formData.append('family_members', members);
    
    fetch('affected_login.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            successDiv.innerHTML = `🎉 Access Key found!<br><br><span style="font-size: 1.35rem; font-weight: 700; letter-spacing: 1.5px; color: #1b5e20; font-family: monospace;">${data.key}</span><br><br>Please copy this key and use it to log in.`;
            successDiv.style.display = 'block';
        } else {
            errorDiv.innerText = data.message || 'Error occurred during recovery.';
            errorDiv.style.display = 'block';
        }
    })
    .catch(err => {
        console.error(err);
        errorDiv.innerText = 'Network error. Please try again.';
        errorDiv.style.display = 'block';
    });
}
</script>
</body>
</html>
<?php $conn->close(); ?>