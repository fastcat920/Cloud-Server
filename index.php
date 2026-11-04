<?php

declare(strict_types=1);
ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
if (isset($_GET['api'])) {
    foreach (['Store','Security','Cloud','Objects','Configs','Importer','Api'] as $file) {
        require_once __DIR__.'/_core/'.$file.'.php';
    }
    try {
        (new CloudDesk\Api(getenv('CLOUD_DESK_DATA') ?: __DIR__.'/_core/data'))->run();
    } catch (Throwable $e) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['data' => null,'error' => $e instanceof RuntimeException ? $e->getMessage() : '服务初始化失败，请检查 PHP 版本（8.2+）和目录权限'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__.'/_core/view.php');
