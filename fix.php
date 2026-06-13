<?php
$files = glob('disaster_relief_app/resources/views/*.blade.php');
foreach($files as $f) {
    $c = file_get_contents($f);
    $c = str_replace("include 'config.php';", "include base_path('../config.php');", $c);
    $c = str_replace("redirect(", "php_redirect(", $c);
    $c = preg_replace('/href="([a-zA-Z0-9_]+)\.php"/', 'href="/$1"', $c);
    $c = preg_replace('/action="([a-zA-Z0-9_]+)\.php"/', 'action="/$1"', $c);
    
    // For forms that have method POST without @csrf, let's inject @csrf.
    // We only inject if it's not already there.
    if (strpos($c, '@csrf') === false) {
        $c = preg_replace('/(<form[^>]*method="POST"[^>]*>)/i', "$1\n                    @csrf", $c);
    }

    file_put_contents($f, $c);
    echo "Processed " . $f . "\n";
}
