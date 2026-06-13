<?php
// Database Configuration for DisasterRelief

// Database Connection Details
define('DB_HOST', 'localhost');      // Your database host
define('DB_USER', 'root');           // Your database username
define('DB_PASS', '');               // Your database password
define('DB_NAME', 'disasterrelief'); // Your database name
define('DB_PORT', 3306);             // Database port (default MySQL)

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
$GLOBALS['conn'] = $conn;

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to UTF-8
$conn->set_charset("utf8");

// Optional: Set timezone
date_default_timezone_set('Asia/Dhaka');

// Ensure necessary columns exist (Simple Migration Logic)
$conn->query("SET SESSION sql_mode = '';"); // Ensure compatibility
$cols = $conn->query("SHOW COLUMNS FROM users LIKE 'last_login'");
if ($cols->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN last_login TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER status");
}

$cols = $conn->query("SHOW COLUMNS FROM tasks LIKE 'task_type'");
if ($cols->num_rows == 0) {
    $conn->query("ALTER TABLE tasks ADD COLUMN task_type ENUM('standard', 'distribution') DEFAULT 'standard' AFTER description");
    $conn->query("ALTER TABLE tasks ADD COLUMN distribution_item VARCHAR(255) AFTER task_type");
    $conn->query("ALTER TABLE tasks ADD COLUMN distribution_qty FLOAT DEFAULT 0 AFTER distribution_item");
}

//Add chat_power to affected_persons (allows affected persons to chat with camp manager)
$ap_cols = $conn->query("SHOW COLUMNS FROM affected_persons LIKE 'chat_power'");
if ($ap_cols && $ap_cols->num_rows == 0) {
    $conn->query("ALTER TABLE affected_persons ADD COLUMN chat_power TINYINT(1) DEFAULT 0 AFTER camp_id");
}

//Add sender_label to affected_messages for camp manager identification
$am_cols = $conn->query("SHOW COLUMNS FROM affected_messages LIKE 'sender_label'");
if ($am_cols && $am_cols->num_rows == 0) {
    $conn->query("ALTER TABLE affected_messages ADD COLUMN sender_label VARCHAR(100) DEFAULT NULL AFTER sender");
}

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper function to escape input
function sanitize($data) {
    if (!isset($GLOBALS['conn'])) return htmlspecialchars(strip_tags(trim($data)));
    return $GLOBALS['conn']->real_escape_string(htmlspecialchars(strip_tags(trim($data))));
}

// Helper function for error messages
function showError($message) {
    echo "<div style='background-color: #f8d7da; color: #721c24; padding: 12px; border-radius: 4px; margin: 10px 0;'>" . htmlspecialchars($message) . "</div>";
}

// Helper function for success messages
function showSuccess($message) {
    echo "<div style='background-color: #d4edda; color: #155724; padding: 12px; border-radius: 4px; margin: 10px 0;'>" . htmlspecialchars($message) . "</div>";
}

// Helper function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Helper function to redirect
function php_redirect($url) {
    header("Location: " . $url);
    exit();
}

if (!function_exists('redirect')) {
    function redirect($url) {
        php_redirect($url);
    }
}
?>