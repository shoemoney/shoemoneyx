<?php

// Router for `php artisan serve`. The framework's default returns false for any path that
// exists under public/, and file_exists() is true for directories, so a folder such as
// public/arena/ turned the SPA route /arena into a 404. Only real files bypass Laravel here.

$publicPath = getcwd();

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');

if ($uri !== '/' && is_file($publicPath.$uri)) {
    return false;
}

file_put_contents('php://stdout', '['.date('D M j H:i:s Y').'] '.$_SERVER['REMOTE_ADDR'].':'.$_SERVER['REMOTE_PORT'].' ['.$_SERVER['REQUEST_METHOD']."] URI: $uri\n");

require_once $publicPath.'/index.php';
