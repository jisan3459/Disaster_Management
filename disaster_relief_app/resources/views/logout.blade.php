<?php
include base_path('../config.php');
session_unset();
session_destroy();
php_redirect(url('signin'));
exit();
?>