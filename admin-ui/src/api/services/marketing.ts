import { adminPath } from '@/app/settings'
import { get, post } from '../request'

export type ActivityType = 'flash_sale' | 'coupon' | 'newcomer'
export type ActivityStatus = 'active' | 'scheduled' | 'ended' | 'disabled'
export type FlashDiscountType = 'fixed_price' | 'percent_off' | 'amount_off'

export interface MarketingActivity {
  key: string
  source_id: number
  type: ActivityType
  name: string
  name_en?: string | null
  starts_at?: number | null
  ends_at?: number | null
  status: ActivityStatus
  enabled: boolean
  scope: string
  orders: number
  revenue: number
  discount_total: number
}

export interface PlanOption { id: number; name: string }

export interface ActivityOverview {
  data: MarketingActivity[]
  plans: PlanOption[]
  summary: { total: number; active: number; scheduled: number; ended: number }
}

export interface FlashSale {
  id: number
  name: string
  name_en?: string | null
  description?: string | null
  description_en?: string | null
  starts_at: number
  ends_at: number
  audience: 'all' | 'new' | 'existing'
  plan_ids?: number[] | null
  periods?: string[] | null
  discount_type: FlashDiscountType
  discount_value: number
  minimum_amount: number
  allow_coupon: boolean | number
  allow_member_discount: boolean | number
  priority: number
  per_user_limit?: number | null
  total_limit?: number | null
  enabled: boolean | number
  order_count?: number
}

export interface FlashSaleInput extends Omit<FlashSale, 'id' | 'order_count'> { id?: number }

const base = (path: string) => adminPath(`/marketing${path}`)

export const fetchActivities = (params?: { type?: string; status?: string; keyword?: string }) =>
  get<MarketingActivity[]>(base('/activities'), params)
export const fetchFlashSales = () => get<FlashSale[]>(base('/flash-sales'))
export const saveFlashSale = (data: FlashSaleInput) => post<FlashSale>(base('/flash-sale/save'), data, true)
export const dropFlashSale = (id: number) => post<boolean>(base('/flash-sale/drop'), { id }, true)
