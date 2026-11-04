<?php
namespace App\Support\FileStore;

/** One locked snapshot makes cross-record changes atomic, including version allocation. */
class Store
{
    private static $state;
    private static $dirty = false;
    public static function transaction(callable $callback)
    {
        if (self::$state !== null) return $callback();
        $dir = storage_path('app/private');
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('无法创建数据目录');
        $lock = fopen($dir . '/state.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('无法锁定数据文件');
        $temporary = null;
        try {
            $path = $dir . '/state.json';
            self::$state = is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
            if (!is_array(self::$state)) throw new \RuntimeException('数据文件格式错误');
            self::$dirty = false;
            $result = $callback();
            if (!self::$dirty) return $result;
            $json = json_encode(self::$state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $temporary = tempnam($dir, '.state-');
            if ($temporary === false) throw new \RuntimeException('无法创建临时数据文件');
            chmod($temporary, 0600);
            if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $path)) throw new \RuntimeException('无法保存数据文件');
            return $result;
        } finally {
            self::$state = null;
            self::$dirty = false;
            if ($temporary && is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
    public static function rows($table) { return self::transaction(function () use ($table) { return self::$state[$table]['rows'] ?? []; }); }
    public static function put($table, array $attributes)
    {
        return self::transaction(function () use ($table, $attributes) {
            $next = self::$state[$table]['sequence'] ?? 0;
            if (empty($attributes['id'])) $attributes['id'] = ++$next;
            self::$dirty = true;
            self::$state[$table]['sequence'] = max($next, $attributes['id']);
            self::$state[$table]['rows'][$attributes['id']] = $attributes;
            return $attributes;
        });
    }
    public static function remove($table, $id) { return self::transaction(function () use ($table, $id) { self::$dirty = true; unset(self::$state[$table]['rows'][$id]); return true; }); }
}
