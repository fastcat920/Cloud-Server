<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ServerService;
use App\Services\UserService;
use App\Services\NodeSecurity\AuditService;
use App\Services\NodeSecurity\UaClassifierService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ServerController extends Controller
{
    public function fetch(Request $request)
    {
        $user = User::find($request->user['id']);
        $servers = [];
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $client = (new UaClassifierService())->classify($request->userAgent());
            $client['user_agent'] = trim((string)$request->userAgent());
            $servers = $serverService->getAvailableServers($user, $client);
        }
        $audit = new AuditService();
        $servers = $audit->prepare($request, $servers, 'user.server.fetch');
        $eTag = sha1(json_encode(array_column($servers, 'cache_key')));
        if (strpos($request->header('If-None-Match'), $eTag) !== false ) {
            $response = response('', 304)->header('ETag', "{$eTag}");
            $audit->record($request, $response);
            return $response;
        }

        $response = response([
            'data' => $servers
        ])->header('ETag', "\"{$eTag}\"");
        $audit->record($request, $response);
        return $response;
    }
}
