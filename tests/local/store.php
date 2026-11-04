<?php
// Run with php tests/local/store.php; no framework or external services required.
$directory = sys_get_temp_dir() . '/cloud-store-' . bin2hex(random_bytes(8));
function storage_path($path) { global $directory; return $directory . '/' . $path; }
require __DIR__ . '/../../app/Support/FileStore/Store.php';
use App\Support\FileStore\Store;
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
try {
    Store::transaction(function () {
        $a = Store::put('accounts', ['name'=>'first']);
        check($a['id'] === 1, 'First ID');
        Store::put('history', ['account_id'=>$a['id']]);
    });
    try {
        Store::transaction(function () { Store::put('accounts', ['name'=>'rollback']); throw new RuntimeException('abort'); });
    } catch (RuntimeException $e) { check($e->getMessage() === 'abort', 'Unexpected exception'); }
    check(count(Store::rows('accounts')) === 1, 'Rollback failed');
    Store::remove('accounts', 1);
    check(Store::put('accounts', ['name'=>'next'])['id'] === 2, 'IDs must not be reused');
    check(count(Store::rows('history')) === 1, 'Cross-table persistence');
    if (function_exists('pcntl_fork')) {
        $children = [];
        for ($n=0; $n<4; $n++) {
            $pid = pcntl_fork();
            if ($pid === 0) { for ($i=0; $i<25; $i++) Store::put('concurrent', ['value'=>$i]); exit(0); }
            $children[] = $pid;
        }
        foreach ($children as $pid) { pcntl_waitpid($pid, $status); check(pcntl_wexitstatus($status) === 0, 'Child failed'); }
        $rows = Store::rows('concurrent');
        check(count($rows) === 100 && count(array_unique(array_column($rows, 'id'))) === 100, 'Concurrent writes lost data');
    }
    file_put_contents(storage_path('app/private/state.json'), '{broken');
    try { Store::rows('accounts'); throw new RuntimeException('Corruption accepted'); } catch (JsonException $expected) {}
    check(file_get_contents(storage_path('app/private/state.json')) === '{broken', 'Corrupt data overwritten');
    echo "PASS: persistence, rollback, monotonic IDs, concurrency, corruption protection\n";
} finally {
    foreach (glob($directory . '/app/private/*') ?: [] as $file) unlink($file);
    @rmdir($directory . '/app/private'); @rmdir($directory . '/app'); @rmdir($directory);
}
