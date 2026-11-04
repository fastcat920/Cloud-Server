<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no">
    <title>{{ $title }}</title>
    <script>
        window.settings = {!! $settings_json !!};
    </script>
    @foreach (($admin_entry['css'] ?? []) as $stylesheet)
        <link rel="stylesheet" href="/assets/admin-app/{{ $stylesheet }}?v={{ $version }}">
    @endforeach
</head>
<body>
<div id="root"></div>
<script type="module" src="/assets/admin-app/{{ $admin_entry['file'] }}?v={{ $version }}"></script>
</body>
</html>
