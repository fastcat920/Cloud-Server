import { CopyOutlined, DeleteOutlined, EditOutlined, EyeOutlined, RocketOutlined, SyncOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Checkbox, Descriptions, Modal, Space, Table, Tag, Typography, type TableColumnsType } from 'antd'
import copy from 'copy-to-clipboard'
import dayjs from 'dayjs'
import { useState } from 'react'
import { cloneClientConfigVersion, dropClientConfigVersion, publishClientConfig, retryClientConfigPublication, type ClientConfigVersion, type Publication, type StorageTarget } from '@/api/services/clientConfig'
import { unwrap } from '@/api/request'
import { message, modal } from '@/app/staticApi'

const STATUS: Record<string, { label: string; color: string }> = {
  draft: { label: '草稿', color: 'default' }, publishing: { label: '发布中', color: 'processing' }, published: { label: '已发布', color: 'success' }, partial: { label: '部分失败', color: 'warning' }, archived: { label: '历史版本', color: 'default' },
  queued: { label: '等待队列', color: 'processing' }, success: { label: '成功', color: 'success' }, failed: { label: '失败', color: 'error' },
}
const MODE: Record<string, string> = { xor_base64: 'XOR + Base64', signed_xor_v2: '签名加密 v2', plain: '明文 JSON' }
const dateTime = (value?: number | null) => value ? dayjs.unix(value).format('YYYY-MM-DD HH:mm:ss') : '-'

export function ReleasesTab({ versions, targets, publications, onEdit, onReload }: { versions: ClientConfigVersion[]; targets: StorageTarget[]; publications: Publication[]; onEdit: (id: number) => void; onReload: (editId?: number) => Promise<void> }) {
  const [publishing, setPublishing] = useState<ClientConfigVersion | null>(null)
  const [targetIds, setTargetIds] = useState<number[]>([])
  const [detail, setDetail] = useState<Publication | null>(null)
  const [saving, setSaving] = useState(false)
  const openPublish = (row: ClientConfigVersion) => { const enabled = targets.filter((target) => target.enabled); if (!enabled.length) return message.error('请先新增并启用至少一个云存储目标'); setTargetIds(enabled.filter((target) => target.is_primary).map((target) => target.id)); setPublishing(row) }
  const publish = async () => { if (!publishing || !targetIds.length) return message.error('请选择至少一个发布目标'); setSaving(true); try { await unwrap(publishClientConfig(publishing.id, targetIds)); message.success('发布任务已进入 default 队列'); setPublishing(null); await onReload() } finally { setSaving(false) } }
  const clone = async (row: ClientConfigVersion) => { const draft = await unwrap(cloneClientConfigVersion(row.id)); message.success('已创建新草稿'); onEdit(draft.id) }
  const drop = (row: ClientConfigVersion) => modal.confirm({ title: `删除草稿 v${row.config_version}？`, content: '删除后无法恢复。', okText: '删除', cancelText: '取消', okButtonProps: { danger: true }, onOk: async () => { await unwrap(dropClientConfigVersion(row.id)); message.success('草稿已删除'); await onReload() } })
  const retry = async (row: Publication) => { await unwrap(retryClientConfigPublication(row.id)); message.success('任务已重新进入队列'); setDetail(null); await onReload() }
  const versionColumns: TableColumnsType<ClientConfigVersion> = [
    { title: '版本', width: 100, render: (_, row) => <strong>v{row.config_version}</strong> }, { title: '说明', dataIndex: 'change_summary', render: (value) => value || '-' },
    { title: '格式', width: 150, render: (_, row) => MODE[row.encryption_mode] ?? row.encryption_mode }, { title: '状态', width: 110, render: (_, row) => <Tag color={STATUS[row.status]?.color}>{STATUS[row.status]?.label ?? row.status}</Tag> },
    { title: '发布时间', width: 180, render: (_, row) => dateTime(row.published_at) },
    { title: '操作', width: 300, fixed: 'right', render: (_, row) => <Space>{row.status === 'draft' ? <><Button type="link" icon={<EditOutlined />} onClick={() => onEdit(row.id)}>编辑</Button><Button type="link" danger icon={<DeleteOutlined />} onClick={() => drop(row)}>删除</Button></> : <Button type="link" icon={<SyncOutlined />} onClick={() => void clone(row)}>创建新草稿</Button>}<Button type="link" icon={<RocketOutlined />} disabled={row.status === 'publishing'} onClick={() => openPublish(row)}>发布</Button></Space> },
  ]
  const publicationColumns: TableColumnsType<Publication> = [
    { title: '版本', width: 90, render: (_, row) => `v${row.config?.config_version ?? row.config_id}` }, { title: '目标', width: 190, render: (_, row) => <div><strong>{row.target?.name ?? '已删除目标'}</strong><div><Typography.Text type="secondary">{row.target ? row.target.provider : '-'}</Typography.Text></div></div> },
    { title: '状态', width: 110, render: (_, row) => <Tag color={STATUS[row.status]?.color}>{STATUS[row.status]?.label ?? row.status}</Tag> }, { title: '尝试', dataIndex: 'attempts', width: 80 },
    { title: '完成时间', width: 180, render: (_, row) => dateTime(row.finished_at) }, { title: '结果', width: 280, render: (_, row) => row.error ? <Typography.Text type="danger" ellipsis={{ tooltip: row.error }}>{row.error}</Typography.Text> : row.public_url ? <Typography.Link href={row.public_url} target="_blank" title={row.public_url} ellipsis>{row.public_url}</Typography.Link> : '-' },
    { title: '操作', width: 210, fixed: 'right', render: (_, row) => <Space><Button type="link" icon={<EyeOutlined />} onClick={() => setDetail(row)}>详情</Button>{row.public_url && <Button type="link" icon={<CopyOutlined />} onClick={() => { copy(row.public_url || ''); message.success('链接已复制') }}>复制链接</Button>}{row.status === 'failed' && <Button type="link" onClick={() => void retry(row)}>重试</Button>}</Space> },
  ]
  const enabledTargets = targets.filter((target) => target.enabled)
  return (
    <Space direction="vertical" size="large" className="w-100">
      <Card title="配置版本"><Table rowKey="id" columns={versionColumns} dataSource={versions} pagination={false} scroll={{ x: 1050 }} /></Card>
      <Card title="发布流水"><Table rowKey="id" columns={publicationColumns} dataSource={publications} pagination={{ pageSize: 20, showSizeChanger: false }} scroll={{ x: 1200 }} /></Card>
      <Modal title={`发布配置 v${publishing?.config_version ?? ''}`} open={Boolean(publishing)} onCancel={() => setPublishing(null)} onOk={() => void publish()} confirmLoading={saving} okText="进入发布队列" cancelText="取消">
        <Alert className="mb-3" type="warning" showIcon message="系统会先上传不可变版本文件并校验，再替换客户端读取的固定地址。" />
        <Checkbox.Group value={targetIds} onChange={(values) => setTargetIds(values.map(Number))} className="catboard-checkbox-list">{enabledTargets.map((target) => <Checkbox key={target.id} value={target.id}><strong>{target.name}</strong><Typography.Text type="secondary"> · {target.public_url}</Typography.Text></Checkbox>)}</Checkbox.Group>
      </Modal>
      <Modal title="发布详情" open={Boolean(detail)} onCancel={() => setDetail(null)} footer={<Space>{detail?.public_url && <Button icon={<CopyOutlined />} onClick={() => { copy(detail.public_url || ''); message.success('链接已复制') }}>复制链接</Button>}{detail?.status === 'failed' && <Button type="primary" onClick={() => void retry(detail)}>重新发布</Button>}<Button onClick={() => setDetail(null)}>关闭</Button></Space>} width={760}>
        {detail && <><Descriptions bordered column={{ xs: 1, sm: 2 }}><Descriptions.Item label="配置版本">v{detail.config?.config_version ?? detail.config_id}</Descriptions.Item><Descriptions.Item label="发布目标">{detail.target?.name ?? '已删除目标'}</Descriptions.Item><Descriptions.Item label="状态">{STATUS[detail.status]?.label ?? detail.status}</Descriptions.Item><Descriptions.Item label="执行次数">{detail.attempts}</Descriptions.Item><Descriptions.Item label="对象路径">{detail.object_key}</Descriptions.Item><Descriptions.Item label="ETag">{detail.etag || '-'}</Descriptions.Item><Descriptions.Item label="开始时间">{dateTime(detail.started_at)}</Descriptions.Item><Descriptions.Item label="完成时间">{dateTime(detail.finished_at)}</Descriptions.Item><Descriptions.Item label="SHA-256" span={2}>{detail.checksum || '-'}</Descriptions.Item></Descriptions>{detail.error && <Alert className="mt-3" type="error" showIcon message="失败原因" description={detail.error} />}</>}
      </Modal>
    </Space>
  )
}
