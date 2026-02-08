<?php

$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$basePath = rtrim($scriptDir, '/');
if ($basePath === '/') {
    $basePath = '';
}

header('Location: ' . $basePath . '/public/index.php');
exit;