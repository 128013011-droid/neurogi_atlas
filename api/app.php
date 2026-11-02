<?php
$root = dirname(__DIR__);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($path === '/' || $path === '') {
    $path = '/index.php';
}
$file = ltrim($path, '/');
$blocked = ['config.php', 'db.php', 'setup_db.php'];
if (strpos($file, '/') !== false || substr($file, -4) !== '.php'
    || in_array($file, $blocked, true) || !is_file($root . '/' . $file)) {
    http_response_code(404);
    echo 'Not found';
    exit;
}
chdir($root);
$_SERVER['SCRIPT_NAME'] = '/' . $file;
$_SERVER['PHP_SELF'] = '/' . $file;
$_SERVER['SCRIPT_FILENAME'] = $root . '/' . $file;
require $root . '/' . $file;
