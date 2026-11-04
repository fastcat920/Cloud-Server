export const isPercentageCoupon = coupon => {
  const type = coupon?.discount_type;
  return type === 'percent' || type === 'percentage' || Number(type) === 2;
};

export const formatCouponValue = (coupon, { currency = '¥', percentSuffix = '%' } = {}) => {
  if (!coupon) return '—';
  const value = Number(coupon.discount_value || 0);
  return isPercentageCoupon(coupon)
    ? `${value}${percentSuffix}`
    : `${currency}${(value / 100).toFixed(2)}`;
};
