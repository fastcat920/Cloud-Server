/**
 * 基础配置文件
 */

// 导入API可用性检测器
import { getAvailableApiUrl } from '@/utils/apiAvailabilityChecker';

// 从外部配置文件中获取配置，如果不存在则使用默认值
const getConfig = (key, defaultValue) => {
  if (typeof window !== 'undefined' && window.EZ_CONFIG && window.EZ_CONFIG[key] !== undefined) {
    return window.EZ_CONFIG[key];
  }
  return defaultValue;
};

// 深度合并对象
const mergeDeep = (target, source) => {
  if (!source) return target;
  const output = { ...target };
  
  if (isObject(target) && isObject(source)) {
    Object.keys(source).forEach(key => {
      if (isObject(source[key])) {
        if (!(key in target)) {
          output[key] = source[key];
        } else {
          output[key] = mergeDeep(target[key], source[key]);
        }
      } else {
        output[key] = source[key];
      }
    });
  }
  return output;
};

// 判断是否为对象
const isObject = item => {
  return (item && typeof item === 'object' && !Array.isArray(item));
};

// 获取授权码

// 用户授权码
export const USER_LICENSE_CODE = '';

export const LICENSE_CODE = USER_LICENSE_CODE || getConfig('LICENSE_CODE', '');

// 获取面板类型的常量
// 可选值: 'V2board', 'Xiao-V2board' 或 'Xboard'
export const PANEL_TYPE = getConfig('PANEL_TYPE', 'V2board');

// 判断是否为Xiao-V2board面板
export const isXiaoV2board = () => {
  return PANEL_TYPE === 'Xiao-V2board';
};

// 判断是否为Xboard面板
export const isXboard = () => {
  return PANEL_TYPE === 'Xboard';
};

// 获取API基础URL的函数
export const getApiBaseUrl = () => {
  // 使用主题运行时配置；未设置时请求当前站点的相对 API 地址。
  if (typeof window !== 'undefined' && window.EZ_CONFIG) {
    // 首先检查是否启用中间件代理
    if (window.EZ_CONFIG.API_MIDDLEWARE_ENABLED === true && window.EZ_CONFIG.API_MIDDLEWARE_URL) {
      // 使用中间件URL和路径
      const middlewareUrl = window.EZ_CONFIG.API_MIDDLEWARE_URL.trim();
      const middlewarePath = window.EZ_CONFIG.API_MIDDLEWARE_PATH;
      
      // 确保URL末尾没有斜杠，且路径开头有斜杠，防止出现重复或缺少斜杠
      const formattedUrl = middlewareUrl.endsWith('/') ? middlewareUrl.slice(0, -1) : middlewareUrl;
      const formattedPath = middlewarePath.startsWith('/') ? middlewarePath : `/${middlewarePath}`;
      
      return formattedUrl + formattedPath;
    }
    
    // 然后检查是否存在API_CONFIG
    if (window.EZ_CONFIG.API_CONFIG) {
      const apiConfig = window.EZ_CONFIG.API_CONFIG;
      
      // 静态URL模式
      if (apiConfig.urlMode === 'static' && apiConfig.staticBaseUrl) {
        // 检查是否有经过API可用性检测的URL
        if (Array.isArray(apiConfig.staticBaseUrl) && apiConfig.staticBaseUrl.length > 1) {
          // 使用API可用性检测器获取可用的URL
          const availableUrl = getAvailableApiUrl();
          if (availableUrl) {
            return availableUrl;
          }
          // 如果没有可用URL，返回数组中的第一个URL
          return apiConfig.staticBaseUrl[0];
        }
        // 如果staticBaseUrl是数组但只有一个元素，返回该元素
        else if (Array.isArray(apiConfig.staticBaseUrl) && apiConfig.staticBaseUrl.length === 1) {
          return apiConfig.staticBaseUrl[0];
        }
        // 如果staticBaseUrl是字符串，直接返回
        else if (typeof apiConfig.staticBaseUrl === 'string') {
        return apiConfig.staticBaseUrl;
        }
        // 如果staticBaseUrl不是字符串也不是数组，返回空字符串
        return '';
      }
      
      // 自动获取模式
      if (apiConfig.urlMode === 'auto' && apiConfig.autoConfig) {
        try {
          const currentUrl = new URL(window.location.href);
          let apiBaseUrl = '';
          
          // 协议
          const protocol = apiConfig.autoConfig.useSameProtocol
            ? currentUrl.protocol
            : 'https:';
          
          // 域名
          apiBaseUrl = `${protocol}//${currentUrl.host}`;
          
          // API路径
          if (apiConfig.autoConfig.appendApiPath && apiConfig.autoConfig.apiPath) {
            apiBaseUrl += apiConfig.autoConfig.apiPath;
          }
          
          return apiBaseUrl;
        } catch (error) {
          console.error('自动获取API URL失败:', error);
          // 仅在自动模式失败时回退到静态URL
          if (apiConfig.staticBaseUrl) {
            return apiConfig.staticBaseUrl;
          }
        }
      }
    }
  }
  
  // 如果没有任何 API 相关配置，返回空字符串
  return '';
};

// 直接导出API基础URL
export const API_BASE_URL = getApiBaseUrl();

/**
 * 安全配置选项
 * 可以通过这些选项轻松启用或禁用各种安全功能
 */
const DEFAULT_SECURITY_CONFIG = {
  // 是否启用前端域名验证（前端域名检查，防止未授权域名访问）
  enableFrontendDomainCheck: false,
  
  // 是否启用授权码验证
  enableLicenseCheck: false,
  
  // 是否启用反调试功能（阻止开发者工具和F12调试）
  enableAntiDebugging: false
};

export const SECURITY_CONFIG = mergeDeep(DEFAULT_SECURITY_CONFIG, getConfig('SECURITY_CONFIG'));

// 授权的前端域名列表
const DEFAULT_AUTHORIZED_DOMAINS = [
  'test.test.test',
  // 在此处添加您授权的其他域名
];

export const AUTHORIZED_DOMAINS = getConfig('AUTHORIZED_DOMAINS', DEFAULT_AUTHORIZED_DOMAINS);

/**
 * 验证码配置
 * 控制注册和登录页面的验证方式
 */
const DEFAULT_CAPTCHA_CONFIG = {
  // 验证方式: 'google' 或 'cloudflare'
  captchaType: 'google',
  
  // Google reCAPTCHA 配置 默认v2
  google: {
    // 验证API地址
    verifyUrl: 'https://www.google.com/recaptcha/api/siteverify'
  },
  
  // Cloudflare Turnstile 配置
  cloudflare: {
    // 验证API地址
    verifyUrl: 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
  }
};

export const CAPTCHA_CONFIG = mergeDeep(DEFAULT_CAPTCHA_CONFIG, getConfig('CAPTCHA_CONFIG'));

/**
 * 自定义请求标头配置
 * 允许用户自定义添加请求标头到所有API请求
 */
const DEFAULT_CUSTOM_HEADERS_CONFIG = {
  // 是否启用自定义标头
  enabled: false,
  
  // 自定义标头列表
  headers: {}
};

export const CUSTOM_HEADERS_CONFIG = mergeDeep(DEFAULT_CUSTOM_HEADERS_CONFIG, getConfig('CUSTOM_HEADERS'));

// 网站名称配置
const DEFAULT_SITE_CONFIG = {
  siteName: 'EZ THEME',
  siteDescription: 'EZ UI',
  copyright: `© ${new Date().getFullYear()} EZ THEME. All Rights Reserved.`,
  
  // 是否显示标题中的网站Logo (true=显示, false=隐藏)
  showLogo: true,
  logo: '',
  
  // Landing页面多语言标语
  landingText: {
    'zh-CN': '探索全球网络无限可能',
    'en-US': 'Explore Unlimited Possibilities of Global Network'
  },
  
  // 自定义landing页面路径（相对于public目录）
  customLandingPage: ''
};

export const SITE_CONFIG = mergeDeep(DEFAULT_SITE_CONFIG, getConfig('SITE_CONFIG'));

// 默认语言和主题配置
const DEFAULT_BASE_CONFIG = {
  // 默认语言 ('zh-CN' 或 'en-US') TODO
  defaultLanguage: 'zh-CN',
  
  // 默认主题 ('light' 或 'dark') TODO
  defaultTheme: 'light',

  // 主题色 (16进制颜色值) TODO
  primaryColor: '#4566AE',

  // 是否启用落地页 (true=启用, false=禁用) TODO
  enableLandingPage: true
};

export const DEFAULT_CONFIG = mergeDeep(DEFAULT_BASE_CONFIG, getConfig('DEFAULT_CONFIG'));

/**
 * 支付相关配置
 */
const DEFAULT_PAYMENT_CONFIG = {
  // 是否在新标签页打开支付链接 (true=新标签页打开, false=当前页面打开)
  openPaymentInNewTab: false,
  
  // 支付二维码大小 (像素)
  qrcodeSize: 200,
  
  // 支付二维码的颜色
  qrcodeColor: '#000000',
  
  // 支付二维码的背景色
  qrcodeBackground: '#ffffff',
  
  // 是否自动检测支付状态 (true=启用自动检测, false=手动检测)
  autoCheckPayment: true,
  
  // 自动检测支付状态的间隔时间 (毫秒)
  autoCheckInterval: 5000,
  
  // 自动检测支付状态的最大次数 (设置为0表示无限次)
  autoCheckMaxTimes: 30,
  
  // 是否对Safari浏览器使用支付弹窗模式，而不是直接跳转 (true=使用弹窗, false=直接跳转)
  useSafariPaymentModal: true,
  
  // 是否自动选择第一个支付方式 (true=自动选择, false=需要用户手动选择)
  autoSelectFirstMethod: true
};

export const PAYMENT_CONFIG = mergeDeep(DEFAULT_PAYMENT_CONFIG, getConfig('PAYMENT_CONFIG'));

/**
 * 用户中心页面配置
 * 控制用户中心页面的功能显示
 */
const DEFAULT_PROFILE_CONFIG = {
  // 是否显示礼品卡兑换栏目 (true=显示, false=隐藏) TODO
  showGiftCardRedeem: false,
  
  // 是否显示最近登录设备栏目 (true=显示, false=隐藏)
  showRecentDevices: true
};

export const PROFILE_CONFIG = mergeDeep(DEFAULT_PROFILE_CONFIG, getConfig('PROFILE_CONFIG'));

/**
 * 工单配置
 * 控制工单功能的行为
 */
const DEFAULT_TICKET_CONFIG = {
  // 是否在创建工单时发送用户基础信息 (true=发送, false=不发送)
  includeUserInfoInTicket: true,
  // 弹窗配置
  popup: {
    enabled: true,
    title: '工单须知',
    content: '<p>请您准确描述您的问题，再提交工单，以便我们更快帮助您。</p>',
    cooldownHours: 24,
    closeWaitSeconds: 0
  }
};

export const TICKET_CONFIG = mergeDeep(DEFAULT_TICKET_CONFIG, getConfig('TICKET_CONFIG'));

/**
 * 流量明细配置
 * 控制流量明细页面的行为
 */
const DEFAULT_TRAFFICLOG_CONFIG = {
  // 是否启用流量明细页面 (true=启用, false=禁用)
  enableTrafficLog: true,
  
  // 显示多少天的流量记录
  daysToShow: 30
};

export const TRAFFICLOG_CONFIG = mergeDeep(DEFAULT_TRAFFICLOG_CONFIG, getConfig('TRAFFICLOG_CONFIG'));

/**
 * 客户端下载配置
 * 用于控制仪表板中的客户端下载选项
 */
const DEFAULT_CLIENT_CONFIG = {
  // 整个下载卡片显示控制 TODO
  showDownloadCard: true,  // 设置为false将隐藏整个客户端下载卡片

  // 平台显示控制 (true=显示, false=隐藏)
  showIOS: true,         // iOS客户端显示控制
  showAndroid: true,     // Android客户端显示控制
  showMacOS: true,       // MacOS客户端显示控制
  showWindows: true,     // Windows客户端显示控制
  showLinux: false,       // Linux客户端显示控制
  showOpenWrt: false,     // OpenWrt客户端显示控制

  // 客户端下载链接
  clientLinks: {
    ios: '/#/docs/13',
    android: '/#/docs/2',
    macos: '/#/docs/5',
    windows: '/#/docs/3',
    linux: 'https://github.com/xxx/releases/latest',     // Linux客户端下载链接
    openwrt: 'https://github.com/xxx/releases/latest'  // OpenWrt客户端下载链接
  },

  // 首页下载卡片显示的版本号
  clientVersions: {
    ios: '2.2.0',
    android: '3.5.9',
    macos: '3.5.9',
    windows: '3.5.9',
    linux: '3.5.9',
    openwrt: '3.5.9'
  },

  // ===========================================================

  // 订阅导入客户端显示控制
  // iOS平台客户端
  showShadowrocket: true,   // Shadowrocket
  showSurge: true,          // Surge
  showStash: true,          // Stash
  showQuantumultX: true,    // QuantumultX
  showSingboxIOS: true,     // SingBox for iOS
  showLoon: true,           // Loon
  
  // Android平台客户端
  showV2rayNG: true,          // V2rayNG
  showClashAndroid: true,     // Clash for Android
  showSurfboard: true,        // Surfboard
  showClashMetaAndroid: true, // Clash Meta for Android
  showNekobox: true,          // Nekobox
  showSingboxAndroid: true,   // SingBox for Android
  showHiddifyAndroid: true,   // Hiddify for Android
  
  // Windows平台客户端
  showClashWindows: true,     // Clash for Windows
  showNekoray: true,          // Nekoray
  showSingboxWindows: true,   // SingBox for Windows
  showHiddifyWindows: true,   // Hiddify for Windows
  
  // MacOS平台客户端
  showClashX: true,           // ClashX
  showClashMetaX: true,       // ClashX Meta
  showSurgeMac: true,         // Surge for Mac
  showStashMac: true,         // Stash for Mac
  showQuantumultXMac: true,   // QuantumultX for Mac
  showSingboxMac: true,       // SingBox for Mac
  showHiddifyMac: true       // Hiddify for Mac
}; 

export const CLIENT_CONFIG = mergeDeep(DEFAULT_CLIENT_CONFIG, getConfig('CLIENT_CONFIG')); 

/**
 * 商店页面配置
 * 控制商店页面的行为和显示
 */
const DEFAULT_SHOP_CONFIG = {
  // 是否在商店导航上显示热销标记
  showHotSaleBadge: true,
  
  // 是否显示套餐特性卡片 (true=显示, false=隐藏)
  showPlanFeatureCards: true,
  
  // 是否自动选择周期最大的标签，设为false则不会自动选择
  autoSelectMaxPeriod: false,
  
  // 是否隐藏周期选择标签 (true=隐藏, false=显示)
  hidePeriodTabs: false,
  
  // 库存紧张的阈值（当库存数量小于等于此值且大于0时显示库存紧张）
  lowStockThreshold: 5,
  
  // 是否启用周期折扣计算显示 (true=启用, false=禁用)
  enableDiscountCalculation: true,
  
  // 价格周期的显示顺序（从大到小）
  periodOrder: [
    'three_year_price', // 三年
    'two_year_price',   // 两年
    'year_price',       // 一年
    'half_year_price',  // 半年
    'quarter_price',    // 季度
    'month_price',      // 月付
    'onetime_price'     // 一次性
  ],
  
  // 商店弹窗配置
  popup: {
    // 是否启用弹窗
    enabled: false,
    
    // 弹窗标题
    title: "",
    
    // 弹窗内容 (支持HTML)
    content: "",
    
    // 冷却时间（小时），在此时间内不会再次显示弹窗
    cooldownHours: 2,
    
    // 等待时间（秒），用户需要等待多少秒才能关闭弹窗，设为0表示无需等待
    closeWaitSeconds: 0
  }
};

export const SHOP_CONFIG = mergeDeep(DEFAULT_SHOP_CONFIG, getConfig('SHOP_CONFIG'));

  // ===========================================================

/**
 * 仪表盘页面配置
 * 控制仪表盘页面的功能与显示
 */
const DEFAULT_DASHBOARD_CONFIG = {
  // 是否在欢迎卡片中显示用户邮箱 (true=显示, false=隐藏)
  showUserEmail: true,
  
  // 是否为导入订阅按钮添加高光效果和填充底色 (true=添加效果, false=不添加效果)
  importButtonHighlightBtnbgcolor: true,

  // 是否启用重置流量功能 (true=启用, false=禁用)
  enableResetTraffic: true,
  
  // 重置流量按钮显示条件 ('always'=始终显示, 'low'=流量低于阈值时显示, 'depleted'=流量耗尽时显示)
  resetTrafficDisplayMode: 'low',
  
  // 低流量阈值百分比 (1-100)，当剩余流量百分比低于此值时触发低流量警告
  lowTrafficThreshold: 10,
  
  // 是否启用续费套餐功能 (true=启用, false=禁用)
  enableRenewPlan: true,
  
  // 续费套餐按钮显示条件 ('always'=始终显示, 'expiring'=套餐即将到期时显示, 'expired'=套餐已过期时显示)
  renewPlanDisplayMode: 'always',
  
  // 即将过期的天数阈值 (1-30)，当剩余天数小于等于此值时触发即将过期警告
  expiringThreshold: 7,

  // 是否显示在线设备数量限制 (true=显示, false=隐藏，仅Xiao-V2board支持)
  showOnlineDevicesLimit: true
};

export const DASHBOARD_CONFIG = mergeDeep(DEFAULT_DASHBOARD_CONFIG, getConfig('DASHBOARD_CONFIG'));

/**
 * 将16进制颜色转换为RGB数组
 * @param {string} hex - 16进制颜色值
 * @returns {number[]} RGB数组
 */
const hexToRgb = (hex) => {
  // 确保输入值是字符串
  if (typeof hex !== 'string') {
    hex = String(hex);
  }
  
  // 去除空格
  hex = hex.trim();
  
  // 处理缩写形式的颜色值（例如#FFF -> #FFFFFF）
  const shorthandRegex = /^#?([a-f\d])([a-f\d])([a-f\d])$/i;
  hex = hex.replace(shorthandRegex, (m, r, g, b) => r + r + g + g + b + b);
  
  // 正则匹配完整的十六进制颜色值
  const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
  
  if (result) {
    return [
      parseInt(result[1], 16),
      parseInt(result[2], 16),
      parseInt(result[3], 16)
    ];
  }
};

/**
 * 计算主题相关的颜色
 * @param {string} primaryColor - 主题色（16进制）
 * @returns {object} 主题色相关的颜色对象
 */
const calculateThemeColors = (primaryColor) => {
  const safePrimaryColor = hexToRgb(primaryColor) ? primaryColor : '#4566AE';
  const rgb = hexToRgb(safePrimaryColor);
  return {
    primaryColor: safePrimaryColor,
    primaryColorRgb: rgb.join(', '),
    // 计算衍生颜色
    primaryColorLight: `rgba(${rgb.join(', ')}, 0.1)`,
    primaryColorDark: safePrimaryColor,
    primaryColorHover: `rgba(${rgb.join(', ')}, 0.9)`,
    primaryColorActive: `rgba(${rgb.join(', ')}, 0.8)`,
    primaryColorFocus: `rgba(${rgb.join(', ')}, 0.25)`
  };
};

// 默认主题配置
const DEFAULT_THEME_CONFIG = {
  // 默认主题（light或dark）
  defaultTheme: DEFAULT_CONFIG.defaultTheme,
  
  // 主题颜色变量
  light: {
    ...calculateThemeColors(DEFAULT_CONFIG.primaryColor),
    primaryForegroundColor: '#FFFFFF',
    backgroundColor: '#FAFBFD',
    cardBackground: '#ffffff',
    cardBackgroundRgb: '255, 255, 255',
    textColor: '#1A2138',
    secondaryTextColor: '#6B7280',
    borderColor: '#EEF0F4',
    shadowColor: 'rgba(0, 0, 0, 0.06)'
  },
  
  dark: {
    // FastCatAPP Material 3 深色方案（seed: #4566AE）
    ...calculateThemeColors('#B0C6FF'),
    primaryForegroundColor: '#152E60',
    primaryColorHover: '#C4D3FF',
    primaryColorActive: '#9BB6F7',
    backgroundColor: '#1C1C1F',
    cardBackground: '#242428',
    cardBackgroundRgb: '36, 36, 40',
    textColor: 'rgba(255, 255, 255, 0.94)',
    secondaryTextColor: 'rgba(255, 255, 255, 0.62)',
    borderColor: 'rgba(255, 255, 255, 0.09)',
    shadowColor: 'rgba(0, 0, 0, 0.22)'
  }
};

export const THEME_CONFIG = mergeDeep(DEFAULT_THEME_CONFIG, getConfig('THEME_CONFIG'));


// 默认背景装饰球配置
export const BACKGROUND_BALLS_CONFIG = [];

/**
 * 浏览器访问限制配置
 * 控制哪些浏览器被禁止访问网站
 */
const DEFAULT_BROWSER_RESTRICT_CONFIG = {
  // 是否启用浏览器限制功能
  enabled: false,
  
  // 各浏览器是否被限制访问（true=限制访问，false=允许访问）
  restrictBrowsers: {
    '360': true,     // 360浏览器
    'QQ': true,      // QQ浏览器
    'WeChat': true,  // 微信内置浏览器
    'Baidu': true,   // 百度浏览器
    'Sogou': true,   // 搜狗浏览器
    'UC': false,     // UC浏览器
    'Maxthon': false // 傲游浏览器
  },
  
  // 推荐下载的浏览器链接
  recommendedBrowsers: {
    'Chrome': 'https://www.google.cn/chrome/',
    'Edge': 'https://www.microsoft.com/zh-cn/edge'
  }
};

export const BROWSER_RESTRICT_CONFIG = mergeDeep(DEFAULT_BROWSER_RESTRICT_CONFIG, getConfig('BROWSER_RESTRICT_CONFIG'));

/**
 * 检测当前浏览器类型
 * @returns {string} 浏览器类型
 */
export const detectBrowser = () => {
  const ua = navigator.userAgent.toLowerCase();
  
  // 检测微信浏览器
  if (ua.indexOf('micromessenger') !== -1) {
    return 'WeChat';
  }
  
  // 检测QQ浏览器
  if (ua.indexOf('qqbrowser') !== -1 || ua.indexOf(' qq') !== -1 && ua.indexOf('mqqbrowser') !== -1) {
    return 'QQ';
  }
  
  // 检测360浏览器 (不同版本有不同标识)
  if (
    ua.indexOf('qihu') !== -1 || 
    ua.indexOf('360ee') !== -1 || 
    ua.indexOf('360se') !== -1 ||
    (ua.indexOf('chrome') !== -1 && (navigator.connection?.saveData === undefined) && (navigator.connection?.rtt === undefined))
  ) {
    return '360';
  }
  
  // 检测百度浏览器
  if (ua.indexOf('bidubrowser') !== -1 || ua.indexOf('baidubrowser') !== -1) {
    return 'Baidu';
  }
  
  // 检测搜狗浏览器
  if (ua.indexOf('metasr') !== -1 || ua.indexOf('sogou') !== -1) {
    return 'Sogou';
  }
  
  // 检测UC浏览器
  if (ua.indexOf('ucbrowser') !== -1 || ua.indexOf('ucweb') !== -1) {
    return 'UC';
  }
  
  // 检测傲游浏览器
  if (ua.indexOf('maxthon') !== -1) {
    return 'Maxthon';
  }
  
  // 检测Edge浏览器
  if (ua.indexOf('edg') !== -1) {
    return 'Edge';
  }
  
  // 检测Chrome浏览器
  if (ua.indexOf('chrome') !== -1) {
    return 'Chrome';
  }
  
  // 检测Safari浏览器
  if (ua.indexOf('safari') !== -1) {
    return 'Safari';
  }
  
  // 检测Firefox浏览器
  if (ua.indexOf('firefox') !== -1) {
    return 'Firefox';
  }
  
  // 默认返回Unknown
  return 'Unknown';
};

/**
 * 检查当前浏览器是否被限制访问
 * @returns {boolean} 是否被限制
 */
export const isBrowserRestricted = () => {
  // 如果功能未启用，所有浏览器都允许访问
  if (!BROWSER_RESTRICT_CONFIG.enabled) {
    return false;
  }
  
  const browserType = detectBrowser();
  
  // 检查浏览器是否在限制列表中
  if (BROWSER_RESTRICT_CONFIG.restrictBrowsers[browserType]) {
    return true;
  }
  
  return false;
};

/**
 * 充值相关配置
 */
const DEFAULT_WALLET_CONFIG = {
  // 预设充值金额选项（单位：元）
  presetAmounts: [6, 30, 68, 128, 256, 328, 648, 1280],
  
  // 默认选中的充值金额（如果设为null则不预选金额）
  defaultSelectedAmount: null,
  
  // 最小充值金额（单位：元）
  minimumDepositAmount: 1
};

export const WALLET_CONFIG = mergeDeep(DEFAULT_WALLET_CONFIG, getConfig('WALLET_CONFIG'));

/**
 * 邀请页面配置
 */
const DEFAULT_INVITE_CONFIG = {
  // 是否在导航栏的邀请按钮上显示返利标记
  showCommissionBadge: true,
  
  // 返佣记录每页显示数量（最小值为10，API限制每次请求最少需要返回10条记录）
  recordsPerPage: 10,
  // 邀请链接配置
  inviteLinkConfig: {
    // 链接模式：'auto'=自动使用当前站点域名，'custom'=使用自定义域名
    linkMode: 'auto',
    // 自定义域名，当linkMode为'custom'时使用
    customDomain: 'https://example.com'
  }
};

export const INVITE_CONFIG = mergeDeep(DEFAULT_INVITE_CONFIG, getConfig('INVITE_CONFIG'));

/**
 * 节点列表配置
 * 控制节点列表页面的显示内容
 */
const DEFAULT_NODES_CONFIG = {
  // 是否显示节点倍率 (true=显示, false=隐藏)
  showNodeRate: true,
  
  // 是否显示节点详细信息（主机和端口）
  showNodeDetails: false
};

export const NODES_CONFIG = mergeDeep(DEFAULT_NODES_CONFIG, getConfig('NODES_CONFIG'));

/**
 * 客服系统配置
 */
const DEFAULT_CUSTOMER_SERVICE_CONFIG = {
  // 是否启用客服系统
  enabled: false,
  
  // 客服系统固定使用 Crisp
  type: 'crisp',

  // Crisp Website ID
  crispId: '',
  
  // 客服系统HTML代码
  customHtml: '',
  
  // 是否在未登录状态下也显示客服图标
  showWhenNotLoggedIn: true,
  
  // 客服系统嵌入模式: 'popup'=弹出式页面, 'embed'=嵌入到每个页面(仅支持Crisp)
  embedMode: 'embed',
  
  // 图标位置配置
  iconPosition: {
    // 桌面版图标距离右下角的距离
    desktop: {
      right: '20px',
      bottom: '20px'
    },
    // 移动版图标距离右下角的距离
    mobile: {
      right: '20px',
      bottom: '100px'
    }
  }
};

// 注意：当使用Crisp类型客服时，系统会自动向Crisp传递用户数据（邮箱、套餐名称、到期时间、可用流量、用户余额）
export const CUSTOMER_SERVICE_CONFIG = mergeDeep(DEFAULT_CUSTOMER_SERVICE_CONFIG, getConfig('CUSTOMER_SERVICE_CONFIG'));

/**
 * More页面自定义卡片配置
 */
const DEFAULT_MORE_PAGE_CONFIG = {
  // 是否启用自定义卡片功能
  enableCustomCards: true,
  
  // 自定义卡片列表
  customCards: [
    // 示例自定义卡片
    // {
    //   id: 'example_card',           // 卡片唯一ID
    //   title: '示例卡片',             // 卡片标题
    //   description: '这是一个示例',   // 卡片描述
    //   icon: 'IconWorld',            // 卡片图标（使用@tabler/icons-vue图标名称）
    //   url: 'https://example.com',   // 点击卡片跳转的URL
    //   openInNewTab: true            // 是否在新标签页打开
    // }
  ]
};

export const MORE_PAGE_CONFIG = mergeDeep(DEFAULT_MORE_PAGE_CONFIG, getConfig('MORE_PAGE_CONFIG'));

/**
 * 认证页面布局配置
 */
const DEFAULT_AUTH_LAYOUT_CONFIG = {
  // 布局类型: 'center' 为居中卡片布局, 'split' 为左右分栏布局
  layoutType: 'center',
  
  // 左右分栏布局配置 (仅当 layoutType 为 'split' 时生效)
  splitLayout: {
    // 左侧区域内容配置
    leftContent: {
      // 左侧背景图片URL (如不设置则不设置图片背景)
      backgroundImage: '',
      // 左上角网站名称配置
      siteName: {
        // 是否显示网站名称
        show: true,
        // 文字颜色 (white或black)
        color: 'white'
      },
      // 左下角问候语配置
      greeting: {
        // 是否显示问候语
        show: true,
        // 文字颜色 (white或black)
        color: 'white'
      }
    }
  }
};

export const AUTH_LAYOUT_CONFIG = mergeDeep(DEFAULT_AUTH_LAYOUT_CONFIG, getConfig('AUTH_LAYOUT_CONFIG'));

/**
 * 认证页面功能配置
 */
const DEFAULT_AUTH_CONFIG = {
  // 是否自动勾选同意条款复选框 (true=自动勾选, false=默认不勾选)
  autoAgreeTerms: false
};

export const AUTH_CONFIG = mergeDeep(DEFAULT_AUTH_CONFIG, getConfig('AUTH_CONFIG'));
