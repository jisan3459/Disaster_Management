<?php
$file = 'disaster_relief_app/resources/views/volunteer_dashboard.blade.php';
$c = file_get_contents($file);

$js_replace = "form.action = window.location.href;\n\n                const csrfInput = document.createElement('input');\n                csrfInput.type = 'hidden';\n                csrfInput.name = '_token';\n                csrfInput.value = '{{ csrf_token() }}';\n                form.appendChild(csrfInput);\n";

$c = preg_replace('/form\.action = window\.location\.href;/', $js_replace, $c);

file_put_contents($file, $c);
echo "CSRF fixed in JS.\n";
