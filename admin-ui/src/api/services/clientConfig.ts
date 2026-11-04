import { adminPath } from '@/app/settings'
import { get, post } from '../request'

export type EncryptionMode = 'xor_base64' | 'plain' | 'signed_xor_v2'
export type StorageProvider = 'aliyun_oss' | 'tencent_cos' | 'ucloud_us3'
export type ConfigStatus = 'draft' | 'publishing' | 'published' | 'partial' | 'archived'

export interface PlatformUpdate {
  enabled: boolean
  source: 'direct' | 'app_store'
  latest_version: string
  min_supported_version: string
  url?: string
  app_id?: string
  force: boolean
  changelog: { zh_CN: string; en_US: string }
}
export interface ClientConfigContent {
  config_version?: string
  panel_type: string
  api_prefix: string
  domains: string[]
  gateway_urls: string[]
  contact: { website: string[]; crisp_website_id?: string; crisp_proxy_url?: string; invite_domain?: string; telegram_group?: string; [key: string]: unknown }
  features: Record<string, boolean>
  latency: { display_discount_percent: number; [key: string]: unknown }
  update: { schema_version: 2; platforms: Record<string, PlatformUpdate>; [key: string]: unknown }
  [key: string]: unknown
}
export interface ClientConfigVersion {
  id: number
  config_version: number
  status: ConfigStatus
  encryption_mode: EncryptionMode
  checksum: string
  change_summary?: string | null
  published_at?: number | null
  created_at: number
  content?: ClientConfigContent
}
export interface StorageTarget {
  cloud_account_id?: number
  id: number
  name: string
  provider: StorageProvider
  enabled: boolean | number
  is_primary: boolean | number
  endpoint: string
  bucket: string
  object_key: string
  public_url: string
  has_credentials: boolean
  access_key_hint: string
}
export interface Publication {
  id: number
  config_id: number
  target_id: number
  status: 'queued' | 'publishing' | 'success' | 'failed'
  object_key: string
  public_url?: string | null
  etag?: string | null
  checksum?: string | null
  error?: string | null
  attempts: number
  started_at?: number | null
  finished_at?: number | null
  target?: Pick<StorageTarget, 'id' | 'name' | 'provider'> | null
  config?: Pick<ClientConfigVersion, 'id' | 'config_version' | 'encryption_mode' | 'change_summary'> | null
}
export interface ClientConfigSettings { encryption_mode: EncryptionMode; has_xor_key: boolean; signing_public_key?: string | null; has_signing_key: boolean }
export interface ClientConfigOverview {
  draft?: ClientConfigVersion | null
  published?: ClientConfigVersion | null
  versions: ClientConfigVersion[]
  targets: StorageTarget[]
  publications: Publication[]
  settings: ClientConfigSettings
}
export interface StorageTargetInput {
  cloud_account_id?: number
  id?: number
  name: string
  provider: StorageProvider
  enabled: boolean
  is_primary: boolean
  endpoint: string
  bucket: string
  object_key: string
  public_url?: string
  access_key_id?: string
  secret_key?: string
  security_token?: string
}

const base = (path: string) => adminPath(`/client-config${path}`)
export const fetchClientConfigOverview = (editId?: number) => get<ClientConfigOverview>(base('/overview'), editId ? { edit_id: editId } : undefined)
export const saveClientConfigDraft = (data: { id?: number; content: ClientConfigContent; encryption_mode: EncryptionMode; change_summary?: string }) => post<ClientConfigVersion>(base('/draft/save'), data, true)
export const cloneClientConfigVersion = (id: number) => post<ClientConfigVersion>(base('/version/clone'), { id }, true)
export const dropClientConfigVersion = (id: number) => post<boolean>(base('/version/drop'), { id }, true)
export const saveClientConfigSettings = (data: { encryption_mode: EncryptionMode; xor_key?: string; regenerate_signing_key: boolean }) => post<ClientConfigSettings>(base('/settings/save'), data, true)
export const saveStorageTarget = (data: StorageTargetInput) => post<StorageTarget>(base('/target/save'), data, true)
export const dropStorageTarget = (id: number) => post<boolean>(base('/target/drop'), { id }, true)
export const testStorageTarget = (id: number) => post<{ success: boolean; url: string; cleanup_warning?: string | null }>(base('/target/test'), { id }, true)
export const previewClientConfig = (id: number, encryptionMode: EncryptionMode) => post<{ payload: string; bytes: number; checksum: string; encryption_mode: EncryptionMode }>(base('/preview'), { id, encryption_mode: encryptionMode }, true)
export const publishClientConfig = (configId: number, targetIds: number[]) => post<Publication[]>(base('/publish'), { config_id: configId, target_ids: targetIds }, true)
export const retryClientConfigPublication = (id: number) => post<Publication>(base('/publication/retry'), { id }, true)
