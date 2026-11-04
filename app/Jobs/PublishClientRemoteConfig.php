<?php

namespace App\Jobs;

use App\Models\ClientConfigPublication;
use App\Models\ClientRemoteConfig;
use App\Services\ClientConfigCryptoService;
use App\Services\ClientConfigStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishClientRemoteConfig implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $publicationId;
    public $tries = 3;
    public $timeout = 120;

    public function __construct(int $publicationId)
    {
        $this->publicationId = $publicationId;
        $this->onQueue('default');
    }

    public function handle(ClientConfigCryptoService $crypto, ClientConfigStorageService $storage)
    {
        $publication = ClientConfigPublication::with(['config', 'target'])->find($this->publicationId);
        if (!$publication || !$publication->config || !$publication->target) return;

        $publication->status = 'publishing';
        $publication->attempts = (int)$publication->attempts + 1;
        $publication->started_at = time();
        $publication->error = null;
        $publication->save();

        try {
            if (!$publication->target->enabled) throw new \RuntimeException('该云存储目标已停用');
            $payload = $crypto->encode($publication->config);
            $payloadChecksum = hash('sha256', $payload);
            if (!hash_equals($publication->checksum, $payloadChecksum)) {
                $publication->checksum = $payloadChecksum;
            }

            $versionKey = $storage->versionedObjectKey($publication->target, (int)$publication->config->config_version);
            $storage->upload($publication->target, $versionKey, $payload);
            $storage->verifyPublicObject($storage->publicUrlFor($publication->target, $versionKey), $payload);

            $result = $storage->upload($publication->target, $publication->object_key, $payload);
            $publicUrl = $storage->publicUrlFor($publication->target, $publication->object_key);
            $storage->verifyPublicObject($publicUrl, $payload);

            $publication->status = 'success';
            $publication->public_url = $publicUrl;
            $publication->etag = $result['etag'] ?: null;
            $publication->finished_at = time();
            $publication->save();
            $this->refreshConfigStatus($publication->config_id);
        } catch (\Throwable $e) {
            $publication->status = 'failed';
            $publication->error = mb_substr($e->getMessage(), 0, 2000);
            $publication->finished_at = $publication->status === 'failed' ? time() : null;
            $publication->save();
            if ($publication->status === 'failed') $this->refreshConfigStatus($publication->config_id);
            // Sync publishing returns failure details through the publication record.
            return;
        }
    }

    public function failed(\Throwable $exception)
    {
        $publication = ClientConfigPublication::find($this->publicationId);
        if (!$publication) return;
        $publication->status = 'failed';
        $publication->error = mb_substr($exception->getMessage(), 0, 2000);
        $publication->finished_at = time();
        $publication->save();
        $this->refreshConfigStatus($publication->config_id);
    }

    private function refreshConfigStatus(int $configId): void
    {
        $config = ClientRemoteConfig::find($configId);
        if (!$config) return;
        $rows = $config->publications()->get();
        if ($rows->contains(function ($row) { return in_array($row->status, ['queued', 'publishing'], true); })) return;

        $allSuccess = $rows->isNotEmpty() && $rows->every(function ($row) { return $row->status === 'success'; });
        if ($allSuccess) {
            ClientRemoteConfig::where('id', '<>', $config->id)->where('status', 'published')->update([
                'status' => 'archived', 'updated_at' => time(),
            ]);
            $config->status = 'published';
            $config->published_at = time();
        } else {
            $config->status = 'partial';
        }
        $config->save();
    }
}
