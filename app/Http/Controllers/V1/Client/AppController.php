<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Services\ServerService;
use App\Services\UserService;
use App\Services\NodeSecurity\AuditService;
use App\Services\NodeSecurity\UaClassifierService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

class AppController extends Controller
{
    public function getConfig(Request $request)
    {
        $servers = [];
        $user = $request->user;
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $client = (new UaClassifierService())->classify($request->userAgent());
            $client['user_agent'] = trim((string)$request->userAgent());
            $servers = $serverService->getAvailableServers($user, $client);
        }
        $audit = new AuditService();
        $servers = $audit->prepare($request, $servers, 'client.app.config');
        $defaultConfig = base_path() . '/resources/rules/app.clash.yaml';
        $customConfig = base_path() . '/resources/rules/custom.app.clash.yaml';
        if (File::exists($customConfig)) {
            $config = Yaml::parseFile($customConfig);
        } else {
            $config = Yaml::parseFile($defaultConfig);
        }
        $proxy = [];
        $proxies = [];

        foreach ($servers as $item) {
            if ($item['type'] === 'shadowsocks'
                && in_array($item['cipher'], [
                    'aes-128-gcm',
                    'aes-192-gcm',
                    'aes-256-gcm',
                    'chacha20-ietf-poly1305'
                ])
            ) {
                array_push($proxy, \App\Protocols\Clash::buildShadowsocks($user['uuid'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === 'vmess') {
                array_push($proxy, \App\Protocols\Clash::buildVmess($user['uuid'], $item));
                array_push($proxies, $item['name']);
            }
            if ($item['type'] === 'trojan') {
                array_push($proxy, \App\Protocols\Clash::buildTrojan($user['uuid'], $item));
                array_push($proxies, $item['name']);
            }
        }

        $config['proxies'] = array_merge($config['proxies'] ? $config['proxies'] : [], $proxy);
        foreach ($config['proxy-groups'] as $k => $v) {
            $config['proxy-groups'][$k]['proxies'] = array_merge($config['proxy-groups'][$k]['proxies'], $proxies);
        }
        \App\Services\NodeEntryPoolService::applyClashFallbackGroups($config, $servers);
        $yamlContent = Yaml::dump($config);
        $response = response($yamlContent, 200)
            ->header('Content-Type', 'text/yaml');
        $audit->record($request, $response);
        return $response;
    }

    public function getVersion(Request $request)
    {
        if (strpos($request->header('user-agent'), 'tidalab/4.0.0') !== false
            || strpos($request->header('user-agent'), 'tunnelab/4.0.0') !== false
        ) {
            if (strpos($request->header('user-agent'), 'Win64') !== false) {
                return response([
                    'data' => [
                        'version' => config('v2board.windows_version'),
                        'download_url' => config('v2board.windows_download_url')
                    ]
                ]);
            } else {
                return response([
                    'data' => [
                        'version' => config('v2board.macos_version'),
                        'download_url' => config('v2board.macos_download_url')
                    ]
                ]);
            }
            return;
        }
        return response([
            'data' => [
                'windows_version' => config('v2board.windows_version'),
                'windows_download_url' => config('v2board.windows_download_url'),
                'macos_version' => config('v2board.macos_version'),
                'macos_download_url' => config('v2board.macos_download_url'),
                'android_version' => config('v2board.android_version'),
                'android_download_url' => config('v2board.android_download_url')
            ]
        ]);
    }
}
