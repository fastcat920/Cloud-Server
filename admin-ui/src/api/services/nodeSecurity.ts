import { adminPath } from '@/app/settings'
import { get, post } from '../request'

export type EntryDeliveryMode = 'primary_only' | 'manual_backup' | 'auto_fallback'
export type ClientVisibilityMode = 'all' | 'allowlist' | 'denylist'

export interface NodeEntry {
  id: number
  name: string
  host: string
  port: number
  priority: number
  is_primary: boolean
  enabled: boolean
  health_status?: string | null
  last_checked_at?: number | null
  last_healthy_at?: number | null
}

export interface EntryClientPolicy {
  id: number
  client_family: string
  client_platform?: string | null
  user_agent?: string | null
  min_version?: string | null
  max_version?: string | null
  delivery_mode: EntryDeliveryMode
  visibility: 'show' | 'hide'
  check_url: string
  check_interval: number
  priority: number
  enabled: boolean
}

export interface EntryPoolNode {
  server_type: string
  server_id: number
  server_name: string
  original_address: string
  delivery_mode: EntryDeliveryMode
  client_visibility_mode: ClientVisibilityMode
  check_url: string
  check_interval: number
  sync_primary_host: boolean
  sync_primary_port: boolean
  entries: NodeEntry[]
  client_policies: EntryClientPolicy[]
}

interface EntryPoolPage {
  data: EntryPoolNode[]
  total: number
}

const base = (path: string) => adminPath(`/security${path}`)

export const fetchEntryPool = (serverType: string, serverId: number) =>
  get<EntryPoolPage>(base('/entry-pools'), { server_type: serverType, server_id: serverId })

export const saveEntrySetting = (data: object) => post<boolean>(base('/entry-setting/save'), data, true)
export const saveEntry = (data: object) => post<boolean>(base('/entry/save'), data, true)
export const deleteEntry = (id: number) => post<boolean>(base('/entry/delete'), { id }, true)
export const saveEntryClientPolicy = (data: object) => post<boolean>(base('/entry-client-policy/save'), data, true)
export const deleteEntryClientPolicy = (id: number) =>
  post<boolean>(base('/entry-client-policy/delete'), { id }, true)
