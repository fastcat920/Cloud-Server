<?php

namespace App\Services\NodeSecurity;

class UaClassifierService
{
    public function classify(?string $userAgent): array
    {
        $raw = trim((string)$userAgent);
        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', $raw) ?: $raw);
        if ($normalized === '') {
            return ['ua_hash' => null, 'client_family' => '未提供', 'client_version' => null, 'client_platform' => '未知'];
        }

        [$family, $version] = $this->familyAndVersion($raw);
        return [
            'ua_hash' => hash('sha256', $normalized),
            'client_family' => $family,
            'client_version' => $version,
            'client_platform' => $this->platform($raw),
        ];
    }

    private function familyAndVersion(string $ua): array
    {
        $rules = [
            ['FastCat', '/\bfastcat[\/\s-]*v?([0-9][\w.-]*)?/i'],
            ['FlClash', '/\bflclash[\/\s-]*v?([0-9][\w.-]*)?/i'],
            ['Digilink', '/\bdigilink[\/\s-]*v?([0-9][\w.-]*)?/i'],
            ['Clash Verge', '/\bclash[\s_-]*verge[\/\s-]*v?([0-9][\w.-]*)?/i'],
            ['Clash Meta / Mihomo', '/\b(?:clash[\s_-]*meta|mihomo)[\/\s-]*v?([0-9][\w.-]*)?/i'],
            ['Shadowrocket', '/\bshadowrocket[\/\s-]*v?([0-9][\w.-]*)?/i'],
            ['Stash', '/\bstash[\/\s-]*v?([0-9][\w.-]*)?/i'],
            ['Surge', '/\bsurge[\/\s-]*v?([0-9][\w.-]*)?/i'],
            ['v2rayN', '/\bv2rayn[\/\s-]*v?([0-9][\w.-]*)?/i'],
            ['v2rayNG', '/\bv2rayng[\/\s-]*v?([0-9][\w.-]*)?/i'],
            ['sing-box', '/\bsing[\s_-]*box[\/\s-]*v?([0-9][\w.-]*)?/i'],
        ];
        foreach ($rules as [$family, $pattern]) {
            if (preg_match($pattern, $ua, $matches)) return [$family, $matches[1] ?? null];
        }
        if (preg_match('/\b(?:mozilla|chrome|safari|firefox|edg)\b/i', $ua)) return ['浏览器', null];
        if (preg_match('/\b(curl|wget|python-requests|okhttp|go-http-client)\b[\/\s-]*v?([0-9][\w.-]*)?/i', $ua, $matches)) {
            return ['脚本 / HTTP 工具', $matches[2] ?? null];
        }
        return ['其他 / 未识别', null];
    }

    private function platform(string $ua): string
    {
        if (preg_match('/\bplatform[\/\s:_-]*(windows|macos|android|ios|linux)\b/i', $ua, $matches)) {
            return $this->platformLabel($matches[1]);
        }
        $rules = [
            'Windows' => '/\bwindows\b/i', 'Android' => '/\bandroid\b/i',
            'iOS' => '/\b(?:iphone|ipad|ios)\b/i', 'macOS' => '/\b(?:macintosh|mac os|macos)\b/i',
            'Linux' => '/\blinux\b/i',
        ];
        foreach ($rules as $label => $pattern) if (preg_match($pattern, $ua)) return $label;
        return '未知';
    }

    private function platformLabel(string $platform): string
    {
        return ['windows' => 'Windows', 'macos' => 'macOS', 'android' => 'Android', 'ios' => 'iOS', 'linux' => 'Linux'][mb_strtolower($platform)] ?? '未知';
    }
}
