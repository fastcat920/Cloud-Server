<?php
// Optional cron worker; never exposed as an HTTP action.
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
foreach(['Store','Security','Cloud','Notifications','Api'] as $file)require_once dirname(__DIR__).'/_core/'.$file.'.php';
try {
    $directory=getenv('CLOUD_DESK_DATA') ?: dirname(__DIR__).'/_core/data';
    if (is_dir($directory) && function_exists('posix_geteuid') && fileowner($directory)!==posix_geteuid()) throw new RuntimeException('请以数据目录所有者执行计划任务，例如 sudo -u www /www/server/php/82/bin/php bin/notify-worker.php');
    $store=new CloudDesk\Store($directory);
    $service=new CloudDesk\Notifications($store,new CloudDesk\Security($store));
    echo 'Processed: '.$service->process(10).PHP_EOL;
} catch(Throwable $e) {fwrite(STDERR,'Notification worker failed: '.$e->getMessage().PHP_EOL);exit(1);}
