<?php

declare(strict_types=1);

namespace CloudDesk;

final class Plans
{
    public static function migrate(Store $store): void
    {
        $store->transaction(function (array &$s) {
            $s['templates'] ??= [];
            $s['plans'] ??= [];
            if (!$s['templates']) {
                $s['templates'][] = ['id' => 1,'name' => '原有配置结构','legacy_validation' => true,'schema' => self::infer($s['versions'][0]['content'] ?? Configs::defaults())];
            }
            if (!$s['plans']) {
                $s['plans'][] = ['id' => 1,'name' => '默认客户端配置','clients' => '原有客户端','template_id' => 1,'targets' => array_column($s['targets'], 'id')];
            }
            foreach ($s['versions'] as &$v) {
                $v['plan_id'] ??= 1;
                $v['template_id'] ??= 1;
                $v['revision'] ??= $v['version'];
                $v['schema'] ??= self::infer($v['content']);
                $v['legacy_validation'] ??= ($s['templates'][0]['legacy_validation'] ?? false);
            }
            unset($v);
        });
    }
    public static function infer(mixed $value): array
    {
        $type = match(true) {
            $value === null => 'null',is_bool($value) => 'boolean',is_int($value) => 'integer',is_float($value) => 'number',is_array($value) && array_is_list($value) => 'array',is_array($value) || is_object($value) => 'object',default => 'string'
        };
        if ($type === 'object') {
            $properties = [];
            foreach ((array)$value as $k => $v) {
                $properties[$k] = self::infer($v);
            }
            return ['type' => 'object','properties' => $properties,'additionalProperties' => true];
        }
        if (is_array($value) && $value === []) {
            return ['type' => 'array','items' => []];
        }
        return ['type' => $type];
    }
    public static function validate(mixed $value, array $schema, string $path = '$'): void
    {
        $type = $schema['type'] ?? null;
        $ok = match($type) {
            null => true,'object' => is_array($value) && ($value === [] || !array_is_list($value)),'array' => is_array($value) && array_is_list($value),'string' => is_string($value),'integer' => is_int($value),'number' => is_int($value) || is_float($value),'boolean' => is_bool($value),'null' => $value === null,default => throw new \RuntimeException('不支持的字段类型：'.$path)
        };
        if (!$ok) {
            throw new \RuntimeException('字段类型不匹配：'.$path.' 应为 '.$type);
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            throw new \RuntimeException('字段选项不匹配：'.$path);
        }
        if ($type === 'object') {
            foreach ($schema['required'] ?? [] as $key) {
                if (!array_key_exists($key, $value)) {
                    throw new \RuntimeException('缺少必填参数：'.$path.'.'.$key);
                }
            }
            foreach ($value as $key => $v) {
                if (isset($schema['properties'][$key])) {
                    self::validate($v, $schema['properties'][$key], $path.'.'.$key);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    throw new \RuntimeException('结构不允许参数：'.$path.'.'.$key);
                }
            }
        }
        if ($type === 'array' && isset($schema['items']) && is_array($schema['items'])) {
            foreach ($value as $i => $v) {
                self::validate($v, $schema['items'], $path.'['.$i.']');
            }
        }
        if (is_string($value) && isset($schema['minLength']) && preg_match_all('/./us', $value) < $schema['minLength']) {
            throw new \RuntimeException('字段过短：'.$path);
        }
        if ((is_int($value) || is_float($value)) && isset($schema['minimum']) && $value < $schema['minimum']) {
            throw new \RuntimeException('字段数值过小：'.$path);
        }
    }
    public static function checkSchema(array $schema): void
    {
        if (($schema['type'] ?? '') !== 'object' || !is_array($schema['properties'] ?? null)) {
            throw new \RuntimeException('结构模板必须包含 type: object 和 properties');
        }
        $walk = function (array $node, int $depth = 0) use (&$walk) {
            if (isset($node['properties']) && !is_array($node['properties'])) {
                throw new \RuntimeException('properties 必须是字段定义对象');
            }
            if (isset($node['enum']) && (!is_array($node['enum']) || !array_is_list($node['enum']) || !$node['enum'])) {
                throw new \RuntimeException('enum 必须为非空选项数组');
            }
            if (isset($node['items']) && !is_array($node['items'])) {
                throw new \RuntimeException('items 必须为字段定义对象');
            }
            if (isset($node['minLength']) && (!is_int($node['minLength']) || $node['minLength'] < 0)) {
                throw new \RuntimeException('minLength 必须是非负整数');
            }
            if (isset($node['additionalProperties']) && !is_bool($node['additionalProperties'])) {
                throw new \RuntimeException('additionalProperties 暂只支持 true 或 false');
            }
            $allowed = ['type','title','description','properties','required','enum','items','minimum','minLength','default','additionalProperties','$schema','$id'];
            foreach (array_keys($node) as $keyword) {
                if (!in_array($keyword, $allowed, true)) {
                    throw new \RuntimeException('暂不支持的结构关键字：'.$keyword);
                }
            }
            if (isset($node['required']) && (!is_array($node['required']) || array_filter($node['required'], fn ($v) => !is_string($v)))) {
                throw new \RuntimeException('required 必须为字段名数组');
            }
            if ($depth > 16) {
                throw new \RuntimeException('模板嵌套过深');
            }
            if (isset($node['type']) && !in_array($node['type'], ['object','array','string','integer','number','boolean','null'], true)) {
                throw new \RuntimeException('不支持的模板字段类型');
            }
            foreach ($node['properties'] ?? [] as $child) {
                if (!is_array($child)) {
                    throw new \RuntimeException('字段定义必须是对象');
                }
                $walk($child, $depth + 1);
            }
            if (isset($node['items'])) {
                $walk($node['items'], $depth + 1);
            }
        };
        $walk($schema);
    }
    public static function diff(mixed $old, mixed $new, string $path = '$'): array
    {
        if ($old === $new) {
            return [];
        }
        if (is_array($old) && is_array($new) && !array_is_list($old) && !array_is_list($new)) {
            $rows = [];
            foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
                $p = $path.'.'.$key;
                if (!array_key_exists($key, $old)) {
                    $rows[] = ['path' => $p,'kind' => '新增','before' => null,'after' => $new[$key]];
                } elseif (!array_key_exists($key, $new)) {
                    $rows[] = ['path' => $p,'kind' => '删除','before' => $old[$key],'after' => null];
                } else {
                    $rows = array_merge($rows, self::diff($old[$key], $new[$key], $p));
                }
            }
            return $rows;
        }
        return [['path' => $path,'kind' => '修改','before' => $old,'after' => $new]];
    }
}
