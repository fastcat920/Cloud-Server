import { adminPath } from '@/app/settings'
import { get, post } from '../request'

export type DnsStrategy = 'round_robin' | 'sequence' | 'weight' | 'source_nearest'
export type EnableStatus = 'enable' | 'disable'
export interface DnsAccount {
    cloud_account_id?: number
    id: number
    name: string
    access_key_masked: string
    has_security_token: boolean
    enabled: boolean
    is_default: boolean
    last_connected_at?: number | null
    last_error?: string | null
}
export interface DnsAddress {
    id: string
    name: string
    address: string
    type: string
    remark: string
    enable_status: EnableStatus
    available_status: string
    health_status: string
    health_judgement: string
    available_mode: 'auto' | 'manual'
    manual_available_status: string
    weight?: number | null
    serial_number?: number | null
    health_tasks: DnsHealthTask[]
}
export interface DnsHealthTask {
    port?: number | null
    template_id: string
    template_name: string
    monitor_status: string
}
export interface DnsMonitorTemplate {
    id: string
    name: string
    ip_version: string
    protocol: 'ping' | 'tcp' | 'http' | 'https' | string
    interval?: number | null
    timeout?: number | null
    remark: string
}
export interface DnsPool {
    id: string
    name: string
    type: string
    remark: string
    strategy: DnsStrategy
    sequence_mode: string
    enable_status: EnableStatus
    available_status: string
    health_status: string
    health_judgement: string
    weight?: number | null
    serial_number?: number | null
    addresses: DnsAddress[]
}
export interface DnsInstance {
    instance_id: string
    config_id: string
    instance_name: string
    domain: string
    zone_name: string
    hostname: string
    record_type: string
    ttl?: number | null
    strategy: DnsStrategy
    sequence_mode: string
    enable_status: EnableStatus
    available_status: string
    health_status: string
    remark: string
    pools: DnsPool[]
}
export interface DnsResources {
    pools: Array<
        Omit<DnsPool, 'strategy' | 'sequence_mode' | 'health_judgement' | 'weight' | 'serial_number' | 'addresses'> & {
            address_count: number
        }
    >
    addresses: Array<
        Omit<
            DnsAddress,
            | 'health_judgement'
            | 'available_mode'
            | 'manual_available_status'
            | 'weight'
            | 'serial_number'
            | 'health_tasks'
        >
    >
}
const base = (path: string) => adminPath(`/aliyun-dns${path}`)
export const fetchDnsOverview = () => get<{ accounts: DnsAccount[]; endpoint: string }>(base('/overview'))
export const saveDnsAccount = (data: {
    id?: number
    name: string
    cloud_account_id?: number; access_key_id?: string
    access_key_secret?: string
    security_token?: string
    clear_security_token: boolean
    enabled: boolean
    is_default: boolean
}) => post<DnsAccount>(base('/account/save'), data, true)
export const dropDnsAccount = (id: number) => post<boolean>(base('/account/drop'), { id }, true)
export const testDnsAccount = (id: number) =>
    post<{ success: boolean; total: number }>(base('/account/test'), { id }, true)
export const fetchDnsInstances = (accountId: number) =>
    get<{ items: DnsInstance[]; total: number }>(base('/instances'), { account_id: accountId })
export const fetchDnsTopology = (accountId: number, instanceId: string, configId: string) =>
    get<DnsInstance>(base('/topology'), { account_id: accountId, instance_id: instanceId, config_id: configId })
export const fetchDnsResources = (accountId: number) => get<DnsResources>(base('/resources'), { account_id: accountId })
export const fetchDnsMonitorTemplates = (accountId: number) =>
    get<{ items: DnsMonitorTemplate[] }>(base('/monitor-templates'), { account_id: accountId })
export const updateDns = (data: Record<string, unknown>) => post<{ success: boolean }>(base('/update'), data, true)
export const saveDnsOrder = (data: {
    account_id: number
    instance_id: string
    config_id: string
    pools: Array<{ pool_id: string; serial_number: number }>
    addresses: Array<{ pool_id: string; address_id: string; serial_number: number }>
}) => post<{ success: boolean }>(base('/order/save'), data, true)
export const saveDnsPool = (data: Record<string, unknown>) =>
    post<{ success: boolean; pool_id: string }>(base('/pool/save'), data, true)
export const attachDnsPool = (data: Record<string, unknown>) =>
    post<{ success: boolean }>(base('/pool/attach'), data, true)
export const dropDnsPool = (data: Record<string, unknown>) => post<{ success: boolean }>(base('/pool/drop'), data, true)
export const saveDnsAddress = (data: Record<string, unknown>) =>
    post<{ success: boolean; address_id: string }>(base('/address/save'), data, true)
export const attachDnsAddress = (data: Record<string, unknown>) =>
    post<{ success: boolean }>(base('/address/attach'), data, true)
export const dropDnsAddress = (data: Record<string, unknown>) =>
    post<{ success: boolean }>(base('/address/drop'), data, true)
