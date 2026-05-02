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

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to UTF-8
$conn->set_charset("utf8");

// Optional: Set timezone
date_default_timezone_set('Asia/Dhaka');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper function to escape input
function sanitize($data) {
    global $conn;
    return $conn->real_escape_string(htmlspecialchars(strip_tags(trim($data))));
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
function redirect($url) {
    header("Location: " . $url);
    exit();
}

?>
