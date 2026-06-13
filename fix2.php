<?php
$files = glob('disaster_relief_app/resources/views/*.blade.php');
foreach($files as $f) {
    $c = file_get_contents($f);
    
    // Fix the double replacement
    $c = str_replace("php_php_redirect(", "php_redirect(", $c);
    
    // Fix the .php extensions inside php_redirect calls
    $c = preg_replace("/php_redirect\(['\"]([a-zA-Z0-9_]+)\.php['\"]\)/", "php_redirect('/$1')", $c);
    
    // Some lines might use variables or ternary operators, like: php_redirect($is_admin ? 'admin_dashboard.php' : 'camp_manager_dashboard.php');
    // Let's just strip .php inside php_redirect completely
    $c = preg_replace("/'([a-zA-Z0-9_]+)\.php'/", "'/$1'", $c);
    $c = preg_replace('/"([a-zA-Z0-9_]+)\.php"/', '"/$1"', $c);

    file_put_contents($f, $c);
    echo "Fixed " . $f . "\n";
}
