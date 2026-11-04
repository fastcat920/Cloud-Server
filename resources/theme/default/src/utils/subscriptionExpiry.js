const MILLISECONDS_PER_DAY = 24 * 60 * 60 * 1000;

const toLocalDate = (expiredAt) => {
  if (!expiredAt) return null;

  const date = expiredAt instanceof Date
    ? expiredAt
    : new Date(Number(expiredAt) * 1000);

  return Number.isNaN(date.getTime()) ? null : date;
};

/** 将后端到期时间戳格式化为套餐卡片使用的本地日期时间。 */
export const formatExpiryDate = (expiredAt) => {
  const date = toLocalDate(expiredAt);
  if (!date) return '';

  const pad = (value) => String(value).padStart(2, '0');
  return `${date.getFullYear()}/${pad(date.getMonth() + 1)}/${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

/**
 * 按本地自然日计算两个日期之间的天数。
 * 使用 UTC 组装本地年月日，避免夏令时导致一天不是固定 24 小时。
 */
export const getNaturalDayDifference = (expiredAt, now = new Date()) => {
  const expiryDate = toLocalDate(expiredAt);
  if (!expiryDate || !(now instanceof Date) || Number.isNaN(now.getTime())) return null;

  const expiryDay = Date.UTC(
    expiryDate.getFullYear(),
    expiryDate.getMonth(),
    expiryDate.getDate()
  );
  const today = Date.UTC(now.getFullYear(), now.getMonth(), now.getDate());

  return Math.round((expiryDay - today) / MILLISECONDS_PER_DAY);
};

/** 今天到期为 0，明天到期为 1；过期日期统一显示 0。 */
export const calculateRemainingDays = (expiredAt, now = new Date()) => {
  const days = getNaturalDayDifference(expiredAt, now);
  return days === null ? null : Math.max(0, days);
};

/** 与后端一致：到达 expired_at 记录的精确秒数后立即视为已过期。 */
export const isExpiryDateExpired = (expiredAt, now = new Date()) => {
  const expiryDate = toLocalDate(expiredAt);
  if (!expiryDate || !(now instanceof Date) || Number.isNaN(now.getTime())) return false;

  return Math.floor(now.getTime() / 1000) >= Math.floor(expiryDate.getTime() / 1000);
};
