import {
  escape as e,
  button,
  badge,
  field,
  select,
  check,
  table,
  panel,
  empty,
  modal,
  toast,
} from "./ui.js";
export const kinds = {
  list: "接入域名",
  pools: "地址池",
  addresses: "地址",
  monitors: "探测模板",
};
const ids = {
  list: "ConfigId",
  pools: "AddressPoolId",
  addresses: "AddressId",
  monitors: "TemplateId",
};
const enums = {
  enable: "启用",
  disable: "禁用",
  any_ok: "至少一个正常",
  p30_ok: "至少 30% 正常",
  p50_ok: "至少 50% 正常",
  p70_ok: "至少 70% 正常",
  all_ok: "全部正常",
  round_robin: "轮询",
  sequence: "顺序 / 主备",
  weight: "加权",
  source_nearest: "来源就近",
  preemptive: "抢占：主资源恢复后切回",
  non_preemptive: "非抢占：保持当前资源",
  auto: "按健康检查自动切换",
  manual: "人工指定状态",
  available: "正常 / 可用",
  unavailable: "异常 / 不可用",
  custom: "自定义托管域名",
  prepay: "绑定已购实例",
};
export function rows(resource, key, singular) {
  const value = resource?.[key];
  return Array.isArray(value)
    ? value
    : Array.isArray(value?.[singular])
      ? value[singular]
      : [];
}
export function gtmName(row = {}) {
  return (
    row.ScheduleDomainName ||
    row.AddressPoolName ||
    row.Name ||
    row.InstanceName ||
    row.Address ||
    row.ConfigId ||
    row.AddressPoolId ||
    row.AddressId ||
    row.TemplateId ||
    "未命名"
  );
}
const stateBadge = (r) =>
  `${badge(enums[r.EnableStatus] || r.Protocol || "—", r.EnableStatus === "enable" ? "good" : "")}${r.HealthStatus ? badge({ ok: "健康", ok_alert: "部分异常", ok_no_monitor: "未探测", exceptional: "异常" }[r.HealthStatus] || r.HealthStatus, r.HealthStatus === "exceptional" ? "bad" : "") : ""}${r.AvailableStatus ? badge(enums[r.AvailableStatus] || r.AvailableStatus, r.AvailableStatus === "unavailable" ? "bad" : "good") : ""}`;
const openButton = (kind, row, label = "配置") =>
  button(label, "gtm.open", { kind, id: row[ids[kind]] }, "text");
export function normalizeGtm(resource) {
  const value = structuredClone(resource);
  for (const [key, singular] of [
    ["AddressPools", "AddressPool"],
    ["Addresses", "Address"],
    ["HealthTasks", "HealthTask"],
    ["IspCityNodes", "IspCityNode"],
  ])
    if (value[key] && !Array.isArray(value[key]))
      value[key] = rows(value, key, singular);
  for (const key of ["AddressPools", "Addresses", "HealthTasks"])
    if (Array.isArray(value[key]))
      value[key] = value[key].map((item) => {
        const next = normalizeGtm(item);
        if (next.RequestSource && !Array.isArray(next.RequestSource))
          next.RequestSource =
            next.RequestSource.RequestSource || next.RequestSource.Source || [];
        return next;
      });
  for (const key of ["AddressPools", "Addresses"])
    if (Array.isArray(value[key]))
      value[key].sort(
        (a, b) =>
          (a.SerialNumber ?? Number.MAX_SAFE_INTEGER) -
          (b.SerialNumber ?? Number.MAX_SAFE_INTEGER),
      );
  if (typeof value.ExtendInfo === "string") {
    try {
      value.ExtendInfo = JSON.parse(value.ExtendInfo);
    } catch {
      throw new Error("云端探测协议参数无法解析，请先在阿里云核对");
    }
  }
  return value;
}
export function moveGtmRow(items, from, to) {
  const result = structuredClone(items);
  if (from < 0 || to < 0 || from >= result.length || to >= result.length)
    return result;
  const [item] = result.splice(from, 1);
  result.splice(to, 0, item);
  for (let i = 0; i < result.length; i++)
    if (
      result[i] &&
      typeof result[i] === "object" &&
      (Object.hasOwn(result[i], "SerialNumber") ||
        result[i].AddressId ||
        result[i].AddressPoolId)
    )
      result[i].SerialNumber = i + 1;
  return result;
}
function initial(fields, resource, create) {
  const defaults = {
    EnableStatus: create ? "disable" : "enable",
    AvailableMode: "auto",
    HealthJudgement: "any_ok",
    ScheduleZoneMode: "custom",
    ChargeType: "prepay",
    Ttl: 60,
    Interval: 60,
    Timeout: 5000,
    EvaluationCount: 3,
    FailureRate: 50,
    SequenceLbStrategyMode: "preemptive",
    ScheduleRrType: "A",
    Type: "IPv4",
    AddressPoolType: "IPv4",
    IpVersion: "IPv4",
    Protocol: "ping",
    ManualAvailableStatus: "available",
    AddressPoolLbStrategy: "round_robin",
    AddressLbStrategy: "round_robin",
  };
  const value = {};
  for (const [key, spec] of Object.entries(fields)) {
    if (Object.hasOwn(resource, key)) value[key] = project(resource[key], spec);
    else if (Object.hasOwn(defaults, key)) value[key] = defaults[key];
    else if (spec.type === "array") value[key] = [];
    else if (spec.required) value[key] = spec.type === "integer" ? 1 : "";
  }
  return value;
}
function project(value, spec) {
  if (spec.type === "object") {
    const result = {};
    for (const [key, child] of Object.entries(spec.properties))
      if (Object.hasOwn(value, key)) result[key] = project(value[key], child);
    return result;
  }
  if (spec.type === "array")
    return (Array.isArray(value) ? value : []).map((item) =>
      project(item, spec.items),
    );
  return value;
}
const extensionSchema = {
  host: { type: "string", label: "HTTP Host" },
  path: { type: "string", label: "HTTP 路径" },
  code: { type: "integer", label: "异常状态码阈值", enum: [400, 500] },
  sni: { type: "boolean", label: "启用 SNI" },
  followRedirect: { type: "boolean", label: "跟随重定向" },
  packetNum: { type: "integer", label: "Ping 数据包数", enum: [20, 50, 100] },
  packetLossRate: {
    type: "integer",
    label: "丢包阈值（%）",
    enum: [10, 30, 40, 80, 90, 100],
  },
};
export function createGtm({ api, ctx, render, queryResources }) {
  let editor = null,
    session = 0;
  async function catalog() {
    const c = ctx();
    c.gtmCatalog ??= await api("gtm.catalog", { account_id: c.account });
    return c.gtmCatalog;
  }
  async function options() {
    const c = ctx();
    c.gtmOptions ??= await api("gtm.options", { account_id: c.account });
    return c.gtmOptions;
  }
  function choices(key, spec, value) {
    const o = editor.options;
    let result = spec.enum?.map((v) => ({
      value: typeof v === "number" ? String(v) : v,
      label: enums[v] || String(v),
    }));
    const source = {
      AddressPoolId: ["pools", "AddressPoolId"],
      AddressId: ["addresses", "AddressId"],
      TemplateId: ["monitors", "TemplateId"],
      InstanceId: ["instances", "InstanceId"],
    }[key];
    if (source) {
      let candidates = o[source[0]] || [];
      if (key === "AddressPoolId" && editor.resource.ScheduleRrType) {
        const allowed =
          {
            A: ["IPv4", "domain"],
            AAAA: ["IPv6", "domain"],
            CNAME: ["domain"],
          }[editor.resource.ScheduleRrType] || [];
        candidates = candidates.filter((row) =>
          allowed.includes(row.AddressPoolType),
        );
      }
      if (key === "AddressId" && editor.resource.AddressPoolType)
        candidates = candidates.filter(
          (row) => row.Type === editor.resource.AddressPoolType,
        );
      if (
        key === "TemplateId" &&
        ["IPv4", "IPv6"].includes(editor.resource.Type)
      )
        candidates = candidates.filter(
          (row) => row.IpVersion === editor.resource.Type,
        );
      result = candidates.map((row) => ({
        value: row[source[1]],
        label: gtmName(row) + " · " + row[source[1]],
      }));
    }
    if (key === "RequestSource")
      result = (o.lines?.SystemLines?.SystemLine || [])
        .filter((row) => row.IsAvailable !== false)
        .map((row) => ({
          value: row.Code,
          label: row.DisplayName || row.Name || row.Code,
        }));
    if (
      result &&
      value !== undefined &&
      value !== "" &&
      !result.some((row) => String(row.value) === String(value))
    )
      result.unshift({ value, label: String(value) + "（当前值）" });
    return result;
  }
  function controls(fields, value, path = []) {
    return Object.entries(fields)
      .map(([key, spec]) => {
        const p = [...path, key],
          encoded = JSON.stringify(p),
          name = "gtm_" + encodeURIComponent(encoded),
          v = value[key];
        if (key === "ExtendInfo") return "";
        if (spec.type === "array") {
          const items = Array.isArray(v) ? v : [];
          if (key === "IspCityNodes") {
            const ip =
              editor.values.IpVersion || editor.resource.IpVersion || "IPv4";
            const nodes =
              ip === "IPv6"
                ? rows(
                    editor.options.nodes,
                    "Ipv6IspCityNodes",
                    "Ipv6IspCityNode",
                  )
                : rows(
                    editor.options.nodes,
                    "Ipv4IspCityNodes",
                    "Ipv4IspCityNode",
                  );
            const visibleNodes = [...nodes];
            for (const selected of items)
              if (
                !visibleNodes.some(
                  (node) =>
                    node.CityCode === selected.CityCode &&
                    node.IspCode === selected.IspCode,
                )
              )
                visibleNodes.push({
                  ...selected,
                  CityName:
                    selected.CityName || `当前城市 ${selected.CityCode}`,
                  IspName: selected.IspName || `运营商 ${selected.IspCode}`,
                });
            return `<fieldset class="gtm-fieldset"><legend>${e(spec.label)}</legend>${visibleNodes.length ? visibleNodes.map((node, i) => `<label class="check"><input type="checkbox" data-gtm-node="${i}" data-city="${e(node.CityCode)}" data-isp="${e(node.IspCode)}" ${items.some((item) => item.CityCode === node.CityCode && item.IspCode === node.IspCode) ? "checked" : ""}>${e([node.GroupName, node.CityName, node.IspName].filter(Boolean).join(" / "))}</label>`).join("") : '<p class="notice warn">无法读取探测节点，创建模板前请检查查询权限。</p>'}</fieldset>`;
          }
          return `<fieldset class="gtm-fieldset"><legend>${e(spec.label)}${spec.required ? " *" : ""}</legend>${["Addresses", "AddressPools"].includes(key) ? '<p class="help">这里只修改当前关联列表，不会全局删除资源。上移/下移会重排优先序号；顺序/主备策略才按该序号调度，权重和线路保持不变。</p>' : ""}${items.length ? items.map((item, i) => `<div class="gtm-repeat"><div class="gtm-repeat-head"><strong>第 ${i + 1} 项</strong><span class="toolbar">${["Addresses", "AddressPools"].includes(key) ? button("上移", "gtm.row.move", { path: encoded, index: i, to: i - 1 }, "text") + button("下移", "gtm.row.move", { path: encoded, index: i, to: i + 1 }, "text") : ""}${button(["Addresses", "AddressPools"].includes(key) ? "解除引用" : "移除", "gtm.row.remove", { path: encoded, index: i }, "text")}</span></div>${spec.items.type === "object" ? controls(spec.items.properties, item, [...p, String(i)]) : scalar(key, spec.items, item, [...p, String(i)])}</div>`).join("") : '<p class="help">暂无关联项。清空关联列表会解除全部引用。</p>'}${button("添加一项", "gtm.row.add", { path: encoded }, "text", "plus")}</fieldset>`;
        }
        return scalar(key, spec, v, p);
      })
      .join("");
  }
  function scalar(key, spec, value, path) {
    if (key === "RequestSource") spec = { ...spec, required: true };
    const encoded = JSON.stringify(path),
      name = "gtm_" + encodeURIComponent(encoded),
      required = spec.required ? "required" : "";
    const opts = choices(key, spec, value);
    const identity =
      path.length === 1 &&
      [
        "ConfigId",
        "AddressPoolId",
        "AddressId",
        "TemplateId",
        "InstanceId",
      ].includes(key) &&
      !editor.spec.create;
    let input;
    if (identity)
      input = field(name, spec.label, value || "", "text", "readonly required");
    else if (opts)
      input = select(
        name,
        spec.label,
        [...(spec.required ? [] : [{ value: "", label: "未设置" }]), ...opts],
        value ?? "",
        required,
      );
    else if (spec.type === "boolean") input = check(name, spec.label, !!value);
    else
      input = field(
        name,
        spec.label,
        value ?? "",
        spec.type === "integer"
          ? "number"
          : key === "Remark"
            ? "textarea"
            : "text",
        `${required} ${spec.type === "integer" ? `step="1" ${spec.minimum !== undefined ? `min="${spec.minimum}"` : ""} ${spec.maximum !== undefined ? `max="${spec.maximum}"` : ""}` : ""}`,
      );
    return `<div data-gtm-path="${e(encoded)}" data-type="${spec.type}" data-required="${spec.required ? "1" : "0"}" data-input="${e(name)}">${input}</div>`;
  }
  function editorBody() {
    const errors = Object.entries(editor.options.errors || {}).map(
      ([kind, message]) => `${kinds[kind] || kind}：${message}`,
    );
    const protocol = editor.values.Protocol || editor.resource.Protocol;
    const protocolFields =
      protocol === "ping"
        ? ["packetNum", "packetLossRate"]
        : [
            "host",
            "path",
            "code",
            ...(protocol === "https" ? ["sni"] : []),
            "followRedirect",
          ];
    return `${editor.references.length ? `<div class="notice warn">此资源被 ${editor.references.length} 项资源引用，修改会作用于所有引用。请在提交前核对关联。</div>` : ""}${errors.length ? `<details class="notice warn"><summary>部分辅助资源读取失败</summary>${errors.map(e).join("<br>")}</details>` : ""}<div class="gtm-form-grid">${controls(editor.spec.fields, editor.values)}</div>${Object.hasOwn(editor.spec.fields, "ExtendInfo") && protocol !== "tcp" ? `<fieldset class="gtm-fieldset"><legend>${e(protocol?.toUpperCase() || "")} 协议参数</legend><div class="gtm-form-grid">${controls(Object.fromEntries(protocolFields.map((key) => [key, extensionSchema[key]])), editor.values.ExtendInfo || {}, ["ExtendInfo"])}</div></fieldset>` : ""}`;
  }
  function collect(form) {
    const values = structuredClone(editor.values);
    for (const node of form.querySelectorAll("[data-gtm-path]")) {
      const path = JSON.parse(node.dataset.gtmPath),
        key = path.pop(),
        input = form.elements[node.dataset.input];
      let parent = values;
      for (const part of path) parent = parent[part] ??= {};
      const value =
        node.dataset.type === "boolean" ? input.checked : input.value;
      if (value === "" && node.dataset.required !== "1") {
        if (Array.isArray(parent)) parent[Number(key)] = "";
        else delete parent[key];
        continue;
      }
      if (node.dataset.type === "integer") {
        if (value === "" || !Number.isInteger(Number(value)))
          throw new Error("请填写有效整数");
        parent[key] = Number(value);
      } else parent[key] = value;
    }
    if (editor.spec.fields.IspCityNodes)
      values.IspCityNodes = [
        ...form.querySelectorAll("[data-gtm-node]:checked"),
      ].map((node) => ({
        CityCode: node.dataset.city,
        IspCode: node.dataset.isp,
      }));
    return values;
  }
  function payload() {
    const params = structuredClone(editor.values);
    if (Object.hasOwn(editor.spec.fields, "ExtendInfo"))
      params.ExtendInfo = JSON.stringify(params.ExtendInfo || {});
    return params;
  }
  function drawEditor() {
    const currentSession = editor.session;
    modal(
      `${editor.spec.label} · ${kinds[editor.spec.kind]}`,
      "修改直接提交到阿里云。先填写参数，再预览差异并确认。",
      editorBody(),
      async (_, form) => {
        editor.values = collect(form);
        const preview = await api("gtm.preview", {
          account_id: editor.account,
          operation: editor.operation,
          parameters: payload(),
          expected_token: editor.token,
        });
        const savedEditor = editor;
        setTimeout(() => {
          if (editor?.session !== currentSession) return;
          const changes = Object.entries(preview.parameters).filter(
            ([key]) =>
              ![
                "InstanceId",
                "ConfigId",
                "AddressId",
                "AddressPoolId",
                "TemplateId",
              ].includes(key),
          );
          modal(
            "确认 GTM 变更",
            `将对「${gtmName(savedEditor.resource)}」执行「${preview.label}」。${preview.references.length ? `影响 ${preview.references.length} 项引用资源。` : ""}DNS 缓存可能使解析结果延后更新。`,
            (preview.references.length
              ? table(
                  ["受影响的引用资源", "ID"],
                  preview.references.map((row) => [
                    e(gtmName(row)),
                    e(
                      row.ConfigId ||
                        row.AddressPoolId ||
                        row.AddressId ||
                        row.TemplateId,
                    ),
                  ]),
                )
              : "") +
              table(
                ["参数", "原值", "提交值"],
                changes.map(([key, value]) => [
                  e(savedEditor.spec.fields[key]?.label || key),
                  formatValue(normalizeGtm(preview.before)[key]),
                  formatValue(key === "ExtendInfo" ? JSON.parse(value) : value),
                ]),
              ) +
              `<div class="toolbar">${button("返回编辑", "gtm.back", {}, "text")}</div>`,
            async () => {
              const result = await api("gtm.action", {
                account_id: savedEditor.account,
                ticket: preview.ticket,
              });
              const c = ctx();
              delete c.gtmOptions;
              toast(
                result.verification === "confirmed"
                  ? "阿里云已确认配置更新"
                  : "请求已提交，请刷新查询云端状态",
              );
              c.gtmDetail = null;
              await queryResources();
              if (result.resource_id && !savedEditor.spec.delete)
                await open(savedEditor.spec.kind, result.resource_id);
            },
            "确认提交",
          );
        }, 0);
      },
      "预览变更",
    );
    const form = document.querySelector("#modal-form");
    for (const key of ["Protocol", "IpVersion"])
      form
        .querySelector(`[data-gtm-path='["${key}"]'] select`)
        ?.addEventListener("change", () => {
          editor.values = collect(form);
          if (key === "Protocol")
            editor.values.ExtendInfo =
              editor.values.Protocol === "ping"
                ? { packetNum: 20, packetLossRate: 30 }
                : { path: "/", code: 500, sni: true, followRedirect: false };
          if (key === "IpVersion") editor.values.IspCityNodes = [];
          drawEditor();
        });
  }
  function formatValue(value) {
    if (value === undefined) return '<span class="help">未设置</span>';
    if (Array.isArray(value))
      return value.length
        ? `<div class="gtm-preview-values">${value.map(formatValue).join("")}</div>`
        : '<span class="help">空列表（解除引用）</span>';
    if (value && typeof value === "object")
      return `<dl class="gtm-values">${Object.entries(value)
        .map(([key, v]) => `<dt>${e(key)}</dt><dd>${formatValue(v)}</dd>`)
        .join("")}</dl>`;
    return e(enums[value] || String(value));
  }
  async function edit(operation, kind, id) {
    const c = ctx();
    if (!c.account) throw new Error("请先选择阿里云账号");
    const op = (await catalog())[operation];
    if (!op) throw new Error("不支持此操作");
    const detail = op.create
      ? { resource: {}, token: null }
      : await api("gtm.resource", {
          account_id: c.account,
          kind: op.kind,
          resource: id,
        });
    const refs = op.create
      ? []
      : await api("gtm.references", {
          account_id: c.account,
          kind: op.kind,
          resource: id,
        });
    const resource = normalizeGtm(detail.resource),
      opts = await options();
    editor = {
      session: ++session,
      account: c.account,
      operation,
      spec: op,
      resource,
      token: detail.token,
      references: refs,
      options: opts,
      values: initial(op.fields, resource, op.create),
    };
    if (op.fields.ExtendInfo)
      editor.values.ExtendInfo =
        resource.ExtendInfo ||
        ((editor.values.Protocol || resource.Protocol) === "ping"
          ? { packetNum: 20, packetLossRate: 30 }
          : { path: "/", code: 500, sni: true, followRedirect: false });
    drawEditor();
  }
  async function open(kind, id) {
    const c = ctx(),
      account = c.account;
    const detail = await api("gtm.resource", {
      account_id: account,
      kind,
      resource: id,
    });
    const refs = await api("gtm.references", {
      account_id: account,
      kind,
      resource: id,
    });
    if (c.account !== account) return;
    c.gtmDetail = { ...detail, references: refs };
    await catalog();
    render();
  }
  function operationButtons(kind, resource) {
    const catalog = ctx().gtmCatalog || {};
    return `<div class="toolbar">${Object.entries(catalog)
      .filter(([, op]) => op.kind === kind && !op.create)
      .map(([operation, op]) =>
        button(
          op.delete && kind !== "list" ? "全局" + op.label : op.label,
          "gtm.edit",
          { operation, kind, id: resource[ids[kind]] },
          op.delete ? "danger" : "",
        ),
      )
      .join("")}</div>`;
  }
  function topology(detail) {
    const resource = normalizeGtm(detail.resource),
      kind = detail.kind;
    const card = (type, row) =>
      `<article class="gtm-node gtm-node-${type}"><div class="gtm-node-title"><strong>${e(gtmName(row))}</strong>${openButton(type, row, "打开")}</div><small class="mono">${e(row[ids[type]])}</small><div class="toolbar">${stateBadge(row)}</div>${row.Address ? `<p>${e(row.Address)}</p>` : ""}${row.WeightValue !== undefined || row.SerialNumber !== undefined ? `<small>权重 ${e(row.WeightValue ?? "—")} · 顺序 ${e(row.SerialNumber ?? "—")}</small>` : ""}</article>`;
    let graph = "";
    if (kind === "list")
      graph = `<div class="gtm-topology-root">${card("list", resource)}</div><div class="gtm-topology-branches">${(resource.AddressPools || []).map((pool) => `<section class="gtm-branch">${card("pools", pool)}<div class="gtm-branch-addresses">${(pool.Addresses || []).map((address) => card("addresses", address)).join("") || '<p class="help">暂无地址</p>'}</div></section>`).join("") || '<p class="help">尚未关联地址池，可通过“关联地址池”添加。</p>'}</div>`;
    if (kind === "pools")
      graph = `<div class="gtm-topology-root">${card("pools", resource)}</div><div class="gtm-topology-branches">${(resource.Addresses || []).map((address) => `<section class="gtm-branch">${card("addresses", address)}</section>`).join("") || '<p class="help">尚未关联地址</p>'}</div>`;
    if (kind === "addresses")
      graph = `<div class="gtm-topology-root">${card("addresses", resource)}</div><div class="gtm-topology-branches">${(resource.HealthTasks || []).map((task) => card("monitors", { ...task, Name: task.TemplateName })).join("") || '<p class="help">未配置健康探测</p>'}</div>`;
    if (kind === "monitors")
      graph = `<dl class="gtm-values"><dt>探测协议</dt><dd>${e(resource.Protocol)}</dd><dt>IP 版本</dt><dd>${e(resource.IpVersion)}</dd><dt>间隔</dt><dd>${e(resource.Interval)} 秒</dd><dt>超时</dt><dd>${e(resource.Timeout)} 毫秒</dd><dt>连续失败</dt><dd>${e(resource.EvaluationCount)} 次</dd><dt>探测节点</dt><dd>${(resource.IspCityNodes || []).map((n) => e([n.CityName, n.IspName].filter(Boolean).join(" / "))).join("、")}</dd></dl>`;
    return panel(
      `${kinds[kind]} · ${gtmName(resource)}`,
      `<div class="panel-body"><div class="gtm-detail-summary">${stateBadge(resource)}${resource.Ttl ? `<span>TTL ${e(resource.Ttl)} 秒</span>` : ""}${resource.ScheduleRrType ? badge(resource.ScheduleRrType) : ""}</div>${operationButtons(kind, resource)}<div class="gtm-topology">${graph}</div>${
        detail.references.length
          ? `<div class="section-label">被 ${detail.references.length} 项资源引用</div><div class="gtm-reference-list">${detail.references
              .map((row) => {
                const type = row.ConfigId
                  ? "list"
                  : row.AddressPoolId
                    ? "pools"
                    : "addresses";
                return `<div>${e(gtmName(row))} ${openButton(type, row, "查看引用")}</div>`;
              })
              .join("")}</div>`
          : '<p class="help">暂无上级引用</p>'
      }</div>`,
      button("关闭详情", "gtm.close", {}, "text"),
    );
  }
  function view(c) {
    const tab = c.tab in kinds ? c.tab : "list";
    const tabs = `<div class="tabs">${Object.entries({
      ...kinds,
      history: "变更记录",
    })
      .map(
        ([key, label]) =>
          `<button class="tab ${c.tab === key ? "active" : ""}" data-action="gtm.tab" data-tab="${key}">${e(label)}</button>`,
      )
      .join("")}</div>`;
    if (c.tab === "history")
      return (
        tabs +
        panel(
          "GTM 变更记录",
          table(
            ["操作", "资源", "状态", "请求编号", "时间", "详情"],
            (c.gtmHistory || []).map((row, index) => [
              e(c.gtmCatalog?.[row.operation]?.label || row.operation),
              e(row.resource_id || "新建"),
              badge(row.status, row.status === "failed" ? "bad" : ""),
              e(row.result?.request_id || row.error || "—"),
              e(new Date(row.time * 1000).toLocaleString()),
              button("查看变更", "gtm.change", { index }, "text"),
            ]),
          ),
        )
      );
    return (
      tabs +
      `<div class="notice">GTM 3.0 · 地址池、地址、探测模板为账号级共享资源。先选择账号并查询，再打开资源进行可视化配置。</div>` +
      panel(
        kinds[tab],
        table(
          ["名称 / ID", "类型 / 策略", "运行状态", "操作"],
          c.items.map((row) => [
            `<strong>${e(gtmName(row))}</strong><span class="secondary mono">${e(row[ids[tab]])}</span>`,
            e(
              row.ScheduleRrType ||
                row.AddressPoolType ||
                row.Type ||
                row.Protocol ||
                "—",
            ) +
              '<span class="secondary">' +
              e(
                enums[row.AddressPoolLbStrategy || row.AddressLbStrategy] ||
                  row.Address ||
                  "",
              ) +
              "</span>",
            `<div class="toolbar">${stateBadge(row)}</div>`,
            openButton(tab, row, "可视化配置"),
          ]),
        ),
        button(
          "创建" + kinds[tab],
          "gtm.create",
          { kind: tab },
          "primary",
          "plus",
        ),
      ) +
      (c.gtmDetail ? topology(c.gtmDetail) : "")
    );
  }
  const actions = {
    "gtm.tab": async (d) => {
      const c = ctx();
      c.tab = d.tab;
      c.page = 1;
      c.gtmDetail = null;
      if (d.tab === "history") {
        if (!c.account) throw new Error("请先选择账号");
        await catalog();
        c.gtmHistory = await api("gtm.history", { account_id: c.account });
        render();
      } else if (c.account) await queryResources();
      else render();
    },
    "gtm.change": (d) => {
      const change = ctx().gtmHistory[Number(d.index)];
      const before = normalizeGtm(change.before);
      modal(
        "GTM 变更详情",
        change.error || `请求编号：${change.result?.request_id || "—"}`,
        table(
          ["字段", "变更前", "提交值"],
          Object.entries(change.parameters).map(([key, value]) => [
            e(key),
            formatValue(before[key]),
            formatValue(key === "ExtendInfo" ? JSON.parse(value) : value),
          ]),
        ),
        async () => {},
        "关闭",
      );
    },
    "gtm.open": (d) => open(d.kind, d.id),
    "gtm.close": () => {
      ctx().gtmDetail = null;
      render();
    },
    "gtm.create": async (d) => {
      const op = Object.entries(await catalog()).find(
        ([, op]) => op.kind === d.kind && op.create,
      )?.[0];
      await edit(op, d.kind);
    },
    "gtm.edit": (d) => edit(d.operation, d.kind, d.id),
    "gtm.back": () => drawEditor(),
    "gtm.row.add": (d) => {
      editor.values = collect(document.querySelector("#modal-form"));
      const path = JSON.parse(d.path);
      let list = editor.values,
        schema = { type: "object", properties: editor.spec.fields };
      for (const key of path) {
        list = list[key];
        schema = schema.properties[key];
      }
      const item =
        schema.items.type === "object"
          ? initial(schema.items.properties, {}, false)
          : "";
      if (schema.items.type === "object") {
        if (schema.items.properties.WeightValue) item.WeightValue = 1;
        if (schema.items.properties.SerialNumber)
          item.SerialNumber =
            Math.max(0, ...list.map((row) => Number(row.SerialNumber) || 0)) +
            1;
      }
      list.push(item);
      drawEditor();
    },
    "gtm.row.move": (d) => {
      editor.values = collect(document.querySelector("#modal-form"));
      const path = JSON.parse(d.path);
      let parent = editor.values;
      for (const key of path.slice(0, -1)) parent = parent[key];
      const key = path.at(-1);
      parent[key] = moveGtmRow(parent[key], Number(d.index), Number(d.to));
      drawEditor();
    },
    "gtm.row.remove": (d) => {
      editor.values = collect(document.querySelector("#modal-form"));
      let list = editor.values;
      for (const key of JSON.parse(d.path)) list = list[key];
      list.splice(Number(d.index), 1);
      drawEditor();
    },
  };
  return { view, actions };
}
