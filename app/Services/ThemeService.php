<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class ThemeService
{
    private $path;
    private $theme;

    public function __construct($theme)
    {
        $this->theme = $theme;
        $this->path = $path = public_path('theme/');
    }

    public function init()
    {
        $data = $this->defaults();

        $data = var_export($data, 1);
        try {
            if (!File::put(base_path() . "/config/theme/{$this->theme}.php", "<?php\n return $data ;")) {
                abort(500, "{$this->theme}初始化失败");
            }
        } catch (\Exception $e) {
            abort(500, '请检查V2Board目录权限');
        }

        try {
            Artisan::call('config:cache');
            while (true) {
                if (config("theme.{$this->theme}")) break;
            }
        } catch (\Exception $e) {
            abort(500, "{$this->theme}初始化失败");
        }
    }

    public function defaults(): array
    {
        $themeConfigFile = $this->path . "{$this->theme}/config.json";
        if (!File::exists($themeConfigFile)) abort(500, "{$this->theme}主题不存在");
        $themeConfig = json_decode(File::get($themeConfigFile), true);
        if (!isset($themeConfig['configs']) || !is_array($themeConfig['configs'])) abort(500, "{$this->theme}主题配置文件有误");
        $data = [];
        foreach ($themeConfig['configs'] as $config) {
            if (empty($config['field_name'])) continue;
            $data[$config['field_name']] = array_key_exists('default_value', $config) ? $config['default_value'] : '';
        }
        return $data;
    }

    public function resolvedConfig(): array
    {
        // 主题升级新增字段时使用新主题默认值，已有字段仍以管理员保存值为准。
        $defaults = $this->defaults();
        $saved = (array) config("theme.{$this->theme}", []);
        // 兼容旧版客服 HTML 配置，升级后自动提取 Crisp Website ID。
        if (array_key_exists('crisp_id', $defaults) && empty($saved['crisp_id']) && !empty($saved['customer_service_html'])) {
            if (preg_match('/CRISP_WEBSITE_ID=["\']([^"\']+)["\']/', $saved['customer_service_html'], $matches)) {
                $saved['crisp_id'] = $matches[1];
            }
        }
        foreach ($defaults as $field => $defaultValue) {
            if (!array_key_exists($field, $saved) || ($saved[$field] === '' && $defaultValue !== '')) {
                $saved[$field] = $defaultValue;
            }
        }
        return array_merge($defaults, $saved);
    }
}
