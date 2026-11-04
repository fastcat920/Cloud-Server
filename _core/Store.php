<?php

declare(strict_types=1);

namespace CloudDesk;

final class Store
{
    public const GUARD = "<?php http_response_code(404); exit; ?>\n";
    private string $directory;
    public function __construct(string $directory)
    {
        $this->directory = $directory;
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('数据目录不可写，请在宝塔将项目所有者设为 www');
        }
    }
    public function directory(): string
    {
        return $this->directory;
    }
    public function read(): array
    {
        return $this->transaction(static fn (array &$state) => $state, false);
    }
    public function transaction(callable $callback, bool $write = true): mixed
    {
        $lock = fopen($this->directory.'/lock.php', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('无法锁定本地数据');
        }
        $temporary = null;
        try {
            if (fstat($lock)['size'] === 0) {
                fwrite($lock, self::GUARD);
                chmod($this->directory.'/lock.php', 0600);
            }
            $path = $this->directory.'/state.php';
            $state = ['schema' => 2,'accounts' => [],'targets' => [],'versions' => [],'publications' => [],'settings' => ['mode' => 'plain'],'events' => [],'rates' => []];
            if (is_file($path)) {
                $raw = file_get_contents($path);
                if (!str_starts_with($raw, self::GUARD)) {
                    throw new \RuntimeException('数据文件格式损坏，已停止写入');
                }
                $decoded = json_decode(substr($raw, strlen(self::GUARD)), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new \RuntimeException('数据文件格式错误');
                }
                $state = array_replace($state, $decoded);
            }
            $result = $callback($state);
            if ($write) {
                $payload = self::GUARD.json_encode($state, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                $temporary = $this->directory.'/write-'.bin2hex(random_bytes(12)).'.php';
                if (!$temporary) {
                    throw new \RuntimeException('无法创建数据文件');
                }
                $written = file_put_contents($temporary, $payload);
                chmod($temporary, 0600);
                if ($written !== strlen($payload) || !rename($temporary, $path)) {
                    throw new \RuntimeException('保存失败');
                }
            }
            return $result;
        } finally {
            if ($temporary && is_file($temporary)) {
                unlink($temporary);
            }flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    public static function id(array &$state, string $type): int
    {
        $state['sequences'][$type] = max($state['sequences'][$type] ?? 0, ...array_merge([0], array_map(fn ($r) => (int)$r['id'], $state[$type] ?? []))) + 1;
        return $state['sequences'][$type];
    }
    public static function find(array $state, string $type, int $id): array
    {
        foreach ($state[$type] ?? [] as $row) {
            if ((int)$row['id'] === $id) {
                return $row;
            }
        }
        throw new \RuntimeException('记录不存在');
    }
    public static function put(array &$state, string $type, array $row): array
    {
        foreach ($state[$type] as $i => $old) {
            if ((int)$old['id'] === (int)$row['id']) {
                $state[$type][$i] = $row;
                return $row;
            }
        }
        $state[$type][] = $row;
        return $row;
    }
    public static function event(array &$state, string $action, string $resource, string $status = 'success'): void
    {
        $state['events'][] = ['id' => bin2hex(random_bytes(8)),'action' => $action,'resource' => $resource,'status' => $status,'time' => time()];
        $state['events'] = array_slice($state['events'], -1000);
    }
}
