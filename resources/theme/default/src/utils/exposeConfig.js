/**
 * 设置网站标题
 * 处理网站标题的简单工具，根据SITE_CONFIG设置文档标题
 */
import { SITE_CONFIG } from './baseConfig';

/**
 * 初始化页面标题
 * 从SITE_CONFIG中读取站点名称并设置为文档标题
 */
export default function initPageTitle() {
  // 初始化时设置文档标题
  document.title = SITE_CONFIG.siteName;
} 