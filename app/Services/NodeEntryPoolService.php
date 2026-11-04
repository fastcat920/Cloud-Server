<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class NodeEntryPoolService
{
    public function expand(array $servers, ?array $client = null): array
    {
        try {
            $settings = DB::table('v2_node_entry_setting')->get()->keyBy(function ($row) {
                return $row->server_type . ':' . $row->server_id;
            });
            $entries = DB::table('v2_node_entry_pool')->where('enabled', 1)
                ->orderBy('priority')->orderBy('id')->get()->groupBy(function ($row) {
                    return $row->server_type . ':' . $row->server_id;
                });
        } catch (\Throwable $e) {
            return $servers;
        }
        $policies = collect();
        if ($client) {
            try {
                $policies = DB::table('v2_node_entry_client_policy')->where('enabled', 1)
                    ->orderBy('priority')->orderBy('id')->get()->groupBy(function ($row) {
                        return $row->server_type . ':' . $row->server_id;
                    });
            } catch (\Throwable $e) {
                // Client policies are optional during rolling upgrades.
            }
        }

        $expanded = [];
        foreach ($servers as $server) {
            $key = ($server['type'] ?? '') . ':' . ($server['id'] ?? '');
            $setting = $settings->get($key);
            $pool = $entries->get($key, collect());
            if (!$setting || $pool->isEmpty()) {
                $expanded[] = $server;
                continue;
            }
            $policy = $client ? $this->matchingPolicy($policies->get($key, collect()), $client) : null;
            if (!$this->isVisibleToClient($setting, $policy, $client !== null)) continue;
            $deliveryMode = $policy->delivery_mode ?? $setting->delivery_mode;
            $mode = in_array($deliveryMode, ['primary_only', 'manual_backup', 'auto_fallback'], true)
                ? $deliveryMode : 'primary_only';
            $checkUrl = $policy->check_url ?? $setting->check_url;
            $checkInterval = (int)($policy->check_interval ?? $setting->check_interval);
            $selected = $mode === 'primary_only'
                ? $pool->sortByDesc('is_primary')->take(1)
                : $pool;
            foreach ($selected as $index => $entry) {
                try { $host = Crypt::decryptString($entry->host_encrypted); } catch (\Throwable $e) { continue; }
                $copy = $server;
                $copy['host'] = $host;
                $copy['port'] = (int)$entry->port;
                $copy['_entry_id'] = (int)$entry->id;
                $copy['_entry_mode'] = $mode;
                $copy['_entry_logical_name'] = $server['name'];
                $copy['_entry_logical_key'] = $key;
                $copy['_entry_check_url'] = $checkUrl;
                $copy['_entry_check_interval'] = $checkInterval;
                if ($policy) $copy['_entry_client_policy_id'] = (int)$policy->id;
                if ($mode !== 'primary_only') {
                    $copy['name'] = $server['name'] . '｜' . ($entry->is_primary ? '主入口' : $entry->name);
                }
                $copy['cache_key'] = ($copy['cache_key'] ?? $key)
                    . '-entry-' . $entry->id . '-' . $entry->updated_at
                    . '-setting-' . $setting->updated_at
                    . ($policy ? '-policy-' . $policy->id . '-' . $policy->updated_at : '');
                $expanded[] = $copy;
            }
        }
        return $expanded;
    }

    private function isVisibleToClient($setting, $policy, bool $hasClient): bool
    {
        $mode = $setting->client_visibility_mode ?? 'all';
        return self::clientVisibilityAllows($mode, $hasClient && (bool)$policy, $policy->visibility ?? null);
    }

    public static function clientVisibilityAllows(string $mode, bool $matched, ?string $visibility): bool
    {
        if ($mode === 'all') return true;
        if ($mode === 'allowlist') return $matched && ($visibility ?? 'show') === 'show';
        if ($mode === 'denylist') return !$matched || ($visibility ?? 'show') !== 'hide';
        return true;
    }

    private function matchingPolicy($policies, array $client)
    {
        return $policies->first(function ($policy) use ($client) {
            return self::clientPolicyMatches($policy, $client);
        });
    }

    public static function clientPolicyMatches($policy, array $client): bool
    {
        $family = (string)($client['client_family'] ?? '');
        $platform = (string)($client['client_platform'] ?? '');
        $version = trim((string)($client['client_version'] ?? ''));
        $userAgent = trim((string)($client['user_agent'] ?? ''));
        $requiredUserAgent = trim((string)($policy->user_agent ?? ''));
        if ($requiredUserAgent !== '' && $requiredUserAgent !== $userAgent) return false;
        if ($policy->client_family !== '*' && $policy->client_family !== $family) return false;
        if ($policy->client_platform && $policy->client_platform !== '*' && $policy->client_platform !== $platform) return false;
        if ($policy->min_version && ($version === '' || version_compare($version, $policy->min_version, '<'))) return false;
        if ($policy->max_version && ($version === '' || version_compare($version, $policy->max_version, '>'))) return false;
        return true;
    }

    public static function applyClashFallbackGroups(array &$config, array $servers): void
    {
        $logical = [];
        foreach ($servers as $server) {
            if (($server['_entry_mode'] ?? '') !== 'auto_fallback') continue;
            $key = $server['_entry_logical_key'];
            if (!isset($logical[$key])) $logical[$key] = [
                'name' => $server['_entry_logical_name'], 'proxies' => [],
                'url' => $server['_entry_check_url'], 'interval' => $server['_entry_check_interval'],
            ];
            $logical[$key]['proxies'][] = $server['name'];
        }
        foreach ($logical as $group) {
            if (count($group['proxies']) < 2) continue;
            foreach ($config['proxy-groups'] as &$existing) {
                if (!isset($existing['proxies']) || !is_array($existing['proxies'])) continue;
                $matched = array_intersect($existing['proxies'], $group['proxies']);
                if (!$matched) continue;
                $existing['proxies'] = array_values(array_diff($existing['proxies'], $group['proxies']));
                if (!in_array($group['name'], $existing['proxies'], true)) $existing['proxies'][] = $group['name'];
            }
            unset($existing);
            $config['proxy-groups'][] = [
                'name' => $group['name'], 'type' => 'fallback', 'proxies' => array_values($group['proxies']),
                'url' => $group['url'], 'interval' => max(30, $group['interval']),
            ];
        }
    }
}
