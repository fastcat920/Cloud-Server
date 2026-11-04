<?php

namespace App\Services\NodeSecurity;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuditService
{
    public function prepare(Request $request, array $servers, string $endpoint): array
    {
        try {
            $settings = (new SettingsService())->all();
            if (!$settings['enabled']) return $servers;
            $score = DB::table('v2_security_user_score')->where('user_id', (int)$request->user['id'])->first();
            $autoThreshold = (int)($settings['auto_suspend_score'] ?? 0);
            if ($score && ($score->status === 'suspended' || ($score->status !== 'trusted' && $autoThreshold > 0 && $score->risk_score >= $autoThreshold))) {
                $request->attributes->set('node_security_denied', true);
                $servers = [];
            }
            $servers = (new WatermarkService())->apply((int)$request->user['id'], $servers);
            $request->attributes->set('node_security_started_at', microtime(true));
            $request->attributes->set('node_security_endpoint', $endpoint);
            $request->attributes->set('node_security_snapshot_ids', (new SnapshotService())->capture($servers));
            foreach ($servers as &$server) unset($server['_watermark_group_id']);
            unset($server);
        } catch (\Throwable $e) {
            Log::warning('Node security prepare failed: ' . $e->getMessage());
        }
        return $servers;
    }

    public function record(Request $request, $response): void
    {
        $snapshotIds = $request->attributes->get('node_security_snapshot_ids');
        if (!is_array($snapshotIds)) return;
        try {
            $now = time();
            $ua = mb_substr((string)$request->userAgent(), 0, 512);
            $uaClassification = (new UaClassifierService())->classify($ua);
            $ip = (string)$request->ip();
            $started = (float)$request->attributes->get('node_security_started_at', microtime(true));
            [$content, $status, $etag] = $this->responseMetadata($response);
            $accessLogId = DB::table('v2_node_access_log')->insertGetId([
                'user_id' => (int)$request->user['id'],
                'session_id' => mb_substr((string)($request->user['auth_session'] ?? ''), 0, 64) ?: null,
                'snapshot_ids' => json_encode($snapshotIds),
                'snapshot_set_hash' => hash('sha256', implode(',', $snapshotIds)),
                'endpoint' => $request->attributes->get('node_security_endpoint', 'unknown'),
                'request_ip' => mb_substr($ip, 0, 64),
                'ip_hash' => hash('sha256', $ip),
                'user_agent' => $ua ?: null,
                'ua_hash' => $uaClassification['ua_hash'],
                'client_family' => $uaClassification['client_family'],
                'client_version' => $uaClassification['client_version'],
                'client_platform' => $uaClassification['client_platform'],
                'device_hash' => hash('sha256', strtolower($ua) . '|' . $ip),
                'etag' => $etag,
                'response_status' => $status,
                'response_bytes' => strlen($content),
                'duration_ms' => max(0, (int)round((microtime(true) - $started) * 1000)),
                'requested_at' => $now,
                'created_at' => $now,
            ]);
            $links = collect($snapshotIds)->map(function ($snapshotId) use ($accessLogId, $request, $now) {
                return [
                    'access_log_id' => $accessLogId, 'user_id' => (int)$request->user['id'],
                    'snapshot_id' => (int)$snapshotId, 'requested_at' => $now, 'created_at' => $now,
                ];
            })->filter(function ($link) { return $link['snapshot_id'] > 0; })->unique('snapshot_id')->values()->all();
            if ($links) DB::table('v2_node_access_snapshot')->insertOrIgnore($links);
        } catch (\Throwable $e) {
            Log::warning('Node security record failed: ' . $e->getMessage());
        }
    }

    private function responseMetadata($response): array
    {
        if (is_object($response) && method_exists($response, 'getContent')) {
            $content = (string)$response->getContent();
        } elseif (is_string($response)) {
            $content = $response;
        } elseif (is_scalar($response)) {
            $content = (string)$response;
        } else {
            $content = json_encode($response) ?: '';
        }

        $status = is_object($response) && method_exists($response, 'getStatusCode')
            ? (int)$response->getStatusCode()
            : 200;
        $etag = null;
        if (is_object($response) && isset($response->headers) && method_exists($response->headers, 'get')) {
            $etag = mb_substr((string)$response->headers->get('ETag'), 0, 64) ?: null;
        }

        return [$content, $status, $etag];
    }
}
