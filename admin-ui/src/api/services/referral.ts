import { adminPath } from '@/app/settings'
import { get, post } from '../request'

export interface CouponTemplateOption { id: number; name: string; name_en?: string | null; ends_at?: number | null }

export interface ReferralSetting {
  id: number
  enabled: boolean | number
  first_order_min: number
  invitee_reward: number
  newcomer_coupon_template_id?: number | null
  base_commission_rate: number
  freeze_days: number
  monthly_reward_limit?: number | null
  no_active_plan_reward_policy?: 'allow_all' | 'block_inviter_commission' | 'block_invitee_rewards' | 'block_both'
}

export interface ReferralDashboard {
  setting: ReferralSetting
  registered_invites: number
  effective_invites: number
  conversion_rate: number
  referral_revenue: number
  reward_total: number
  active_promoters: number
  coupon_templates: CouponTemplateOption[]
}

export interface ReferralTrend {
  date: string
  visits: number
  registrations: number
  first_orders: number
  repeat_purchases: number
  renewals: number
  plan_changes: number
}

export interface ReferralFunnel {
  summary: {
    visits: number
    registrations: number
    verified: number | null
    first_orders: number
    first_purchase_users: number
    registration_to_first_rate: number
    renewal_users: number
    renewal_rate: number
    pending_rewards: number
    reversed_rewards: number
    revenue: number
    reward_cost: number
    roi: number | null
  }
  trend: ReferralTrend[]
  weekly_trend: ReferralTrend[]
  monthly_trend: ReferralTrend[]
  channels: Array<{ channel: string | null; visits: number; registrations: number }>
  plans: Array<{ plan_id: number; orders: number; revenue: number }>
}

export type RewardType = 'none' | 'effective_invite' | 'balance' | 'commission_balance' | 'level' | 'traffic' | 'duration'

export interface LevelReward {
  id: number
  enabled: boolean | number
  reward_type: RewardType
  reward_value: number
}

export interface ReferralLevel {
  id: number
  name: string
  name_en?: string | null
  description?: string | null
  description_en?: string | null
  sort: number
  required_invites: number
  required_revenue: number
  commission_rate: number
  member_discount: number
  valid_days: number
  retain_invites: number
  retain_revenue?: number
  enabled: boolean | number
  reward?: LevelReward | null
}

export interface ReferralRelation {
  id: number
  email: string
  invite_user_id: number
  inviter_email?: string | null
  created_at: number
  effective: boolean
}

export interface ReferralReward {
  id: number
  user_id: number
  invited_user_id?: number | null
  order_id?: number | null
  user_email?: string | null
  invited_user_email?: string | null
  reward_type: RewardType
  reward_value: number
  status: 'granted' | 'pending' | 'reversed' | 'rejected'
  description?: string | null
  created_at: number
}

export interface ReferralRelationDetail {
  user: {
    id: number
    email: string
    invite_user_id?: number | null
    created_at: number
    invite_commission_eligible?: boolean | number | null
    invitee_reward_eligible?: boolean | number | null
    invite_reward_evaluated_at?: number | null
  }
  inviter?: { id: number; email: string } | null
  orders: Array<{ id: number; trade_no: string; plan_id: number; total_amount: number; status: number; created_at: number }>
  rewards: ReferralReward[]
}

const base = (path: string) => adminPath(`/referral${path}`)

export const fetchReferralDashboard = (refresh = false) => get<ReferralDashboard>(base('/dashboard'), refresh ? { refresh: 1 } : undefined)
export const saveReferralSetting = (data: Omit<ReferralSetting, 'id'>) => post<ReferralSetting>(base('/setting/save'), data, true)
export const fetchReferralFunnel = (days = 30) => get<ReferralFunnel>(base('/funnel'), { days })
export const fetchReferralLevels = () => get<ReferralLevel[]>(base('/levels'))
export const saveReferralLevel = (data: Omit<ReferralLevel, 'reward' | 'id'> & { id?: number; reward_type: RewardType; reward_value: number }) =>
  post<ReferralLevel>(base('/level/save'), data, true)
export const dropReferralLevel = (id: number) => post<boolean>(base('/level/drop'), { id }, true)
export const fetchReferralRelations = (params: { current: number; pageSize: number; keyword?: string; status?: string }) =>
  get<ReferralRelation[]>(base('/relations'), params)
export const fetchReferralRewards = (params: { current: number; pageSize: number; keyword?: string; status?: string; type?: string; from?: string; to?: string }) =>
  get<ReferralReward[]>(base('/rewards'), params)
export const fetchReferralRelationDetail = (userId: number) => get<ReferralRelationDetail>(base('/relation/detail'), { user_id: userId })
export const changeReferralRelation = (userId: number, inviterId: number | null) => post<boolean>(base('/relation/change'), { user_id: userId, inviter_id: inviterId }, true)
export const reverseReferralReward = (id: number, reason: string) => post<{ reversed: number }>(base('/reward/reverse'), { id, reason }, true)
