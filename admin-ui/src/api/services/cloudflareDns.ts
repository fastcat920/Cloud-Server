import { adminPath } from '@/app/settings'
import { get, post } from '../request'

export interface CloudflareAccount {
    id: number
    name: string
    token_masked: string
    has_api_token: boolean
    enabled: boolean
    is_default: boolean
    last_connected_at?: number | null
    last_error?: string | null
}

export interface CloudflareZone {
    id: string
    name: string
    status: string
    paused: boolean
    type: string
    plan_name: string
    name_servers: string[]
    modified_on?: string | null
}

export interface CloudflareRecordData {
    flags?: number
    tag?: string
    value?: string
    name?: string
    priority?: number
    weight?: number
    port?: number
    target?: string
}

export interface CloudflareRecord {
    id: string
    zone_id: string
    zone_name: string
    name: string
    type: string
    content: string
    proxiable: boolean
    proxied: boolean
    ttl: number
    priority?: number
    comment?: string | null
    data?: CloudflareRecordData
    modified_on?: string
}

export interface CloudflareRecordInput {
    account_id: number
    zone_id: string
    record_id?: string
    type: string
    name: string
    content?: string
    ttl: number
    proxied?: boolean
    priority?: number
    comment?: string
    data?: CloudflareRecordData
}

const base = (path: string) => adminPath(`/cloudflare-dns${path}`)

export const fetchCloudflareOverview = () => get<{ accounts: CloudflareAccount[]; endpoint: string }>(base('/overview'))
export const saveCloudflareAccount = (data: {
    id?: number
    name: string
    api_token?: string
    enabled: boolean
    is_default: boolean
}) => post<CloudflareAccount>(base('/account/save'), data, true)
export const testCloudflareAccount = (id: number) =>
    post<{ success: boolean; zone_count: number }>(base('/account/test'), { id }, true)
export const dropCloudflareAccount = (id: number) => post<boolean>(base('/account/drop'), { id }, true)
export const fetchCloudflareZones = (accountId: number) =>
    get<{ items: CloudflareZone[] }>(base('/zones'), { account_id: accountId })
export const fetchCloudflareRecords = (params: {
    account_id: number
    zone_id: string
    page: number
    per_page: number
    type?: string
    name?: string
}) => get<{ items: CloudflareRecord[]; page: number; per_page: number; total: number }>(base('/records'), params)
export const saveCloudflareRecord = (data: CloudflareRecordInput) =>
    post<CloudflareRecord>(base('/record/save'), data, true)
export const dropCloudflareRecord = (accountId: number, zoneId: string, recordId: string) =>
    post<boolean>(base('/record/drop'), { account_id: accountId, zone_id: zoneId, record_id: recordId }, true)
