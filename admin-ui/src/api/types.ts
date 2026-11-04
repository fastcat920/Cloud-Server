// 后端数据结构（仅列出页面用到的字段）
import type { RouteAction } from '@/utils/constants'

export interface LoginResult {
  token: string
  is_admin: number | boolean
  auth_data: string
}

export interface CheckLoginResult {
  is_login: boolean
  is_admin?: boolean
}

export interface UserInfo {
  email: string
  avatar_url?: string
  [key: string]: unknown
}

export interface StatOverride {
  online_user?: number
  month_income?: number
  month_register_total?: number
  day_register_total?: number
  ticket_pending_total?: number
  commission_pending_total?: number
  day_income?: number
  last_month_income?: number
  commission_month_payout?: number
  commission_last_month_payout?: number
}

export interface StatOrderItem {
  type: string
  date: string
  value: number
}

export interface ServerRankItem {
  server_id: number
  server_type: string
  u: number
  d: number
  total: number
  server_name?: string
}

export interface UserRankItem {
  user_id: number
  u: number
  d: number
  total: number
  email: string
}

export interface Notice {
  id: number
  title: string
  title_en: string | null
  content: string
  content_en: string | null
  show: number
  img_url: string | null
  tags: string[] | null
  created_at: number
  updated_at: number
}

export type ConfigGroups = Record<string, Record<string, unknown>>

/** 发送测试邮件的结果（config/testSendMail 响应里的 log；config 为后端的 mail 配置） */
export interface TestMailLog {
  email?: string
  subject?: string
  template_name?: string
  error?: string | null
  config?: { host?: string; port?: string | number; encryption?: string; username?: string; [key: string]: unknown }
}

export interface Payment {
  id: number
  uuid: string
  /** 支付接口（app/Payments 下的类名，如 EPay） */
  payment: string
  name: string
  name_en: string | null
  icon: string | null
  config: Record<string, string>
  notify_domain: string | null
  /** 分 */
  handling_fee_fixed: number | null
  handling_fee_percent: string | null
  enable: number
  sort: number | null
  created_at: number
  updated_at: number
  notify_url: string
}

/** 支付接口的配置表单：字段名 → 描述（value 为已保存的值；type 为 alert 的项只有 content） */
export type PaymentForm = Record<
  string,
  { label?: string; description?: string; type?: string; value?: string; content?: string }
>

/** 主题目录下 config.json 的一个配置项 */
export interface ThemeConfigField {
  label: string
  placeholder?: string
  field_name: string
  field_type: 'select' | 'input' | 'textarea'
  select_options?: Record<string, string>
  default_value?: string
}

export interface ThemeInfo {
  name: string
  description: string
  version?: string
  images?: string
  configs: ThemeConfigField[]
}

export interface HorizonStats {
  status?: string
  [key: string]: unknown
}

export interface ServerGroup {
  id: number
  name: string
  user_count: number
  server_count: number
  created_at: number
  updated_at: number
}

export interface ServerRoute {
  id: number
  remarks: string
  /** 后端能解析成 JSON 数组时为数组，否则是旧版逗号分隔的字符串 */
  match: string[] | string
  action: RouteAction
  action_value: string | null
  created_at: number
  updated_at: number
}

export interface Plan {
  id: number
  group_id: number | null
  transfer_enable: number | null
  device_limit: number | null
  name: string
  name_en: string | null
  speed_limit: number | null
  show: number
  sort: number | null
  renew: number
  content: string | null
  content_en: string | null
  month_price: number | null
  quarter_price: number | null
  half_year_price: number | null
  year_price: number | null
  two_year_price: number | null
  three_year_price: number | null
  onetime_price: number | null
  reset_price: number | null
  reset_traffic_method: number | null
  capacity_limit: number | null
  created_at: number
  updated_at: number
  /** 当前订阅人数 */
  count?: number
}

export interface Coupon {
  id: number
  code: string
  name: string
  /** 1 按金额（value 单位为分）/ 2 按比例 */
  type: 1 | 2
  value: number
  show: number
  limit_use: number | null
  limit_use_with_user: number | null
  limit_plan_ids: string[] | null
  limit_period: string[] | null
  started_at: number
  ended_at: number
  created_at: number
  updated_at: number
}

/** 分页列表接口的通用参数（与原版 dva model 的 pagination + sort 一致） */
export interface PageParams {
  pageSize: number
  current: number
  total?: number
  sort_type?: 'ASC' | 'DESC'
  sort?: string
}

export interface Giftcard {
  id: number
  code: string
  name: string
  /** 1 余额（value 单位为分）/ 2 订阅时长（天）/ 3 流量（GB）/ 4 重置流量 / 5 兑换套餐（天，0 为一次性） */
  type: 1 | 2 | 3 | 4 | 5
  value: number | null
  plan_id: number | null
  limit_use: number | null
  used_user_ids: number[] | null
  started_at: number
  ended_at: number
  created_at: number
  updated_at: number
}

export interface Knowledge {
  id: number
  language: string
  category: string
  category_en: string | null
  title: string
  title_en: string | null
  /** 列表接口不返回正文，按 id 获取时才有 */
  body?: string
  body_en?: string | null
  sort?: number | null
  show: number
  created_at?: number
  updated_at: number
}

/** 节点协议（也是接口路径 /server/<type>/... 与 getNodes 返回的 type 字段） */
export type NodeType = 'shadowsocks' | 'vmess' | 'trojan' | 'hysteria' | 'tuic' | 'vless' | 'anytls' | 'v2node'

/**
 * 节点（server/manage/getNodes）。各协议的字段不同，这里只列出列表页用到的；
 * 编辑时与原版一样把整条记录（含 online、available_status 等后端追加的字段）原样提交
 */
export interface ServerNode {
  id: number
  type: NodeType
  name: string
  host: string
  port: string | number
  parent_id: number | null
  /** 后台表单提交的是字符串 id（原版按字符串匹配权限组筛选） */
  group_id: Array<string | number>
  route_id: Array<string | number> | null
  tags: string[] | null
  rate: string | number
  show: number | string
  sort: number | null
  /** 在线人数、最后检查 / 上报时间来自节点上报的缓存，没有上报时为 null */
  online: number | string | null
  /** 0 未运行 / 1 无人使用或上报异常 / 2 运行正常 */
  available_status: 0 | 1 | 2
  [key: string]: unknown
}

/** 过滤器条件（原版过滤器抽屉 hVla；用户 / 订单列表的 filter 参数，后端按 key、condition、value 拼查询） */
export interface FilterCondition {
  key: string
  condition: string
  value: unknown
}

/**
 * 用户（user/fetch、user/getUserInfoById）。与原版一致，列表和编辑抽屉里
 * 流量（transfer_enable / u / d / total_used）换算成 GB、金额（balance / commission_balance）换算成元，都是 toFixed(2) 的字符串；
 * 编辑时把整条记录原样提交（后端只取校验过的字段）
 */
export interface AdminUser {
  id: number
  email: string
  invite_user_id: number | null
  /** 最后在线时间（unix 秒，0 为从未在线） */
  t: number
  banned: number
  is_admin: number
  is_staff: number
  plan_id: number | null
  group_id: number | null
  transfer_enable: string
  u: string
  d: string
  balance: string
  commission_balance: string
  commission_type: number
  commission_rate: number | null
  discount: number | null
  device_limit: number | null
  speed_limit: number | null
  expired_at: number | null
  remarks: string | null
  password: string
  token: string
  uuid: string
  created_at: number
  updated_at: number
  /** 以下只在列表里有 */
  plan_name?: string
  total_used?: string
  alive_ip?: number | null
  ips?: string
  subscribe_url?: string
  /** 以下只在 getUserInfoById 里有 */
  invite_user?: AdminUser
  invite_user_email?: string
  [key: string]: unknown
}

export interface Order {
  id: number
  invite_user_id: number | null
  user_id: number
  plan_id: number
  coupon_id: number | null
  payment_id: number | null
  /** 1 新购 / 2 续费 / 3 变更 / 4 流量包 / 9 充值 */
  type: number
  period: string
  trade_no: string
  callback_no: string | null
  total_amount: number
  handling_amount: number | null
  discount_amount: number | null
  surplus_amount: number | null
  refund_amount: number | null
  balance_amount: number | null
  surplus_order_ids: number[] | null
  /** 0 待支付 / 1 开通中 / 2 已取消 / 3 已完成 / 4 已折抵 */
  status: number
  /** 0 待确认 / 1 发放中 / 2 已发放 / 3 已驳回 */
  commission_status: number
  commission_balance: number
  actual_commission_balance: number | null
  paid_at: number | null
  created_at: number
  updated_at: number
  plan_name?: string
}

export interface Ticket {
  id: number
  user_id: number
  subject: string
  /** 0 低 / 1 中 / 2 高 */
  level: number
  /** 0 开启 / 1 已关闭 */
  status: number
  /** 0 待回复 / 1 已回复 */
  reply_status: number
  created_at: number
  updated_at: number
}

export interface TicketMessage {
  id: number
  user_id: number
  ticket_id: number
  message: string
  /** 不是工单创建者发的（管理员回复） */
  is_me: boolean
  created_at: number
  updated_at: number
}

export interface TicketDetail extends Ticket {
  message: TicketMessage[]
}

/** 用户流量记录（stat/getStatUser） */
export interface StatUserRecord {
  id: number
  user_id: number
  server_rate: string
  u: number
  d: number
  record_type: string
  record_at: number
}

/** Horizon 概况（system/getQueueStats） */
export interface QueueStats {
  failedJobs: number
  jobsPerMinute: number
  recentJobs: number
  status: boolean
  [key: string]: unknown
}

/** Horizon 各队列负载（system/getQueueWorkload） */
export interface QueueWorkload {
  name: string
  length: number
  wait: number
  processes: number
}
