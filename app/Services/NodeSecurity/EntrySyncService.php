<?php

namespace App\Services\NodeSecurity;

use App\Models\ServerAnytls;
use App\Models\ServerHysteria;
use App\Models\ServerShadowsocks;
use App\Models\ServerTrojan;
use App\Models\ServerTuic;
use App\Models\ServerV2node;
use App\Models\ServerVless;
use App\Models\ServerVmess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EntrySyncService
{
    public static function modelMap(): array
    {
        return [
            'shadowsocks' => ServerShadowsocks::class,
            'vmess' => ServerVmess::class,
            'trojan' => ServerTrojan::class,
            'tuic' => ServerTuic::class,
            'hysteria' => ServerHysteria::class,
            'vless' => ServerVless::class,
            'anytls' => ServerAnytls::class,
            'v2node' => ServerV2node::class,
        ];
    }

    public function syncModelToPrimary(string $serverType, Model $server): void
    {
        $this->syncServerToPrimary($serverType, (int)$server->getKey(), [
            'host' => $server->getAttribute('host'),
            'port' => $server->getAttribute('port'),
        ]);
    }

    public function syncServerToPrimary(string $serverType, int $serverId, array $server): void
    {
        $setting = $this->setting($serverType, $serverId);
        if (!$setting) return;

        $primary = DB::table('v2_node_entry_pool')->where([
            'server_type' => $serverType, 'server_id' => $serverId, 'is_primary' => 1,
        ])->first();
        if (!$primary) return;

        $changes = [];
        if ($setting->sync_primary_host) {
            $host = trim((string)($server['host'] ?? ''));
            $hash = hash('sha256', strtolower($host));
            if ($host !== '' && $hash !== $primary->host_hash) {
                $changes['host_encrypted'] = Crypt::encryptString($host);
                $changes['host_hash'] = $hash;
            }
        }
        if ($setting->sync_primary_port && $this->validPort($server['port'] ?? null) && (int)$server['port'] !== (int)$primary->port) {
            $changes['port'] = (int)$server['port'];
        }
        if ($changes) {
            $changes['updated_at'] = time();
            DB::table('v2_node_entry_pool')->where('id', $primary->id)->update($changes);
        }
    }

    public function syncPrimaryToServer(string $serverType, int $serverId): void
    {
        $setting = $this->setting($serverType, $serverId);
        $modelClass = self::modelMap()[$serverType] ?? null;
        if (!$setting || !$modelClass) return;

        $primary = DB::table('v2_node_entry_pool')->where([
            'server_type' => $serverType, 'server_id' => $serverId, 'is_primary' => 1,
        ])->first();
        if (!$primary) return;

        $changes = [];
        if ($setting->sync_primary_host) {
            try { $changes['host'] = Crypt::decryptString($primary->host_encrypted); } catch (\Throwable $e) { return; }
        }
        if ($setting->sync_primary_port) $changes['port'] = (int)$primary->port;
        if ($changes) $modelClass::whereKey($serverId)->update($changes);
    }

    public function validPort($port): bool
    {
        $port = (string)$port;
        return ctype_digit($port) && (int)$port >= 1 && (int)$port <= 65535;
    }

    private function setting(string $serverType, int $serverId)
    {
        if (!Schema::hasTable('v2_node_entry_setting') ||
            !Schema::hasColumn('v2_node_entry_setting', 'sync_primary_host') ||
            !Schema::hasColumn('v2_node_entry_setting', 'sync_primary_port')) return null;

        return DB::table('v2_node_entry_setting')->where([
            'server_type' => $serverType, 'server_id' => $serverId,
        ])->first();
    }
}
