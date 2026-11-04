<?php

namespace App\Protocols;

use App\Services\FastCatSubscriptionCipher;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class FastCatV1 extends ClashMeta
{
    public $flag = 'fastcat-v1';

    private const HIDDEN_PROXY_GROUPS = ['自动选择', '故障转移'];

    public function handle()
    {
        if (!config('fastcat.subscription.enabled', false)) {
            throw new ServiceUnavailableHttpException(null, 'FastCat encrypted subscription is disabled.');
        }

        $yaml = parent::handle();
        $envelope = app(FastCatSubscriptionCipher::class)->encrypt($yaml);

        header('Content-Type: application/vnd.fastcat.subscription+json; charset=utf-8');
        header('X-FastCat-Protocol: 1');
        header('Cache-Control: private, no-store');

        return $envelope;
    }

    protected function transformConfig(array $config): array
    {
        return self::removeHiddenProxyGroups($config);
    }

    public static function removeHiddenProxyGroups(array $config): array
    {
        $groups = $config['proxy-groups'] ?? [];
        if (!is_array($groups)) {
            return $config;
        }

        $config['proxy-groups'] = array_values(array_filter(array_map(function ($group) {
            if (!is_array($group)) {
                return $group;
            }

            if (in_array($group['name'] ?? null, self::HIDDEN_PROXY_GROUPS, true)) {
                return null;
            }

            if (isset($group['proxies']) && is_array($group['proxies'])) {
                $group['proxies'] = array_values(array_filter(
                    $group['proxies'],
                    function ($proxy) {
                        return !in_array($proxy, self::HIDDEN_PROXY_GROUPS, true);
                    }
                ));
            }

            return $group;
        }, $groups), function ($group) {
            return $group !== null;
        }));

        return $config;
    }
}
