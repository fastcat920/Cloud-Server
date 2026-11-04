import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const sourceDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const projectRoot = path.resolve(sourceDir, '../../..');
const distDir = path.join(sourceDir, 'dist');
const outputDir = path.join(projectRoot, 'public/theme/default');

const copyDir = async (source, target) => {
  await fs.mkdir(target, { recursive: true });
  for (const entry of await fs.readdir(source, { withFileTypes: true })) {
    const from = path.join(source, entry.name);
    const to = path.join(target, entry.name);
    if (entry.isDirectory()) await copyDir(from, to);
    else await fs.copyFile(from, to);
  }
};

const assetTags = html => ({
  css: [...html.matchAll(/<link[^>]+rel="stylesheet"[^>]+href="([^"]+\.css(?:\?[^"]*)?)"[^>]*>/g)].map(x => x[1]),
  js: [...html.matchAll(/<script[^>]+defer="defer"[^>]+src="([^"]+\.js(?:\?[^"]*)?)"[^>]*><\/script>/g)].map(x => x[1])
});

const blade = ({ css, js }) => `<!doctype html>
<html lang="zh-CN">
<head>
  <base href="/theme/default/">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no">
  <meta name="description" content="{{ $description }}">
  <title>{{ $title }}</title>
  <script>
    window.CATBOARD_THEME = Object.assign({}, @json($theme_config ?? []), {
      title: @json($title),
      title_zh: @json($title_zh ?? $title),
      title_en: @json($title_en ?? $title),
      description: @json($description),
      description_zh: @json($description_zh ?? $description),
      description_en: @json($description_en ?? $description),
      logo: @json($logo ?? '')
    });
    if (!window.location.hash) window.location.replace(window.location.pathname + window.location.search + '#/');
  </script>
${css.map(href => `  <link rel="stylesheet" href="${href.split('?')[0]}?v={{ $version }}">`).join('\n')}
${js.map(src => `  <script defer src="${src.split('?')[0]}?v={{ $version }}"></script>`).join('\n')}
</head>
<body>
  <div id="app"></div>
  {!! $theme_config['custom_html'] ?? '' !!}
</body>
</html>
`;

const main = async () => {
  const html = await fs.readFile(path.join(distDir, 'index.html'), 'utf8');
  const tags = assetTags(html);
  if (!tags.js.length) throw new Error('No compiled JavaScript assets found');

  // 保留历史哈希资源，避免发布过程中浏览器/CDN 缓存的旧入口请求到已删除分包而白屏。
  // 新入口会引用新哈希文件；历史资源可在确认缓存完全失效后单独清理。
  await fs.mkdir(outputDir, { recursive: true });
  await copyDir(path.join(distDir, 'static'), path.join(outputDir, 'static'));
  try { await copyDir(path.join(sourceDir, 'public/images'), path.join(outputDir, 'images')); } catch (_) {}
  await fs.writeFile(path.join(outputDir, 'dashboard.blade.php'), blade(tags));
  await fs.writeFile(path.join(outputDir, 'config.json'), JSON.stringify({
    name: 'default',
    description: 'Catboard 默认用户主题',
    version: '2.0.0',
    images: '/theme/default/images/background.jpg',
    configs: [
      { label: '主题主色', placeholder: '例如 #4566AE', field_name: 'primary_color', field_type: 'input', default_value: '#4566AE' },
      { label: '显示标题 Logo', placeholder: '请选择是否显示', field_name: 'show_logo', field_type: 'select', select_options: { '1': '显示', '0': '隐藏' }, default_value: '1' },
      { label: '默认语言', field_name: 'default_language', field_type: 'select', select_options: { 'zh-CN': '简体中文', 'en-US': 'English' }, default_value: 'zh-CN' },
      { label: '默认外观', field_name: 'default_theme', field_type: 'select', select_options: { light: '浅色', dark: '深色' }, default_value: 'light' },
      { label: '启用落地页', placeholder: '请选择是否启用', field_name: 'enable_landing_page', field_type: 'select', select_options: { '1': '启用', '0': '关闭' }, default_value: '1' },
      { label: '显示客户端下载卡片', field_name: 'show_download_card', field_type: 'select', select_options: { '1': '显示', '0': '隐藏' }, default_value: '1' },
      { label: '显示 iOS 客户端', field_name: 'show_ios', field_type: 'select', select_options: { '1': '显示', '0': '隐藏' }, default_value: '1' },
      { label: '显示 Android 客户端', field_name: 'show_android', field_type: 'select', select_options: { '1': '显示', '0': '隐藏' }, default_value: '1' },
      { label: '显示 macOS 客户端', field_name: 'show_macos', field_type: 'select', select_options: { '1': '显示', '0': '隐藏' }, default_value: '1' },
      { label: '显示 Windows 客户端', field_name: 'show_windows', field_type: 'select', select_options: { '1': '显示', '0': '隐藏' }, default_value: '1' },
      { label: '显示 Linux 客户端', field_name: 'show_linux', field_type: 'select', select_options: { '1': '显示', '0': '隐藏' }, default_value: '0' },
      { label: '显示 OpenWrt 客户端', field_name: 'show_openwrt', field_type: 'select', select_options: { '1': '显示', '0': '隐藏' }, default_value: '0' },
      { label: 'iOS 下载地址', field_name: 'client_link_ios', field_type: 'input', default_value: '/#/docs/13' },
      { label: 'Android 下载地址', field_name: 'client_link_android', field_type: 'input', default_value: '/#/docs/2' },
      { label: 'macOS 下载地址', field_name: 'client_link_macos', field_type: 'input', default_value: '/#/docs/5' },
      { label: 'Windows 下载地址', field_name: 'client_link_windows', field_type: 'input', default_value: '/#/docs/3' },
      { label: 'Linux 下载地址', field_name: 'client_link_linux', field_type: 'input', default_value: 'https://github.com/xxx/releases/latest' },
      { label: 'OpenWrt 下载地址', field_name: 'client_link_openwrt', field_type: 'input', default_value: 'https://github.com/xxx/releases/latest' },
      { label: 'iOS 版本号', placeholder: '例如 2.2.0', field_name: 'client_version_ios', field_type: 'input', default_value: '2.2.0' },
      { label: 'Android 版本号', placeholder: '例如 3.5.9', field_name: 'client_version_android', field_type: 'input', default_value: '3.5.9' },
      { label: 'macOS 版本号', placeholder: '例如 3.5.9', field_name: 'client_version_macos', field_type: 'input', default_value: '3.5.9' },
      { label: 'Windows 版本号', placeholder: '例如 3.5.9', field_name: 'client_version_windows', field_type: 'input', default_value: '3.5.9' },
      { label: 'Linux 版本号', placeholder: '例如 3.5.9', field_name: 'client_version_linux', field_type: 'input', default_value: '3.5.9' },
      { label: 'OpenWrt 版本号', placeholder: '例如 3.5.9', field_name: 'client_version_openwrt', field_type: 'input', default_value: '3.5.9' },
      { label: '启用客服系统', field_name: 'customer_service_enabled', field_type: 'select', select_options: { '1': '启用', '0': '关闭' }, default_value: '0' },
      { label: '客服嵌入模式', field_name: 'customer_service_embed_mode', field_type: 'select', select_options: { embed: '页面嵌入', popup: '弹出页面' }, default_value: 'embed' },
      { label: '未登录时显示客服', field_name: 'customer_service_guest_visible', field_type: 'select', select_options: { '1': '显示', '0': '隐藏' }, default_value: '1' },
      { label: 'Crisp ID', placeholder: '填写 Crisp Website ID', field_name: 'crisp_id', field_type: 'input', default_value: '' },
      { label: '自定义页脚 HTML', placeholder: '可填写客服、统计等 HTML 或 JavaScript', field_name: 'custom_html', field_type: 'textarea', default_value: '' }
    ]
  }, null, 2) + '\n');
  console.log(`Default theme deployed to ${outputDir}`);
};

main().catch(error => { console.error(error); process.exit(1); });
