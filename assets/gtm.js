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
  errorDetails,
} from "./ui.js";
export function gtmSourceLabel(row, lines = []) {
  const values = Array.isArray(row.RequestSource) ? row.RequestSource : [];
  return (
    values
      .map((code) => {
        if (code === "default") return "默认";
        const line = lines.find((item) => item.Code === code);
        return line?.DisplayName || line?.Name || String(code);
      })
      .join("、") || "未配置"
  );
}
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
  available: "可用",
  unavailable: "不可用",
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
export function gtmStateBadge(row) {
  const status = gtmStatus(row);
  return badge(
    status === "good" && row.HealthStatus === "ok_no_monitor"
      ? "正常（无探测）"
      : { good: "正常", warn: "警告", bad: "异常" }[status] ||
          row.Protocol?.toUpperCase() ||
          "未知",
    status === "unknown" ? "" : status,
  );
}

const stateBadge = gtmStateBadge;
const primaryEdit = {
  pools: "UpdateCloudGtmAddressPoolBasicConfig",
  addresses: "UpdateCloudGtmAddress",
  monitors: "UpdateCloudGtmMonitorTemplate",
};
const openButton = (kind, row) =>
  kind === "list"
    ? button("可视化配置", "gtm.open", { kind, id: row[ids[kind]] }, "text")
    : button(
        "编辑",
        "gtm.edit",
        { kind, id: row[ids[kind]], operation: primaryEdit[kind] },
        "text",
      );
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
// Compare submitted fields only; match associations by identity rather than array position.
export function gtmChanges(before, parameters, fields = {}, deleting = false) {
  before = normalizeGtm(before || {});
  parameters = normalizeGtm(parameters || {});
  const result = [];
  const identifiers = new Set([
    "InstanceId",
    "ConfigId",
    "AddressId",
    "AddressPoolId",
    "TemplateId",
  ]);
  if (deleting)
    return [{ path: "删除资源", before: gtmName(before), after: undefined }];
  const walk = (old, next, path, schema) => {
    if (Array.isArray(next)) {
      const key = ["AddressPoolId", "AddressId", "TemplateId", "CityCode"].find(
        (k) =>
          next.some((v) => v && typeof v === "object" && k in v) ||
          (old || []).some?.((v) => v && typeof v === "object" && k in v),
      );
      if (key) {
        const duplicateTemplates =
          key === "TemplateId" &&
          [next, Array.isArray(old) ? old : []].some(
            (items) =>
              new Set(items.map((v) => v.TemplateId)).size < items.length,
          );
        const identity = (v) =>
          key === "CityCode"
            ? `${v.CityCode}/${v.IspCode}`
            : duplicateTemplates
              ? `${v.TemplateId}:${v.Port ?? "ping"}`
              : String(v[key]);
        const previous = new Map(
          (Array.isArray(old) ? old : []).map((v) => [identity(v), v]),
        );
        for (const item of next) {
          const id = identity(item),
            prior = previous.get(id),
            label = `${path}[${id}]`;
          if (!prior)
            result.push({
              path: label + "（新增）",
              before: undefined,
              after: item,
            });
          else walk(prior, item, label, schema?.items);
          previous.delete(id);
        }
        for (const [id, item] of previous)
          result.push({
            path: `${path}[${id}]（移除）`,
            before: schema?.items ? project(item, schema.items) : { [key]: id },
            after: undefined,
          });
      } else if (JSON.stringify(old) !== JSON.stringify(next)) {
        const prior = Array.isArray(old) ? old : [];
        const removed = prior.filter((v) => !next.includes(v)),
          added = next.filter((v) => !prior.includes(v));
        if (removed.length)
          result.push({
            path: path + "（移除）",
            before: removed,
            after: undefined,
          });
        if (added.length)
          result.push({
            path: path + "（新增）",
            before: undefined,
            after: added,
          });
        if (!removed.length && !added.length && next.length)
          result.push({ path: path + "（顺序）", before: prior, after: next });
      }
      return;
    }
    if (next && typeof next === "object") {
      for (const [key, value] of Object.entries(next))
        walk(old?.[key], value, path + "." + key, schema?.properties?.[key]);
      // Protocol settings are full replacements, so omitted old keys are actual removals.
      if (path === "ExtendInfo" || schema?.type === "object")
        for (const key of Object.keys(old || {}))
          if (
            !(key in next) &&
            (path === "ExtendInfo" || schema?.properties?.[key])
          )
            result.push({
              path: path + "." + key,
              before: old[key],
              after: undefined,
            });
      return;
    }
    const same =
      schema?.type === "integer" && old !== undefined
        ? Number(old) === next
        : old === next;
    if (!same) result.push({ path, before: old, after: next });
  };
  for (const [key, value] of Object.entries(parameters))
    if (!identifiers.has(key)) walk(before[key], value, key, fields[key]);
  return result;
}
export const gtmProbeKey = (node) =>
  JSON.stringify([String(node.CityCode), String(node.IspCode)]);
export function gtmProbeNodes(data, ip = "IPv4") {
  const prefix = ip === "IPv6" ? "Ipv6" : "Ipv4";
  const unique = new Map();
  for (const node of rows(
    data,
    prefix + "IspCityNodes",
    prefix + "IspCityNode",
  )) {
    if (
      !node ||
      typeof node !== "object" ||
      node.CityCode === undefined ||
      node.IspCode === undefined
    )
      continue;
    const value = {
      ...node,
      CityCode: String(node.CityCode),
      IspCode: String(node.IspCode),
      GroupName: node.GroupName || "其他节点",
      CityName: node.CityName || "未知城市",
      IspName: node.IspName || "未知运营商",
    };
    if (!unique.has(gtmProbeKey(value))) unique.set(gtmProbeKey(value), value);
  }
  return [...unique.values()];
}
const gtmLabels = {
  Address: "服务地址",
  Name: "名称",
  HealthTasks: "关联探测模板",
  HealthJudgement: "健康判定条件",
  Port: "探测端口",
  TemplateId: "探测模板",
  AddressPools: "关联地址池",
  Addresses: "关联地址",
  AddressPoolId: "地址池",
  AddressId: "地址",
  ConfigId: "接入域名配置",
  InstanceId: "GTM 实例",
  IspCityNodes: "探测节点",
  CityCode: "城市",
  IspCode: "运营商",
  WeightValue: "权重",
  SerialNumber: "优先顺序",
  RequestSource: "请求来源线路",
  EnableStatus: "启用状态",
  AvailableMode: "故障切换模式",
  ManualAvailableStatus: "人工可用状态",
  Remark: "备注",
  Ttl: "TTL（秒）",
  AddressPoolName: "地址池名称",
  AddressPoolType: "地址池类型",
  Type: "地址类型",
  AddressPoolLbStrategy: "地址池间调度策略",
  AddressLbStrategy: "地址间调度策略",
  SequenceLbStrategyMode: "主备恢复模式",
  ScheduleZoneName: "接入域名",
  ScheduleHostname: "主机记录",
  ScheduleRrType: "记录类型",
  ScheduleZoneMode: "域名托管模式",
  ChargeType: "计费方式",
  IpVersion: "IP 版本",
  Protocol: "探测协议",
  Interval: "探测间隔",
  Timeout: "超时",
  EvaluationCount: "连续失败次数",
  FailureRate: "失败节点比例",
  ExtendInfo: "协议参数",
  path: "探测路径",
  host: "HTTP 主机名",
  code: "异常状态码阈值",
  sni: "启用 SNI",
  followRedirect: "跟随重定向",
  packetNum: "Ping 数据包数",
  packetLossRate: "丢包阈值",
};
export function gtmFieldPath(path, fields = {}, resolve = () => "关联项") {
  return path.replace(
    /\[([^\]]+)\]|([A-Za-z_][A-Za-z0-9_]*)|(\.)/g,
    (_, id, key, dot) =>
      id !== undefined
        ? " / " + resolve(id)
        : dot
          ? " / "
          : fields[key]?.label || gtmLabels[key] || key,
  );
}
export function gtmUpdateParameters(fields, before, parameters) {
  const identities = new Set([
    "ConfigId",
    "InstanceId",
    "AddressPoolId",
    "AddressId",
    "TemplateId",
  ]);
  const patch = Object.fromEntries(
    Object.entries(fields)
      .filter(
        ([key, spec]) =>
          Object.hasOwn(parameters, key) &&
          (identities.has(key) ||
            spec.required ||
            gtmChanges(
              { [key]: before[key] },
              { [key]: parameters[key] },
              { [key]: spec },
            ).length),
      )
      .map(([key]) => [key, parameters[key]]),
  );
  if (
    patch.AvailableMode === "manual" &&
    fields.ManualAvailableStatus &&
    Object.hasOwn(parameters, "ManualAvailableStatus")
  )
    patch.ManualAvailableStatus = parameters.ManualAvailableStatus;
  if (
    [patch.AddressPoolLbStrategy, patch.AddressLbStrategy].includes(
      "sequence",
    ) &&
    fields.SequenceLbStrategyMode &&
    Object.hasOwn(parameters, "SequenceLbStrategyMode")
  )
    patch.SequenceLbStrategyMode = parameters.SequenceLbStrategyMode;
  if (
    fields.HealthTasks &&
    fields.HealthJudgement &&
    parameters.HealthJudgement
  )
    patch.HealthJudgement = parameters.HealthJudgement;
  return patch;
}
export function gtmStatus(row) {
  if (row.AvailableMode === "manual") {
    const manual = row.ManualAvailableStatus || row.AvailableStatus;
    if (manual === "available") return "good";
    if (manual === "unavailable") return "bad";
  }
  return (
    { ok: "good", ok_no_monitor: "good", ok_alert: "warn", exceptional: "bad" }[
      row.HealthStatus
    ] || "unknown"
  );
}

export function gtmEdgeInactive(row) {
  return row.EnableStatus === "disable" || gtmStatus(row) === "bad";
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
export function initial(fields, resource, create) {
  const defaults = {
    Remark: "",
    EnableStatus: create ? "disable" : "enable",
    AvailableMode: "auto",
    HealthJudgement: "any_ok",
    ManualAvailableStatus: "available",
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
    AddressPoolLbStrategy: "round_robin",
    AddressLbStrategy: "round_robin",
  };
  const value = {};
  for (const [key, spec] of Object.entries(fields)) {
    if (spec.enum?.length)
      value[key] =
        spec.enum.find((option) => String(option) === String(resource[key])) ??
        spec.enum[0];
    else if (Object.hasOwn(resource, key))
      value[key] = project(resource[key], spec);
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
if (typeof document !== "undefined")
  document.addEventListener("keydown", (event) => {
    if (
      event.target.matches?.('.gtm-node[role="button"]') &&
      ["Enter", " "].includes(event.key)
    ) {
      event.preventDefault();
      event.target.click();
    }
  });
export function createGtm({ api, ctx, render, queryResources }) {
  let editor = null,
    opening = null,
    session = 0;
  let nameIndex = null;
  const detailCache = new Map();
  const detailKey = (kind, id) =>
    `${ctx().account}:${ctx().serial || 0}:${kind}:${id}`;
  function rememberDetail(kind, id, detail) {
    detailCache.set(detailKey(kind, id), {
      time: Date.now(),
      pending: false,
      promise: Promise.resolve(detail),
    });
    while (detailCache.size > 64)
      detailCache.delete(detailCache.keys().next().value);
  }
  function updateListedResource(detail) {
    const c = ctx(),
      field = ids[detail.kind];
    if ((c.tab || "list") === detail.kind && field)
      c.items = c.items.map((row) =>
        row[field] === detail.resource[field]
          ? { ...row, ...detail.resource }
          : row,
      );
  }
  async function resourceDetail(
    kind,
    id,
    { fresh = false, background = false, instance } = {},
  ) {
    const key = detailKey(kind, id),
      cached = detailCache.get(key);
    if (
      !fresh &&
      cached &&
      (cached.pending || Date.now() - cached.time < 15000)
    )
      return structuredClone(await cached.promise);
    const entry = { time: Date.now(), pending: true };
    entry.promise = api(
      "gtm.resource",
      { account_id: ctx().account, kind, resource: id, instance_id: instance },
      { background },
    );
    detailCache.set(key, entry);
    while (detailCache.size > 64)
      detailCache.delete(detailCache.keys().next().value);
    try {
      const detail = await entry.promise;
      entry.pending = false;
      entry.time = Date.now();
      return structuredClone(detail);
    } catch (error) {
      if (detailCache.get(key) === entry) detailCache.delete(key);
      throw error;
    }
  }
  let hoverTimer,
    prefetching = 0,
    warmedList = null;
  function warmDetail(kind, id, instance) {
    if (prefetching >= 2) return;
    ++prefetching;
    resourceDetail(kind, id, { background: true, instance })
      .catch(() => {})
      .finally(() => --prefetching);
  }
  document.addEventListener("pointerover", (event) => {
    const node = event.target.closest?.(
      '[data-action="gtm.edit"], [data-action="gtm.open"]',
    );
    if (!node || !node.dataset.id || node.contains(event.relatedTarget)) return;
    clearTimeout(hoverTimer);
    const account = ctx().account;
    hoverTimer = setTimeout(() => {
      if (node.isConnected && ctx().account === account)
        warmDetail(
          node.dataset.kind,
          node.dataset.id,
          knownResources().get(node.dataset.id)?.InstanceId,
        );
    }, 150);
  });
  document.addEventListener("pointerout", (event) => {
    const node = event.target.closest?.(
      '[data-action="gtm.edit"], [data-action="gtm.open"]',
    );
    if (node && !node.contains(event.relatedTarget)) clearTimeout(hoverTimer);
  });
  async function catalog() {
    const c = ctx();
    c.gtmCatalog ??= await api("gtm.catalog", { account_id: c.account });
    return c.gtmCatalog;
  }
  function optionTTL(section) {
    return ["nodes", "lines"].includes(section) ? 600000 : 30000;
  }
  function cachedOptions(section) {
    const entry = ctx().gtmOptions?.[`${ctx().account}:${section}`];
    return entry?.data && Date.now() - entry.time < optionTTL(section)
      ? entry.data
      : null;
  }
  async function options(operation, section) {
    const c = ctx(),
      account = c.account;
    c.gtmOptions ??= {};
    const key = `${account}:${section}`,
      cached = c.gtmOptions[key];
    if (
      cached &&
      (cached.pending || Date.now() - cached.time < optionTTL(section))
    )
      return cached.promise;
    const entry = { time: Date.now(), pending: true };
    entry.promise = api(
      "gtm.options",
      { account_id: account, operation, unified: true, section },
      { background: true },
    );
    c.gtmOptions[key] = entry;
    try {
      const result = await entry.promise;
      entry.pending = false;
      entry.time = Date.now();
      if (Object.keys(result.errors || {}).length) {
        if (c.gtmOptions?.[key] === entry) delete c.gtmOptions[key];
      } else entry.data = result;
      return result;
    } catch (error) {
      if (c.gtmOptions?.[key] === entry) delete c.gtmOptions[key];
      throw error;
    }
  }
  function optionSections(
    fields,
    create = false,
    nested = false,
    found = new Set(),
  ) {
    for (const [key, spec] of Object.entries(fields)) {
      if ((nested || create) && sourceKind(key)) found.add(sourceKind(key));
      if (key === "IspCityNodes") found.add("nodes");
      if (spec.items?.properties)
        optionSections(spec.items.properties, create, true, found);
    }
    return found;
  }
  function knownResources() {
    if (nameIndex) return nameIndex;
    const found = new Map();
    const walk = (row) => {
      if (!row || typeof row !== "object") return;
      for (const key of Object.values(ids).concat("InstanceId"))
        if (row[key]) found.set(row[key], row);
      for (const task of rows(row, "HealthTasks", "HealthTask"))
        if (task.TemplateName)
          found.set(task.TemplateId, { Name: task.TemplateName });
      for (const child of [
        ...rows(row, "AddressPools", "AddressPool"),
        ...rows(row, "Addresses", "Address"),
      ])
        walk(child);
    };
    for (const row of ctx().items || []) walk(row);
    if (ctx().gtmDetail) walk(ctx().gtmDetail.resource);
    for (const section of ["pools", "addresses", "monitors", "instances"])
      for (const row of cachedOptions(section)?.[section] || []) walk(row);
    if (editor) {
      walk(editor.resource);
      for (const key of ["pools", "addresses", "monitors", "instances"])
        for (const row of editor.options[key] || []) walk(row);
    }
    nameIndex = found;
    return found;
  }
  function displayName(row) {
    return (
      row?.ScheduleDomainName ||
      row?.AddressPoolName ||
      row?.Name ||
      row?.InstanceName ||
      row?.Address ||
      "未命名资源"
    );
  }
  function refreshTemplateLabels() {
    const names = new Map(
      (cachedOptions("monitors")?.monitors || []).map((row) => [
        row.TemplateId,
        displayName(row),
      ]),
    );
    for (const node of document.querySelectorAll("[data-gtm-template]")) {
      const label = names.get(node.dataset.gtmTemplate);
      if (label)
        node.textContent =
          label + (node.dataset.port ? ":" + node.dataset.port : "");
    }
    if (document.querySelector(".gtm-canvas[data-zoom-bound]"))
      zoomCanvas(canvasZoom);
  }
  function relationStrategy() {
    return (
      editor.values.AddressPoolLbStrategy ||
      editor.values.AddressLbStrategy ||
      editor.resource.AddressPoolLbStrategy ||
      editor.resource.AddressLbStrategy ||
      "round_robin"
    );
  }
  function sourceKind(key) {
    return {
      AddressPoolId: "pools",
      AddressId: "addresses",
      TemplateId: "monitors",
      InstanceId: "instances",
    }[key];
  }
  async function loadOptions(section) {
    const current = editor;
    const result = await options(current.operation, section);
    if (editor !== current) return;
    const errors = { ...editor.options.errors, ...result.errors };
    if (!result.errors?.[section]) delete errors[section];
    Object.assign(editor.options, result, { errors });
    nameIndex = null;
    if (result.errors?.[section]) throw new Error(result.errors[section]);
    delete editor.optionErrors?.[section];
    if (
      ["nodes", "lines"].includes(section) &&
      !["nodes", "lines"].some((key) => editor.optionErrors?.[key])
    ) {
      const status = document.querySelector("#gtm-aux-status");
      if (status) status.textContent = "";
    }
    if (section === "lines") refreshLineTags();
    return result;
  }
  function refreshResourceOptions(section) {
    const form = document.querySelector("#gtm-editor-form");
    if (!form || !editor) return;
    for (const container of form.querySelectorAll(
      `[data-gtm-source="${section}"]`,
    )) {
      const input = container.querySelector("select"),
        path = JSON.parse(container.dataset.gtmPath),
        selected = input.value;
      const values = choices(path.at(-1), {}, selected) || [];
      input.replaceChildren(
        ...values.map((row) => new Option(row.label, row.value)),
      );
      input.value = selected || values[0]?.value || "";
      input.setAttribute(
        "aria-busy",
        editor.optionLoads?.[section] ? "true" : "false",
      );
      container.dataset.loaded =
        Object.hasOwn(editor.options, section) &&
        !editor.optionErrors?.[section]
          ? "1"
          : "0";
      let status = container.querySelector(".gtm-option-status");
      if (!status) {
        status = document.createElement("div");
        status.className = "gtm-option-status help";
        status.setAttribute("role", "status");
        container.append(status);
      }
      status.innerHTML = editor.optionErrors?.[section]
        ? e(editor.optionErrors[section]) +
          button("重试", "gtm.options.retry", { section }, "text")
        : editor.optionLoads?.[section]
          ? "正在加载选项…"
          : "";
    }
  }
  function requestEditorOptions(section, retry = false) {
    const current = editor;
    if (!current) return;
    current.optionLoads ??= {};
    current.optionErrors ??= {};
    if (retry) {
      delete ctx().gtmOptions?.[`${current.account}:${section}`];
      delete current.optionErrors[section];
    }
    if (current.optionLoads[section]) return current.optionLoads[section];
    if (
      !retry &&
      Object.hasOwn(current.options, section) &&
      !current.optionErrors[section]
    ) {
      refreshResourceOptions(section);
      return Promise.resolve();
    }
    const promise = loadOptions(section)
      .then(() => {
        if (editor !== current) return;
        delete current.optionErrors[section];
        const form = document.querySelector("#gtm-editor-form");
        if (section === "nodes" && form) {
          refreshNodeTags();
          drawNodes();
        }
        if (section === "lines") {
          refreshLineTags();
          drawLines();
        }
      })
      .catch((error) => {
        if (editor !== current) return;
        current.optionErrors[section] = error.message;
        if (["lines", "nodes"].includes(section)) {
          const status = document.querySelector("#gtm-aux-status");
          if (status)
            status.innerHTML =
              e(error.message) +
              button("重试", "gtm.options.retry", { section }, "text");
        }
      })
      .finally(() => {
        if (editor === current) {
          delete current.optionLoads[section];
          refreshResourceOptions(section);
          if (section === "nodes") {
            refreshNodeTags();
            drawNodes();
          }
        }
      });
    current.optionLoads[section] = promise;
    refreshResourceOptions(section);
    if (section === "nodes") drawNodes();
    return promise;
  }
  function prepareEditorOptions(form) {
    const sections = new Set(
      [...form.querySelectorAll("[data-gtm-source]")].map(
        (node) => node.dataset.gtmSource,
      ),
    );
    if (form.querySelector("[data-gtm-nodes]")) sections.add("nodes");
    if (form.querySelector("[data-lines-path]")) sections.add("lines");
    for (const section of sections) requestEditorOptions(section);
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
        ["IPv4", "IPv6"].includes(editor.values.Type || editor.resource.Type)
      )
        candidates = candidates.filter(
          (row) =>
            row.IpVersion === (editor.values.Type || editor.resource.Type),
        );
      result = candidates.map((row) => ({
        value: row[source[1]],
        label: displayName(row),
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
      !spec.enum &&
      result &&
      value !== undefined &&
      value !== "" &&
      !result.some((row) => String(row.value) === String(value))
    )
      result.unshift({
        value,
        label: !sourceKind(key)
          ? (enums[value] || String(value)) + "（当前值）"
          : knownResources().has(value)
            ? displayName(knownResources().get(value))
            : editor.optionErrors?.[sourceKind(key)]
              ? "名称加载失败（当前关联已保留）"
              : editor.options[sourceKind(key)]
                ? "当前关联项（名称暂不可用）"
                : `正在加载${{ pools: "地址池", addresses: "地址", monitors: "探测模板", instances: "实例" }[sourceKind(key)] || "名称"}…`,
      });
    return result;
  }
  function lineName(code) {
    if (code === "default") return "默认";
    const line = editor.options.lines?.SystemLines?.SystemLine?.find(
      (row) => row.Code === code,
    );
    return line?.DisplayName || line?.Name || "已配置线路";
  }
  function lineControl(path, values, label) {
    const encoded = JSON.stringify(path),
      active = editor.linePath === encoded;
    return `<fieldset class="gtm-fieldset gtm-lines" data-lines-path="${e(encoded)}"><legend>${e(label || "请求来源线路")}</legend><div class="gtm-line-tags">${values.map((value, index) => `<span class="gtm-line-tag">${e(lineName(value))}${button("×", "gtm.row.remove", { path: encoded, index }, "text danger")}</span>`).join("") || '<span class="help">尚未选择线路</span>'}</div>${button(active ? "收起选择" : "选择线路", "gtm.lines.open", { path: encoded }, "text")}${active ? '<div class="gtm-line-menu"><input type="search" class="gtm-line-search" placeholder="搜索地区或运营商" aria-label="搜索请求来源线路"><div class="gtm-line-results" aria-live="polite"></div></div>' : ""}</fieldset>`;
  }
  function refreshLineTags() {
    for (const fieldset of document.querySelectorAll("[data-lines-path]")) {
      const path = JSON.parse(fieldset.dataset.linesPath);
      let values = editor.values;
      for (const part of path) values = values[part];
      const container = document.createElement("div");
      container.innerHTML = lineControl(path, values, "请求来源线路");
      fieldset
        .querySelector(".gtm-line-tags")
        .replaceWith(container.querySelector(".gtm-line-tags"));
    }
  }
  function lineValues() {
    let values = editor.values;
    for (const part of JSON.parse(editor.linePath)) values = values[part];
    return values;
  }
  function drawLines(query = "") {
    const target = document.querySelector(".gtm-line-results");
    if (!target || !editor?.linePath) return;
    const lines = editor.options.lines?.SystemLines?.SystemLine;
    if (!lines) {
      target.innerHTML = '<p class="help">正在加载线路…</p>';
      return;
    }
    const branch = editor.lineBranch || "",
      values = lineValues(),
      byCode = new Map(lines.map((row) => [row.Code, row]));
    let trail = [],
      parent = branch,
      seen = new Set();
    while (byCode.has(parent) && !seen.has(parent)) {
      seen.add(parent);
      const row = byCode.get(parent);
      trail.unshift(row);
      parent = row.ParentCode;
    }
    const children = new Set(
      lines.map((row) => row.ParentCode).filter(Boolean),
    );
    const filtered = query
      ? lines.filter((row) =>
          (row.DisplayName || row.Name || "")
            .toLowerCase()
            .includes(query.toLowerCase()),
        )
      : lines.filter(
          (row) =>
            (byCode.has(row.ParentCode) ? row.ParentCode : "") === branch,
        );
    const candidates = filtered.slice(0, 80);
    target.innerHTML = `<nav class="gtm-line-breadcrumb" aria-label="线路层级">${button("全部线路", "gtm.lines.branch", { code: "" }, "text")}${trail.map((row) => button(row.Name || row.DisplayName, "gtm.lines.branch", { code: row.Code }, "text")).join("")}</nav><div class="gtm-line-list">${candidates.map((row) => `<div class="gtm-line-option"><label class="check"><input type="checkbox" data-line-code="${e(row.Code)}" ${values.includes(row.Code) ? "checked" : ""} ${row.IsAvailable === false || row.IsAvailable === "false" ? "disabled" : ""}>${e(query ? row.DisplayName || row.Name : row.Name || row.DisplayName)}</label>${children.has(row.Code) ? button("展开 ›", "gtm.lines.branch", { code: row.Code }, "text") : ""}</div>`).join("") || '<p class="help">没有匹配的线路</p>'}</div>${filtered.length > 80 ? '<p class="help">当前显示前 80 项，请输入更具体的地区或运营商。</p>' : ""}`;
  }
  function currentProbeNodes() {
    return gtmProbeNodes(
      editor.options.nodes,
      editor.values.IpVersion || editor.resource.IpVersion,
    );
  }
  function nodeTags() {
    const names = new Map(
      currentProbeNodes().map((node) => [
        gtmProbeKey(node),
        [node.GroupName, node.CityName, node.IspName].join(" / "),
      ]),
    );
    for (const node of editor.resource.IspCityNodes || []) {
      const label = [node.GroupName, node.CityName, node.IspName]
        .filter(Boolean)
        .join(" / ");
      if (label && !names.has(gtmProbeKey(node)))
        names.set(gtmProbeKey(node), label);
    }
    return (
      (editor.values.IspCityNodes || [])
        .map(
          (node, index) =>
            `<span class="gtm-line-tag">${e(names.get(gtmProbeKey(node)) || "已选节点（名称暂不可用）")}${button("×", "gtm.nodes.remove", { index }, "text danger")}</span>`,
        )
        .join("") || '<span class="help">尚未选择探测节点</span>'
    );
  }
  function nodeControl(label) {
    return `<fieldset class="gtm-fieldset" data-gtm-nodes><legend>${e(label || "探测节点")}</legend><div class="gtm-node-tags gtm-line-tags">${nodeTags()}</div>${button(editor.nodesOpen ? "收起选择" : "选择探测节点", "gtm.nodes.open", {}, "text")}${editor.nodesOpen ? '<div class="gtm-line-menu"><input type="search" class="gtm-line-search gtm-node-search" placeholder="搜索分组、城市或运营商" aria-label="搜索探测节点"><div class="gtm-node-results" aria-live="polite"></div></div>' : ""}</fieldset>`;
  }
  function refreshNodeTags() {
    const target = document.querySelector(".gtm-node-tags");
    if (target) target.innerHTML = nodeTags();
  }
  function drawNodes(
    query = document.querySelector(".gtm-node-search")?.value || "",
  ) {
    const target = document.querySelector(".gtm-node-results");
    if (!target || !editor) return;
    if (editor.optionErrors?.nodes) {
      target.innerHTML = `<p class="notice warn">${e(editor.optionErrors.nodes)}</p>${button("重试", "gtm.options.retry", { section: "nodes" }, "text")}`;
      return;
    }
    if (
      !editor.options.nodes ||
      (editor.optionLoads?.nodes && !currentProbeNodes().length)
    ) {
      target.innerHTML = '<p class="help">正在加载探测节点…</p>';
      return;
    }
    const all = currentProbeNodes(),
      selected = new Set((editor.values.IspCityNodes || []).map(gtmProbeKey)),
      group = editor.nodeGroup,
      city = editor.nodeCity;
    const trail =
      button("全部分组", "gtm.nodes.branch", {}, "text") +
      (group ? button(group, "gtm.nodes.branch", { group }, "text") : "") +
      (city
        ? `<span>${e(all.find((node) => node.GroupName === group && node.CityCode === city)?.CityName || "城市")}</span>`
        : "");
    let choices;
    if (query || (group && city)) {
      const matches = all.filter((node) =>
        query
          ? [node.GroupName, node.CityName, node.IspName]
              .join(" / ")
              .toLowerCase()
              .includes(query.toLowerCase())
          : node.GroupName === group && node.CityCode === city,
      );
      choices =
        matches
          .slice(0, 80)
          .map(
            (node) =>
              `<div class="gtm-line-option"><label class="check"><input type="checkbox" data-gtm-probe="${e(gtmProbeKey(node))}" ${selected.has(gtmProbeKey(node)) ? "checked" : ""}>${e(query ? [node.GroupName, node.CityName, node.IspName].join(" / ") : node.IspName)}</label></div>`,
          )
          .join("") || '<p class="help">没有匹配的节点</p>';
      if (matches.length > 80)
        choices +=
          '<p class="help">当前显示前 80 项，请输入更具体的城市或运营商。</p>';
    } else {
      const branches = new Map();
      for (const node of all.filter(
        (node) => !group || node.GroupName === group,
      )) {
        const key = group ? node.CityCode : node.GroupName;
        if (!branches.has(key))
          branches.set(key, {
            name: group ? node.CityName : node.GroupName,
            count: 0,
            selected: 0,
          });
        const item = branches.get(key);
        ++item.count;
        if (selected.has(gtmProbeKey(node))) ++item.selected;
      }
      choices =
        [...branches]
          .slice(0, 80)
          .map(
            ([key, item]) =>
              `<div class="gtm-line-option"><span>${e(item.name)} <span class="help">${item.selected ? `已选 ${item.selected} / ` : ""}${item.count} 个节点</span></span>${button("展开 ›", "gtm.nodes.branch", group ? { group, city: key } : { group: key }, "text")}</div>`,
          )
          .join("") || '<p class="help">暂无可用探测节点</p>';
      if (branches.size > 80)
        choices +=
          '<p class="help">当前显示前 80 项，可搜索其他城市或运营商。</p>';
    }
    target.innerHTML = `<nav class="gtm-line-breadcrumb" aria-label="探测节点层级">${trail}</nav><div class="gtm-line-list">${choices}</div>`;
  }
  function controls(fields, value, path = []) {
    return Object.entries(fields)
      .map(([key, spec]) => {
        const p = [...path, key],
          encoded = JSON.stringify(p),
          name = "gtm_" + encodeURIComponent(encoded),
          v = value[key];
        if (key === "ExtendInfo") return "";
        if (
          path.length === 0 &&
          Object.values(ids).concat("InstanceId").includes(key) &&
          !editor.spec.create
        )
          return "";
        if (
          key === "ManualAvailableStatus" &&
          (editor.values.AvailableMode ||
            editor.resource.AvailableMode ||
            "auto") !== "manual"
        )
          return "";
        const strategy = relationStrategy();
        if (key === "SequenceLbStrategyMode" && strategy !== "sequence")
          return "";
        if (path.length && key === "WeightValue" && strategy !== "weight")
          return "";
        if (
          path.length &&
          key === "SerialNumber" &&
          !["sequence", "source_nearest"].includes(strategy)
        )
          return "";
        if (key === "RequestSource" && strategy !== "source_nearest") return "";
        if (key === "RequestSource")
          return lineControl(p, Array.isArray(v) ? v : [], spec.label);
        if (spec.type === "array") {
          const items = Array.isArray(v) ? v : [];
          if (key === "IspCityNodes") return nodeControl(spec.label);

          return `<fieldset class="gtm-fieldset"><legend>${e(spec.label)}${spec.required ? " *" : ""}</legend>${["Addresses", "AddressPools"].includes(key) ? '<p class="help">这里只修改当前关联列表，不会全局删除资源。上移/下移会重排优先序号；顺序/主备策略才按该序号调度，权重和线路保持不变。</p>' : ""}${items.length ? items.map((item, i) => `<div class="gtm-repeat"><div class="gtm-repeat-head"><strong>第 ${i + 1} 项</strong><span class="toolbar">${["Addresses", "AddressPools"].includes(key) && ["sequence", "source_nearest"].includes(strategy) ? button("上移", "gtm.row.move", { path: encoded, index: i, to: i - 1 }, "text") + button("下移", "gtm.row.move", { path: encoded, index: i, to: i + 1 }, "text") : ""}${button(["Addresses", "AddressPools"].includes(key) ? "解除引用" : "移除", "gtm.row.remove", { path: encoded, index: i }, "text danger")}</span></div>${spec.items.type === "object" ? controls(spec.items.properties, item, [...p, String(i)]) : scalar(key, spec.items, item, [...p, String(i)])}</div>`).join("") : '<p class="help">暂无关联项。清空关联列表会解除全部引用。</p>'}${button("添加一项", "gtm.row.add", { path: encoded }, "text", "plus")}</fieldset>`;
        }
        return scalar(key, spec, v, p);
      })
      .join("");
  }
  function scalar(key, spec, value, path) {
    if (key === "RequestSource") spec = { ...spec, required: true };
    const explicit = [
      "AvailableMode",
      "HealthJudgement",
      "ManualAvailableStatus",
    ].includes(key);
    if (key === "AvailableMode" || key === "ManualAvailableStatus")
      spec = { ...spec, required: true };
    if (
      key === "HealthJudgement" &&
      Object.hasOwn(editor.spec.fields, "HealthTasks")
    )
      spec = { ...spec, required: !!editor.values.HealthTasks?.length };
    const encoded = JSON.stringify(path),
      name = "gtm_" + encodeURIComponent(encoded),
      required = spec.required ? "required" : "";
    let opts = choices(key, spec, value);
    if (explicit && opts)
      opts = opts.filter((row) => spec.enum.includes(row.value));
    const valid = !explicit || spec.enum.includes(value);
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
        opts,
        valid ? (value ?? "") : (opts[0]?.value ?? ""),
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
    return `<div data-gtm-path="${e(encoded)}" data-type="${spec.type}" data-preserve-empty="${explicit ? "1" : "0"}" data-required="${spec.required ? "1" : "0"}" data-input="${e(name)}" ${sourceKind(key) && !identity ? `data-gtm-source="${sourceKind(key)}"` : ""}>${input}</div>`;
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
    const groups = [
      [
        "基础信息",
        [
          "Name",
          "AddressPoolName",
          "InstanceId",
          "ConfigId",
          "AddressPoolId",
          "AddressId",
          "TemplateId",
          "ScheduleZoneMode",
          "ScheduleZoneName",
          "ScheduleHostname",
          "ScheduleRrType",
          "Type",
          "AddressPoolType",
          "Address",
          "IpVersion",
          "Protocol",
          "ChargeType",
        ],
      ],
      [
        "启停与调度",
        [
          "EnableStatus",
          "AvailableMode",
          "ManualAvailableStatus",
          "Ttl",
          "AddressPoolLbStrategy",
          "AddressLbStrategy",
          "SequenceLbStrategyMode",
        ],
      ],
      [
        "健康检查",
        [
          "HealthJudgement",
          "Interval",
          "Timeout",
          "EvaluationCount",
          "FailureRate",
          "HealthTasks",
          "ExtendInfo",
        ],
      ],
      ["探测节点", ["IspCityNodes"]],
      ["关联资源", ["AddressPools", "Addresses"]],
      ["备注", ["Remark"]],
    ];
    const handled = new Set(groups.flatMap(([, keys]) => keys));
    groups.push([
      "其他设置",
      Object.keys(editor.spec.fields).filter((key) => !handled.has(key)),
    ]);
    const sections = groups
      .map(([label, keys]) => {
        const fields = Object.fromEntries(
          keys
            .filter((key) => Object.hasOwn(editor.spec.fields, key))
            .map((key) => [key, editor.spec.fields[key]]),
        );
        const contents =
          controls(fields, editor.values) +
          (keys.includes("ExtendInfo") &&
          Object.hasOwn(editor.spec.fields, "ExtendInfo") &&
          protocol !== "tcp"
            ? `<fieldset class="gtm-fieldset"><legend>${e(protocol?.toUpperCase() || "")} 协议参数</legend><div class="gtm-form-grid">${controls(Object.fromEntries(protocolFields.map((key) => [key, extensionSchema[key]])), editor.values.ExtendInfo || {}, ["ExtendInfo"])}</div></fieldset>`
            : "");
        return contents
          ? `<section class="gtm-editor-section" aria-label="${e(label)}"><div class="gtm-form-grid">${contents}</div></section>`
          : "";
      })
      .join("");
    return `${editor.bindingHint ? `<div class="notice">${e(editor.bindingHint)}</div>` : ""}${!editor.spec.create && editor.spec.kind !== "list" ? '<div class="notice warn">共享资源的修改会影响其他引用，提交时将核对引用范围。</div>' : ""}<p id="gtm-aux-status" class="help" role="status"></p>${errors.length ? `<details class="notice warn"><summary>部分辅助资源读取失败</summary>${errors.map(e).join("<br>")}</details>` : ""}${sections}`;
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
      if (value === "" && node.dataset.preserveEmpty === "1") continue;
      if (value === "" && node.dataset.required !== "1") {
        if (Array.isArray(parent)) parent[Number(key)] = "";
        else if (
          node.dataset.type === "string" &&
          (Object.hasOwn(parent, key) || key === "Remark")
        )
          parent[key] = "";
        else delete parent[key];
        continue;
      }
      if (node.dataset.type === "integer") {
        if (value === "" || !Number.isInteger(Number(value)))
          throw new Error("请填写有效整数");
        parent[key] = Number(value);
      } else parent[key] = value;
    }
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
    const oldBox = document.querySelector("#gtm-inspector");
    const oldScroller = oldBox?.closest(".gtm-drawer")
      ? oldBox.querySelector(".gtm-inspector-body")
      : oldBox;
    const keepPosition = oldBox?.dataset.session === String(currentSession);
    const inspectorScroll =
      keepPosition && oldBox.dataset.loading !== "1"
        ? {
            top: oldScroller.scrollTop,
            left: oldScroller.scrollLeft,
            x: window.scrollX,
            y: window.scrollY,
          }
        : null;
    const active =
      keepPosition &&
      oldBox.dataset.loading !== "1" &&
      oldBox.contains(document.activeElement)
        ? document.activeElement
        : null;
    const wasLoading = oldBox?.dataset.loading === "1";
    const activeName = active?.name;
    const selection =
      active && ["text", "textarea", "search"].includes(active.type)
        ? [active.selectionStart, active.selectionEnd]
        : null;
    const canvas = document.querySelector(".gtm-canvas");
    const scroll = {
      left: canvas?.scrollLeft || 0,
      top: canvas?.scrollTop || 0,
    };
    if (!keepPosition) render();
    const restored = document.querySelector(".gtm-canvas");
    if (restored) {
      restored.scrollLeft = scroll.left;
      restored.scrollTop = scroll.top;
    }
    const box = document.querySelector("#gtm-inspector");
    if (!box) return;
    const extra =
      !editor.spec.create && !editor.spec.delete
        ? (editor.resource.HealthTasks || [])
            .map((task) =>
              button(
                "编辑探测模板 " +
                  (task.TemplateName ||
                    displayName(knownResources().get(task.TemplateId))),
                "gtm.edit",
                {
                  kind: "monitors",
                  id: task.TemplateId,
                  operation: "UpdateCloudGtmMonitorTemplate",
                },
                "text",
              ),
            )
            .join("") +
          editor.operations
            .filter(([, op]) => op.delete)
            .map(([operation, op]) =>
              button(
                op.label,
                "gtm.confirmDelete",
                { kind: op.kind, id: editor.resource[ids[op.kind]], operation },
                "danger",
              ),
            )
            .join("") +
          (["list", "pools"].includes(editor.spec.kind)
            ? button(
                editor.spec.kind === "list" ? "新增地址池" : "新增地址",
                "gtm.create",
                {
                  kind: editor.spec.kind === "list" ? "pools" : "addresses",
                  parentkind: editor.spec.kind,
                  parentid: editor.resource[ids[editor.spec.kind]],
                  parenttype:
                    editor.resource.ScheduleRrType ||
                    editor.resource.AddressPoolType,
                },
                "text",
              )
            : "")
        : "";
    box.innerHTML = `<form id="gtm-editor-form"><header class="gtm-inspector-head"><div><small>${e(kinds[editor.spec.kind])}</small><h3 id="gtm-editor-title">${e(gtmName(editor.resource))}</h3></div>${button("×", "gtm.inspector.close", {}, "icon-only")}</header><div class="gtm-inspector-body"><div class="dialog-error" role="alert"></div>${editorBody()}${extra ? `<section class="gtm-editor-section gtm-editor-actions" aria-label="资源操作"><div class="toolbar">${extra}</div></section>` : ""}</div><footer class="gtm-inspector-foot">${button("关闭", "gtm.inspector.close", {}, "text")}<button type="submit" class="button primary">提交</button></footer></form>`;
    const onSubmit = async (_, form, override = null) => {
      if (!override) editor.values = collect(form);
      const parameters = override?.parameters || payload();
      let request = { operation: editor.operation, parameters };
      if (override?.operation) request.operation = override.operation;
      if (!override?.operation && !editor.spec.create && !editor.spec.delete) {
        const steps = editor.operations
          .filter(([, op]) => !op.delete)
          .map(([operation, op]) => {
            const parametersForOperation = gtmUpdateParameters(
              op.fields,
              editor.baseline,
              parameters,
            );
            return {
              operation,
              parameters: parametersForOperation,
              changes: gtmChanges(
                editor.baseline,
                parametersForOperation,
                op.fields,
              ),
            };
          })
          .filter((step) => step.changes.length)
          .map(({ operation, parameters }) => ({ operation, parameters }));
        if (!steps.length) throw new Error("没有修改内容，无需提交");
        request = steps.length === 1 ? steps[0] : { steps };
      }
      const savedEditor = editor;
      const submitButton = form.querySelector('button[type="submit"]');
      let preview;
      try {
        if (savedEditor.spec.create) submitButton.textContent = "正在校验…";
        preview = await api("gtm.preview", {
          account_id: savedEditor.account,
          expected_token: savedEditor.token,
          ...request,
        });
      } catch (error) {
        detailCache.clear();
        throw error;
      }
      if (editor?.session !== currentSession) return;
      const perform = async () => {
        if (savedEditor.spec.create) submitButton.textContent = "正在创建并核验…";
        detailCache.clear();
        const result = await api("gtm.action", {
          account_id: savedEditor.account,
          ticket: preview.ticket,
        });
        const c = ctx();
        c.gtmListPages?.clear();
        if (c.gtmOptions) delete c.gtmOptions[`${savedEditor.account}:${result.kind}`];
        toast(
          result.partial
            ? "部分变更已提交，后续步骤已停止：" +
                (result.error || "等待云端确认，请刷新后核对")
            : result.verification === "confirmed"
              ? "阿里云已确认配置更新"
              : "请求已提交，请刷新查询云端状态",
        );
        const root = savedEditor.root;
        document.querySelector("#gtm-drawer")?.close();
        editor = null;
        detailCache.clear();
        if (
          !result.partial &&
          result.verification === "confirmed" &&
          result.resource &&
          result.token
        )
          rememberDetail(result.kind, result.resource_id, {
            kind: result.kind,
            resource: result.resource,
            token: result.token,
          });
        const removedRoot =
          root &&
          (savedEditor.spec.delete || override?.deleting) &&
          root.kind === savedEditor.spec.kind &&
          root.id === result.resource_id;
        if (root && !removedRoot) {
          if (
            !result.partial &&
            result.verification === "confirmed" &&
            result.kind === root.kind &&
            result.resource_id === root.id &&
            result.token
          ) {
            c.gtmDetail = {
              kind: root.kind,
              resource: result.resource,
              token: result.token,
            };
            updateListedResource(c.gtmDetail);
            render();
          } else if (savedEditor.spec.create && !result.partial && result.verification === "confirmed") {
            render();
          } else await open(root.kind, root.id, true);
          restoreListPosition(savedEditor.returnTo);
        } else {
          c.gtmDetail = null;
          if (result.verified_page && c.tab === result.kind && Number(c.page || 1) === 1 && !result.partial) {
            c.serial = (c.serial || 0) + 1;
            c.items = result.verified_page.items;
            c.total = result.verified_page.total;
            c.raw = result.verified_page;
            c.error = "";
            render();
          } else if (
            savedEditor.spec.create &&
            result.verification === "confirmed" &&
            result.resource_id &&
            result.resource &&
            c.account === savedEditor.account &&
            c.tab === result.kind
          ) {
            const idField = ids[result.kind];
            const alreadyListed = c.items.some((row) => row[idField] === result.resource_id);
            const firstPage = Number(c.page || 1) === 1;
            c.page = 1;
            const items = [
              result.resource,
              ...(firstPage ? c.items.filter((row) => row[idField] !== result.resource_id) : []),
            ].slice(0, 100);
            const key = JSON.stringify([c.account, c.tab, c.page]);
            (c.gtmListPages ??= new Map()).set(key, {
              result: { items, total: Number(c.total || 0) + Number(!alreadyListed), page: 1 },
              time: Date.now(),
            });
            void queryResources({ reuseGtmPage: true, refreshGtmPage: true });
          } else await queryResources();
          if (
            !result.partial &&
            result.verification === "confirmed" &&
            result.resource &&
            result.token
          )
            rememberDetail(result.kind, result.resource_id, {
              kind: result.kind,
              resource: result.resource,
              token: result.token,
            });
          restoreListPosition(savedEditor.returnTo);
        }
        if (savedEditor.attach && result.resource_id) {
          const parent = savedEditor.attach;
          await edit(
            parent.kind === "list"
              ? "ReplaceCloudGtmInstanceConfigAddressPool"
              : "ReplaceCloudGtmAddressPoolAddress",
            parent.kind,
            parent.id,
          );
          const field = parent.kind === "list" ? "AddressPools" : "Addresses",
            idField = parent.kind === "list" ? "AddressPoolId" : "AddressId";
          editor.values[field] ??= [];
          editor.values[field].push({
            [idField]: result.resource_id,
            WeightValue: 1,
            SerialNumber:
              Math.max(
                0,
                ...editor.values[field].map(
                  (row) => Number(row.SerialNumber) || 0,
                ),
              ) + 1,
            RequestSource: ["default"],
          });
          editor.bindingHint = "新资源已创建，当前尚未绑定。请提交保存此关联。";
          drawEditor();
        }
      };
      if (savedEditor.spec.delete) {
        modal(
          "确认删除 GTM 资源",
          `将删除「${gtmName(savedEditor.resource)}」。`,
          "",
          perform,
          "删除",
        );
      } else await perform();
    };
    document
      .querySelectorAll(".gtm-node[data-id]")
      .forEach((node) =>
        node.classList.toggle(
          "selected",
          node.dataset.kind === editor.spec.kind &&
            node.dataset.id === editor.resource[ids[editor.spec.kind]],
        ),
      );
    delete box.dataset.loading;
    box.dataset.session = String(currentSession);
    const form = document.querySelector("#gtm-editor-form");
    editor.confirmedSubmit = (override) => onSubmit(null, form, override);
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const btn = event.submitter;
      const submittingEditor = editor;
      if (submittingEditor.submitting) return;
      submittingEditor.submitting = true;
      btn.disabled = true;
      try {
        await onSubmit(new FormData(form), form);
      } catch (error) {
        form.querySelector(".dialog-error").innerHTML =
          e(error.message) + errorDetails(error.details);
      } finally {
        submittingEditor.submitting = false;
        btn.disabled = false;
        if (submittingEditor.spec.create) btn.textContent = "提交";
      }
    });
    drawNodes();
    form
      .querySelector(".gtm-node-search")
      ?.addEventListener("input", (event) => drawNodes(event.target.value));
    form
      .querySelector(".gtm-node-results")
      ?.addEventListener("change", (event) => {
        if (!event.target.matches("[data-gtm-probe]")) return;
        editor.values = collect(form);
        const node = currentProbeNodes().find(
          (value) => gtmProbeKey(value) === event.target.dataset.gtmProbe,
        );
        if (!node) return;
        const list = (editor.values.IspCityNodes ??= []),
          index = list.findIndex(
            (value) => gtmProbeKey(value) === gtmProbeKey(node),
          );
        if (event.target.checked && index < 0)
          list.push({ CityCode: node.CityCode, IspCode: node.IspCode });
        else if (!event.target.checked && index >= 0) list.splice(index, 1);
        refreshNodeTags();
      });
    drawLines();
    form
      .querySelector(".gtm-lines .gtm-line-search")
      ?.addEventListener("input", (event) => drawLines(event.target.value));
    form
      .querySelector(".gtm-line-results")
      ?.addEventListener("change", (event) => {
        if (!event.target.matches("[data-line-code]")) return;
        editor.values = collect(form);
        const values = lineValues(),
          code = event.target.dataset.lineCode;
        if (event.target.checked && !values.includes(code)) values.push(code);
        else if (!event.target.checked && values.includes(code))
          values.splice(values.indexOf(code), 1);
        const container = event.target.closest("[data-lines-path]");
        const tags = document.createElement("div");
        tags.innerHTML = lineControl(
          JSON.parse(editor.linePath),
          values,
          "请求来源线路",
        );
        container
          .querySelector(".gtm-line-tags")
          .replaceWith(tags.querySelector(".gtm-line-tags"));
      });
    form.addEventListener("focusin", (event) => {
      const container = event.target.closest("[data-gtm-source]");
      if (container && container.dataset.loaded !== "1")
        requestEditorOptions(container.dataset.gtmSource);
    });
    prepareEditorOptions(form);
    for (const key of [
      "AddressPoolLbStrategy",
      "AddressLbStrategy",
      "AvailableMode",
      "Type",
    ])
      form
        .querySelector(`[data-gtm-path='["${key}"]'] select`)
        ?.addEventListener("change", () => {
          editor.values = collect(form);
          drawEditor();
        });
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
          if (key === "IpVersion") {
            editor.values.IspCityNodes = [];
            editor.nodeGroup = null;
            editor.nodeCity = null;
          }
          drawEditor();
        });
    if (inspectorScroll) {
      const restore = () => {
        if (!box.isConnected || editor?.session !== currentSession) return;
        if (activeName) {
          const input = form.elements.namedItem(activeName);
          input?.focus({ preventScroll: true });
          if (selection && input?.setSelectionRange)
            input.setSelectionRange(...selection);
        } else if (active?.tagName === "BUTTON") {
          [...form.querySelectorAll("button")]
            .find(
              (button) =>
                button.type === active.type &&
                JSON.stringify(button.dataset) ===
                  JSON.stringify(active.dataset),
            )
            ?.focus({ preventScroll: true });
        }
        const scroller = box.closest(".gtm-drawer")
          ? box.querySelector(".gtm-inspector-body")
          : box;
        scroller.scrollTop = inspectorScroll.top;
        scroller.scrollLeft = inspectorScroll.left;
        window.scrollTo(inspectorScroll.x, inspectorScroll.y);
      };
      restore();
      requestAnimationFrame(restore);
    }
    mountDrawer();
    if (wasLoading)
      form
        .querySelector("input:not([readonly]),textarea,select")
        ?.focus({ preventScroll: true });
  }
  function showOpening() {
    const old = document.querySelector("#gtm-inspector");
    if (old) {
      const template = document.createElement("div");
      template.innerHTML = inspectorShell();
      old.replaceWith(template.querySelector("#gtm-inspector"));
    } else if (ctx().gtmDetail) {
      const workspace = document.querySelector(".gtm-workspace");
      workspace.classList.add("editing");
      workspace.insertAdjacentHTML("beforeend", inspectorShell());
    } else
      document
        .querySelector(".gtm-page")
        .insertAdjacentHTML("beforeend", inspectorShell());
    document
      .querySelectorAll(".gtm-node[data-id]")
      .forEach((node) =>
        node.classList.toggle(
          "selected",
          node.dataset.kind === opening.kind && node.dataset.id === opening.id,
        ),
      );
    mountDrawer();
    document
      .querySelector('#gtm-inspector [data-action="gtm.inspector.close"]')
      ?.focus({ preventScroll: true });
  }
  function mountDrawer() {
    const drawer = document.querySelector("#gtm-drawer");
    if (drawer && !drawer.open) {
      drawer.addEventListener("cancel", (event) => {
        event.preventDefault();
        actions["gtm.inspector.close"]();
      });
      drawer.addEventListener("click", (event) => {
        const rect = drawer.getBoundingClientRect();
        if (
          event.target === drawer &&
          (event.clientX < rect.left ||
            event.clientX > rect.right ||
            event.clientY < rect.top ||
            event.clientY > rect.bottom)
        )
          actions["gtm.inspector.close"]();
      });
      drawer.showModal();
      document
        .querySelector(
          "#gtm-inspector input:not([readonly]), #gtm-inspector textarea, #gtm-inspector select",
        )
        ?.focus({ preventScroll: true });
      restoreListPosition(editor?.returnTo || opening?.returnTo, false);
    }
  }
  function displayChangePath(path, fields) {
    return gtmFieldPath(path, fields, (id) => {
      const plain = id.split(":")[0],
        row = knownResources().get(id) || knownResources().get(plain);
      if (row) return displayName(row);
      if (id.includes("/")) {
        const node =
          editor &&
          currentProbeNodes().find(
            (node) => node.CityCode + "/" + node.IspCode === id,
          );
        if (node) return [node.CityName, node.IspName].join(" / ");
      }
      return "关联项";
    });
  }
  function formatValue(value, key = "") {
    if (value === undefined) return '<span class="help">未设置</span>';
    if (value === null) return '<span class="help">空值</span>';
    if (Array.isArray(value))
      return value.length
        ? `<div class="gtm-preview-values">${value.map((item) => formatValue(item)).join("")}</div>`
        : '<span class="help">空列表（解除引用）</span>';
    if (value && typeof value === "object")
      return `<dl class="gtm-values">${Object.entries(value)
        .map(
          ([key, v]) =>
            `<dt>${e(gtmLabels[key] || key)}</dt><dd>${formatValue(v, key)}</dd>`,
        )
        .join("")}</dl>`;
    if (
      [
        "TemplateId",
        "AddressId",
        "AddressPoolId",
        "ConfigId",
        "InstanceId",
      ].includes(key)
    )
      return e(
        knownResources().has(value)
          ? displayName(knownResources().get(value))
          : "所选" + gtmLabels[key],
      );
    if (typeof value === "boolean") return value ? "是" : "否";
    return e(enums[value] || String(value));
  }
  function mayDiscard() {
    const form = document.querySelector("#gtm-editor-form");
    if (!editor || !form) return true;
    try {
      if (JSON.stringify(collect(form)) === JSON.stringify(editor.baseline))
        return true;
    } catch {}
    return window.confirm(
      "当前有未保存的修改，离开编辑会放弃这些内容，是否继续？",
    );
  }
  async function edit(operation, kind, id, attach = null) {
    if (!mayDiscard()) return;
    const requested = ++session;
    const c = ctx(),
      selectedAccount = c.account;
    const canvas = document.querySelector(".gtm-canvas");
    const returnTo = editor?.returnTo || {
      x: window.scrollX,
      y: window.scrollY,
      kind,
      id,
      canvas: canvas
        ? { left: canvas.scrollLeft, top: canvas.scrollTop }
        : null,
    };
    if (!c.account) throw new Error("请先选择阿里云账号");
    const knownName = displayName(knownResources().get(id));
    editor = null;
    opening = { session: requested, kind, id, name: knownName, returnTo };
    showOpening();
    try {
      const allOperations = await catalog();
      if (requested !== session || selectedAccount !== ctx().account) return;
      const sourceOp = allOperations[operation];
      const operations = Object.entries(allOperations).filter(
        ([, spec]) => spec.kind === kind && !spec.create,
      );
      const op =
        sourceOp && !sourceOp.create && !sourceOp.delete
          ? {
              ...sourceOp,
              fields: Object.assign(
                {},
                ...operations
                  .filter(([, spec]) => !spec.delete)
                  .map(([, spec]) => spec.fields),
              ),
            }
          : sourceOp;
      if (!op) throw new Error("不支持此操作");
      const needed = optionSections(op.fields, op.create);
      for (const section of needed) options(operation, section).catch(() => {});
      const known = knownResources().get(id);
      const detail = op.create
        ? { resource: {}, token: null }
        : await resourceDetail(op.kind, id, { instance: known?.InstanceId });
      const resource = normalizeGtm(detail.resource),
        opts = { errors: {} };
      for (const section of needed)
        Object.assign(opts, cachedOptions(section) || {});
      const strategy =
        resource.AddressPoolLbStrategy || resource.AddressLbStrategy;
      if (strategy === "source_nearest") {
        const cached = cachedOptions("lines");
        if (cached) Object.assign(opts, cached);
      }
      if (requested !== session || selectedAccount !== ctx().account) return;
      opening = null;
      editor = {
        session: requested,
        returnTo,
        attach,
        root: c.gtmDetail
          ? {
              kind: c.gtmDetail.kind,
              id: c.gtmDetail.resource[ids[c.gtmDetail.kind]],
            }
          : null,
        account: c.account,
        operation,
        operations,
        spec: op,
        resource,
        token: detail.token,
        references: [],
        options: opts,
        values: initial(op.fields, resource, op.create),
      };
      if (op.fields.ExtendInfo)
        editor.values.ExtendInfo =
          resource.ExtendInfo ||
          ((editor.values.Protocol || resource.Protocol) === "ping"
            ? { packetNum: 20, packetLossRate: 30 }
            : { path: "/", code: 500, sni: true, followRedirect: false });
      if (attach?.parenttype && op.create) {
        if (op.kind === "addresses") editor.values.Type = attach.parenttype;
        if (op.kind === "pools")
          editor.values.AddressPoolType =
            attach.parenttype === "AAAA"
              ? "IPv6"
              : attach.parenttype === "CNAME"
                ? "domain"
                : "IPv4";
      }
      editor.baseline = structuredClone(editor.values);
      drawEditor();
    } catch (error) {
      if (session === requested) {
        actions["gtm.inspector.close"]();
        restoreListPosition(returnTo);
      }
      throw error;
    }
  }
  async function open(kind, id, fresh = false) {
    if (!mayDiscard()) return;
    editor = null;
    opening = null;
    ++session;
    const c = ctx(),
      account = c.account;
    const requested = session,
      known = knownResources().get(id);
    document.querySelector("#gtm-drawer")?.close();
    document.querySelector("#gtm-drawer")?.remove();
    document.querySelector("#gtm-inspector")?.remove();
    document.querySelector(".gtm-workspace")?.classList.remove("editing");
    document.querySelector("#gtm-canvas-loading")?.remove();
    const notice = document.createElement("p");
    notice.id = "gtm-canvas-loading";
    notice.className = "notice";
    notice.setAttribute("role", "status");
    notice.textContent = "正在读取云端画布…";
    document.querySelector(".gtm-page")?.prepend(notice);
    if (["list", "pools", "addresses"].includes(kind))
      options("UpdateCloudGtmAddress", "monitors").catch(() => {});
    try {
      const [detail] = await Promise.all([
        resourceDetail(kind, id, { fresh, instance: known?.InstanceId }),
        catalog(),
      ]);
      if (c.account !== account || session !== requested) return;
      c.gtmDetail = detail;
      updateListedResource(detail);
      render();
      const root = normalizeGtm(detail.resource);
      if (
        root.AddressPoolLbStrategy === "source_nearest" ||
        root.AddressLbStrategy === "source_nearest" ||
        root.AddressPools?.some(
          (pool) => pool.AddressLbStrategy === "source_nearest",
        )
      ) {
        options("ReplaceCloudGtmInstanceConfigAddressPool", "lines")
          .then((result) => {
            if (c.account !== account || c.gtmDetail !== detail) return;
            for (const label of document.querySelectorAll(
              "[data-gtm-edge-sources]",
            )) {
              label.textContent =
                "来源：" +
                gtmSourceLabel(
                  { RequestSource: JSON.parse(label.dataset.gtmEdgeSources) },
                  result.lines?.SystemLines?.SystemLine || [],
                );
            }
          })
          .catch(() => {});
      }
    } finally {
      notice.remove();
    }
  }
  function restoreListPosition(position, focus = true) {
    if (!position) return;
    const restore = () => {
      if (focus) {
        const selector = position.id
          ? `[data-action="gtm.edit"][data-kind="${CSS.escape(position.kind)}"][data-id="${CSS.escape(position.id)}"]`
          : `[data-action="gtm.create"][data-kind="${CSS.escape(position.kind)}"]`;
        document.querySelector(selector)?.focus({ preventScroll: true });
      }
      if (position.canvas) {
        const canvas = document.querySelector(".gtm-canvas");
        if (canvas) {
          canvas.scrollLeft = position.canvas.left;
          canvas.scrollTop = position.canvas.top;
        }
      }
      window.scrollTo(position.x, position.y);
    };
    restore();
    requestAnimationFrame(restore);
  }
  function inspectorShell() {
    if (!editor && !opening) return "";
    const loading = opening
      ? `<div class="gtm-loading"><header class="gtm-inspector-head"><div><small>${e(kinds[opening.kind])}</small><h3 id="gtm-editor-title">${e(opening.name)}</h3></div>${button("×", "gtm.inspector.close", {}, "icon-only")}</header><div class="gtm-inspector-body" role="status">正在读取云端配置…</div><footer class="gtm-inspector-foot">${button("关闭", "gtm.inspector.close")}</footer></div>`
      : "";
    const content = `<aside class="gtm-inspector" id="gtm-inspector" aria-label="GTM 参数编辑" ${opening ? `data-session="${opening.session}" data-loading="1"` : ""}>${loading}</aside>`;
    return ctx().gtmDetail && !window.matchMedia("(max-width: 800px)").matches
      ? content
      : `<dialog class="gtm-drawer" id="gtm-drawer" aria-labelledby="gtm-editor-title">${content}</dialog>`;
  }
  const defaultCanvasZoom = 0.8;
  let canvasZoom = defaultCanvasZoom,
    zoomContext = "";
  function zoomCanvas(next, anchor = null) {
    const canvas = document.querySelector(".gtm-canvas"),
      surface = canvas?.querySelector(".gtm-zoom-surface"),
      graph = surface?.querySelector(".gtm-graph");
    if (!graph) return;
    const old = Number(surface.dataset.zoom || 1),
      rect = surface.getBoundingClientRect();
    const cr = canvas.getBoundingClientRect();
    const point = anchor || {
      x: cr.left + canvas.clientWidth / 2,
      y: cr.top + canvas.clientHeight / 2,
    };
    const x = (point.x - rect.left) / old,
      y = (point.y - rect.top) / old;
    const width = Number(surface.dataset.width || graph.scrollWidth),
      height = graph.offsetHeight;
    canvasZoom = Math.max(0.25, Math.min(2, next));
    Object.assign(surface.dataset, { width, height, zoom: canvasZoom });
    graph.style.width = width + "px";
    graph.style.minWidth = width + "px";
    graph.style.position = "absolute";
    graph.style.transform = `scale(${canvasZoom})`;
    surface.style.width = width * canvasZoom + "px";
    surface.style.height = height * canvasZoom + "px";
    canvas.scrollLeft += x * (canvasZoom - old);
    canvas.scrollTop += y * (canvasZoom - old);
    const label = canvas
      .closest(".gtm-workbench")
      .querySelector('[data-action="gtm.zoom.reset"]');
    if (label) label.textContent = Math.round(canvasZoom * 100) + "%";
  }
  function mountCanvas() {
    const canvas = document.querySelector(".gtm-canvas");
    if (!canvas || canvas.dataset.zoomBound) return;
    canvas.dataset.zoomBound = "1";
    const detail = ctx().gtmDetail;
    const key = `${ctx().account}:${detail?.kind}:${detail?.resource?.[ids[detail?.kind]]}`;
    if (key !== zoomContext) {
      zoomContext = key;
      canvasZoom = defaultCanvasZoom;
    }
    zoomCanvas(canvasZoom);
    let pinch = null,
      suppressClickUntil = 0;
    const measure = (touches) => ({
      distance: Math.hypot(
        touches[0].clientX - touches[1].clientX,
        touches[0].clientY - touches[1].clientY,
      ),
      x: (touches[0].clientX + touches[1].clientX) / 2,
      y: (touches[0].clientY + touches[1].clientY) / 2,
    });
    canvas.addEventListener(
      "touchstart",
      (event) => {
        if (event.touches.length !== 2) return;
        event.preventDefault();
        const point = measure(event.touches);
        pinch = { ...point, zoom: canvasZoom };
        suppressClickUntil = Date.now() + 400;
      },
      { passive: false },
    );
    canvas.addEventListener(
      "touchmove",
      (event) => {
        if (!pinch || event.touches.length !== 2) return;
        event.preventDefault();
        const point = measure(event.touches);
        if (pinch.distance)
          zoomCanvas((pinch.zoom * point.distance) / pinch.distance, point);
        suppressClickUntil = Date.now() + 400;
      },
      { passive: false },
    );
    const end = (event) => {
      if (event.touches.length < 2) pinch = null;
    };
    canvas.addEventListener("touchend", end);
    canvas.addEventListener("touchcancel", end);
    canvas.addEventListener(
      "click",
      (event) => {
        if (Date.now() < suppressClickUntil) {
          event.preventDefault();
          event.stopPropagation();
        }
      },
      true,
    );
  }
  function topology(detail) {
    const resource = normalizeGtm(detail.resource),
      kind = detail.kind;
    const opFor = (type, mode) =>
      ({
        list: {
          basic: "UpdateCloudGtmInstanceConfigBasic",
          policy: "UpdateCloudGtmInstanceConfigLbStrategy",
          link: "ReplaceCloudGtmInstanceConfigAddressPool",
        },
        pools: {
          basic: "UpdateCloudGtmAddressPoolBasicConfig",
          policy: "UpdateCloudGtmAddressPoolLbStrategy",
          link: "ReplaceCloudGtmAddressPoolAddress",
        },
        addresses: {
          basic: "UpdateCloudGtmAddress",
          link: "UpdateCloudGtmAddress",
        },
        monitors: { basic: "UpdateCloudGtmMonitorTemplate" },
      })[type][mode];
    const card = (type, row) => {
      const selected =
        editor?.spec.kind === type &&
        editor.resource[ids[type]] === row[ids[type]];
      return `<article role="button" tabindex="0" aria-label="${row.EnableStatus === "disable" ? "未启用，" : ""}编辑 ${e(gtmName(row))}" data-action="gtm.edit" data-kind="${type}" data-id="${e(row[ids[type]])}" data-operation="${opFor(type, "basic")}" class="gtm-node gtm-node-${type} gtm-state-${gtmStatus(row)} ${row.EnableStatus === "disable" ? "gtm-node-paused" : ""} ${selected ? "selected" : ""}"><div class="gtm-node-title">${stateBadge(row)}<span class="gtm-kind">${e(kinds[type])}</span></div><div class="gtm-node-name">${e(gtmName(row))}</div>${row.EnableStatus === "disable" ? '<span class="gtm-pause-icon" role="img" aria-label="未启用" title="未启用"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9 7v10M15 7v10"/></svg></span>' : ""}${row.Ttl ? `<div class="gtm-node-meta">TTL ${e(row.Ttl)}s</div>` : ""}${row.Address ? `<div class="gtm-node-address mono">${e(row.Address)}</div>` : ""}${type === "addresses" && row.HealthTasks?.length ? `<div class="gtm-monitor-chips">${row.HealthTasks.map((task) => `<span data-gtm-template="${e(task.TemplateId)}" data-port="${e(task.Port ?? "")}">${e(task.TemplateName || displayName(knownResources().get(task.TemplateId)))}${task.Port !== undefined ? ":" + e(task.Port) : ""}</span>`).join("")}</div>` : ""}</article>`;
    };
    const incoming = (row, strategy) => {
      const text =
        strategy === "weight" && row.WeightValue !== undefined
          ? `权重：${row.WeightValue}`
          : ["sequence", "source_nearest"].includes(strategy) &&
              row.SerialNumber !== undefined
            ? `顺序号：${row.SerialNumber}`
            : "";
      const source =
        strategy === "source_nearest"
          ? `<span class="gtm-edge-source" data-gtm-edge-sources="${e(JSON.stringify(row.RequestSource || []))}">来源：${e(gtmSourceLabel(row, cachedOptions("lines")?.lines?.SystemLines?.SystemLine || []))}</span>`
          : "";
      return source || text
        ? `<span class="gtm-edge-label gtm-edge-incoming">${source}${text ? `<span>${e(text)}</span>` : ""}</span>`
        : "";
    };
    const outgoing = (row, hasChildren) => {
      const strategy = row.AddressPoolLbStrategy || row.AddressLbStrategy;
      return hasChildren && enums[strategy]
        ? `<span class="gtm-edge-label gtm-edge-policy" title="${e([enums[strategy], enums[row.SequenceLbStrategyMode]].filter(Boolean).join(" · "))}">${e(strategy === "sequence" ? "顺序（主备）" : enums[strategy])}</span>`
        : "";
    };
    const branch = (pool) => {
      const children = pool.Addresses || [];
      const inactive = gtmEdgeInactive(pool) || gtmEdgeInactive(resource);
      return `<section class="gtm-lane gtm-edge-${gtmStatus(pool)} ${inactive ? "gtm-edge-inactive" : ""}">${kind === "list" ? incoming(pool, resource.AddressPoolLbStrategy) : ""}<div class="gtm-pool-column ${children.length ? "" : "gtm-no-children"}">${card("pools", pool)}${outgoing(pool, children.length)}</div><div class="gtm-address-grid gtm-tree-addresses">${children.map((address) => `<div class="gtm-address-leaf gtm-edge-${gtmStatus(address)} ${inactive || gtmEdgeInactive(address) ? "gtm-edge-inactive" : ""}">${incoming(address, pool.AddressLbStrategy)}${card("addresses", address)}</div>`).join("") || '<span class="help">暂无地址，点击地址池，在编辑区添加。</span>'}</div></section>`;
    };
    let graph;
    if (kind === "list")
      graph = `<div class="gtm-domain-column gtm-edge-${gtmStatus(resource)} ${gtmEdgeInactive(resource) ? "gtm-edge-inactive" : ""} ${(resource.AddressPools || []).length ? "" : "gtm-no-children"}">${card("list", resource)}${outgoing(resource, resource.AddressPools?.length)}</div><div class="gtm-lanes">${(resource.AddressPools || []).map(branch).join("") || '<p class="help">暂无地址池，点击接入域名，在编辑区添加。</p>'}</div>`;
    else if (kind === "pools") graph = branch(resource);
    else if (kind === "addresses")
      graph = `<div class="gtm-address-grid">${card("addresses", resource)}</div>`;
    else graph = card("monitors", resource);
    return `<section class="gtm-workbench"><header class="gtm-workbench-head"><div class="toolbar">${button("返回列表", "gtm.close", {}, "text")}<strong>${e(gtmName(resource))}</strong>${badge("GTM 3.0")}</div><div class="toolbar">${button("刷新画布", "gtm.canvas.refresh", {}, "text")}${button("新建地址池", "gtm.create", { kind: "pools" }, "text", "plus")}${button("新建地址", "gtm.create", { kind: "addresses" }, "text", "plus")}</div></header><div class="gtm-canvas-tools"><span class="gtm-canvas-hint">点击节点编辑 · 双指缩放画布</span><div class="gtm-zoom-controls" aria-label="画布缩放"><button type="button" class="button text" data-action="gtm.zoom.out" aria-label="缩小画布">−</button><button type="button" class="button text" data-action="gtm.zoom.reset" aria-label="重置画布缩放为 80%">80%</button><button type="button" class="button text" data-action="gtm.zoom.in" aria-label="放大画布">＋</button></div></div><div class="gtm-workspace ${editor || opening ? "editing" : ""}"><div class="gtm-canvas" aria-label="GTM 关联画布"><div class="gtm-zoom-surface"><div class="gtm-graph gtm-graph-${kind}">${graph}</div></div></div>${inspectorShell()}</div></section>`;
  }
  function view(c) {
    nameIndex = null;
    const tab = c.tab in kinds ? c.tab : "list";
    if (
      tab === "list" &&
      !c.gtmDetail &&
      !editor &&
      c.items?.length &&
      warmedList !== c.items
    ) {
      const items = c.items,
        account = c.account;
      warmedList = items;
      setTimeout(() => {
        if (
          ctx().items === items &&
          ctx().account === account &&
          !ctx().gtmDetail &&
          !editor
        ) {
          warmDetail("list", items[0].ConfigId, items[0].InstanceId);
          options("UpdateCloudGtmAddress", "monitors").catch(() => {});
        }
      }, 200);
    }
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
    if (c.gtmDetail) return tabs + topology(c.gtmDetail);
    return (
      tabs +
      (c.gtmListLoading ? `<p class="help" role="status">${c.items.length ? "正在更新列表…" : "正在加载列表…"}</p>` : "") +
      `<div class="notice">GTM 3.0 · 地址池、地址、探测模板为账号级共享资源。先选择账号并查询，再打开资源进行可视化配置。</div>` +
      panel(
        kinds[tab],
        table(
          ["名称", "类型 / 策略", "运行状态", "操作"],
          c.items.map((row) => [
            `<strong>${e(displayName(row))}</strong>`,
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
            openButton(tab, row) + button("删除", "gtm.listDelete", { kind: tab, id: row[ids[tab]] }, "text danger"),
          ]),
        ),
        c.account ? button(
          "创建" + kinds[tab],
          "gtm.create",
          { kind: tab },
          "primary",
          "plus",
        ) : `<button type="button" class="button primary" disabled title="请先选择云账号">创建${e(kinds[tab])}</button>`,
      ) +
      inspectorShell()
    );
  }
  const actions = {
    "gtm.nodes.open": () => {
      editor.values = collect(document.querySelector("#gtm-editor-form"));
      editor.nodesOpen = !editor.nodesOpen;
      drawEditor();
    },
    "gtm.nodes.branch": (d) => {
      editor.nodeGroup = d.group || null;
      editor.nodeCity = d.city || null;
      const input = document.querySelector(".gtm-node-search");
      if (input) input.value = "";
      drawNodes("");
    },
    "gtm.nodes.remove": (d) => {
      editor.values = collect(document.querySelector("#gtm-editor-form"));
      editor.values.IspCityNodes.splice(Number(d.index), 1);
      refreshNodeTags();
      drawNodes();
    },
    "gtm.options.retry": (d) => requestEditorOptions(d.section, true),
    "gtm.lines.open": async (d) => {
      editor.values = collect(document.querySelector("#gtm-editor-form"));
      editor.linePath = editor.linePath === d.path ? null : d.path;
      editor.lineBranch = "";
      drawEditor();
      if (!editor.linePath) return;
      try {
        await loadOptions("lines");
        drawLines();
      } catch (error) {
        const status = document.querySelector("#gtm-aux-status");
        if (status) status.textContent = error.message;
      }
    },
    "gtm.lines.branch": (d) => {
      editor.lineBranch = d.code;
      const input = document.querySelector(".gtm-lines .gtm-line-search");
      if (input) input.value = "";
      drawLines();
    },
    "gtm.tab": async (d) => {
      if (!mayDiscard()) return;
      ++session;
      editor = null;
      opening = null;
      const c = ctx();
      c.tab = d.tab;
      c.page = 1;
      c.gtmDetail = null;
      if (d.tab === "history") {
        if (!c.account) throw new Error("请先选择账号");
        await catalog();
        c.gtmHistory = await api("gtm.history", { account_id: c.account });
        render();
      } else if (c.account) await queryResources({ reuseGtmPage: true });
      else render();
    },
    "gtm.change": (d) => {
      const change = ctx().gtmHistory[Number(d.index)];
      const spec = ctx().gtmCatalog?.[change.operation];
      const changes = gtmChanges(
        change.before,
        change.parameters,
        spec?.fields,
        spec?.delete || change.operation.startsWith("Delete"),
      );
      modal(
        "GTM 变更详情",
        change.error || `请求编号：${change.result?.request_id || "—"}`,
        table(
          ["字段", "变更前", "提交值"],
          changes.map((item) => [
            e(displayChangePath(item.path, spec?.fields)),
            formatValue(item.before),
            formatValue(item.after),
          ]),
        ) + errorDetails(change.error_details),
        async () => {},
        "关闭",
      );
    },
    "gtm.open": (d) => open(d.kind, d.id),
    "gtm.close": () => {
      if (!mayDiscard()) return;
      ++session;
      editor = null;
      opening = null;
      ctx().gtmDetail = null;
      render();
    },
    "gtm.create": async (d) => {
      if (!ctx().account) throw new Error("请先选择云账号");
      const op = Object.entries(await catalog()).find(
        ([, op]) => op.kind === d.kind && op.create,
      )?.[0];
      await edit(
        op,
        d.kind,
        undefined,
        d.parentid
          ? { kind: d.parentkind, id: d.parentid, parenttype: d.parenttype }
          : null,
      );
    },
    "gtm.zoom.in": () => zoomCanvas(canvasZoom + 0.1),
    "gtm.zoom.out": () => zoomCanvas(canvasZoom - 0.1),
    "gtm.zoom.reset": () => zoomCanvas(defaultCanvasZoom),
    "gtm.edit": (d) => edit(d.operation, d.kind, d.id),
    "gtm.listDelete": (d) => {
      const current = ctx();
      const account = current.account;
      const row = current.items.find((item) => item[ids[d.kind]] === d.id);
      if (!row) return;
      modal("确认删除 GTM 资源", `将删除「${gtmName(row)}」。仍被引用的资源需要先解除关联。`, "", async () => {
        if (ctx() !== current || current.account !== account) throw new Error("账号已变化，请重新操作");
        const operations = await catalog();
        const operation = Object.keys(operations).find((key) => operations[key].kind === d.kind && operations[key].delete);
        if (!operation) throw new Error("此资源不支持删除");
        const parameters = { [ids[d.kind]]: d.id };
        if (d.kind === "list" && row.InstanceId) parameters.InstanceId = row.InstanceId;
        const preview = await api("gtm.preview", { account_id: account, operation, parameters, list_delete: true });
        const result = await api("gtm.action", { account_id: account, ticket: preview.ticket });
        detailCache.clear();
        current.gtmListPages?.clear();
        if (current.gtmOptions) delete current.gtmOptions[`${account}:${result.kind}`];
        if (ctx() !== current || current.account !== account || current.tab !== d.kind) return;
        toast(result.verification === "confirmed" ? "资源已删除" : "删除请求已提交，请刷新核对");
        if (result.verified_page && Number(current.page || 1) === 1) {
          current.serial = (current.serial || 0) + 1;
          current.items = result.verified_page.items;
          current.total = result.verified_page.total;
          current.raw = result.verified_page;
          current.error = "";
          render();
        } else await queryResources();
      }, "确认删除", true);
    },
    "gtm.confirmDelete": (d) => {
      const current = editor;
      if (!current || current.resource[ids[d.kind]] !== d.id) return;
      const spec = current.operations.find(([operation]) => operation === d.operation)?.[1];
      if (!spec?.delete) return;
      const parameters = Object.fromEntries(Object.keys(spec.fields)
        .filter((key) => current.resource[key] !== undefined)
        .map((key) => [key, current.resource[key]]));
      modal("确认删除 GTM 资源", `将删除「${gtmName(current.resource)}」，其他未提交的编辑不会保存。`, "", async () => {
        if (editor !== current) throw new Error("编辑对象已变化，请重新打开");
        await current.confirmedSubmit({operation: d.operation, parameters, deleting: true});
      }, "确认删除", true);
    },
    "gtm.back": () => {
      document.querySelector("#dialog").close();
      drawEditor();
    },
    "gtm.inspector.close": () => {
      if (!mayDiscard()) return;
      ++session;
      const position = editor?.returnTo || opening?.returnTo;
      const drawer = document.querySelector("#gtm-drawer");
      drawer?.close();
      editor = null;
      opening = null;
      if (drawer) drawer.remove();
      document.querySelector("#gtm-inspector")?.remove();
      document.querySelector(".gtm-workspace")?.classList.remove("editing");
      document
        .querySelectorAll(".gtm-node.selected")
        .forEach((node) => node.classList.remove("selected"));
      restoreListPosition(position);
    },
    "gtm.canvas.refresh": () => {
      const detail = ctx().gtmDetail;
      detailCache.clear();
      delete ctx().gtmOptions;
      nameIndex = null;
      return open(detail.kind, detail.resource[ids[detail.kind]], true);
    },
    "gtm.row.add": (d) => {
      editor.values = collect(document.querySelector("#gtm-editor-form"));
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
        if (schema.items.properties.RequestSource)
          item.RequestSource = ["default"];
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
      editor.values = collect(document.querySelector("#gtm-editor-form"));
      const path = JSON.parse(d.path);
      let parent = editor.values;
      for (const key of path.slice(0, -1)) parent = parent[key];
      const key = path.at(-1);
      parent[key] = moveGtmRow(parent[key], Number(d.index), Number(d.to));
      drawEditor();
    },
    "gtm.row.remove": (d) => {
      const current = editor;
      const next = collect(document.querySelector("#gtm-editor-form"));
      let items = next;
      for (const key of JSON.parse(d.path)) items = items[key];
      items.splice(Number(d.index), 1);
      modal("确认移除当前项", current.spec.create ? "从当前新建草稿移除此项。" : "移除此项并提交当前表单，包括其他未保存的修改。关联资源本身不会被删除。", "", async () => {
        if (editor !== current) throw new Error("编辑对象已变化，请重新打开");
        if (current.spec.create) { current.values = next; drawEditor(); return; }
        const parameters = structuredClone(next);
        if (Object.hasOwn(current.spec.fields, "ExtendInfo")) parameters.ExtendInfo = JSON.stringify(parameters.ExtendInfo || {});
        await current.confirmedSubmit({parameters});
      }, "确认移除", true);
    },
  };
  return {
    view,
    actions,
    mount: mountCanvas,
    leave: () => {
      if (!mayDiscard()) return false;
      ++session;
      editor = null;
      opening = null;
      return true;
    },
  };
}
