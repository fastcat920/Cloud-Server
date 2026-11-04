import { useEffect, useState } from 'react'
import { Select } from 'antd'
import { fetchCloudAccounts, type CloudAccount, type CloudProvider } from '@/api/services/cloudAccount'
import { unwrap } from '@/api/request'
export function CloudAccountSelect({ provider, value, onChange }: { provider: CloudProvider; value?: number; onChange?: (value: number | undefined) => void }) {
  const [accounts, setAccounts] = useState<CloudAccount[]>([])
  const [loading, setLoading] = useState(false)
  useEffect(() => { setLoading(true); void unwrap(fetchCloudAccounts()).then((data) => setAccounts(data.accounts)).catch(() => undefined).finally(() => setLoading(false)) }, [])
  return <Select value={value} onChange={onChange} loading={loading} showSearch optionFilterProp="label" placeholder="选择云账号；请先在云账号管理添加" options={accounts.filter((a) => a.provider === provider).map((a) => ({ value: a.id, label: `${a.name}${a.enabled ? '' : '（已停用）'}`, disabled: !a.enabled }))} />
}
