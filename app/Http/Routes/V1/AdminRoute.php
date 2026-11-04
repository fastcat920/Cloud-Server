<?php
namespace App\Http\Routes\V1;

use Illuminate\Contracts\Routing\Registrar;

class AdminRoute
{
    public function map(Registrar $router)
    {
        $router->group([
            'prefix' => config('v2board.secure_path', config('v2board.frontend_admin_path', hash('crc32b', config('app.key')))),
            'middleware' => ['admin', \App\Http\Middleware\LocalWrite::class, 'log'],
        ], function ($router) {
            $router->get('/cloud-resource/regions', 'V1\\Admin\\CloudResourceController@regions')->withoutMiddleware(\App\Http\Middleware\LocalWrite::class);
            $router->get('/cloud-resource/instances', 'V1\\Admin\\CloudResourceController@instances')->withoutMiddleware(\App\Http\Middleware\LocalWrite::class);
            $router->post('/cloud-resource/instance/operate', 'V1\\Admin\\CloudResourceController@operate')->withoutMiddleware(\App\Http\Middleware\LocalWrite::class);
            $router->get('/cloud-resource/buckets', 'V1\\Admin\\CloudResourceController@buckets')->withoutMiddleware(\App\Http\Middleware\LocalWrite::class);
            $router->get('/cloud-resource/objects', 'V1\\Admin\\CloudResourceController@objects')->withoutMiddleware(\App\Http\Middleware\LocalWrite::class);
            $router->post('/cloud-resource/object/upload', 'V1\\Admin\\CloudResourceController@upload')->withoutMiddleware(\App\Http\Middleware\LocalWrite::class);
            $router->post('/cloud-resource/object/delete', 'V1\\Admin\\CloudResourceController@delete')->withoutMiddleware(\App\Http\Middleware\LocalWrite::class);
            $router->get('/cloud-resource/object/download', 'V1\\Admin\\CloudResourceController@download')->withoutMiddleware(\App\Http\Middleware\LocalWrite::class);
            $router->get('/cloud-resource/operations', 'V1\\Admin\\CloudResourceController@operations')->withoutMiddleware(\App\Http\Middleware\LocalWrite::class);
            $router->get ('/config/fetch', 'V1\\Admin\\ConfigController@fetch');
            $router->get ('/client-config/overview', 'V1\\Admin\\ClientRemoteConfigController@overview');
            $router->post('/client-config/draft/save', 'V1\\Admin\\ClientRemoteConfigController@saveDraft');
            $router->post('/client-config/version/clone', 'V1\\Admin\\ClientRemoteConfigController@cloneVersion');
            $router->post('/client-config/version/drop', 'V1\\Admin\\ClientRemoteConfigController@dropVersion');
            $router->post('/client-config/settings/save', 'V1\\Admin\\ClientRemoteConfigController@saveSettings');
            $router->post('/client-config/target/save', 'V1\\Admin\\ClientRemoteConfigController@saveTarget');
            $router->post('/client-config/target/drop', 'V1\\Admin\\ClientRemoteConfigController@dropTarget');
            $router->post('/client-config/target/test', 'V1\\Admin\\ClientRemoteConfigController@testTarget');
            $router->post('/client-config/preview', 'V1\\Admin\\ClientRemoteConfigController@preview');
            $router->post('/client-config/publish', 'V1\\Admin\\ClientRemoteConfigController@publish');
            $router->post('/client-config/publication/retry', 'V1\\Admin\\ClientRemoteConfigController@retryPublication');
            $router->get ('/aliyun-dns/overview', 'V1\\Admin\\AliyunDnsController@overview');
            $router->post('/aliyun-dns/account/save', 'V1\\Admin\\AliyunDnsController@saveAccount');
            $router->post('/aliyun-dns/account/drop', 'V1\\Admin\\AliyunDnsController@dropAccount');
            $router->post('/aliyun-dns/account/test', 'V1\\Admin\\AliyunDnsController@testAccount');
            $router->get ('/aliyun-dns/instances', 'V1\\Admin\\AliyunDnsController@instances');
            $router->get ('/aliyun-dns/topology', 'V1\\Admin\\AliyunDnsController@topology');
            $router->get ('/aliyun-dns/resources', 'V1\\Admin\\AliyunDnsController@resources');
            $router->get ('/aliyun-dns/monitor-templates', 'V1\\Admin\\AliyunDnsController@monitorTemplates');
            $router->post('/aliyun-dns/update', 'V1\\Admin\\AliyunDnsController@update');
            $router->post('/aliyun-dns/order/save', 'V1\\Admin\\AliyunDnsController@saveOrder');
            $router->post('/aliyun-dns/pool/save', 'V1\\Admin\\AliyunDnsController@savePool');
            $router->post('/aliyun-dns/pool/attach', 'V1\\Admin\\AliyunDnsController@attachPool');
            $router->post('/aliyun-dns/pool/drop', 'V1\\Admin\\AliyunDnsController@dropPool');
            $router->post('/aliyun-dns/address/save', 'V1\\Admin\\AliyunDnsController@saveAddress');
            $router->post('/aliyun-dns/address/attach', 'V1\\Admin\\AliyunDnsController@attachAddress');
            $router->post('/aliyun-dns/address/drop', 'V1\\Admin\\AliyunDnsController@dropAddress');
            $router->get ('/cloudflare-dns/overview', 'V1\\Admin\\CloudflareDnsController@overview');
            $router->post('/cloudflare-dns/account/save', 'V1\\Admin\\CloudflareDnsController@saveAccount');
            $router->post('/cloudflare-dns/account/test', 'V1\\Admin\\CloudflareDnsController@testAccount');
            $router->post('/cloudflare-dns/account/drop', 'V1\\Admin\\CloudflareDnsController@dropAccount');
            $router->get ('/cloudflare-dns/zones', 'V1\\Admin\\CloudflareDnsController@zones');
            $router->get ('/cloudflare-dns/records', 'V1\\Admin\\CloudflareDnsController@records');
            $router->post('/cloudflare-dns/record/save', 'V1\\Admin\\CloudflareDnsController@saveRecord');
            $router->post('/cloudflare-dns/record/drop', 'V1\\Admin\\CloudflareDnsController@dropRecord');
            $router->get ('/cloud-account/overview', 'V1\\Admin\\CloudAccountController@overview');
            $router->post('/cloud-account/save', 'V1\\Admin\\CloudAccountController@save');
            $router->post('/cloud-account/refresh', 'V1\\Admin\\CloudAccountController@refresh');
            $router->post('/cloud-account/refresh-all', 'V1\\Admin\\CloudAccountController@refreshAll');
            $router->post('/cloud-account/drop', 'V1\\Admin\\CloudAccountController@drop');
        });
    }
}
