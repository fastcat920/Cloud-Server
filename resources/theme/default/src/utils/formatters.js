/**
 * 流量格式化缓存
 * 缓存已经格式化过的流量值，避免重复计算
 */
const trafficCache = new Map();
const MAX_CACHE_SIZE = 100;

/**
 * 格式化流量数据
 * @param {number} bytes - 字节数
 * @returns {string} 格式化后的流量字符串
 */
export function formatTraffic(bytes) {
  if (bytes === 0) return '0 B';
  
  // 检查缓存中是否已有该值的格式化结果
  if (trafficCache.has(bytes)) {
    return trafficCache.get(bytes);
  }

  const k = 1024;
  const sizes = ['B', 'KB', 'MB', 'GB'];
  const i = Math.min(Math.floor(Math.log(bytes) / Math.log(k)), sizes.length - 1);
  const result = parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  
  // 缓存结果以提高性能
  if (trafficCache.size >= MAX_CACHE_SIZE) {
    // 清除缓存中的第一项
    const firstKey = trafficCache.keys().next().value;
    trafficCache.delete(firstKey);
  }
  trafficCache.set(bytes, result);
  
  return result;
}

/**
 * 日期格式化缓存
 * 缓存已经格式化过的日期，避免重复计算
 */
const dateCache = new Map();

/**
 * 格式化日期时间
 * @param {Date|number} date - 日期对象或时间戳（秒）
 * @param {boolean} withTime - 是否包含时间
 * @returns {string} 格式化后的日期时间字符串
 */
export function formatDate(date, withTime = false) {
  if (!date) return '--';
  
  // 生成缓存键
  const cacheKey = `${date}_${withTime}`;
  
  // 检查缓存中是否已有该日期的格式化结果
  if (dateCache.has(cacheKey)) {
    return dateCache.get(cacheKey);
  }
  
  // 如果传入的是时间戳（秒），则转换为日期对象
  let dateObj = date;
  if (typeof date === 'number') {
    dateObj = new Date(date * 1000);
  }
  
  // 确保是有效的Date对象
  if (!(dateObj instanceof Date) || isNaN(dateObj.getTime())) {
    console.error('Invalid date:', date);
    return '--';
  }
  
  const options = {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit'
  };
  
  if (withTime) {
    options.hour = '2-digit';
    options.minute = '2-digit';
    options.second = '2-digit';
    options.hour12 = false;
  }
  
  let result;
  const pad = (num) => String(num).padStart(2, '0');
  const year = dateObj.getFullYear();
  const month = pad(dateObj.getMonth() + 1);
  const day = pad(dateObj.getDate());
  
  if (withTime) {
    const hours = pad(dateObj.getHours());
    const minutes = pad(dateObj.getMinutes());
    const seconds = pad(dateObj.getSeconds());
    result = `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
  } else {
    result = `${year}-${month}-${day}`;
  }
  
  // 缓存结果以提高性能
  if (dateCache.size >= MAX_CACHE_SIZE) {
    // 清除缓存中的第一项
    const firstKey = dateCache.keys().next().value;
    dateCache.delete(firstKey);
  }
  dateCache.set(cacheKey, result);
  
  return result;
}

/**
 * 格式化用户信息为工单附加内容
 * @param {Object} userInfo - 用户信息
 * @param {Object} ipInfo - IP位置信息
 * @param {Object} userSubscribe - 可选的订阅信息
 * @returns {string} 格式化后的用户信息字符串
 */
export function formatUserInfoForTicket(userInfo, ipInfo, userSubscribe = null) {
  // API 可能直接返回数据对象，也可能嵌套在data属性中，我们需要做兼容处理
  const userData = userInfo.data ? userInfo.data : userInfo;
  const ipData = ipInfo.data ? ipInfo.data : ipInfo;
  const subscribeData = userSubscribe ? (userSubscribe.data ? userSubscribe.data : userSubscribe) : null;
  
  // 确保各数据是对象类型
  const uData = typeof userData === 'object' && userData !== null ? userData : {};
  const iData = typeof ipData === 'object' && ipData !== null ? ipData : {};
  const sData = typeof subscribeData === 'object' && subscribeData !== null ? subscribeData : {};
  
  // 获取IP地址
  const ipAddress = iData.ip || '--';
  
  // 格式化位置信息
  let location = '';
  
  // 处理ipwho.is的数据格式
  if (iData.country) {
    location += iData.country;
    if (iData.region) location += ' ' + iData.region;
    if (iData.city) location += ' ' + iData.city;
  } 
  // 兼容旧格式（百度API）
  else if (iData.prov) {
    if (iData.country) location += iData.country;
    location += ' ' + iData.prov;
    if (iData.city) location += ' ' + iData.city;
    if (iData.district) location += ' ' + iData.district;
  }
  
  // 获取套餐名称（优先从subscribe接口获取）
  let planName = "未知套餐";
  if (sData && sData.plan && sData.plan.name) {
    planName = sData.plan.name;
  } else if (uData.plan_id) {
    // 当只有ID时，仍然显示ID
    planName = `ID: ${uData.plan_id}`;
  }
  
  // 尝试从用户信息获取套餐名称（如果上面未获取到）
  if (planName === "未知套餐" || planName.startsWith("ID:")) {
    if (uData.plan_name && uData.plan_name.trim() !== '') {
      planName = uData.plan_name;
    } else if (uData.group && uData.group.name && uData.group.name.trim() !== '') {
      planName = uData.group.name;
    }
  }
  
  // 获取到期时间（优先从subscribe接口获取）
  let expireDate = formatDate(sData.expired_at || uData.expired_at);
  
  // 获取货币符号
  let currencySymbol = '¥'; // 默认货币符号
  if (userInfo.currency_symbol) {
    currencySymbol = userInfo.currency_symbol;
  } else if (userInfo.data && userInfo.data.currency_symbol) {
    currencySymbol = userInfo.data.currency_symbol;
  }
  
  // 计算流量信息（优先从subscribe接口获取）
  let transferEnable = 0;
  let usedU = 0;
  let usedD = 0;
  
  if (sData) {
    transferEnable = sData.transfer_enable || 0;
    usedU = sData.u || 0;
    usedD = sData.d || 0;
  } else {
    transferEnable = uData.transfer_enable || 0;
    usedU = uData.u || 0;
    usedD = uData.d || 0;
  }
  
  const remainingTraffic = transferEnable - (usedU + usedD);
  
  return `
-------------
用户信息
注册时间：${formatDate(uData.created_at)}
套餐名称：${planName}
到期时间：${expireDate}
剩余流量：${formatTraffic(remainingTraffic > 0 ? remainingTraffic : 0)}
已使用流量：${formatTraffic(usedU + usedD)}
用户余额：${((uData.balance || 0) / 100).toFixed(2)} ${currencySymbol}
创建工单时的IP：${ipAddress}
创建工单的位置：${location || '--'}
-------------
`;
} 