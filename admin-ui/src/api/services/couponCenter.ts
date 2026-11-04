import { adminPath } from '@/app/settings'
import { ApiError, get, post } from '../request'

export type CouponDiscountType = 'fixed' | 'percent'
export type CouponStatus = 'pending' | 'available' | 'locked' | 'used' | 'expired' | 'revoked'
export type DistributionStatus = 'pending' | 'running' | 'completed' | 'partial' | 'failed' | 'cancelled'

export interface CouponPlan { id: number; name: string }

export interface CouponTemplate {
  id: number
  name: string
  name_en?: string | null
  description?: string | null
  description_en?: string | null
  discount_type: CouponDiscountType
  discount_value: number
  plan_ids?: number[] | null
  periods?: string[] | null
  first_order_only: boolean | number
  stackable: boolean | number
  per_user_limit: number
  total_limit?: number | null
  daily_limit?: number | null
  valid_days?: number | null
  starts_at?: number | null
  ends_at?: number | null
  issued_count: number
  used_count: number
  enabled: boolean | number
  email_notify_enabled: boolean | number
  created_at: number
  updated_at: number
}

export interface CouponDashboard {
  templates: number
  issued: number
  available: number
  used: number
  expired: number
  discount_total: number
  revenue: number
  template_ranking: Array<Pick<CouponTemplate, 'id' | 'name' | 'issued_count' | 'used_count'>>
}

export interface CouponAudienceFilters {
  user_ids?: number[]
  emails?: string[]
  plan_ids?: number[]
  subscription_status?: '' | 'active' | 'expired'
  never_purchased?: boolean
}

export interface DistributionTask {
  id: number
  template_id: number
  name: string
  filters: CouponAudienceFilters
  status: DistributionStatus
  estimated_count: number
  processed_count: number
  success_count: number
  skipped_count: number
  failed_count: number
  total_batches: number
  completed_batches: number
  attempts: number
  last_error?: string | null
  progress: number
  worker_warning: boolean
  created_at: number
  completed_at?: number | null
}

export interface UserCoupon {
  id: number
  template_id: number
  user_id: number
  user_email?: string | null
  template?: CouponTemplate | null
  source: string
  status: CouponStatus
  starts_at: number
  expires_at: number
  order_id?: number | null
  notification_status?: 'pending' | 'sending' | 'sent' | 'failed' | 'disabled' | null
  notification_attempts?: number
  notification_error?: string | null
  created_at: number
}

export interface CouponTemplateInput {
  id?: number
  name: string
  name_en?: string
  description?: string
  description_en?: string
  discount_type: CouponDiscountType
  discount_value: number
  plan_ids: number[]
  periods: string[]
  first_order_only: boolean
  stackable: boolean
  per_user_limit: number
  total_limit?: number
  daily_limit?: number
  valid_days?: number
  starts_at?: number
  ends_at?: number
  enabled: boolean
  email_notify_enabled: boolean
}

const base = (path: string) => adminPath(`/coupon${path}`)

export const fetchCouponDashboard = () => get<CouponDashboard>(base('/dashboard'))
export async function fetchCouponTemplates() {
  const result = await get<CouponTemplate[]>(base('/templates'))
  if (result.code !== 200) throw new ApiError(result.code, result.msg)
  return {
    templates: result.data ?? [],
    plans: Array.isArray(result.plans) ? result.plans as CouponPlan[] : [],
  }
}
export const saveCouponTemplate = (data: CouponTemplateInput) => post<CouponTemplate>(base('/template/save'), data, true)
export const copyCouponTemplate = (id: number) => post<CouponTemplate>(base('/template/copy'), { id }, true)
export const dropCouponTemplate = (id: number) => post<boolean>(base('/template/drop'), { id }, true)
export const estimateCouponAudience = (filters: CouponAudienceFilters) => post<{ count: number }>(base('/distribution/estimate'), { filters }, true)
export const createDistributionTask = (templateId: number, name: string, filters: CouponAudienceFilters) =>
  post<DistributionTask>(base('/distribution/create'), { template_id: templateId, name, filters }, true)
export const fetchDistributionTasks = () => get<DistributionTask[]>(base('/distribution/tasks'))
export const cancelDistributionTask = (id: number) => post<boolean>(base('/distribution/cancel'), { id }, true)
export const retryDistributionTask = (id: number) => post<DistributionTask>(base('/distribution/retry'), { id }, true)
export const importDistributionTask = (templateId: number, name: string, csv: string) =>
  post<DistributionTask>(base('/distribution/import'), { template_id: templateId, name, csv }, true)
export const fetchUserCoupons = (params: { current: number; pageSize: number; keyword?: string; status?: string; template_id?: number; refresh?: number }) =>
  get<UserCoupon[]>(base('/user-coupons'), params)
export const issueUserCoupon = (templateId: number, userId: number) => post<UserCoupon>(base('/user-coupon/issue'), { template_id: templateId, user_id: userId }, true)
export const revokeUserCoupon = (id: number) => post<boolean>(base('/user-coupon/revoke'), { id }, true)
export const extendUserCoupon = (id: number, days: number) => post<UserCoupon>(base('/user-coupon/extend'), { id, days }, true)
export const retryCouponNotification = (id: number) => post<boolean>(base('/user-coupon/retry-notification'), { id }, true)
export const exportUserCoupons = () => get<never>(base('/user-coupons/export'))
