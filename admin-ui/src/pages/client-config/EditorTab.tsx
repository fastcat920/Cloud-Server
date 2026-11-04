import { CopyOutlined, EyeOutlined, SaveOutlined } from '@ant-design/icons'
import { Alert, Button, Card, Col, Collapse, Form, Input, InputNumber, Modal, Row, Select, Space, Switch, Typography } from 'antd'
import copy from 'copy-to-clipboard'
import { useEffect, useState } from 'react'
import {
  previewClientConfig,
  saveClientConfigDraft,
  saveClientConfigSettings,
  type ClientConfigContent,
  type ClientConfigOverview,
  type EncryptionMode,
  type PlatformUpdate,
} from '@/api/services/clientConfig'
import { unwrap } from '@/api/request'
import { message } from '@/app/staticApi'

const FEATURES: Array<[string, string]> = [
  ['balance_enabled', '钱包余额'], ['devices_enabled', '设备管理'], ['gift_card_enabled', '礼品卡'], ['join_group_enabled', '加入群组'],
  ['knowledge_base_enabled', '使用文档'], ['orders_enabled', '订单记录'], ['tickets_enabled', '工单'], ['traffic_details_enabled', '流量明细'],
]
const PLATFORMS = [
  { key: 'android', label: 'Android', store: false }, { key: 'windows', label: 'Windows', store: false }, { key: 'macos', label: 'macOS', store: false },
  { key: 'linux', label: 'Linux', store: false }, { key: 'ios', label: 'iOS', store: true }, { key: 'tvos', label: 'tvOS', store: true },
] as const
interface EditorForm {
  panel_type: string; api_prefix: string; domains: string; gateway_urls: string; website: string; invite_domain?: string; telegram_group?: string
  crisp_website_id?: string; crisp_proxy_url?: string; features: Record<string, boolean>; display_discount_percent: number
  platforms: Record<string, PlatformUpdate>; encryption_mode: EncryptionMode; xor_key?: string; regenerate_signing_key: boolean; change_summary?: string
}
const lines = (value?: string[]) => (value ?? []).join('\n')
const splitLines = (value?: string) => (value ?? '').split(/\r?\n/).map((item) => item.trim()).filter(Boolean)

export function EditorTab({ data, onReload }: { data: ClientConfigOverview; onReload: (editId?: number) => Promise<void> }) {
  const [form] = Form.useForm<EditorForm>()
  const [saving, setSaving] = useState(false)
  const [preview, setPreview] = useState<{ payload: string; bytes: number; checksum: string; encryption_mode: EncryptionMode } | null>(null)
  const config = data.draft ?? data.published

  useEffect(() => {
    const content = config?.content
    const platformRows = content?.update?.platforms ?? {}
    const platformValues: Record<string, PlatformUpdate> = {}
    for (const platform of PLATFORMS) {
      const row = platformRows[platform.key]
      platformValues[platform.key] = {
        enabled: row?.enabled ?? false, source: platform.store ? 'app_store' : 'direct', latest_version: row?.latest_version ?? '', min_supported_version: row?.min_supported_version ?? '',
        url: platform.store ? '' : row?.url ?? '', app_id: platform.store ? row?.app_id ?? '' : '', force: row?.force ?? false,
        changelog: { zh_CN: row?.changelog?.zh_CN ?? '', en_US: row?.changelog?.en_US ?? '' },
      }
    }
    const featureValues: Record<string, boolean> = {}
    for (const [key] of FEATURES) featureValues[key] = content?.features?.[key] ?? true
    form.setFieldsValue({
      panel_type: content?.panel_type ?? 'v2board', api_prefix: content?.api_prefix ?? '/api/v1', domains: lines(content?.domains), gateway_urls: lines(content?.gateway_urls),
      website: lines(content?.contact?.website), invite_domain: String(content?.contact?.invite_domain ?? ''), telegram_group: String(content?.contact?.telegram_group ?? ''),
      crisp_website_id: String(content?.contact?.crisp_website_id ?? ''), crisp_proxy_url: String(content?.contact?.crisp_proxy_url ?? ''),
      features: featureValues, display_discount_percent: content?.latency?.display_discount_percent ?? 0, platforms: platformValues,
      encryption_mode: config?.encryption_mode ?? data.settings.encryption_mode ?? 'xor_base64', xor_key: '', regenerate_signing_key: false, change_summary: config?.change_summary ?? '',
    })
  }, [config, data.settings.encryption_mode, form])

  const buildContent = (values: EditorForm): ClientConfigContent => {
    const previous = config?.content ?? {} as ClientConfigContent
    const platforms: Record<string, PlatformUpdate> = {}
    for (const platform of PLATFORMS) {
      const row = values.platforms[platform.key]
      platforms[platform.key] = {
        enabled: Boolean(row.enabled), source: platform.store ? 'app_store' : 'direct', latest_version: row.latest_version?.trim() ?? '', min_supported_version: row.min_supported_version?.trim() ?? '',
        url: platform.store ? '' : row.url?.trim() ?? '', force: Boolean(row.force), changelog: { zh_CN: row.changelog?.zh_CN?.trim() ?? '', en_US: row.changelog?.en_US?.trim() ?? '' },
        ...(platform.store && row.app_id?.trim() ? { app_id: row.app_id.trim() } : {}),
      }
    }
    return {
      ...previous, panel_type: values.panel_type.trim(), api_prefix: values.api_prefix.trim() || '/api/v1', domains: splitLines(values.domains), gateway_urls: splitLines(values.gateway_urls),
      contact: { ...previous.contact, website: splitLines(values.website), invite_domain: values.invite_domain?.trim() ?? '', telegram_group: values.telegram_group?.trim() ?? '', crisp_website_id: values.crisp_website_id?.trim() ?? '', crisp_proxy_url: values.crisp_proxy_url?.trim() ?? '' },
      features: values.features, latency: { ...previous.latency, display_discount_percent: values.display_discount_percent },
      update: { schema_version: 2, platforms },
    }
  }
  const save = async () => {
    const values = await form.validateFields()
    setSaving(true)
    try {
      await unwrap(saveClientConfigSettings({ encryption_mode: values.encryption_mode, xor_key: values.xor_key?.trim(), regenerate_signing_key: values.regenerate_signing_key }))
      const saved = await unwrap(saveClientConfigDraft({ id: data.draft?.id, content: buildContent(values), encryption_mode: values.encryption_mode, change_summary: values.change_summary?.trim() }))
      message.success('客户端配置草稿已保存')
      await onReload(saved.id)
    } finally { setSaving(false) }
  }
  const showPreview = async () => {
    if (!data.draft) return message.error('请先保存草稿，再预览最终输出')
    const mode = form.getFieldValue('encryption_mode')
    setPreview(await unwrap(previewClientConfig(data.draft.id, mode)))
  }

  return (
    <Form form={form} layout="vertical" requiredMark={false}>
      <Row gutter={[16, 16]}>
        <Col span={24}><Card><Row gutter={[16, 8]}><Col xs={24} md={12}><Typography.Text type="secondary">当前编辑</Typography.Text><div className="catboard-stat-value">v{config?.config_version ?? 1} · {data.draft ? '草稿' : data.published ? '已发布版本' : '新配置'}</div></Col><Col xs={24} md={12}><Typography.Text type="secondary">上次发布</Typography.Text><div className="catboard-stat-value">{data.published ? `v${data.published.config_version}` : '尚未发布'}</div></Col></Row></Card></Col>
        <Col span={24}><Card title="基础连接" extra={<Typography.Text type="secondary">客户端按顺序尝试连接，允许 HTTP 和 HTTPS</Typography.Text>}><Row gutter={16}><Col xs={24} md={12}><Form.Item name="panel_type" label="面板类型" rules={[{ required: true }]}><Input /></Form.Item></Col><Col xs={24} md={12}><Form.Item name="api_prefix" label="API 路径" rules={[{ required: true }]}><Input /></Form.Item></Col><Col span={24}><Form.Item name="domains" label="面板 API 地址（每行一个）" rules={[{ required: true, message: '至少填写一个面板地址' }]}><Input.TextArea rows={4} /></Form.Item></Col><Col span={24}><Form.Item name="gateway_urls" label="网关地址（每行一个）"><Input.TextArea rows={4} /></Form.Item></Col></Row></Card></Col>
        <Col span={24}><Card title="版本更新" extra={<Typography.Text type="secondary">仅输出新版 platforms 配置</Typography.Text>}><Collapse items={PLATFORMS.map((platform) => ({ key: platform.key, label: platform.label, extra: <Form.Item name={['platforms', platform.key, 'enabled']} valuePropName="checked" noStyle><Switch checkedChildren="启用" unCheckedChildren="停用" onClick={(_, event) => event.stopPropagation()} /></Form.Item>, children: <Row gutter={16}><Col xs={24} md={8}><Form.Item name={['platforms', platform.key, 'latest_version']} label="最新版本"><Input /></Form.Item></Col><Col xs={24} md={8}><Form.Item name={['platforms', platform.key, 'min_supported_version']} label="最低支持版本"><Input /></Form.Item></Col><Col xs={24} md={8}><Form.Item name={['platforms', platform.key, platform.store ? 'app_id' : 'url']} label={platform.store ? '商店 ID' : '下载地址'}><Input /></Form.Item></Col><Col span={24}><Form.Item name={['platforms', platform.key, 'force']} valuePropName="checked"><Switch checkedChildren="强制更新" unCheckedChildren="非强制更新" /></Form.Item></Col><Col xs={24} md={12}><Form.Item name={['platforms', platform.key, 'changelog', 'zh_CN']} label="中文更新说明"><Input.TextArea rows={3} /></Form.Item></Col><Col xs={24} md={12}><Form.Item name={['platforms', platform.key, 'changelog', 'en_US']} label="英文更新说明"><Input.TextArea rows={3} /></Form.Item></Col></Row> }))} /></Card></Col>
        <Col xs={24} lg={12}><Card title="联系与服务" className="h-100"><Form.Item name="website" label="官网地址（每行一个）"><Input.TextArea rows={3} /></Form.Item><Row gutter={16}><Col xs={24} md={12}><Form.Item name="invite_domain" label="邀请链接域名"><Input /></Form.Item></Col><Col xs={24} md={12}><Form.Item name="telegram_group" label="Telegram 群组"><Input /></Form.Item></Col><Col xs={24} md={12}><Form.Item name="crisp_website_id" label="Crisp Website ID"><Input /></Form.Item></Col><Col xs={24} md={12}><Form.Item name="crisp_proxy_url" label="Crisp 代理地址"><Input /></Form.Item></Col></Row></Card></Col>
        <Col xs={24} lg={12}><Card title="功能与延迟" className="h-100"><Row gutter={[16, 16]}>{FEATURES.map(([key, label]) => <Col xs={12} md={6} lg={12} xl={6} key={key}><Form.Item name={['features', key]} valuePropName="checked" className="mb-0"><Switch checkedChildren={label} unCheckedChildren={label} /></Form.Item></Col>)}</Row><Form.Item name="display_discount_percent" label="延迟显示折扣（%）" className="mt-4"><InputNumber min={0} max={90} precision={0} className="w-100" /></Form.Item></Card></Col>
        <Col span={24}><Card title="加密与签名"><Alert className="mb-3" type="info" showIcon message="签名加密 v2 可防止配置被伪造；重新生成密钥后，使用旧公钥的客户端会失效。" /><Row gutter={16}><Col xs={24} md={8}><Form.Item name="encryption_mode" label="输出格式"><Select options={[{ value: 'xor_base64', label: 'XOR + Base64' }, { value: 'signed_xor_v2', label: '签名加密 v2' }, { value: 'plain', label: '明文 JSON（调试）' }]} /></Form.Item></Col><Col xs={24} md={8}><Form.Item name="xor_key" label="XOR 密钥"><Input.Password placeholder={data.settings.has_xor_key ? '已保存，留空保持不变' : '首次使用前必须设置'} /></Form.Item></Col><Col xs={24} md={8}><Form.Item name="regenerate_signing_key" label="签名密钥" valuePropName="checked"><Switch checkedChildren="重新生成" unCheckedChildren="保持现有" /></Form.Item></Col><Col span={24}><Typography.Text type="secondary">签名公钥</Typography.Text><div className="catboard-copy-code"><code>{data.settings.signing_public_key || '尚未生成'}</code><Button disabled={!data.settings.signing_public_key} icon={<CopyOutlined />} onClick={() => { copy(data.settings.signing_public_key || ''); message.success('公钥已复制') }}>复制</Button></div></Col></Row></Card></Col>
        <Col span={24}><Card><Row gutter={16} align="bottom"><Col xs={24} md={16}><Form.Item name="change_summary" label="版本说明" className="mb-0"><Input maxLength={500} /></Form.Item></Col><Col xs={24} md={8}><Space wrap><Button icon={<EyeOutlined />} onClick={() => void showPreview()}>预览输出</Button><Button type="primary" icon={<SaveOutlined />} loading={saving} onClick={() => void save()}>保存草稿</Button></Space></Col></Row></Card></Col>
      </Row>
      <Modal title="输出预览" open={Boolean(preview)} onCancel={() => setPreview(null)} footer={<Space><Button onClick={() => setPreview(null)}>关闭</Button><Button type="primary" icon={<CopyOutlined />} onClick={() => { copy(preview?.payload ?? ''); message.success('配置内容已复制') }}>复制内容</Button></Space>} width={900}>
        {preview && <><Space wrap className="mb-3"><Typography.Text>格式：{preview.encryption_mode}</Typography.Text><Typography.Text>大小：{preview.bytes} bytes</Typography.Text><Typography.Text ellipsis={{ tooltip: preview.checksum }}>SHA-256：{preview.checksum}</Typography.Text></Space><Input.TextArea value={preview.payload} readOnly autoSize={{ minRows: 16, maxRows: 26 }} /></>}
      </Modal>
    </Form>
  )
}
