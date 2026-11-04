<?php

namespace App\Http\Controllers\V1\Client;

use App\Http\Controllers\Controller;
use App\Protocols\General;
use App\Protocols\Singbox\Singbox;
use App\Protocols\Singbox\SingboxOld;
use App\Protocols\ClashMeta;
use App\Protocols\FastCatV1;
use App\Services\ServerService;
use App\Services\UserService;
use App\Services\NodeSecurity\AuditService;
use App\Services\NodeSecurity\UaClassifierService;
use App\Utils\Helper;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = $request->user;
        // account not expired and is not banned.
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $client = (new UaClassifierService())->classify($request->userAgent());
            $client['user_agent'] = trim((string)$request->userAgent());
            $servers = $serverService->getAvailableServers($user, $client);
            $audit = new AuditService();
            $servers = $audit->prepare($request, $servers, 'client.subscribe');
            $queryFlag = strtolower((string) $request->query('flag', ''));
            $fastCatFlag = strtolower((string) config('fastcat.subscription.flag', 'fastcat-v1'));
            if ($queryFlag !== '' && hash_equals($fastCatFlag, $queryFlag)) {
                $this->setSubscribeInfoToServers($servers, $user);
                $class = new FastCatV1($user, $servers);
                return $this->auditedResponse($audit, $request, $class->handle());
            }
            if($flag) {
                if (!strpos($flag, 'sing')) {
                    $this->setSubscribeInfoToServers($servers, $user);
                    foreach (array_reverse(glob(app_path('Protocols') . '/*.php')) as $file) {
                        $file = 'App\\Protocols\\' . basename($file, '.php');
                        // FastCatV1 is intentionally query-flag only. Never let an
                        // existing FastCat User-Agent opt into encrypted output.
                        if ($file === FastCatV1::class) {
                            continue;
                        }
                        $class = new $file($user, $servers);
                        if (strpos($flag, $class->flag) !== false) {
                            return $this->auditedResponse($audit, $request, $class->handle());
                        }
                    }
                }
                if (strpos($flag, 'sing') !== false) {
                    $version = null;
                    if (preg_match('/sing-box\s+([0-9.]+)/i', $flag, $matches)) {
                        $version = $matches[1];
                    }
                    if (!is_null($version) && $version >= '1.12.0') {
                        $class = new Singbox($user, $servers);
                    } else {
                        $class = new SingboxOld($user, $servers);
                    }
                    return $this->auditedResponse($audit, $request, $class->handle());
                }
            }
            $class = new General($user, $servers);
            return $this->auditedResponse($audit, $request, $class->handle());
        }
    }

    private function auditedResponse(AuditService $audit, Request $request, $response)
    {
        $audit->record($request, $response);
        return $response;
    }

    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!isset($servers[0])) return;
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = $user['u'] + $user['d'];
        $totalTraffic = $user['transfer_enable'];
        $remainingTraffic = Helper::trafficConvert($totalTraffic - $useTraffic);
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
            '_entry_mode' => 'info',
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
                '_entry_mode' => 'info',
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic}",
            '_entry_mode' => 'info',
        ]));
    }
}
