import { fetchBuckets, type Bucket } from '@/api/services/cloudResource'
import { CloudAccountSelect } from '@/components/CloudAccountSelect'
import { CopyOutlined, DeleteOutlined, EditOutlined, PlusOutlined, ThunderboltOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Form, Input, Modal, Select, Space, Switch, Table, Tag, Typography, type TableColumnsType } from 'antd'
import copy from 'copy-to-clipboard'
import { useEffect, useState } from 'react'
import { dropStorageTarget, saveStorageTarget, testStorageTarget, type StorageProvider, type StorageTarget, type StorageTargetInput } from '@/api/services/clientConfig'
import { unwrap } from '@/api/request'
import { message, modal } from '@/app/staticApi'

const PROVIDERS: Record<StorageProvider, string> = { aliyun_oss: '阿里云 OSS', tencent_cos: '腾讯云 COS', ucloud_us3: 'UCloud US3' }
interface TargetForm extends StorageTargetInput { custom_public_url: boolean }

function publicUrl(endpoint?: string, bucket?: string, objectKey?: string) {
  if (!endpoint || !bucket || !objectKey) return ''
  const scheme = endpoint.toLowerCase().startsWith('http://') ? 'http' : 'https'
  const cleanEndpoint = endpoint.replace(/^https?:\/\//i, '').replace(/\/+$/, '')
  const host = cleanEndpoint.toLowerCase().startsWith(`${bucket.toLowerCase()}.`) ? cleanEndpoint : `${bucket}.${cleanEndpoint}`
  return `${scheme}://${host}/${objectKey.replace(/^\/+/, '').split('/').map(encodeURIComponent).join('/')}`
}

function TargetEditor({ open, row, onClose, onSaved }: { open: boolean; row: StorageTarget | null; onClose: () => void; onSaved: () => void }) {
  const [form] = Form.useForm<TargetForm>()
  const [saving, setSaving] = useState(false)
  const [buckets, setBuckets] = useState<Bucket[]>([])
  const [bucketLoading, setBucketLoading] = useState(false)
  const cloudAccountId = Form.useWatch('cloud_account_id', form)
  const provider = Form.useWatch('provider', form) ?? row?.provider ?? 'aliyun_oss'
  const endpoint = Form.useWatch('endpoint', form)
  const bucket = Form.useWatch('bucket', form)
  const objectKey = Form.useWatch('object_key', form)
  const custom = Form.useWatch('custom_public_url', form)
  const generated = publicUrl(endpoint, bucket, objectKey)
  useEffect(() => {
    if (!open) return
    const automatic = publicUrl(row?.endpoint, row?.bucket, row?.object_key)
    const isCustom = Boolean(row?.public_url && row.public_url !== automatic)
    form.setFieldsValue({ cloud_account_id: row?.cloud_account_id, name: row?.name ?? '', provider: row?.provider ?? 'aliyun_oss', endpoint: row?.endpoint ?? '', bucket: row?.bucket ?? '', object_key: row?.object_key ?? 'config.json', custom_public_url: isCustom, public_url: isCustom ? row?.public_url : '', access_key_id: '', secret_key: '', security_token: '', enabled: row ? Boolean(row.enabled) : true, is_primary: Boolean(row?.is_primary) })
  }, [form, open, row])
  useEffect(() => { setBuckets([]) }, [cloudAccountId, provider])
  const loadBuckets = async () => {
    if (!cloudAccountId) return
    const host = (endpoint || '').replace(/^https?:\/\//, '')
    const region = provider === 'aliyun_oss' ? host.match(/oss-([a-z0-9-]+)/)?.[1] || 'cn-hangzhou' : provider === 'tencent_cos' ? host.match(/cos\.([a-z0-9-]+)/)?.[1] || 'ap-guangzhou' : host.match(/(?:^|\.)(?:s3-)?([a-z0-9-]+)\.ufileos\.com/)?.[1] || 'cn-bj'
    setBucketLoading(true)
    try { const data = await unwrap(fetchBuckets({ account_id: cloudAccountId, region })); setBuckets(data.items); if (data.next_marker) message.success('此处显示首批 Bucket，完整列表请到云账号资源页查看') } finally { setBucketLoading(false) }
  }
  const save = async () => {
    const values = await form.validateFields()
    setSaving(true)
    try {
      await unwrap(saveStorageTarget({ cloud_account_id: values.cloud_account_id, id: row?.id, name: values.name.trim(), provider: values.provider, endpoint: values.endpoint.trim(), bucket: values.bucket.trim(), object_key: values.object_key.trim(), public_url: values.custom_public_url ? values.public_url?.trim() : '', access_key_id: values.access_key_id?.trim(), secret_key: values.secret_key?.trim(), security_token: values.security_token?.trim(), enabled: values.enabled, is_primary: values.is_primary }))
      message.success('云存储目标已保存'); onClose(); onSaved()
    } finally { setSaving(false) }
  }
  return (
    <Modal title={`${row ? '编辑' : '新增'}云存储目标`} open={open} onCancel={onClose} onOk={() => void save()} confirmLoading={saving} okText="保存" cancelText="取消" width={780} destroyOnHidden>
      <Form form={form} layout="vertical" requiredMark={false}>
        <div className="catboard-form-grid">
          <Form.Item name="name" label="目标名称" rules={[{ required: true, message: '请输入目标名称' }]}><Input /></Form.Item>
          <Form.Item name="provider" label="云服务商" rules={[{ required: true }]}><Select onChange={() => form.setFieldValue('cloud_account_id', undefined)} options={Object.entries(PROVIDERS).map(([value, label]) => ({ value, label }))} /></Form.Item>
          <Form.Item name="endpoint" label="Endpoint" rules={[{ required: true, message: '请输入 Endpoint' }]}><Input placeholder="oss-cn-hongkong.aliyuncs.com" /></Form.Item>
          <Form.Item name="bucket" label="Bucket" rules={[{ required: true, message: '请输入 Bucket' }]}><Input /></Form.Item>
          <Form.Item name="object_key" label="对象路径" rules={[{ required: true, message: '请输入对象路径' }]} className="span-2"><Input /></Form.Item>
        </div>
        <Alert className="mb-3" type="info" showIcon message="自动生成的公开访问地址" description={generated || '填写 Endpoint、Bucket 和对象路径后自动生成'} />
        <Form.Item name="custom_public_url" valuePropName="checked"><Switch checkedChildren="使用 CDN / 自定义地址" unCheckedChildren="使用自动地址" /></Form.Item>
        {custom && <Form.Item name="public_url" label="自定义公开访问地址" rules={[{ required: true, message: '请输入公开访问地址' }, { type: 'url', message: '请输入有效地址' }]}><Input /></Form.Item>}
        <Form.Item name="cloud_account_id" label="统一云账号" rules={[{ required: true, message: '请选择云账号' }]} extra="访问密钥统一在云账号管理维护。"><CloudAccountSelect provider={provider === 'aliyun_oss' ? 'aliyun' : provider === 'tencent_cos' ? 'tencent' : 'ucloud'} /></Form.Item>
        <Form.Item label="选择云端 Bucket" extra="读取账号下的存储桶后自动填写 Bucket 和 Endpoint；也可手动填写。">
          <Space wrap><Button disabled={!cloudAccountId} loading={bucketLoading} onClick={() => void loadBuckets()}>读取 Bucket</Button><Select aria-label="选择发布存储桶" placeholder="选择已有 Bucket" style={{ minWidth: 280 }} showSearch optionFilterProp="label" options={buckets.map((b) => ({ value: b.name, label: `${b.name} · ${b.region}` }))} onChange={(name) => { const bucket = buckets.find((b) => b.name === name); if (!bucket) return; form.setFieldsValue({ bucket: name, endpoint: provider === 'aliyun_oss' ? `oss-${bucket.region}.aliyuncs.com` : provider === 'tencent_cos' ? `cos.${bucket.region}.myqcloud.com` : `${bucket.region}.ufileos.com` }) }} /></Space>
        </Form.Item>
        <Space size="large" wrap><Form.Item name="enabled" valuePropName="checked" className="mb-0"><Switch checkedChildren="启用目标" unCheckedChildren="停用目标" /></Form.Item><Form.Item name="is_primary" valuePropName="checked" className="mb-0"><Switch checkedChildren="主目标" unCheckedChildren="普通目标" /></Form.Item></Space>
      </Form>
    </Modal>
  )
}

export function StorageTab({ rows, onReload }: { rows: StorageTarget[]; onReload: () => Promise<void> }) {
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<StorageTarget | null>(null)
  const [testing, setTesting] = useState<number | null>(null)
  const test = async (row: StorageTarget) => {
    setTesting(row.id)
    try { const result = await unwrap(testStorageTarget(row.id)); if (result.cleanup_warning) message.error(`连接成功，但测试文件清理失败：${result.cleanup_warning}`); else message.success('连接测试通过，测试文件已自动清理') } finally { setTesting(null) }
  }
  const remove = (row: StorageTarget) => modal.confirm({ title: `删除云存储目标“${row.name}”？`, content: '历史发布记录会保留。', okText: '删除', cancelText: '取消', okButtonProps: { danger: true }, onOk: async () => { await unwrap(dropStorageTarget(row.id)); message.success('目标已删除'); await onReload() } })
  const columns: TableColumnsType<StorageTarget> = [
    { title: '名称', width: 180, render: (_, row) => <Space><strong>{row.name}</strong>{row.is_primary && <Tag color="blue">主目标</Tag>}</Space> },
    { title: '服务商', width: 140, render: (_, row) => PROVIDERS[row.provider] },
    { title: 'Bucket / Endpoint', width: 260, render: (_, row) => <div>{row.bucket}<div><Typography.Text type="secondary">{row.endpoint}</Typography.Text></div></div> },
    { title: '固定地址', width: 300, render: (_, row) => <Typography.Link href={row.public_url} target="_blank" title={row.public_url} ellipsis>{row.public_url}</Typography.Link> },
    { title: '状态', width: 120, render: (_, row) => <div><Tag color={row.enabled ? 'success' : 'default'}>{row.enabled ? '启用' : '停用'}</Tag><div><Typography.Text type="secondary">{row.access_key_hint}</Typography.Text></div></div> },
    { title: '操作', width: 285, fixed: 'right', render: (_, row) => <Space><Button type="link" icon={<ThunderboltOutlined />} loading={testing === row.id} onClick={() => void test(row)}>测试</Button><Button type="link" icon={<CopyOutlined />} onClick={() => { copy(row.public_url); message.success('链接已复制') }}>复制</Button><Button type="link" icon={<EditOutlined />} onClick={() => { setEditing(row); setOpen(true) }}>编辑</Button><Button type="link" danger icon={<DeleteOutlined />} onClick={() => remove(row)}>删除</Button></Space> },
  ]
  return (
    <Card title="云存储目标" extra={<Button type="primary" icon={<PlusOutlined />} onClick={() => { setEditing(null); setOpen(true) }}>新增目标</Button>}>
      <Table rowKey="id" columns={columns} dataSource={rows} pagination={false} scroll={{ x: 1300 }} />
      <TargetEditor open={open} row={editing} onClose={() => setOpen(false)} onSaved={() => void onReload()} />
    </Card>
  )
}