<!DOCTYPE html>
<html>

<head>
    <link rel="stylesheet" href="/assets/admin/components.chunk.css?v={{$version}}">
    <link rel="stylesheet" href="/assets/admin/umi.css?v={{$version}}">
    <link rel="stylesheet" href="/assets/admin/custom.css?v={{$version}}">
    <link rel="stylesheet" href="/assets/admin/security-entry.css?v={{$version}}-{{filemtime(public_path('assets/admin/security-entry.css'))}}">
    <link rel="stylesheet" href="/assets/admin/referral-admin.css?v={{$version}}-{{filemtime(public_path('assets/admin/referral-admin.css'))}}">
    <link rel="stylesheet" href="/assets/referral-growth.css?v={{$version}}-{{filemtime(public_path('assets/referral-growth.css'))}}">
    <link rel="stylesheet" href="/assets/admin/coupon-center.css?v={{$version}}-{{filemtime(public_path('assets/admin/coupon-center.css'))}}">
    <link rel="stylesheet" href="/assets/admin/marketing-activity.css?v={{$version}}-{{filemtime(public_path('assets/admin/marketing-activity.css'))}}">
    <link rel="stylesheet" href="/assets/admin/client-config-center.css?v={{$version}}-{{filemtime(public_path('assets/admin/client-config-center.css'))}}">
    <link rel="stylesheet" href="/assets/admin/aliyun-dns.css?v={{$version}}-{{filemtime(public_path('assets/admin/aliyun-dns.css'))}}">
    <link rel="stylesheet" href="/assets/admin/cloud-account.css?v={{$version}}-{{filemtime(public_path('assets/admin/cloud-account.css'))}}">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no">
    <title>{{$title}}</title>
    <!-- <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Nunito+Sans:300,400,400i,600,700"> -->
    <script>window.routerBase = "/";</script>
    <script>
        window.settings = {
            title: '{{$title}}',
            theme: {
                sidebar: '{{$theme_sidebar}}',
                header: '{{$theme_header}}',
                color: '{{$theme_color}}',
            },
            version: '{{$version}}',
            background_url: '{{$background_url}}',
            logo: '{{$logo}}',
            secure_path: '{{$secure_path}}'
        }
    </script>
</head>

<body>
<div id="root"></div>
<script src="/assets/admin/vendors.async.js?v={{$version}}"></script>
<script src="/assets/admin/components.async.js?v={{$version}}"></script>
<script>
    (function () {
        var customRoutes = ['#/client-config', '#/aliyun-dns', '#/cloud-accounts'];
        if (customRoutes.indexOf(window.location.hash) === -1) return;
        window.__fastcatCustomAdminRoute = window.location.hash;
        window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search + '#/dashboard');
    })();
</script>
<script src="/assets/admin/umi.js?v={{$version}}-{{filemtime(public_path('assets/admin/umi.js'))}}"></script>
<script src="/assets/admin/security-entry.js?v={{$version}}-{{filemtime(public_path('assets/admin/security-entry.js'))}}"></script>
<script src="/assets/admin/referral-admin.js?v={{$version}}-{{filemtime(public_path('assets/admin/referral-admin.js'))}}"></script>
<script src="/assets/admin/coupon-center.js?v={{$version}}-{{filemtime(public_path('assets/admin/coupon-center.js'))}}"></script>
<script src="/assets/admin/marketing-activity.js?v={{$version}}-{{filemtime(public_path('assets/admin/marketing-activity.js'))}}"></script>
<script src="/assets/admin/client-config-center.js?v={{$version}}-{{filemtime(public_path('assets/admin/client-config-center.js'))}}"></script>
<script src="/assets/admin/aliyun-dns.js?v={{$version}}-{{filemtime(public_path('assets/admin/aliyun-dns.js'))}}"></script>
<script src="/assets/admin/cloud-account.js?v={{$version}}-{{filemtime(public_path('assets/admin/cloud-account.js'))}}"></script>
<script>
    (function restoreCustomAdminRoute(attempt) {
        var target = window.__fastcatCustomAdminRoute;
        if (!target) return;
        if (!document.getElementById('sidebar')) {
            if (attempt < 200) window.setTimeout(function () { restoreCustomAdminRoute(attempt + 1); }, 50);
            return;
        }
        window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search + target);
        window.__fastcatCustomAdminRoute = null;
        window.dispatchEvent(new CustomEvent('fastcat:admin-route-ready', { detail: target }));
    })(0);
</script>
</body>

</html>
