import { CloudResources } from './CloudResources'
import {
  CloudOutlined,
  CopyOutlined,
  DeleteOutlined,
  EditOutlined,
  PlusOutlined,
  ReloadOutlined,
  SafetyCertificateOutlined,
} from '@ant-design/icons'
import { Alert, Button, Card, Col, Empty, Form, Input, Modal, Row, Select, Space, Switch, Tag, Typography } from 'antd'
import { useCallback, useEffect, useState } from 'react'
import {
  dropCloudAccount,
  fetchCloudAccounts,
  refreshAllCloudAccounts,
  refreshCloudAccount,
  saveCloudAccount,
  type CloudAccount,
  type CloudAccountInput,
  type CloudHealth,
  type CloudProvider,
} from '@/api/services/cloudAccount'
import { unwrap } from '@/api/request'
import { message, modal } from '@/app/staticApi'
import { AdminLayout } from '@/layouts/AdminLayout'
import { copyText } from '@/utils/clipboard'

const PROVIDERS: Record<CloudProvider, string> = { aliyun: '阿里云', tencent: '腾讯云', ucloud: 'UCloud' }
const SITES = { china: '国内站', international: '国际站' } as const
const KEY_LABELS: Record<CloudProvider, [string, string]> = {
  aliyun: ['AccessKey ID', 'AccessKey Secret'],
  tencent: ['SecretId', 'SecretKey'],
  ucloud: ['公钥', '私钥'],
}
const HEALTH: Record<CloudHealth, { label: string; color: string }> = {
  healthy: { label: '余额充足', color: 'success' },
  low: { label: '余额偏低', color: 'warning' },
  recharge: { label: '建议充值', color: 'error' },
  error: { label: '账单查询失败', color: 'error' },
  unknown: { label: '等待同步', color: 'default' },
  disabled: { label: '已停用', color: 'default' },
}

function money(value: number | null, currency: string) {
  if (value === null) return '--'
  const symbol = { CNY: '¥', USD: '$', JPY: '¥' }[currency.toUpperCase()] ?? `${currency} `
  return `${symbol}${value.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function dateTime(value: number | null) {
  return value ? new Date(value * 1000).toLocaleString('zh-CN', { hour12: false }) : '尚未同步'
}

interface EditorProps {
  account: CloudAccount | null
  open: boolean
  onClose: () => void
  onSaved: () => void
}

function AccountEditor({ account, open, onClose, onSaved }: EditorProps) {
  const [form] = Form.useForm<CloudAccountInput>()
  const [saving, setSaving] = useState(false)
  const provider = Form.useWatch('provider', form) ?? account?.provider ?? 'aliyun'
  const labels = KEY_LABELS[provider]
  const sameProvider = account?.provider === provider

  useEffect(() => {
    if (!open) return
    form.setFieldsValue({
      name: account?.name ?? '',
      account_id: account?.account_id ?? '',
      provider: account?.provider ?? 'aliyun',
      site: account?.site ?? 'china',
      enabled: account?.enabled ?? true,
      access_key_id: '',
      access_key_secret: '',
      security_token: '',
    })
  }, [account, form, open])

  const submit = async () => {
    const values = await form.validateFields()
    setSaving(true)
    try {
      const saved = await unwrap(saveCloudAccount({ ...values, id: account?.id }))
      message.success(saved.last_error ? '账号已保存；账单查询错误不影响其他功能' : '账号已保存')
      onClose()
      onSaved()
    } finally {
      setSaving(false)
    }
  }

  const stored = (kind: 'id' | 'secret' | 'token') => {
    if (!sameProvider) return false
    if (kind === 'id') return account?.has_access_key
    if (kind === 'secret') return account?.has_secret_key
    return account?.has_security_token
  }

  return (
    <Modal
      title={account ? '编辑云账号' : '添加云账号'}
      open={open}
      onCancel={onClose}
      onOk={() => void submit()}
      confirmLoading={saving}
      okText="保存账号"
      cancelText="取消"
      destroyOnHidden
      width={720}
    >
      <Typography.Paragraph type="secondary">访问密钥仅加密保存在服务器中，编辑时留空表示保持原值。</Typography.Paragraph>
      <Form form={form} layout="vertical" requiredMark={false}>
        <Row gutter={16}>
          <Col xs={24} md={12}>
            <Form.Item name="name" label="账号名称" rules={[{ required: true, message: '请输入账号名称' }]}>
              <Input placeholder="例如：阿里云主账号" autoFocus />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="provider" label="云服务商" rules={[{ required: true }]}>
              <Select options={Object.entries(PROVIDERS).map(([value, label]) => ({ value, label }))} />
            </Form.Item>
          </Col>
          <Col span={24}>
            <Form.Item name="site" label="账号站点" rules={[{ required: true }]}>
              <Select options={Object.entries(SITES).map(([value, label]) => ({ value, label }))} />
            </Form.Item>
          </Col>
          <Col span={24}>
            <Form.Item
              name="account_id"
              label="账号 ID"
              extra={provider === 'ucloud' ? 'UCloud 暂不提供调用者身份接口，请填写控制台显示的账号 ID。' : '保存或刷新时通过云厂商身份接口自动获取。'}
            >
              <Input
                disabled={provider !== 'ucloud'}
                maxLength={191}
                placeholder={provider === 'ucloud' ? '请输入 UCloud 控制台显示的账号 ID' : '保存后自动获取'}
              />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="access_key_id" label={labels[0]}>
              <Input.Password autoComplete="new-password" placeholder={stored('id') ? '已安全保存，留空保持不变' : `请输入${labels[0]}`} />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="access_key_secret" label={labels[1]}>
              <Input.Password autoComplete="new-password" placeholder={stored('secret') ? '已安全保存，留空保持不变' : `请输入${labels[1]}`} />
            </Form.Item>
          </Col>
          {provider !== 'ucloud' && (
            <Col span={24}>
              <Form.Item name="security_token" label="临时安全令牌（可选）">
                <Input.Password autoComplete="new-password" placeholder={stored('token') ? '已安全保存，留空保持不变' : '使用临时凭证时填写'} />
              </Form.Item>
            </Col>
          )}
        </Row>
        <Alert
          showIcon
          icon={<SafetyCertificateOutlined />}
          type="info"
          message="建议使用只读子账号密钥"
          description={{
            aliyun: '需要 AliyunBSSReadOnlyAccess，或余额和账单查询的只读权限。',
            tencent: '需要 QcloudFinanceReadOnlyAccess，至少允许余额与账单概览查询。',
            ucloud: '需要 GetBalance 与 ListUBillOverview 的只读权限。',
          }[provider]}
        />
        <Form.Item name="enabled" valuePropName="checked" className="mt-3 mb-0">
          <Switch checkedChildren="已启用" unCheckedChildren="已停用" />
        </Form.Item>
      </Form>
    </Modal>
  )
}

export default function CloudAccountPage() {
  const [resources,setResources] = useState<CloudAccount | null>(null)
  const [accounts, setAccounts] = useState<CloudAccount[]>([])
  const [periods, setPeriods] = useState({ current: '', previous: '' })
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState<number | 'all' | null>(null)
  const [editorOpen, setEditorOpen] = useState(false)
  const [editing, setEditing] = useState<CloudAccount | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await unwrap(fetchCloudAccounts())
      setAccounts(data.accounts)
      setPeriods(data.periods)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => void load(), 0)
    return () => window.clearTimeout(timer)
  }, [load])

  const refreshOne = async (account: CloudAccount) => {
    if (!account.enabled) return message.error('请先启用该账号')
    setRefreshing(account.id)
    try {
      await unwrap(refreshCloudAccount(account.id))
      message.success('账号数据已更新')
      await load()
    } finally {
      setRefreshing(null)
    }
  }

  const refreshAll = async () => {
    setRefreshing('all')
    try {
      const data = await unwrap(refreshAllCloudAccounts())
      const failed = data.accounts.filter((account) => account.last_error).length
      if (failed) message.error(`同步完成，${failed} 个账号失败`)
      else message.success('所有账号已更新')
      await load()
    } finally {
      setRefreshing(null)
    }
  }

  const remove = (account: CloudAccount) => {
    modal.confirm({
      title: `删除云账号“${account.name}”？`,
      content: '已同步的余额与账单数据也会一并删除。',
      okText: '删除',
      okButtonProps: { danger: true },
      cancelText: '取消',
      onOk: async () => {
        await unwrap(dropCloudAccount(account.id))
        message.success('云账号已删除')
        await load()
      },
    })
  }

  const cards = accounts.map((account) => {
        const health = HEALTH[account.health]
        return (
          <Col xs={24} xl={12} key={account.id}>
            <Card
              className="catboard-account-card"
              loading={loading}
              title={
                <div className="catboard-account-heading">
                  <span className={`catboard-provider is-${account.provider}`}>{PROVIDERS[account.provider].slice(0, 1)}</span>
                  <div className="catboard-account-identity">
                    <Space size={8} wrap>
                      <span>{account.name}</span>
                      <Tag>{PROVIDERS[account.provider]}</Tag>
                      <Tag color={account.site === 'international' ? 'blue' : 'default'}>{SITES[account.site]}</Tag>
                    </Space>
                    <div className="catboard-account-id">
                      <Typography.Text type="secondary">账号 ID：</Typography.Text>
                      <code>{account.account_id || '未填写'}</code>
                      <Button
                        type="link"
                        size="small"
                        icon={<CopyOutlined />}
                        disabled={!account.account_id}
                        onClick={() => copyText(account.account_id || '')}
                      >
                        复制
                      </Button>
                    </div>
                  </div>
                </div>
              }
              extra={<Tag color={health.color}>{health.label}</Tag>}
              actions={[
                <Button type="text" disabled={!account.enabled} icon={<CloudOutlined />} onClick={() => setResources(account)} key="resources">资源</Button>,
                <Button type="text" icon={<ReloadOutlined />} loading={refreshing === account.id} onClick={() => void refreshOne(account)} key="refresh">刷新</Button>,
                <Button type="text" icon={<EditOutlined />} onClick={() => { setEditing(account); setEditorOpen(true) }} key="edit">编辑</Button>,
                <Button type="text" danger icon={<DeleteOutlined />} onClick={() => remove(account)} key="drop">删除</Button>,
              ]}
            >
              <Typography.Text type="secondary">更新于 {dateTime(account.last_synced_at)}</Typography.Text>
              <Row gutter={[12, 12]} className="catboard-account-metrics">
                <Col span={8}><div><span>账户余额</span><strong>{money(account.balance, account.currency)}</strong></div></Col>
                <Col span={8}><div><span>{periods.previous || '上月'}账单</span><strong>{money(account.previous_month_amount, account.currency)}</strong></div></Col>
                <Col span={8}><div><span>{periods.current || '本月'}账单</span><strong>{money(account.current_month_amount, account.currency)}</strong></div></Col>
              </Row>
              {account.coverage_months !== null && <Typography.Text type="secondary">约可覆盖 {account.coverage_months} 个月</Typography.Text>}
              {account.last_error && <Alert className="mt-3" type="error" showIcon message={account.last_error} />}
            </Card>
          </Col>
        )
      })

  return (
    <AdminLayout title="云账号管理">
      <div className="catboard-page">
        <div className="catboard-page-heading">
          <div><h2>云账号管理</h2><p>统一管理云凭证、余额、服务器和对象存储</p></div>
          <Space wrap>
            <Button icon={<ReloadOutlined />} loading={refreshing === 'all'} onClick={() => void refreshAll()}>刷新全部</Button>
            <Button type="primary" icon={<PlusOutlined />} onClick={() => { setEditing(null); setEditorOpen(true) }}>添加账号</Button>
          </Space>
        </div>
        <Alert className="mb-3" type="info" showIcon message="账单金额使用各云厂商的折后应付口径；本月数据可能存在延迟，仅用于充值判断。" />
        {accounts.length || loading ? <Row gutter={[16, 16]}>{cards}</Row> : <Card><Empty image={<CloudOutlined />} description="还没有云账号"><Button type="primary" onClick={() => setEditorOpen(true)}>添加第一个账号</Button></Empty></Card>}
      </div>
      <CloudResources account={resources} onClose={() => setResources(null)} />
      <AccountEditor account={editing} open={editorOpen} onClose={() => setEditorOpen(false)} onSaved={() => void load()} />
    </AdminLayout>
  )
}
