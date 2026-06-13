<?php
include 'config.php';
$q = $conn->query("SHOW COLUMNS FROM tasks LIKE 'camp_id'");
print_r($q->fetch_assoc());
