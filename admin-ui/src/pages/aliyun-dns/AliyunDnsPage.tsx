import { CloudAccountSelect } from '@/components/CloudAccountSelect'
import {
    ApartmentOutlined,
    CloseOutlined,
    CloudServerOutlined,
    DeleteOutlined,
    EditOutlined,
    GlobalOutlined,
    LinkOutlined,
    MinusCircleOutlined,
    OrderedListOutlined,
    PlusOutlined,
    ReloadOutlined,
    ThunderboltOutlined,
} from '@ant-design/icons'
import {
    Alert,
    Button,
    Card,
    Col,
    Descriptions,
    Drawer,
    Empty,
    Form,
    Input,
    InputNumber,
    Modal,
    Radio,
    Row,
    Select,
    Space,
    Switch,
    Table,
    Tabs,
    Tag,
    Typography,
    type TableColumnsType,
} from 'antd'
import dayjs from 'dayjs'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router'
import {
    attachDnsAddress,
    attachDnsPool,
    dropDnsAccount,
    dropDnsAddress,
    dropDnsPool,
    fetchDnsInstances,
    fetchDnsMonitorTemplates,
    fetchDnsOverview,
    fetchDnsResources,
    fetchDnsTopology,
    saveDnsAccount,
    saveDnsAddress,
    saveDnsOrder,
    saveDnsPool,
    testDnsAccount,
    updateDns,
    type DnsAccount,
    type DnsAddress,
    type DnsInstance,
    type DnsMonitorTemplate,
    type DnsPool,
    type DnsResources,
    type DnsStrategy,
} from '@/api/services/aliyunDns'
import { unwrap } from '@/api/request'
import { message, modal } from '@/app/staticApi'
import { AdminLayout } from '@/layouts/AdminLayout'

const STRATEGY: Record<string, string> = {
    sequence: '顺序（主备）',
    round_robin: '轮询',
    weight: '权重',
    source_nearest: '来源就近',
}
const HEALTH: Record<string, string> = {
    ok: '正常',
    ok_alert: '警告',
    ok_no_monitor: '正常（无监控）',
    exceptional: '异常',
    available: '可用',
    unavailable: '不可用',
}
const strategyOptions = Object.entries(STRATEGY).map(([value, label]) => ({ value, label }))
const judgementOptions = [
    { value: 'any_ok', label: '至少一个健康检查正常' },
    { value: 'p30_ok', label: '至少 30% 正常' },
    { value: 'p50_ok', label: '至少 50% 正常' },
    { value: 'p70_ok', label: '至少 70% 正常' },
    { value: 'all_ok', label: '全部正常' },
]
type Selection =
    | { type: 'instance'; data: DnsInstance }
    | { type: 'pool'; data: DnsPool }
    | { type: 'address'; data: DnsAddress; pool: DnsPool }
const stateColor = (item: { enable_status?: string; health_status?: string; available_status?: string }) =>
    item.enable_status === 'disable'
        ? 'default'
        : item.health_status === 'exceptional' || item.available_status === 'unavailable'
          ? 'error'
          : item.health_status === 'ok_alert'
            ? 'warning'
            : 'success'
const stateName = (item: { enable_status?: string; health_status?: string; available_status?: string }) =>
    item.enable_status === 'disable'
        ? '已停用'
        : item.available_status === 'unavailable'
          ? '不可用'
          : (HEALTH[item.health_status ?? ''] ?? '正常')
const contextOf = (accountId: number, topology: DnsInstance) => ({
    account_id: accountId,
    instance_id: topology.instance_id,
    config_id: topology.config_id,
})
const serialNumber = (value: number | null | undefined, fallback: number) => value ?? fallback
const bySerialNumber = <T extends { serial_number?: number | null }>(left: T, right: T) =>
    serialNumber(left.serial_number, Number.MAX_SAFE_INTEGER) -
    serialNumber(right.serial_number, Number.MAX_SAFE_INTEGER)

function AccountEditor({
    open,
    row,
    onClose,
    onSaved,
}: {
    open: boolean
    row: DnsAccount | null
    onClose: () => void
    onSaved: (id: number) => void
}) {
    const [form] = Form.useForm<{
        name: string
        cloud_account_id?: number
        access_key_id?: string
        access_key_secret?: string
        security_token?: string
        clear_security_token: boolean
        enabled: boolean
        is_default: boolean
    }>()
    const [saving, setSaving] = useState(false)
    useEffect(() => {
        if (open)
            form.setFieldsValue({
                cloud_account_id: row?.cloud_account_id,
                name: row?.name ?? '',
                access_key_id: '',
                access_key_secret: '',
                security_token: '',
                clear_security_token: false,
                enabled: row ? row.enabled : true,
                is_default: Boolean(row?.is_default),
            })
    }, [form, open, row])
    const save = async () => {
        const values = await form.validateFields()
        setSaving(true)
        try {
            const saved = await unwrap(saveDnsAccount({ id: row?.id, ...values }))
            message.success('阿里云账号已保存')
            onClose()
            onSaved(saved.id)
        } finally {
            setSaving(false)
        }
    }
    return (
        <Modal
            title={`${row ? '编辑' : '添加'}阿里云账号`}
            open={open}
            onCancel={onClose}
            onOk={() => void save()}
            confirmLoading={saving}
            okText="保存"
            cancelText="取消"
            destroyOnHidden
        >
            <Alert
                className="mb-3"
                type="info"
                showIcon
                message="建议使用仅授予 AliDNS GTM 权限的 RAM 用户，凭证只会加密保存在服务端。"
            />
            <Form form={form} layout="vertical" requiredMark={false}>
                <Form.Item name="name" label="账号名称" rules={[{ required: true, message: '请输入名称' }]}>
                    <Input />
                </Form.Item>
                <Form.Item name="cloud_account_id" label="统一云账号" rules={[{ required: true, message: '请选择阿里云账号' }]} extra="请先在云账号管理添加阿里云凭证，解析权限与余额查询权限独立。"><CloudAccountSelect provider="aliyun" /></Form.Item>
                <Space size="large">
                    <Form.Item name="enabled" valuePropName="checked" className="mb-0">
                        <Switch checkedChildren="启用" unCheckedChildren="停用" />
                    </Form.Item>
                    <Form.Item name="is_default" valuePropName="checked" className="mb-0">
                        <Switch checkedChildren="默认账号" unCheckedChildren="普通账号" />
                    </Form.Item>
                </Space>
            </Form>
        </Modal>
    )
}

function AccountsTab({
    rows,
    endpoint,
    onReload,
    onSelect,
}: {
    rows: DnsAccount[]
    endpoint: string
    onReload: () => Promise<void>
    onSelect: (id: number) => void
}) {
    const [editing, setEditing] = useState<DnsAccount | null>(null)
    const [open, setOpen] = useState(false)
    const [testing, setTesting] = useState<number>()
    const test = async (row: DnsAccount) => {
        setTesting(row.id)
        try {
            const result = await unwrap(testDnsAccount(row.id))
            message.success(`连接成功，读取到 ${result.total} 个接入配置`)
            await onReload()
        } finally {
            setTesting(undefined)
        }
    }
    const remove = (row: DnsAccount) =>
        modal.confirm({
            title: `删除账号“${row.name}”？`,
            content: '已加密保存的凭证也会一并删除。',
            okText: '删除',
            cancelText: '取消',
            okButtonProps: { danger: true },
            onOk: async () => {
                await unwrap(dropDnsAccount(row.id))
                message.success('账号已删除')
                await onReload()
            },
        })
    const columns: TableColumnsType<DnsAccount> = [
        {
            title: '账号',
            render: (_, row) => (
                <div>
                    <Space>
                        <strong>{row.name}</strong>
                        {row.is_default && <Tag color="blue">默认</Tag>}
                    </Space>
                    <div>
                        <Typography.Text type="secondary">{row.access_key_masked || '-'}</Typography.Text>
                    </div>
                </div>
            ),
        },
        {
            title: '状态',
            width: 100,
            render: (_, row) => (
                <Tag color={row.enabled ? 'success' : 'default'}>{row.enabled ? '已启用' : '已停用'}</Tag>
            ),
        },
        {
            title: '最近连接',
            width: 240,
            render: (_, row) => (
                <div>
                    {row.last_connected_at ? dayjs.unix(row.last_connected_at).format('YYYY-MM-DD HH:mm') : '尚未测试'}
                    {row.last_error && (
                        <div>
                            <Typography.Text type="danger" ellipsis={{ tooltip: row.last_error }}>
                                {row.last_error}
                            </Typography.Text>
                        </div>
                    )}
                </div>
            ),
        },
        {
            title: '操作',
            width: 250,
            render: (_, row) => (
                <Space>
                    <Button
                        type="link"
                        icon={<ThunderboltOutlined />}
                        loading={testing === row.id}
                        onClick={() => void test(row)}
                    >
                        测试
                    </Button>
                    <Button
                        type="link"
                        icon={<EditOutlined />}
                        onClick={() => {
                            setEditing(row)
                            setOpen(true)
                        }}
                    >
                        编辑
                    </Button>
                    <Button type="link" danger icon={<DeleteOutlined />} onClick={() => remove(row)}>
                        删除
                    </Button>
                </Space>
            ),
        },
    ]
    return (
        <Card
            title="阿里云接入账号"
            extra={
                <Button
                    type="primary"
                    icon={<PlusOutlined />}
                    onClick={() => {
                        setEditing(null)
                        setOpen(true)
                    }}
                >
                    添加账号
                </Button>
            }
        >
            <Alert className="mb-3" type="info" message="API Endpoint" description={<code>{endpoint}</code>} />
            <Table rowKey="id" columns={columns} dataSource={rows} pagination={false} />
            <AccountEditor
                open={open}
                row={editing}
                onClose={() => setOpen(false)}
                onSaved={(id) => {
                    onSelect(id)
                    void onReload()
                }}
            />
        </Card>
    )
}

interface PoolForm {
    name: string
    type: string
    remark?: string
    health_judgement: string
    strategy: DnsStrategy
    sequence_mode?: string
    status: string
    weight: number
    serial_number: number
}
interface AddressForm {
    name: string
    type: string
    address: string
    remark?: string
    health_judgement: string
    health_tasks: Array<{ template_id: string; port?: number | null }>
    status: string
    available_mode: string
    manual_status: string
    weight: number
    serial_number: number
}

function HealthTaskFields({ templates, addressType }: { templates: DnsMonitorTemplate[]; addressType: string }) {
    const form = Form.useFormInstance()
    const compatibleTemplates = templates.filter(
        (template) => !['IPv4', 'IPv6'].includes(addressType) || template.ip_version === addressType,
    )
    const options = compatibleTemplates.map((template) => ({
        value: template.id,
        label: `${template.name} · ${template.protocol.toUpperCase()} · ${template.ip_version}`,
    }))
    return (
        <div className="dns-health-tasks">
            <Typography.Title level={5}>健康探测</Typography.Title>
            <Typography.Paragraph type="secondary">
                每个节点最多配置 5 项探测任务；Ping 无需端口，TCP、HTTP 和 HTTPS 请填写服务端口。
            </Typography.Paragraph>
            {!compatibleTemplates.length && (
                <Alert
                    className="mb-3"
                    type="warning"
                    showIcon
                    message="没有适用于当前地址类型的探测模板"
                    description="请先在阿里云 GTM 3.0 中创建对应 IP 版本的健康探测模板，再返回这里关联。"
                />
            )}
            <Form.List name="health_tasks">
                {(fields, { add, remove }) => (
                    <Space direction="vertical" className="w-100" size="small">
                        {fields.map((field) => (
                            <Form.Item noStyle shouldUpdate key={field.key}>
                                {() => {
                                    const templateId = form.getFieldValue(['health_tasks', field.name, 'template_id'])
                                    const template = compatibleTemplates.find((item) => item.id === templateId)
                                    const isPing = template?.protocol.toLowerCase() === 'ping'
                                    return (
                                        <div className="dns-health-task-row">
                                            <Form.Item
                                                name={[field.name, 'template_id']}
                                                rules={[{ required: true, message: '请选择探测模板' }]}
                                            >
                                                <Select
                                                    showSearch
                                                    optionFilterProp="label"
                                                    placeholder="选择探测模板"
                                                    options={options}
                                                    onChange={(value) => {
                                                        if (
                                                            compatibleTemplates
                                                                .find((item) => item.id === value)
                                                                ?.protocol.toLowerCase() === 'ping'
                                                        )
                                                            form.setFieldValue(
                                                                ['health_tasks', field.name, 'port'],
                                                                null,
                                                            )
                                                    }}
                                                />
                                            </Form.Item>
                                            <Form.Item name={[field.name, 'port']}>
                                                <InputNumber
                                                    min={0}
                                                    max={65535}
                                                    disabled={isPing}
                                                    placeholder={isPing ? '无需端口' : '端口'}
                                                    className="w-100"
                                                />
                                            </Form.Item>
                                            <Button
                                                type="text"
                                                danger
                                                title="删除探测任务"
                                                aria-label="删除探测任务"
                                                icon={<MinusCircleOutlined />}
                                                onClick={() => remove(field.name)}
                                            />
                                        </div>
                                    )
                                }}
                            </Form.Item>
                        ))}
                        <Button
                            block
                            type="dashed"
                            icon={<PlusOutlined />}
                            disabled={!compatibleTemplates.length || fields.length >= 5}
                            onClick={() => add({ template_id: '', port: null })}
                        >
                            添加探测任务{fields.length ? `（${fields.length}/5）` : ''}
                        </Button>
                    </Space>
                )}
            </Form.List>
        </div>
    )
}

function NodeEditor({
    selection,
    accountId,
    topology,
    onClose,
    onSaved,
    onCreateAddress,
    onAttachAddress,
    onRemove,
    monitorTemplates,
}: {
    selection: Selection | null
    accountId: number
    topology: DnsInstance
    onClose: () => void
    onSaved: () => void
    onCreateAddress: (pool: DnsPool) => void
    onAttachAddress: (pool: DnsPool) => void
    onRemove: (selection: Selection, removeResource: boolean) => void
    monitorTemplates: DnsMonitorTemplate[]
}) {
    const [form] = Form.useForm<Record<string, unknown>>()
    const [saving, setSaving] = useState(false)
    const strategy = Form.useWatch('strategy', form)
    const availableMode = Form.useWatch('available_mode', form)
    useEffect(() => {
        if (!selection) return
        const values: Record<string, unknown> = { ...selection.data, status: selection.data.enable_status }
        if (selection.type === 'address') values.manual_status = selection.data.manual_available_status || 'available'
        if (selection.type === 'instance') values.ttl = selection.data.ttl ?? 60
        form.setFieldsValue(values)
    }, [form, selection])
    const save = async () => {
        if (!selection) return
        const values = await form.validateFields()
        const context = contextOf(accountId, topology)
        setSaving(true)
        try {
            if (selection.type === 'instance') {
                await Promise.all([
                    unwrap(
                        updateDns({
                            ...context,
                            operation: 'instance_basic',
                            schedule_zone_name: topology.zone_name,
                            schedule_hostname: topology.hostname,
                            ttl: values.ttl,
                        }),
                    ),
                    unwrap(
                        updateDns({
                            ...context,
                            operation: 'instance_strategy',
                            strategy: values.strategy,
                            sequence_mode: values.strategy === 'sequence' ? values.sequence_mode : null,
                        }),
                    ),
                    unwrap(updateDns({ ...context, operation: 'instance_status', status: values.status })),
                ])
            } else if (selection.type === 'pool')
                await unwrap(
                    saveDnsPool({
                        ...context,
                        pool_id: selection.data.id,
                        name: values.name,
                        type: selection.data.type,
                        remark: values.remark,
                        health_judgement: values.health_judgement,
                        strategy: values.strategy,
                        sequence_mode: values.strategy === 'sequence' ? values.sequence_mode : null,
                        status: values.status,
                        weight: values.weight,
                        serial_number: values.serial_number,
                    }),
                )
            else
                await unwrap(
                    saveDnsAddress({
                        ...context,
                        pool_id: selection.pool.id,
                        address_id: selection.data.id,
                        name: values.name,
                        type: selection.data.type,
                        address: values.address,
                        remark: values.remark,
                        health_judgement: values.health_judgement,
                        health_tasks: values.health_tasks,
                        status: values.status,
                        available_mode: values.available_mode,
                        manual_status: values.manual_status,
                        weight: values.weight,
                        serial_number: values.serial_number,
                    }),
                )
            message.success('配置已更新')
            onSaved()
        } finally {
            setSaving(false)
        }
    }
    const title = selection?.type === 'instance' ? '接入域名' : selection?.type === 'pool' ? '地址池' : '节点地址'
    return (
        <Drawer
            title={
                <div className="dns-node-editor-title">
                    <span>{title}</span>
                    {selection && <Tag color={stateColor(selection.data)}>{stateName(selection.data)}</Tag>}
                </div>
            }
            open={Boolean(selection)}
            onClose={onClose}
            width={440}
            closeIcon={<CloseOutlined />}
            className="dns-node-editor"
            destroyOnHidden
        >
            {selection && (
                <Form form={form} layout="vertical" requiredMark={false}>
                    {selection.type === 'instance' ? (
                        <>
                            <Descriptions size="small" column={1} className="mb-3">
                                <Descriptions.Item label="域名">{selection.data.domain}</Descriptions.Item>
                                <Descriptions.Item label="记录类型">{selection.data.record_type}</Descriptions.Item>
                                <Descriptions.Item label="配置 ID">{selection.data.config_id}</Descriptions.Item>
                            </Descriptions>
                            <Form.Item name="ttl" label="全局 TTL（秒）">
                                <InputNumber min={1} max={86400} className="w-100" />
                            </Form.Item>
                        </>
                    ) : (
                        <>
                            <Form.Item
                                name="name"
                                label={selection.type === 'pool' ? '地址池名称' : '节点名称'}
                                rules={[{ required: true }]}
                            >
                                <Input />
                            </Form.Item>
                            {selection.type === 'address' && (
                                <Form.Item name="address" label="节点地址" rules={[{ required: true }]}>
                                    <Input />
                                </Form.Item>
                            )}
                            <Form.Item name="remark" label="备注">
                                <Input.TextArea rows={2} />
                            </Form.Item>
                            <Row gutter={12}>
                                <Col span={12}>
                                    <Form.Item name="weight" label="调度权重">
                                        <InputNumber min={1} max={100} className="w-100" />
                                    </Form.Item>
                                </Col>
                                <Col span={12}>
                                    <Form.Item name="serial_number" label="顺序号">
                                        <InputNumber min={1} max={1000} className="w-100" />
                                    </Form.Item>
                                </Col>
                            </Row>
                            <Form.Item name="health_judgement" label="健康判定">
                                <Select options={judgementOptions} />
                            </Form.Item>
                            {selection.type === 'address' && (
                                <HealthTaskFields templates={monitorTemplates} addressType={selection.data.type} />
                            )}
                        </>
                    )}
                    {selection.type !== 'address' && (
                        <>
                            <Form.Item name="strategy" label="负载均衡策略">
                                <Select options={strategyOptions} />
                            </Form.Item>
                            {strategy === 'sequence' && (
                                <Form.Item name="sequence_mode" label="恢复模式">
                                    <Select
                                        options={[
                                            { value: 'preemptive', label: '抢占模式' },
                                            { value: 'non_preemptive', label: '非抢占模式' },
                                        ]}
                                    />
                                </Form.Item>
                            )}
                        </>
                    )}
                    {selection.type === 'address' && (
                        <>
                            <Form.Item name="available_mode" label="探测异常切换">
                                <Select
                                    options={[
                                        { value: 'auto', label: '自动按健康检查切换' },
                                        { value: 'manual', label: '手动固定状态' },
                                    ]}
                                />
                            </Form.Item>
                            {availableMode === 'manual' && (
                                <Form.Item name="manual_status" label="手动可用状态">
                                    <Select
                                        options={[
                                            { value: 'available', label: '可用' },
                                            { value: 'unavailable', label: '不可用' },
                                        ]}
                                    />
                                </Form.Item>
                            )}
                        </>
                    )}
                    <Form.Item name="status" label="启用状态">
                        <Select
                            options={[
                                { value: 'enable', label: '启用' },
                                { value: 'disable', label: '停用' },
                            ]}
                        />
                    </Form.Item>
                    <Button block type="primary" loading={saving} onClick={() => void save()}>
                        保存配置
                    </Button>
                    {selection.type === 'pool' && (
                        <Space direction="vertical" className="w-100 mt-3">
                            <Button block icon={<PlusOutlined />} onClick={() => onCreateAddress(selection.data)}>
                                新增节点
                            </Button>
                            <Button block icon={<LinkOutlined />} onClick={() => onAttachAddress(selection.data)}>
                                关联已有节点
                            </Button>
                        </Space>
                    )}
                    {selection.type !== 'instance' && (
                        <Space direction="vertical" className="w-100 mt-3">
                            <Button block danger onClick={() => onRemove(selection, false)}>
                                解除当前关联
                            </Button>
                            <Button block type="link" danger onClick={() => onRemove(selection, true)}>
                                删除云端资源
                            </Button>
                        </Space>
                    )}
                </Form>
            )}
        </Drawer>
    )
}

interface OrderPoolRow {
    id: string
    name: string
    serialNumber: number
}
interface OrderAddressRow {
    id: string
    poolId: string
    poolName: string
    name: string
    address: string
    serialNumber: number
}

function OrderEditor({
    topology,
    saving,
    onClose,
    onSave,
}: {
    topology: DnsInstance
    saving: boolean
    onClose: () => void
    onSave: (data: {
        pools: Array<{ pool_id: string; serial_number: number }>
        addresses: Array<{ pool_id: string; address_id: string; serial_number: number }>
    }) => Promise<void>
}) {
    const orderedPools = topology.pools.toSorted(bySerialNumber)
    const [pools, setPools] = useState<OrderPoolRow[]>(() =>
        orderedPools.map((pool, index) => ({
            id: pool.id,
            name: pool.name,
            serialNumber: serialNumber(pool.serial_number, index + 1),
        })),
    )
    const [addresses, setAddresses] = useState<OrderAddressRow[]>(() =>
        orderedPools.flatMap((pool) =>
            pool.addresses.toSorted(bySerialNumber).map((address, index) => ({
                id: address.id,
                poolId: pool.id,
                poolName: pool.name,
                name: address.name || address.address,
                address: address.address,
                serialNumber: serialNumber(address.serial_number, index + 1),
            })),
        ),
    )
    const submit = async () => {
        const duplicatePoolOrder = new Set(pools.map((row) => row.serialNumber)).size !== pools.length
        if (duplicatePoolOrder) return message.error('地址池顺序号不能重复')
        for (const pool of pools) {
            const rows = addresses.filter((row) => row.poolId === pool.id)
            if (new Set(rows.map((row) => row.serialNumber)).size !== rows.length) {
                return message.error(`地址池“${pool.name}”中的节点顺序号不能重复`)
            }
        }
        await onSave({
            pools: pools.map((row) => ({ pool_id: row.id, serial_number: row.serialNumber })),
            addresses: addresses.map((row) => ({
                pool_id: row.poolId,
                address_id: row.id,
                serial_number: row.serialNumber,
            })),
        })
    }
    return (
        <Modal
            title="调整顺序号"
            open
            width={720}
            onCancel={onClose}
            onOk={() => void submit()}
            confirmLoading={saving}
            okText="保存顺序"
            cancelText="取消"
            destroyOnHidden
        >
            <Alert
                className="mb-3"
                type="info"
                showIcon
                message="顺序号越小越优先，仅在顺序（主备）策略下参与调度；地址池之间、同一地址池内的节点分别排序。"
            />
            <div className="dns-order-editor">
                <section>
                    <Typography.Title level={5}>地址池顺序</Typography.Title>
                    <div className="dns-order-list">
                        {pools.map((pool) => (
                            <div className="dns-order-row" key={pool.id}>
                                <div>
                                    <strong>{pool.name}</strong>
                                    <small>地址池</small>
                                </div>
                                <InputNumber
                                    min={1}
                                    max={1000}
                                    value={pool.serialNumber}
                                    aria-label={`${pool.name}顺序号`}
                                    onChange={(value) =>
                                        setPools((rows) =>
                                            rows.map((row) =>
                                                row.id === pool.id ? { ...row, serialNumber: Number(value ?? 1) } : row,
                                            ),
                                        )
                                    }
                                />
                            </div>
                        ))}
                    </div>
                </section>
                {pools.map((pool) => {
                    const rows = addresses.filter((row) => row.poolId === pool.id)
                    if (!rows.length) return null
                    return (
                        <section key={pool.id}>
                            <Typography.Title level={5}>{pool.name} · 节点顺序</Typography.Title>
                            <div className="dns-order-list">
                                {rows.map((address) => (
                                    <div className="dns-order-row" key={`${address.poolId}:${address.id}`}>
                                        <div>
                                            <strong>{address.name}</strong>
                                            <small>{address.address}</small>
                                        </div>
                                        <InputNumber
                                            min={1}
                                            max={1000}
                                            value={address.serialNumber}
                                            aria-label={`${address.name}顺序号`}
                                            onChange={(value) =>
                                                setAddresses((items) =>
                                                    items.map((item) =>
                                                        item.poolId === address.poolId && item.id === address.id
                                                            ? { ...item, serialNumber: Number(value ?? 1) }
                                                            : item,
                                                    ),
                                                )
                                            }
                                        />
                                    </div>
                                ))}
                            </div>
                        </section>
                    )
                })}
                {!pools.length && <Empty description="当前接入域名没有可排序的地址池" />}
            </div>
        </Modal>
    )
}

export default function AliyunDnsPage() {
    const [params, setParams] = useSearchParams()
    const active = params.get('tab') === 'accounts' ? 'accounts' : 'topology'
    const [accounts, setAccounts] = useState<DnsAccount[]>([])
    const [endpoint, setEndpoint] = useState('')
    const [accountId, setAccountId] = useState<number>(0)
    const [instances, setInstances] = useState<DnsInstance[]>([])
    const [configId, setConfigId] = useState('')
    const [topology, setTopology] = useState<DnsInstance | null>(null)
    const [selection, setSelection] = useState<Selection | null>(null)
    const [orderOpen, setOrderOpen] = useState(false)
    const [loading, setLoading] = useState(true)
    const [poolOpen, setPoolOpen] = useState(false)
    const [addressPool, setAddressPool] = useState<DnsPool | null>(null)
    const [attach, setAttach] = useState<{ type: 'pool' | 'address'; pool?: DnsPool } | null>(null)
    const [resources, setResources] = useState<DnsResources | null>(null)
    const [monitorTemplates, setMonitorTemplates] = useState<DnsMonitorTemplate[]>([])
    const [poolForm] = Form.useForm<PoolForm>()
    const [addressForm] = Form.useForm<AddressForm>()
    const createAddressMode = Form.useWatch('available_mode', addressForm)
    const [resourceId, setResourceId] = useState<string>()
    const [saving, setSaving] = useState(false)
    const loadOverview = useCallback(async (selectedId?: number) => {
        const result = await unwrap(fetchDnsOverview())
        setAccounts(result.accounts)
        setEndpoint(result.endpoint)
        const preferred =
            result.accounts.find((row) => row.id === selectedId && row.enabled) ??
            result.accounts.find((row) => row.is_default && row.enabled) ??
            result.accounts.find((row) => row.enabled)
        if (preferred) setAccountId(preferred.id)
        return preferred?.id
    }, [])
    const loadInstances = useCallback(async (id: number) => {
        setLoading(true)
        try {
            const result = await unwrap(fetchDnsInstances(id))
            setInstances(result.items)
            setConfigId(result.items[0]?.config_id ?? '')
            setTopology(null)
            setSelection(null)
        } finally {
            setLoading(false)
        }
    }, [])
    const loadMonitorTemplates = useCallback(async (id: number) => {
        const result = await unwrap(fetchDnsMonitorTemplates(id))
        setMonitorTemplates(result.items)
    }, [])
    const loadTopology = useCallback(async () => {
        const instance = instances.find((row) => row.config_id === configId)
        if (!instance || !accountId) return
        setLoading(true)
        try {
            setTopology(await unwrap(fetchDnsTopology(accountId, instance.instance_id, instance.config_id)))
            setSelection(null)
        } finally {
            setLoading(false)
        }
    }, [accountId, configId, instances])
    useEffect(() => {
        const timer = window.setTimeout(
            () =>
                void loadOverview().then((id) => {
                    if (id) {
                        void loadMonitorTemplates(id)
                        return loadInstances(id)
                    }
                }),
            0,
        )
        return () => window.clearTimeout(timer)
    }, [loadInstances, loadMonitorTemplates, loadOverview])
    useEffect(() => {
        if (!configId || !instances.length) return
        const timer = window.setTimeout(() => void loadTopology(), 0)
        return () => window.clearTimeout(timer)
    }, [configId, instances, loadTopology])
    const context = topology ? contextOf(accountId, topology) : null
    const refresh = async () => {
        const id = await loadOverview(accountId)
        if (id) await loadInstances(id)
    }
    const createPool = async () => {
        if (!context) return
        const values = await poolForm.validateFields()
        setSaving(true)
        try {
            await unwrap(
                saveDnsPool({
                    ...context,
                    ...values,
                    sequence_mode: values.strategy === 'sequence' ? values.sequence_mode : null,
                }),
            )
            message.success('地址池已创建并关联')
            setPoolOpen(false)
            await loadTopology()
        } finally {
            setSaving(false)
        }
    }
    const createAddress = async () => {
        if (!context || !addressPool) return
        const values = await addressForm.validateFields()
        setSaving(true)
        try {
            await unwrap(saveDnsAddress({ ...context, pool_id: addressPool.id, ...values }))
            message.success('节点已创建并加入地址池')
            setAddressPool(null)
            await loadTopology()
        } finally {
            setSaving(false)
        }
    }
    const updateOrder = async (data: {
        pools: Array<{ pool_id: string; serial_number: number }>
        addresses: Array<{ pool_id: string; address_id: string; serial_number: number }>
    }) => {
        if (!context) return
        setSaving(true)
        try {
            await unwrap(saveDnsOrder({ ...context, ...data }))
            message.success('顺序号已更新')
            setOrderOpen(false)
            await loadTopology()
        } finally {
            setSaving(false)
        }
    }
    const openAttach = async (type: 'pool' | 'address', pool?: DnsPool) => {
        setAttach({ type, pool })
        setResourceId(undefined)
        setResources(await unwrap(fetchDnsResources(accountId)))
    }
    const attachResource = async () => {
        if (!attach || !resourceId || !context) return message.error('请选择资源')
        setSaving(true)
        try {
            if (attach.type === 'pool') await unwrap(attachDnsPool({ ...context, pool_id: resourceId, weight: 100 }))
            else
                await unwrap(
                    attachDnsAddress({ ...context, pool_id: attach.pool?.id, address_id: resourceId, weight: 100 }),
                )
            setAttach(null)
            await loadTopology()
            message.success('资源已关联')
        } finally {
            setSaving(false)
        }
    }
    const remove = (node: Selection, removeResource: boolean) => {
        if (!context || node.type === 'instance') return
        const label = node.type === 'pool' ? '地址池' : '节点'
        modal.confirm({
            title: `${removeResource ? '删除' : '解除关联'}${label}？`,
            content: removeResource
                ? `系统会先解除当前关联，再删除阿里云${label}资源。`
                : `仅解除当前关联，云端${label}资源会保留。`,
            okText: removeResource ? '删除' : '解除',
            cancelText: '取消',
            okButtonProps: { danger: true },
            onOk: async () => {
                if (node.type === 'pool')
                    await unwrap(dropDnsPool({ ...context, pool_id: node.data.id, delete_resource: removeResource }))
                else
                    await unwrap(
                        dropDnsAddress({
                            ...context,
                            pool_id: node.pool.id,
                            address_id: node.data.id,
                            delete_resource: removeResource,
                        }),
                    )
                message.success('操作成功')
                setSelection(null)
                await loadTopology()
            },
        })
    }
    const currentInstance = instances.find((row) => row.config_id === configId)
    const orderedPools = useMemo(
        () =>
            topology
                ? topology.pools
                      .toSorted(bySerialNumber)
                      .map((pool) => ({ ...pool, addresses: pool.addresses.toSorted(bySerialNumber) }))
                : [],
        [topology],
    )
    const availableResources = useMemo(() => {
        if (!attach || !resources || !topology) return []
        if (attach.type === 'pool') {
            const used = new Set(topology.pools.map((item) => item.id))
            const type = { A: 'IPv4', AAAA: 'IPv6', CNAME: 'domain' }[topology.record_type] ?? ''
            return resources.pools.filter((item) => !used.has(item.id) && (!type || item.type === type))
        }
        const used = new Set(attach.pool?.addresses.map((item) => item.id))
        return resources.addresses.filter((item) => !used.has(item.id) && item.type === attach.pool?.type)
    }, [attach, resources, topology])

    return (
        <AdminLayout title="云解析 DNS">
            <div className="catboard-page">
                <div className="catboard-page-heading">
                    <div>
                        <h2>云解析 DNS</h2>
                        <p>管理阿里云全局流量管理 GTM 3.0 的接入域名、地址池与节点状态</p>
                    </div>
                    <Button icon={<ReloadOutlined />} loading={loading} onClick={() => void refresh()}>
                        刷新
                    </Button>
                </div>
                <Tabs
                    activeKey={active}
                    onChange={(key) => setParams(key === 'topology' ? {} : { tab: key }, { replace: true })}
                    items={[
                        {
                            key: 'topology',
                            label: '流量拓扑',
                            children: accounts.length ? (
                                <>
                                    <Card className="mb-3">
                                        <Space wrap size="large">
                                            <Select
                                                value={accountId || undefined}
                                                style={{ minWidth: 260 }}
                                                onChange={(id) => {
                                                    setAccountId(id)
                                                    setConfigId('')
                                                    void loadMonitorTemplates(id)
                                                    void loadInstances(id)
                                                }}
                                                options={accounts.map((row) => ({
                                                    value: row.id,
                                                    label: `${row.name}${row.is_default ? '（默认）' : ''}`,
                                                    disabled: !row.enabled,
                                                }))}
                                            />
                                            <Typography.Text>
                                                <strong>{instances.length}</strong> 个接入配置
                                            </Typography.Text>
                                            <Button onClick={() => setParams({ tab: 'accounts' })}>账号设置</Button>
                                        </Space>
                                    </Card>
                                    {instances.length ? (
                                        <>
                                            <div className="dns-instance-strip">
                                                {instances.map((item) => {
                                                    const current = topology?.config_id === item.config_id ? topology : item
                                                    return (
                                                        <button
                                                            type="button"
                                                            className={item.config_id === configId ? 'active' : ''}
                                                            key={item.config_id}
                                                            onClick={() => setConfigId(item.config_id)}
                                                        >
                                                            <span className="dns-instance-title-row">
                                                                <strong>{item.domain || '未命名域名'}</strong>
                                                                <Tag color={stateColor(current)}>{stateName(current)}</Tag>
                                                            </span>
                                                            <span className="dns-instance-meta">
                                                                {item.record_type} · TTL {item.ttl ?? '-'}s ·{' '}
                                                                {STRATEGY[item.strategy] ?? item.strategy}
                                                            </span>
                                                        </button>
                                                    )
                                                })}
                                            </div>
                                            <Card
                                                title={topology?.domain ?? currentInstance?.domain ?? '流量拓扑'}
                                                extra={
                                                    <Space>
                                                        <Button
                                                            icon={<OrderedListOutlined />}
                                                            onClick={() => setOrderOpen(true)}
                                                        >
                                                            调整顺序
                                                        </Button>
                                                        <Button
                                                            type="primary"
                                                            icon={<PlusOutlined />}
                                                            onClick={() => {
                                                                poolForm.setFieldsValue({
                                                                    name: '',
                                                                    type:
                                                                        { A: 'IPv4', AAAA: 'IPv6', CNAME: 'domain' }[
                                                                            topology?.record_type ?? ''
                                                                        ] ?? 'IPv4',
                                                                    health_judgement: 'any_ok',
                                                                    strategy: 'round_robin',
                                                                    sequence_mode: 'preemptive',
                                                                    status: 'enable',
                                                                    weight: 100,
                                                                    serial_number: (topology?.pools.length ?? 0) + 1,
                                                                })
                                                                setPoolOpen(true)
                                                            }}
                                                        >
                                                            新增地址池
                                                        </Button>
                                                        <Button
                                                            icon={<LinkOutlined />}
                                                            onClick={() => void openAttach('pool')}
                                                        >
                                                            关联已有
                                                        </Button>
                                                    </Space>
                                                }
                                                loading={loading}
                                            >
                                                <div className="dns-tree-canvas">
                                                    {topology ? (
                                                        <div className="dns-topology-flow">
                                                            <button
                                                                type="button"
                                                                className={`dns-react-node dns-topology-root is-${stateColor(topology)}`}
                                                                onClick={() =>
                                                                    setSelection({ type: 'instance', data: topology })
                                                                }
                                                            >
                                                                <GlobalOutlined />
                                                                <span className="dns-react-content">
                                                                    <span className="dns-react-title-row">
                                                                        <strong>{topology.domain}</strong>
                                                                        <Tag color={stateColor(topology)}>
                                                                            {stateName(topology)}
                                                                        </Tag>
                                                                    </span>
                                                                    <small>
                                                                        {topology.record_type} · TTL {topology.ttl}s
                                                                    </small>
                                                                </span>
                                                            </button>
                                                            <div className="dns-react-branches">
                                                                {orderedPools.map((pool, poolIndex) => (
                                                                    <div className="dns-react-branch" key={pool.id}>
                                                                        <button
                                                                            type="button"
                                                                            className={`dns-react-node is-${stateColor(pool)}`}
                                                                            onClick={() =>
                                                                                setSelection({
                                                                                    type: 'pool',
                                                                                    data: pool,
                                                                                })
                                                                            }
                                                                        >
                                                                            <ApartmentOutlined />
                                                                            <span className="dns-react-content">
                                                                                <span className="dns-react-title-row">
                                                                                    <strong>{pool.name}</strong>
                                                                                    <Tag color={stateColor(pool)}>
                                                                                        {stateName(pool)}
                                                                                    </Tag>
                                                                                </span>
                                                                                <small>
                                                                                    顺序{' '}
                                                                                    {serialNumber(
                                                                                        pool.serial_number,
                                                                                        poolIndex + 1,
                                                                                    )}{' '}
                                                                                    ·{' '}
                                                                                    {STRATEGY[pool.strategy] ??
                                                                                        pool.strategy}{' '}
                                                                                    · {pool.addresses.length} 个地址
                                                                                </small>
                                                                            </span>
                                                                        </button>
                                                                        <div
                                                                            className={`dns-react-addresses${pool.addresses.length > 1 ? ' has-multiple' : ''}`}
                                                                        >
                                                                            {pool.addresses.length ? (
                                                                                pool.addresses.map(
                                                                                    (address, addressIndex) => (
                                                                                        <button
                                                                                            type="button"
                                                                                            className={`dns-react-node is-${stateColor(address)}`}
                                                                                            key={address.id}
                                                                                            onClick={() =>
                                                                                                setSelection({
                                                                                                    type: 'address',
                                                                                                    data: address,
                                                                                                    pool,
                                                                                                })
                                                                                            }
                                                                                        >
                                                                                            <CloudServerOutlined />
                                                                                            <span className="dns-react-content">
                                                                                                <span className="dns-react-title-row">
                                                                                                    <strong>
                                                                                                        {address.name ||
                                                                                                            address.address}
                                                                                                    </strong>
                                                                                                    <Tag
                                                                                                        color={stateColor(
                                                                                                            address,
                                                                                                        )}
                                                                                                    >
                                                                                                        {stateName(
                                                                                                            address,
                                                                                                        )}
                                                                                                    </Tag>
                                                                                                </span>
                                                                                                <small>
                                                                                                    顺序{' '}
                                                                                                    {serialNumber(
                                                                                                        address.serial_number,
                                                                                                        addressIndex +
                                                                                                            1,
                                                                                                    )}{' '}
                                                                                                    · {address.address}
                                                                                                </small>
                                                                                            </span>
                                                                                        </button>
                                                                                    ),
                                                                                )
                                                                            ) : (
                                                                                <div className="dns-react-empty">
                                                                                    暂无节点
                                                                                </div>
                                                                            )}
                                                                        </div>
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <Empty description="正在读取拓扑" />
                                                    )}
                                                </div>
                                            </Card>
                                        </>
                                    ) : (
                                        <Card>
                                            <Empty description="当前账号暂无 GTM 接入配置" />
                                        </Card>
                                    )}
                                </>
                            ) : (
                                <Card>
                                    <Empty description="请先添加阿里云账号">
                                        <Button type="primary" onClick={() => setParams({ tab: 'accounts' })}>
                                            添加账号
                                        </Button>
                                    </Empty>
                                </Card>
                            ),
                        },
                        {
                            key: 'accounts',
                            label: '接入账号',
                            children: (
                                <AccountsTab
                                    rows={accounts}
                                    endpoint={endpoint}
                                    onReload={async () => {
                                        await loadOverview()
                                    }}
                                    onSelect={setAccountId}
                                />
                            ),
                        },
                    ]}
                />
            </div>
            {topology && (
                <NodeEditor
                    selection={selection}
                    accountId={accountId}
                    topology={topology}
                    onClose={() => setSelection(null)}
                    onSaved={() => void loadTopology()}
                    onCreateAddress={(pool) => {
                        addressForm.setFieldsValue({
                            name: '',
                            type: pool.type,
                            address: '',
                            remark: '',
                            health_judgement: 'any_ok',
                            health_tasks: [],
                            status: 'enable',
                            available_mode: 'auto',
                            manual_status: 'available',
                            weight: 100,
                            serial_number: pool.addresses.length + 1,
                        })
                        setAddressPool(pool)
                    }}
                    onAttachAddress={(pool) => void openAttach('address', pool)}
                    onRemove={remove}
                    monitorTemplates={monitorTemplates}
                />
            )}
            {topology && orderOpen && (
                <OrderEditor
                    topology={topology}
                    saving={saving}
                    onClose={() => setOrderOpen(false)}
                    onSave={updateOrder}
                />
            )}
            <Modal
                title="新增地址池"
                open={poolOpen}
                onCancel={() => setPoolOpen(false)}
                onOk={() => void createPool()}
                confirmLoading={saving}
                okText="创建并关联"
                cancelText="取消"
            >
                <Form form={poolForm} layout="vertical">
                    <Form.Item name="name" label="名称" rules={[{ required: true }]}>
                        <Input />
                    </Form.Item>
                    <Row gutter={12}>
                        <Col span={12}>
                            <Form.Item name="type" label="地址类型">
                                <Select
                                    options={['IPv4', 'IPv6', 'domain'].map((value) => ({ value, label: value }))}
                                />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item name="weight" label="关联权重">
                                <InputNumber min={1} max={100} className="w-100" />
                            </Form.Item>
                        </Col>
                    </Row>
                    <Form.Item name="remark" label="备注">
                        <Input.TextArea rows={2} />
                    </Form.Item>
                    <Form.Item name="health_judgement" label="健康判定">
                        <Select options={judgementOptions} />
                    </Form.Item>
                    <Form.Item name="strategy" label="负载均衡策略">
                        <Select options={strategyOptions} />
                    </Form.Item>
                    <Form.Item name="status" label="状态">
                        <Select
                            options={[
                                { value: 'enable', label: '启用' },
                                { value: 'disable', label: '停用' },
                            ]}
                        />
                    </Form.Item>
                </Form>
            </Modal>
            <Modal
                title={`新增节点到“${addressPool?.name ?? ''}”`}
                open={Boolean(addressPool)}
                onCancel={() => setAddressPool(null)}
                onOk={() => void createAddress()}
                confirmLoading={saving}
                okText="创建并加入"
                cancelText="取消"
            >
                <Form form={addressForm} layout="vertical">
                    <Row gutter={12}>
                        <Col span={12}>
                            <Form.Item name="name" label="节点名称" rules={[{ required: true }]}>
                                <Input />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item name="type" label="地址类型">
                                <Input disabled />
                            </Form.Item>
                        </Col>
                    </Row>
                    <Form.Item name="address" label="节点地址" rules={[{ required: true }]}>
                        <Input />
                    </Form.Item>
                    <Form.Item name="remark" label="备注">
                        <Input.TextArea rows={2} />
                    </Form.Item>
                    <Form.Item name="health_judgement" label="健康判定">
                        <Select options={judgementOptions} />
                    </Form.Item>
                    <HealthTaskFields templates={monitorTemplates} addressType={addressPool?.type ?? ''} />
                    <Form.Item name="available_mode" label="切换方式">
                        <Select
                            options={[
                                { value: 'auto', label: '自动' },
                                { value: 'manual', label: '手动' },
                            ]}
                        />
                    </Form.Item>
                    {createAddressMode === 'manual' && (
                        <Form.Item name="manual_status" label="手动可用状态">
                            <Select
                                options={[
                                    { value: 'available', label: '可用' },
                                    { value: 'unavailable', label: '不可用' },
                                ]}
                            />
                        </Form.Item>
                    )}
                    <Form.Item name="status" label="状态">
                        <Select
                            options={[
                                { value: 'enable', label: '启用' },
                                { value: 'disable', label: '停用' },
                            ]}
                        />
                    </Form.Item>
                    <Row gutter={12}>
                        <Col span={12}>
                            <Form.Item name="weight" label="池内权重">
                                <InputNumber min={1} max={100} className="w-100" />
                            </Form.Item>
                        </Col>
                        <Col span={12}>
                            <Form.Item name="serial_number" label="顺序号">
                                <InputNumber min={1} max={1000} className="w-100" />
                            </Form.Item>
                        </Col>
                    </Row>
                </Form>
            </Modal>
            <Modal
                title={attach?.type === 'pool' ? '关联已有地址池' : '关联已有节点'}
                open={Boolean(attach)}
                onCancel={() => setAttach(null)}
                onOk={() => void attachResource()}
                confirmLoading={saving}
                okText="关联"
                cancelText="取消"
            >
                <Radio.Group
                    value={resourceId}
                    onChange={(event) => setResourceId(event.target.value)}
                    className="catboard-radio-list"
                >
                    {availableResources.map((item) => (
                        <Radio value={item.id} key={item.id}>
                            <strong>{item.name || ('address' in item ? item.address : '未命名资源')}</strong> ·{' '}
                            {item.type}
                        </Radio>
                    ))}
                </Radio.Group>
                {!availableResources.length && <Empty description="没有可关联的资源" />}
            </Modal>
        </AdminLayout>
    )
}
