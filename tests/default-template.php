<?php

declare(strict_types=1);
foreach (['Store','Security','Configs','Plans'] as $name) {
    require __DIR__.'/../_core/'.$name.'.php';
}
use CloudDesk\{Store,Configs,Plans};
function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$directory = sys_get_temp_dir().'/cloud-default-'.bin2hex(random_bytes(8));
$store = new Store($directory);
try {
    $defaults = Configs::defaults();
    check(array_keys($defaults) === ['config_version','panel_type','api_prefix','domains','gateway_urls','legal','contact','update'], 'Exact OSS root fields and order');
    check(array_keys($defaults['update']['platforms']) === ['android','ios','tvos','windows','macos','linux'], 'Six platform definitions');
    Plans::migrate($store);
    $fresh = $store->read();
    $template = $fresh['templates'][0];
    check($template['builtin'] === 'fastcat-oss-v1', 'Fresh default template');
    Plans::checkSchema($template['schema']);
    Plans::validate($defaults, $template['schema']);
    $fromTemplate = array_map(fn ($spec) => $spec['default'], $template['schema']['properties']);
    check($fromTemplate === $defaults, 'Template creates the complete OSS example');
    check($template['schema']['properties']['domains']['items']['type'] === 'string', 'Typed domain list');
    $oldSchema = ['type'=>'object','properties'=>['old'=>['type'=>'boolean']],'additionalProperties'=>true];
    $oldVersion = ['id'=>1,'version'=>1,'revision'=>1,'status'=>'published','content'=>['old'=>true],'schema'=>$oldSchema,'template_id'=>1,'plan_id'=>1,'legacy_validation'=>true];
    $store->transaction(function (array &$s) use ($oldSchema,$oldVersion) {
        $s['templates'] = [['id'=>1,'name'=>'Old','legacy_validation'=>true,'schema'=>$oldSchema],['id'=>2,'name'=>'Custom','schema'=>$oldSchema]];
        $s['plans'] = [['id'=>1,'template_id'=>1,'targets'=>[]],['id'=>2,'template_id'=>2,'targets'=>[]]];
        $s['versions'] = [$oldVersion];
    });
    Plans::migrate($store);
    $migrated = $store->read();
    check(count($migrated['templates']) === 3, 'Adds one built-in template');
    check($migrated['plans'][0]['template_id'] === 3 && $migrated['plans'][1]['template_id'] === 2, 'Updates legacy default, preserves custom default');
    check($migrated['versions'][0]['content'] === $oldVersion['content'] && $migrated['versions'][0]['schema'] === $oldSchema && $migrated['versions'][0]['template_id'] === 1, 'Preserves historical content and structure');
    Plans::migrate($store);
    check(count($store->read()['templates']) === 3, 'Migration is idempotent');
    echo "PASS: complete OSS defaults, typed schema, existing-site migration, custom and historical isolation\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($directory);
}
