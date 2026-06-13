<?php
$file = 'disaster_relief_app/resources/views/volunteer_dashboard.blade.php';
$c = file_get_contents($file);

// 1. config.php
$c = str_replace("include 'config.php';", "include base_path('../config.php');", $c);

// 2. php_redirect
$c = preg_replace('/(?<!function )redirect\(/', 'php_redirect(', $c);

// 3. csrf in forms
$c = preg_replace('/(<form[^>]*method="POST"[^>]*>)/i', "$1\n                    @csrf", $c);

// 4. href and action links
$c = preg_replace('/href="([a-zA-Z0-9_]+)\.php(\??[^"]*)"/', 'href="/$1$2"', $c);
$c = preg_replace('/action="([a-zA-Z0-9_]+)\.php(\??[^"]*)"/', 'action="/$1$2"', $c);

// 5. header redirects
$c = str_replace('header("Location: volunteer_dashboard.php', 'header("Location: /volunteer_dashboard', $c);

// 6. fix updateTaskStatus to include csrf
$js_search = "form.action = window.location.href;\n";
$js_replace = "form.action = window.location.href;\n\n                const csrfInput = document.createElement('input');\n                csrfInput.type = 'hidden';\n                csrfInput.name = '_token';\n                csrfInput.value = '{{ csrf_token() }}';\n                form.appendChild(csrfInput);\n";
$c = str_replace($js_search, $js_replace, $c);

file_put_contents($file, $c);
echo "File fixed completely.\n";
