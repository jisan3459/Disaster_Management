<?php
$files = glob('disaster_relief_app/resources/views/*.blade.php');
foreach($files as $f) {
    $c = file_get_contents($f);
    
    // Replace href="something.php?param=1" with href="/something?param=1"
    $c = preg_replace('/href="([a-zA-Z0-9_]+)\.php(\??[^"]*)"/', 'href="/$1$2"', $c);
    
    // Replace action="something.php?param=1" with action="/something?param=1"
    $c = preg_replace('/action="([a-zA-Z0-9_]+)\.php(\??[^"]*)"/', 'action="/$1$2"', $c);

    file_put_contents($f, $c);
    echo "Fixed links in " . $f . "\n";
}
