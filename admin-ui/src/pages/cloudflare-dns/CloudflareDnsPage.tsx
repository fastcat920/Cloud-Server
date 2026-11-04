import {
    CloudOutlined,
    DeleteOutlined,
    EditOutlined,
    GlobalOutlined,
    PlusOutlined,
    ReloadOutlined,
    SafetyCertificateOutlined,
} from '@ant-design/icons'
import {
    Alert,
    Button,
    Card,
    Col,
    Empty,
    Form,
    Input,
    InputNumber,
    Modal,
    Row,
    Select,
    Space,
    Switch,
    Table,
    Tabs,
    Tag,
    Typography,
} from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { useCallback, useEffect, useState } from 'react'
import {
    dropCloudflareAccount,
    dropCloudflareRecord,
    fetchCloudflareOverview,
    fetchCloudflareRecords,
    fetchCloudflareZones,
    saveCloudflareAccount,
    saveCloudflareRecord,
    testCloudflareAccount,
    type CloudflareAccount,
    type CloudflareRecord,
    type CloudflareRecordInput,
    type CloudflareZone,
} from '@/api/services/cloudflareDns'
import { unwrap } from '@/api/request'
import { message, modal } from '@/app/staticApi'
import { AdminLayout } from '@/layouts/AdminLayout'

const RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'CAA', 'NS', 'SRV']
const PROXY_TYPES = ['A', 'AAAA', 'CNAME']
const TTL_OPTIONS = [
    { value: 1, label: '自动' },
    { value: 60, label: '1 分钟' },
    { value: 120, label: '2 分钟' },
    { value: 300, label: '5 分钟' },
    { value: 600, label: '10 分钟' },
    { value: 1800, label: '30 分钟' },
    { value: 3600, label: '1 小时' },
    { value: 7200, label: '2 小时' },
    { value: 18000, label: '5 小时' },
    { value: 43200, label: '12 小时' },
    { value: 86400, label: '1 天' },
]

function dateTime(value?: number | null) {
    return value ? new Date(value * 1000).toLocaleString('zh-CN', { hour12: false }) : '尚未测试'
}

function displayRecordContent(record: CloudflareRecord) {
    if (record.type === 'CAA')
        return `${record.data?.flags ?? 0} ${record.data?.tag ?? ''} ${record.data?.value ?? ''}`.trim()
    if (record.type === 'SRV') {
        const data = record.data ?? {}
        return `${data.priority ?? 0} ${data.weight ?? 0} ${data.port ?? 0} ${data.target ?? ''}`.trim()
    }
    return record.content
}

function recordNamePrefix(name: string | undefined, zoneName: string) {
    const normalizedName = (name ?? '').trim().replace(/\.$/, '')
    const normalizedZone = zoneName.trim().replace(/\.$/, '')
    if (!normalizedName || normalizedName.toLowerCase() === normalizedZone.toLowerCase()) return '@'
    const suffix = `.${normalizedZone.toLowerCase()}`
    return normalizedName.toLowerCase().endsWith(suffix) ? normalizedName.slice(0, -suffix.length) : normalizedName
}

function completeRecordName(prefix: string, zoneName: string) {
    const normalizedPrefix = prefix.trim().replace(/\.$/, '')
    const normalizedZone = zoneName.trim().replace(/\.$/, '')
    if (!normalizedPrefix || normalizedPrefix === '@') return normalizedZone
    const suffix = `.${normalizedZone.toLowerCase()}`
    if (
        normalizedPrefix.toLowerCase() === normalizedZone.toLowerCase() ||
        normalizedPrefix.toLowerCase().endsWith(suffix)
    ) {
        return normalizedPrefix
    }
    return `${normalizedPrefix}.${normalizedZone}`
}

interface AccountEditorProps {
    open: boolean
    account: CloudflareAccount | null
    onClose: () => void
    onSaved: () => void
}

function AccountEditor({ open, account, onClose, onSaved }: AccountEditorProps) {
    const [form] = Form.useForm()
    const [saving, setSaving] = useState(false)

    useEffect(() => {
        if (!open) return
        form.setFieldsValue({
            name: account?.name ?? '',
            api_token: '',
            enabled: account?.enabled ?? true,
            is_default: account?.is_default ?? false,
        })
    }, [account, form, open])

    const submit = async () => {
        const values = await form.validateFields()
        setSaving(true)
        try {
            const saved = await unwrap(saveCloudflareAccount({ ...values, id: account?.id }))
            try {
                const result = await unwrap(testCloudflareAccount(saved.id))
                message.success(`账号已保存，已读取 ${result.zone_count} 个授权域名`)
            } catch {
                message.error('账号已保存，但连接测试未通过，请检查 Token 权限')
            }
            onClose()
            onSaved()
        } finally {
            setSaving(false)
        }
    }

    return (
        <Modal
            title={account ? '编辑 Cloudflare 账号' : '添加 Cloudflare 账号'}
            open={open}
            onCancel={onClose}
            onOk={() => void submit()}
            confirmLoading={saving}
            okText="保存并测试"
            cancelText="取消"
            destroyOnHidden
            width={680}
        >
            <Alert
                showIcon
                type="info"
                icon={<SafetyCertificateOutlined />}
                message="请使用受限 API Token"
                description="Token 至少需要 Zone / Zone / Read 与 Zone / DNS / Edit 权限。Token 只会加密保存在服务器中，编辑时留空表示保持原值。"
                className="mb-3"
            />
            <Form form={form} layout="vertical" requiredMark={false}>
                <Form.Item name="name" label="账号名称" rules={[{ required: true, message: '请输入账号名称' }]}>
                    <Input placeholder="例如：Cloudflare 主账号" autoFocus />
                </Form.Item>
                <Form.Item
                    name="api_token"
                    label="API Token"
                    rules={account ? [] : [{ required: true, message: '请输入 API Token' }]}
                >
                    <Input.Password
                        autoComplete="new-password"
                        placeholder={
                            account?.has_api_token ? '已安全保存，留空保持不变' : '请输入 Cloudflare API Token'
                        }
                    />
                </Form.Item>
                <Space size="large">
                    <Form.Item name="enabled" valuePropName="checked" label="启用账号">
                        <Switch checkedChildren="启用" unCheckedChildren="停用" />
                    </Form.Item>
                    <Form.Item name="is_default" valuePropName="checked" label="设为默认">
                        <Switch checkedChildren="默认" unCheckedChildren="普通" />
                    </Form.Item>
                </Space>
            </Form>
        </Modal>
    )
}

interface RecordEditorProps {
    open: boolean
    accountId: number
    zone: CloudflareZone
    record: CloudflareRecord | null
    onClose: () => void
    onSaved: () => void
}

function RecordEditor({ open, accountId, zone, record, onClose, onSaved }: RecordEditorProps) {
    const [form] = Form.useForm<CloudflareRecordInput>()
    const [saving, setSaving] = useState(false)
    const type = Form.useWatch('type', form) ?? record?.type ?? 'A'

    useEffect(() => {
        if (!open) return
        form.setFieldsValue({
            type: record?.type ?? 'A',
            name: recordNamePrefix(record?.name, zone.name),
            content: record?.content ?? '',
            ttl: record?.ttl ?? 1,
            proxied: record?.proxied ?? false,
            priority: record?.priority ?? 10,
            comment: record?.comment ?? '',
            data: {
                flags: record?.data?.flags ?? 0,
                tag: record?.data?.tag ?? 'issue',
                value: record?.data?.value ?? '',
                priority: record?.data?.priority ?? 0,
                weight: record?.data?.weight ?? 0,
                port: record?.data?.port ?? 443,
                target: record?.data?.target ?? '',
            },
        })
    }, [form, open, record, zone.name])

    const submit = async () => {
        const values = await form.validateFields()
        setSaving(true)
        try {
            await unwrap(
                saveCloudflareRecord({
                    ...values,
                    name: completeRecordName(values.name, zone.name),
                    account_id: accountId,
                    zone_id: zone.id,
                    record_id: record?.id,
                }),
            )
            message.success(record ? 'DNS 记录已更新' : 'DNS 记录已添加')
            onClose()
            onSaved()
        } finally {
            setSaving(false)
        }
    }

    const contentLabel = type === 'TXT' ? '文本内容' : type === 'MX' ? '邮件服务器' : '记录内容'

    return (
        <Modal
            title={record ? '编辑 DNS 记录' : '添加 DNS 记录'}
            open={open}
            onCancel={onClose}
            onOk={() => void submit()}
            confirmLoading={saving}
            okText="保存"
            cancelText="取消"
            destroyOnHidden
            width={720}
        >
            <Form form={form} layout="vertical" requiredMark={false}>
                <Row gutter={16}>
                    <Col xs={24} md={8}>
                        <Form.Item name="type" label="记录类型" rules={[{ required: true }]}>
                            <Select options={RECORD_TYPES.map((value) => ({ value, label: value }))} />
                        </Form.Item>
                    </Col>
                    <Col xs={24} md={16}>
                        <Form.Item
                            name="name"
                            label="记录名称"
                            extra="根域名填写 @，保存时会自动拼接完整域名"
                            rules={[{ required: true, message: '请输入记录前缀' }]}
                        >
                            <Input
                                addonAfter={`.${zone.name}`}
                                placeholder={type === 'SRV' ? '例如：_sip._tcp' : '例如：www'}
                            />
                        </Form.Item>
                    </Col>
                </Row>

                {type === 'CAA' ? (
                    <Row gutter={16}>
                        <Col xs={8}>
                            <Form.Item name={['data', 'flags']} label="标志">
                                <InputNumber min={0} max={255} className="w-100" />
                            </Form.Item>
                        </Col>
                        <Col xs={16} md={8}>
                            <Form.Item name={['data', 'tag']} label="标签" rules={[{ required: true }]}>
                                <Select
                                    options={['issue', 'issuewild', 'iodef'].map((value) => ({ value, label: value }))}
                                />
                            </Form.Item>
                        </Col>
                        <Col xs={24} md={8}>
                            <Form.Item
                                name={['data', 'value']}
                                label="值"
                                rules={[{ required: true, message: '请输入 CAA 值' }]}
                            >
                                <Input placeholder="letsencrypt.org" />
                            </Form.Item>
                        </Col>
                    </Row>
                ) : type === 'SRV' ? (
                <Row gutter={16}>
                    <Col xs={8}>
                        <Form.Item name={['data', 'priority']} label="优先级">
                                <InputNumber min={0} max={65535} className="w-100" />
                            </Form.Item>
                        </Col>
                    <Col xs={8}>
                            <Form.Item name={['data', 'weight']} label="权重">
                                <InputNumber min={0} max={65535} className="w-100" />
                            </Form.Item>
                        </Col>
                    <Col xs={8}>
                            <Form.Item name={['data', 'port']} label="端口" rules={[{ required: true }]}>
                                <InputNumber min={0} max={65535} className="w-100" />
                            </Form.Item>
                        </Col>
                        <Col span={24}>
                            <Form.Item name={['data', 'target']} label="目标主机" rules={[{ required: true }]}>
                                <Input placeholder={`例如：server.${zone.name}`} />
                            </Form.Item>
                        </Col>
                    </Row>
                ) : (
                    <Form.Item
                        name="content"
                        label={contentLabel}
                        rules={[{ required: true, message: `请输入${contentLabel}` }]}
                    >
                        <Input.TextArea
                            rows={type === 'TXT' ? 3 : 1}
                            placeholder={type === 'A' ? '例如：192.0.2.1' : undefined}
                        />
                    </Form.Item>
                )}

                <Row gutter={16}>
                    {type === 'MX' && (
                        <Col xs={24} md={8}>
                            <Form.Item name="priority" label="优先级" rules={[{ required: true }]}>
                                <InputNumber min={0} max={65535} className="w-100" />
                            </Form.Item>
                        </Col>
                    )}
                    <Col xs={24} md={type === 'MX' ? 8 : 12}>
                        <Form.Item name="ttl" label="TTL" rules={[{ required: true }]}>
                            <Select options={TTL_OPTIONS} />
                        </Form.Item>
                    </Col>
                    {PROXY_TYPES.includes(type) && (
                        <Col xs={24} md={type === 'MX' ? 8 : 12}>
                            <Form.Item name="proxied" valuePropName="checked" label="Cloudflare 代理">
                                <Switch checkedChildren="已代理" unCheckedChildren="仅 DNS" />
                            </Form.Item>
                        </Col>
                    )}
                </Row>
                <Form.Item name="comment" label="备注（可选）">
                    <Input maxLength={500} placeholder="仅用于后台管理，不会影响解析" />
                </Form.Item>
            </Form>
        </Modal>
    )
}

export default function CloudflareDnsPage() {
    const [tab, setTab] = useState('records')
    const [accounts, setAccounts] = useState<CloudflareAccount[]>([])
    const [accountId, setAccountId] = useState<number>()
    const [zones, setZones] = useState<CloudflareZone[]>([])
    const [zoneId, setZoneId] = useState<string>()
    const [records, setRecords] = useState<CloudflareRecord[]>([])
    const [loading, setLoading] = useState(true)
    const [zoneLoading, setZoneLoading] = useState(false)
    const [recordLoading, setRecordLoading] = useState(false)
    const [page, setPage] = useState(1)
    const [total, setTotal] = useState(0)
    const [typeFilter, setTypeFilter] = useState<string>()
    const [nameFilter, setNameFilter] = useState('')
    const [accountEditorOpen, setAccountEditorOpen] = useState(false)
    const [editingAccount, setEditingAccount] = useState<CloudflareAccount | null>(null)
    const [recordEditorOpen, setRecordEditorOpen] = useState(false)
    const [editingRecord, setEditingRecord] = useState<CloudflareRecord | null>(null)

    const selectedZone = zones.find((zone) => zone.id === zoneId)

    const loadOverview = useCallback(async () => {
        setLoading(true)
        try {
            const data = await unwrap(fetchCloudflareOverview())
            setAccounts(data.accounts)
            setAccountId((current) => {
                if (current && data.accounts.some((account) => account.id === current && account.enabled))
                    return current
                return (
                    data.accounts.find((account) => account.is_default && account.enabled)?.id ??
                    data.accounts.find((account) => account.enabled)?.id
                )
            })
        } finally {
            setLoading(false)
        }
    }, [])

    const loadZones = useCallback(async (selectedAccountId: number) => {
        setZoneLoading(true)
        try {
            const data = await unwrap(fetchCloudflareZones(selectedAccountId))
            setZones(data.items)
            setZoneId((current) =>
                current && data.items.some((zone) => zone.id === current) ? current : data.items[0]?.id,
            )
        } finally {
            setZoneLoading(false)
        }
    }, [])

    // oxlint-disable react/memo-dependencies -- 账号、域名、分页与筛选条件都是 DNS 请求参数，改变任一项都需重新请求。
    const loadRecords = useCallback(async () => {
        if (!accountId || !zoneId) return
        setRecordLoading(true)
        try {
            const data = await unwrap(
                fetchCloudflareRecords({
                    account_id: accountId,
                    zone_id: zoneId,
                    page,
                    per_page: 20,
                    type: typeFilter,
                    name: nameFilter || undefined,
                }),
            )
            setRecords(data.items)
            setTotal(data.total)
        } finally {
            setRecordLoading(false)
        }
    }, [accountId, nameFilter, page, typeFilter, zoneId])
    // oxlint-enable react/memo-dependencies

    useEffect(() => {
        const timer = window.setTimeout(() => void loadOverview(), 0)
        return () => window.clearTimeout(timer)
    }, [loadOverview])

    useEffect(() => {
        const timer = window.setTimeout(() => {
            if (!accountId) {
                setZones([])
                setZoneId(undefined)
                return
            }
            void loadZones(accountId)
        }, 0)
        return () => window.clearTimeout(timer)
    }, [accountId, loadZones])

    useEffect(() => {
        const timer = window.setTimeout(() => {
            if (!accountId || !zoneId) {
                setRecords([])
                return
            }
            void loadRecords()
        }, 0)
        return () => window.clearTimeout(timer)
    }, [accountId, loadRecords, zoneId])

    const testAccount = async (account: CloudflareAccount) => {
        const result = await unwrap(testCloudflareAccount(account.id))
        message.success(`连接正常，可管理 ${result.zone_count} 个域名`)
        await loadOverview()
    }

    const removeAccount = (account: CloudflareAccount) => {
        modal.confirm({
            title: `删除账号“${account.name}”？`,
            content: '只会删除本地保存的 Token，不会删除 Cloudflare 上的域名或 DNS 记录。',
            okText: '删除',
            okButtonProps: { danger: true },
            cancelText: '取消',
            onOk: async () => {
                await unwrap(dropCloudflareAccount(account.id))
                message.success('账号已删除')
                await loadOverview()
            },
        })
    }

    const removeRecord = (record: CloudflareRecord) => {
        if (!accountId || !zoneId) return
        modal.confirm({
            title: `删除 ${record.type} 记录？`,
            content: `${record.name} → ${displayRecordContent(record)}`,
            okText: '删除',
            okButtonProps: { danger: true },
            cancelText: '取消',
            onOk: async () => {
                await unwrap(dropCloudflareRecord(accountId, zoneId, record.id))
                message.success('DNS 记录已删除')
                await loadRecords()
            },
        })
    }

    const columns: ColumnsType<CloudflareRecord> = [
        { title: '类型', dataIndex: 'type', width: 88, render: (value: string) => <Tag color="blue">{value}</Tag> },
        { title: '名称', dataIndex: 'name', ellipsis: true, width: 260 },
        {
            title: '内容',
            key: 'content',
            ellipsis: true,
            render: (_, record) => <Typography.Text code>{displayRecordContent(record)}</Typography.Text>,
        },
        {
            title: '代理状态',
            key: 'proxy',
            width: 126,
            render: (_, record) =>
                record.proxiable ? (
                    <Tag color={record.proxied ? 'orange' : 'default'}>{record.proxied ? '已代理' : '仅 DNS'}</Tag>
                ) : (
                    <Typography.Text type="secondary">不支持</Typography.Text>
                ),
        },
        {
            title: 'TTL',
            dataIndex: 'ttl',
            width: 100,
            render: (value: number) => (value === 1 ? '自动' : `${value} 秒`),
        },
        {
            title: '操作',
            key: 'actions',
            width: 140,
            fixed: 'right',
            render: (_, record) => (
                <Space>
                    <Button
                        type="link"
                        size="small"
                        icon={<EditOutlined />}
                        onClick={() => {
                            setEditingRecord(record)
                            setRecordEditorOpen(true)
                        }}
                    >
                        编辑
                    </Button>
                    <Button
                        type="link"
                        size="small"
                        danger
                        icon={<DeleteOutlined />}
                        onClick={() => removeRecord(record)}
                    >
                        删除
                    </Button>
                </Space>
            ),
        },
    ]

    const accountPanel = (
        <>
            <div className="catboard-section-actions">
                <Typography.Text type="secondary">
                    用于访问 Cloudflare API 的 Token 会加密保存，页面不会返回明文。
                </Typography.Text>
                <Button
                    type="primary"
                    icon={<PlusOutlined />}
                    onClick={() => {
                        setEditingAccount(null)
                        setAccountEditorOpen(true)
                    }}
                >
                    添加账号
                </Button>
            </div>
            {accounts.length === 0 && !loading ? (
                <Empty description="还没有 Cloudflare API Token" />
            ) : (
                <Row gutter={[16, 16]}>
                    {accounts.map((account) => (
                        <Col xs={24} lg={12} key={account.id}>
                            <Card
                                loading={loading}
                                className="catboard-account-card"
                                title={
                                    <Space wrap>
                                        <span className="catboard-provider is-cloudflare">CF</span>
                                        <span>{account.name}</span>
                                        {account.is_default && <Tag color="blue">默认</Tag>}
                                        <Tag color={account.enabled ? 'success' : 'default'}>
                                            {account.enabled ? '已启用' : '已停用'}
                                        </Tag>
                                    </Space>
                                }
                                actions={[
                                    <Button
                                        type="text"
                                        icon={<ReloadOutlined />}
                                        onClick={() => void testAccount(account)}
                                        key="test"
                                    >
                                        测试
                                    </Button>,
                                    <Button
                                        type="text"
                                        icon={<EditOutlined />}
                                        onClick={() => {
                                            setEditingAccount(account)
                                            setAccountEditorOpen(true)
                                        }}
                                        key="edit"
                                    >
                                        编辑
                                    </Button>,
                                    <Button
                                        type="text"
                                        danger
                                        icon={<DeleteOutlined />}
                                        onClick={() => removeAccount(account)}
                                        key="drop"
                                    >
                                        删除
                                    </Button>,
                                ]}
                            >
                                <Space direction="vertical" size={4}>
                                    <Typography.Text>API Token：{account.token_masked}</Typography.Text>
                                    <Typography.Text type="secondary">
                                        最近连接：{dateTime(account.last_connected_at)}
                                    </Typography.Text>
                                    {account.last_error && (
                                        <Typography.Text type="danger">{account.last_error}</Typography.Text>
                                    )}
                                </Space>
                            </Card>
                        </Col>
                    ))}
                </Row>
            )}
        </>
    )

    const recordsPanel =
        accounts.length === 0 && !loading ? (
            <Empty description="请先添加 Cloudflare API Token">
                <Button type="primary" onClick={() => setTab('accounts')}>
                    配置账号
                </Button>
            </Empty>
        ) : (
            <>
                <Card className="cloudflare-selector-card">
                    <Row gutter={[12, 12]} align="middle">
                        <Col xs={24} md={9}>
                            <Select
                                value={accountId}
                                onChange={(value) => {
                                    setAccountId(value)
                                    setZoneId(undefined)
                                    setRecords([])
                                    setPage(1)
                                }}
                                className="w-100"
                                placeholder="选择 Cloudflare 账号"
                                options={accounts
                                    .filter((account) => account.enabled)
                                    .map((account) => ({ value: account.id, label: account.name }))}
                            />
                        </Col>
                        <Col xs={24} md={11}>
                            <Select
                                showSearch
                                optionFilterProp="label"
                                value={zoneId}
                                loading={zoneLoading}
                                onChange={(value) => {
                                    setZoneId(value)
                                    setPage(1)
                                }}
                                className="w-100"
                                placeholder="选择授权域名"
                                options={zones.map((zone) => ({ value: zone.id, label: zone.name }))}
                            />
                        </Col>
                        <Col xs={24} md={4}>
                            <Button
                                block
                                icon={<ReloadOutlined />}
                                disabled={!accountId}
                                onClick={() => accountId && void loadZones(accountId)}
                            >
                                刷新域名
                            </Button>
                        </Col>
                    </Row>
                </Card>

                {selectedZone ? (
                    <>
                        <Card className="cloudflare-zone-card">
                            <div>
                                <Space wrap>
                                    <GlobalOutlined />
                                    <Typography.Title level={4}>{selectedZone.name}</Typography.Title>
                                    <Tag
                                        color={
                                            selectedZone.status === 'active' && !selectedZone.paused
                                                ? 'success'
                                                : 'warning'
                                        }
                                    >
                                        {selectedZone.paused
                                            ? '已暂停'
                                            : selectedZone.status === 'active'
                                              ? '正常'
                                              : selectedZone.status}
                                    </Tag>
                                    {selectedZone.plan_name && <Tag>{selectedZone.plan_name}</Tag>}
                                </Space>
                                <Typography.Text type="secondary">
                                    NS：{selectedZone.name_servers.join('、') || '--'}
                                </Typography.Text>
                            </div>
                            <Button
                                type="primary"
                                icon={<PlusOutlined />}
                                onClick={() => {
                                    setEditingRecord(null)
                                    setRecordEditorOpen(true)
                                }}
                            >
                                添加记录
                            </Button>
                        </Card>
                        <Card>
                            <div className="cloudflare-record-toolbar">
                                <Select
                                    allowClear
                                    placeholder="全部类型"
                                    value={typeFilter}
                                    onChange={(value) => {
                                        setTypeFilter(value)
                                        setPage(1)
                                    }}
                                    options={RECORD_TYPES.map((value) => ({ value, label: value }))}
                                />
                                <Input.Search
                                    allowClear
                                    placeholder="输入完整记录名称查询"
                                    onSearch={(value) => {
                                        setNameFilter(value.trim())
                                        setPage(1)
                                    }}
                                />
                                <Button icon={<ReloadOutlined />} onClick={() => void loadRecords()}>
                                    刷新
                                </Button>
                            </div>
                            <Table
                                rowKey="id"
                                columns={columns}
                                dataSource={records}
                                loading={recordLoading}
                                scroll={{ x: 1000 }}
                                pagination={{
                                    current: page,
                                    pageSize: 20,
                                    total,
                                    showSizeChanger: false,
                                    showTotal: (count) => `共 ${count} 条`,
                                    onChange: setPage,
                                }}
                                locale={{
                                    emptyText: (
                                        <Empty description="暂无 DNS 记录" image={Empty.PRESENTED_IMAGE_SIMPLE} />
                                    ),
                                }}
                            />
                        </Card>
                    </>
                ) : (
                    !zoneLoading && (
                        <Card>
                            <Empty description="当前 Token 没有可管理的域名，请检查 Zone Read 权限与 Token 资源范围" />
                        </Card>
                    )
                )}
            </>
        )

    return (
        <AdminLayout>
            <div className="catboard-page">
                <div className="catboard-page-heading">
                    <div>
                        <h2>Cloudflare 解析</h2>
                        <p>集中管理授权域名与 DNS 记录，无需在日常操作时登录 Cloudflare 控制台</p>
                    </div>
                    <CloudOutlined className="catboard-page-heading-icon" />
                </div>
                <Card>
                    <Tabs
                        activeKey={tab}
                        onChange={setTab}
                        items={[
                            { key: 'records', label: '域名解析', children: recordsPanel },
                            { key: 'accounts', label: 'API Token', children: accountPanel },
                        ]}
                    />
                </Card>
            </div>
            <AccountEditor
                open={accountEditorOpen}
                account={editingAccount}
                onClose={() => setAccountEditorOpen(false)}
                onSaved={() => void loadOverview()}
            />
            {selectedZone && accountId && (
                <RecordEditor
                    open={recordEditorOpen}
                    accountId={accountId}
                    zone={selectedZone}
                    record={editingRecord}
                    onClose={() => setRecordEditorOpen(false)}
                    onSaved={() => void loadRecords()}
                />
            )}
        </AdminLayout>
    )
}
