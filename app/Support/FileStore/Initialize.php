<?php
namespace App\Support\FileStore;
use App\Models\ClientRemoteConfig;
use App\Models\ClientConfigSetting;
class Initialize {
    public function run() {
        Store::transaction(function () {
            ClientConfigSetting::current();
            if (!ClientRemoteConfig::exists()) {
                $content = json_encode($this->defaultConfig(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                ClientRemoteConfig::create(['config_version'=>1, 'content_json'=>$content, 'status'=>'draft', 'encryption_mode'=>'xor_base64', 'checksum'=>hash('sha256',$content), 'change_summary'=>'初始化客户端远程配置']);
            }
        });
    }
    private function defaultConfig(): array
    {
        return [
            'config_version' => '1',
            'panel_type' => 'v2board',
            'api_prefix' => '/api/v1',
            'domains' => [],
            'gateway_urls' => [],
            'update' => [
                'schema_version' => 2,
                'platforms' => array_reduce(['android', 'windows', 'macos', 'linux', 'ios', 'tvos'], function ($rows, $platform) {
                    $rows[$platform] = [
                        'enabled' => false,
                        'source' => in_array($platform, ['ios', 'tvos'], true) ? 'app_store' : 'direct',
                        'latest_version' => '',
                        'min_supported_version' => '',
                        'url' => '',
                        'force' => false,
                        'changelog' => ['zh_CN' => '', 'en_US' => ''],
                    ];
                    return $rows;
                }, []),
            ],
            'contact' => [
                'crisp_proxy_url' => '',
                'crisp_website_id' => '',
                'invite_domain' => '',
                'telegram_group' => '',
                'website' => [],
            ],
            'features' => [
                'balance_enabled' => true,
                'devices_enabled' => true,
                'gift_card_enabled' => true,
                'join_group_enabled' => true,
                'knowledge_base_enabled' => true,
                'orders_enabled' => true,
                'tickets_enabled' => true,
                'traffic_details_enabled' => true,
            ],
            'latency' => ['display_discount_percent' => 0],
        ];
    }
}
