<?php
use Illuminate\Support\Facades\Route;
Route::get('/setup', 'SetupController@show');
Route::post('/setup', 'SetupController@create');
$securePath = config('v2board.secure_path', 'admin');
Route::get('/', function () use ($securePath) { return redirect('/' . $securePath); });
// Catboard 正式管理端：React 源码与 Laravel 同仓维护，刷新任意子路由均由此入口接管。
Route::get('/' . $securePath . '/{path?}', function () use ($securePath) {
    $manifestPath = public_path('assets/admin-app/.vite/manifest.json');
    if (!is_file($manifestPath)) {
        abort(503, '新管理端尚未构建，请先在 admin-ui 目录执行 pnpm build');
    }

    $manifest = json_decode(file_get_contents($manifestPath), true);
    $entry = $manifest['index.html'] ?? null;
    if (!$entry || empty($entry['file'])) {
        abort(503, '新管理端构建产物不完整');
    }

    // Keep JSON construction out of Blade. Some Laravel 8 Blade compiler
    // versions cannot reliably parse a multiline @json array expression.
    $settings = [
        'title' => config('v2board.app_name', 'V2Board'),
        'host' => '',
        'secure_path' => $securePath,
        'admin_base_path' => '/' . $securePath,
        'theme' => [
            'sidebar' => config('v2board.frontend_theme_sidebar', 'light'),
            'header' => config('v2board.frontend_theme_header', 'dark'),
            'color' => config('v2board.frontend_theme_color', 'default'),
        ],
        'background_url' => config('v2board.frontend_background_url'),
        'logo' => config('v2board.logo'),
    ];

    return view('admin-next', [
        'title' => config('v2board.app_name', 'V2Board'),
        'version' => config('app.version'),
        'settings_json' => json_encode(
            $settings,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_HEX_TAG
                | JSON_HEX_AMP
                | JSON_HEX_APOS
                | JSON_HEX_QUOT
        ),
        'admin_entry' => $entry,
    ]);
})->where('path', '.*');

