// 默认主题启动入口：业务默认值随主题编译，后台主题配置通过页面直接注入。
import themeDefaults from './utils/themeDefaults';

localStorage.removeItem('savedPassword');

const normalizeBoolean = (value, fallback = false) => {
  if (typeof value === 'boolean') return value;
  if (typeof value === 'number') return value === 1;
  if (typeof value === 'string') {
    const normalized = value.trim().toLowerCase();
    if (['1', 'true', 'on', 'yes'].includes(normalized)) return true;
    if (['0', 'false', 'off', 'no'].includes(normalized)) return false;
  }
  return fallback;
};

const valueOr = (value, fallback) => typeof value === 'string' && value.trim() !== '' ? value.trim() : fallback;
const normalizeCrispId = value => typeof value === 'string' && /^[a-z0-9-]+$/i.test(value.trim()) ? value.trim() : '';
const mergeDeep = (target, source) => {
  const result = { ...target };
  Object.keys(source || {}).forEach(key => {
    const targetValue = result[key];
    const sourceValue = source[key];
    result[key] = targetValue && sourceValue
      && typeof targetValue === 'object' && typeof sourceValue === 'object'
      && !Array.isArray(targetValue) && !Array.isArray(sourceValue)
      ? mergeDeep(targetValue, sourceValue)
      : sourceValue;
  });
  return result;
};
const theme = window.CATBOARD_THEME || {};
const primaryColor = /^#[0-9a-f]{6}$/i.test(theme.primary_color || '') ? theme.primary_color : '#4566AE';
const legacyCrispMatch = typeof theme.customer_service_html === 'string'
  ? theme.customer_service_html.match(/CRISP_WEBSITE_ID=["']([^"']+)["']/)
  : null;
const crispId = normalizeCrispId(theme.crisp_id || (legacyCrispMatch ? legacyCrispMatch[1] : ''));
const crispHtml = crispId
  // Keep the escaped closing tag so this string remains safe if the bootstrap is inlined.
  // eslint-disable-next-line no-useless-escape
  ? `<script type="text/javascript">window.$crisp=[];window.CRISP_WEBSITE_ID="${crispId}";(function(){var d=document;var s=d.createElement("script");s.src="https://client.crisp.chat/l.js";s.async=1;d.getElementsByTagName("head")[0].appendChild(s);})();<\/script>`
  : '';

const managedConfig = {
  SITE_CONFIG: {
    siteName: theme.title || theme.site_name,
    siteDescription: theme.description,
    showLogo: normalizeBoolean(theme.show_logo, true),
    logo: theme.logo || ''
  },
  DEFAULT_CONFIG: {
    defaultLanguage: ['zh-CN', 'en-US'].includes(theme.default_language) ? theme.default_language : 'zh-CN',
    defaultTheme: ['light', 'dark'].includes(theme.default_theme) ? theme.default_theme : 'light',
    primaryColor,
    enableLandingPage: normalizeBoolean(theme.enable_landing_page, true)
  },
  CLIENT_CONFIG: {
    showDownloadCard: normalizeBoolean(theme.show_download_card, true),
    showIOS: normalizeBoolean(theme.show_ios, true),
    showAndroid: normalizeBoolean(theme.show_android, true),
    showMacOS: normalizeBoolean(theme.show_macos, true),
    showWindows: normalizeBoolean(theme.show_windows, true),
    showLinux: normalizeBoolean(theme.show_linux, false),
    showOpenWrt: normalizeBoolean(theme.show_openwrt, false),
    clientLinks: {
      ios: valueOr(theme.client_link_ios, '/#/docs/13'),
      android: valueOr(theme.client_link_android, '/#/docs/2'),
      macos: valueOr(theme.client_link_macos, '/#/docs/5'),
      windows: valueOr(theme.client_link_windows, '/#/docs/3'),
      linux: valueOr(theme.client_link_linux, 'https://github.com/xxx/releases/latest'),
      openwrt: valueOr(theme.client_link_openwrt, 'https://github.com/xxx/releases/latest')
    },
    clientVersions: {
      ios: valueOr(theme.client_version_ios, '2.2.0'),
      android: valueOr(theme.client_version_android, '3.5.9'),
      macos: valueOr(theme.client_version_macos, '3.5.9'),
      windows: valueOr(theme.client_version_windows, '3.5.9'),
      linux: valueOr(theme.client_version_linux, '3.5.9'),
      openwrt: valueOr(theme.client_version_openwrt, '3.5.9')
    }
  },
  CUSTOMER_SERVICE_CONFIG: {
    enabled: normalizeBoolean(theme.customer_service_enabled, false),
    type: 'crisp',
    crispId,
    customHtml: crispHtml,
    embedMode: ['popup', 'embed'].includes(theme.customer_service_embed_mode) ? theme.customer_service_embed_mode : 'embed',
    showWhenNotLoggedIn: normalizeBoolean(theme.customer_service_guest_visible, true)
  }
};

window.EZ_CONFIG = mergeDeep(themeDefaults, managedConfig);

import('./appInit.js');
