import { adminPath } from '@/app/settings'
import { get, post } from '../request'

export type CloudProvider = 'aliyun' | 'tencent' | 'ucloud'
export type CloudSite = 'china' | 'international'
export type CloudHealth = 'healthy' | 'low' | 'recharge' | 'error' | 'unknown' | 'disabled'

export interface CloudAccount {
  id: number
  name: string
  account_id: string | null
  provider: CloudProvider
  site: CloudSite
  enabled: boolean
  currency: string
  balance: number | null
  current_month_amount: number | null
  previous_month_amount: number | null
  coverage_months: number | null
  health: CloudHealth
  last_synced_at: number | null
  last_error: string | null
  has_access_key: boolean
  has_secret_key: boolean
  has_security_token: boolean
}

export interface CloudAccountOverview {
  accounts: CloudAccount[]
  periods: { current: string; previous: string }
}

export interface CloudAccountInput {
  id?: number
  name: string
  account_id?: string
  provider: CloudProvider
  site: CloudSite
  access_key_id?: string
  access_key_secret?: string
  security_token?: string
  enabled: boolean
}

const base = (path: string) => adminPath(`/cloud-account${path}`)

export const fetchCloudAccounts = () => get<CloudAccountOverview>(base('/overview'))
export const saveCloudAccount = (data: CloudAccountInput) => post<CloudAccount>(base('/save'), data, true)
export const refreshCloudAccount = (id: number) => post<CloudAccount>(base('/refresh'), { id }, true)
export const refreshAllCloudAccounts = () => post<{ accounts: CloudAccount[] }>(base('/refresh-all'), {}, true)
export const dropCloudAccount = (id: number) => post<boolean>(base('/drop'), { id }, true)
