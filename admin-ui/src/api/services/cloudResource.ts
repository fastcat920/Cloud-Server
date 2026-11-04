import { adminPath, apiBase } from '@/app/settings'
import { getToken } from '@/utils/storage'
import { get, post, uploadForm } from '../request'
export interface ResourceContext { account_id: number; region: string; project?: string }
export interface Instance { id: string; name: string; status: string; ip: string; private_ip: string; cpu: number | null; memory_gb: number | null; zone: string; expires_at: string }
export interface Bucket { name: string; region: string; created_at: string }
export interface CloudObject { key: string; directory: boolean; size: number; modified_at: string }
export interface Operation { id: number; operation: string; resource: string; created_at: number }
const base = (path: string) => adminPath(`/cloud-resource${path}`)
export const fetchRegions = (account_id: number) => get<Array<{id: string; name: string}>>(base('/regions'), {account_id})
export const fetchInstances = (data: ResourceContext & {page: number}) => get<{items: Instance[]; total: number}>(base('/instances'), data)
export const operateInstance = (data: ResourceContext & {instance_id: string; operation: 'start'|'stop'|'reboot'; confirmation: string}) => post<{submitted: boolean; request_id?: string}>(base('/instance/operate'), data, true)
export const fetchBuckets = (data: ResourceContext & {marker?: string}) => get<{items: Bucket[]; next_marker: string}>(base('/buckets'), data)
export const fetchObjects = (data: ResourceContext & {bucket: string; prefix: string; marker: string}) => get<{items: CloudObject[]; next_marker: string}>(base('/objects'), data)
export const deleteCloudObject = (data: ResourceContext & {bucket: string; key: string; confirmation: string}) => post<boolean>(base('/object/delete'), data, true)
export const uploadCloudObject = (data: ResourceContext & {bucket: string; key: string; file: File}) => {
  const form = new FormData()
  form.set('account_id', String(data.account_id)); form.set('region', data.region); form.set('bucket', data.bucket); form.set('key', data.key); form.set('confirmation', data.key); form.set('file', data.file)
  return uploadForm<boolean>(base('/object/upload'), form)
}
export const fetchOperations = (account_id: number) => get<Operation[]>(base('/operations'), {account_id})
export async function downloadCloudObject(data: ResourceContext & {bucket: string; key: string}) {
  const query = new URLSearchParams({account_id: String(data.account_id),region: data.region,bucket: data.bucket,key: data.key})
  const response = await fetch(`${apiBase}${base('/object/download')}?${query}`, {headers: {authorization: getToken() || ''}})
  if (!response.ok) { const error = await response.json().catch(() => ({})) as {message?: string}; throw new Error(error.message || '下载失败') }
  const blob = await response.blob(); const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = data.key.split('/').pop() || 'download'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000)
}
