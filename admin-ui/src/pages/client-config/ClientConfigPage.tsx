import { ReloadOutlined } from '@ant-design/icons'
import { Alert, Button, Skeleton, Tabs } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router'
import { fetchClientConfigOverview, type ClientConfigOverview } from '@/api/services/clientConfig'
import { unwrap } from '@/api/request'
import { AdminLayout } from '@/layouts/AdminLayout'
import { EditorTab } from './EditorTab'
import { ReleasesTab } from './ReleasesTab'
import { StorageTab } from './StorageTab'

const TAB_KEYS = ['editor', 'storage', 'releases'] as const
type TabKey = (typeof TAB_KEYS)[number]
export default function ClientConfigPage() {
  const [params, setParams] = useSearchParams()
  const requested = params.get('tab') as TabKey | null
  const active = requested && TAB_KEYS.includes(requested) ? requested : 'editor'
  const editId = Number(params.get('edit') || 0) || undefined
  const [data, setData] = useState<ClientConfigOverview | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string>()
  const load = useCallback(async (requestedEditId?: number) => {
    setLoading(true); setError(undefined)
    try { setData(await unwrap(fetchClientConfigOverview(requestedEditId ?? editId))) } catch (reason) { setError(reason instanceof Error ? reason.message : '客户端配置加载失败') } finally { setLoading(false) }
  }, [editId])
  useEffect(() => { const timer = window.setTimeout(() => void load(), 0); return () => window.clearTimeout(timer) }, [load])
  const activePublishing = data?.publications.some((row) => row.status === 'queued' || row.status === 'publishing')
  useEffect(() => { if (!activePublishing) return; const timer = window.setTimeout(() => void load(), 5000); return () => window.clearTimeout(timer) }, [activePublishing, load])
  const changeTab = (key: string) => { const next: Record<string, string> = {}; if (key !== 'editor') next.tab = key; if (editId) next.edit = String(editId); setParams(next, { replace: true }) }
  const edit = (id: number) => { setParams({ edit: String(id) }, { replace: true }); void load(id) }

  return (
    <AdminLayout title="客户端配置">
      <div className="catboard-page">
        <div className="catboard-page-heading"><div><h2>客户端配置</h2><p>可视化管理远程配置、加密方式与多云发布</p></div><Button icon={<ReloadOutlined />} loading={loading} onClick={() => void load()}>刷新</Button></div>
        {error && <Alert className="mb-3" type="error" showIcon message="加载失败" description={error} />}
        {!data ? <Skeleton active /> : <Tabs activeKey={active} onChange={changeTab} destroyOnHidden items={[
          { key: 'editor', label: '配置编辑', children: <EditorTab data={data} onReload={load} /> },
          { key: 'storage', label: '云存储', children: <StorageTab rows={data.targets} onReload={load} /> },
          { key: 'releases', label: '版本与发布', children: <ReleasesTab versions={data.versions} targets={data.targets} publications={data.publications} onEdit={edit} onReload={load} /> },
        ]} />}
      </div>
    </AdminLayout>
  )
}
