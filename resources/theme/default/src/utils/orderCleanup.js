import { submitOrder, cancelOrder } from '@/api/shop';
import { fetchOrderList } from '@/api/orderlist';
import { requestMessage } from '@/api/request';

const getBackendMessage = value => {
  if (!value) return '';
  if (typeof value === 'string') return value;
  return [
    value.message,
    value.error,
    value.response?.message,
    value.response?.data?.message,
    value.response?.data?.error,
    value.data?.message,
    value.data?.error
  ].filter(item => typeof item === 'string').join(' ');
};

const isUnpaidOrderConflict = value => {
  const message = getBackendMessage(value).toLowerCase();
  return [
    '待支付',
    '待付款',
    '未支付',
    '未付款',
    '未完成订单',
    '存在订单',
    'pending order',
    'unpaid order',
    'unpaid invoice',
    'outstanding order',
    'incomplete order',
    'order pending',
    'wait payment',
    'waiting for payment'
  ].some(keyword => message.includes(keyword));
};

export const cancelUnpaidOrders = async () => {
  const response = await fetchOrderList(1, 100);
  const orders = Array.isArray(response?.data) ? response.data : [];
  const tradeNos = [...new Set(
    orders
      .filter(order => Number(order?.status) === 0 && order?.trade_no)
      .map(order => order.trade_no)
  )];

  if (!tradeNos.length) return 0;

  const results = await Promise.allSettled(
    tradeNos.map(tradeNo => cancelOrder(tradeNo))
  );
  const failed = results.filter(result => result.status === 'rejected');

  if (failed.length) {
    const message = getBackendMessage(failed[0].reason);
    throw new Error(message || requestMessage('failed'));
  }

  return tradeNos.length;
};

export const createOrderAfterUnpaidCleanup = async orderData => {
  await cancelUnpaidOrders();

  try {
    const response = await submitOrder(orderData);
    if (!response?.data && isUnpaidOrderConflict(response)) {
      await cancelUnpaidOrders();
      return submitOrder(orderData);
    }
    return response;
  } catch (error) {
    if (!isUnpaidOrderConflict(error)) throw error;
    await cancelUnpaidOrders();
    return submitOrder(orderData);
  }
};
