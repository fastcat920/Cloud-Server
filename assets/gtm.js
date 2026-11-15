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
const stateBadge = (r) =>
  `${badge(enums[r.EnableStatus] || r.Protocol || "—", r.EnableStatus === "enable" ? "good" : "")}${r.HealthStatus ? badge({ ok: "健康", ok_alert: "异常", ok_no_monitor: "未探测", exceptional: "异常" }[r.HealthStatus] || r.HealthStatus, r.HealthStatus === "exceptional" ? "bad" : "") : ""}${r.AvailableStatus ? badge(enums[r.AvailableStatus] || r.AvailableStatus, r.AvailableStatus === "unavailable" ? "bad" : "good") : ""}`;
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
export function gtmStatus(row) {
  if (
    row.HealthStatus === "exceptional" ||
    row.AvailableStatus === "unavailable" ||
    row.EnableStatus === "disable"
  )
    return "bad";
  if (row.HealthStatus === "ok_alert") return "warn";
  if (row.HealthStatus === "ok" || row.AvailableStatus === "available")
    return "good";
  return "unknown";
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
    Remark: "",
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
    session = 0;
  let nameIndex = null;
  async function catalog() {
    const c = ctx();
    c.gtmCatalog ??= await api("gtm.catalog", { account_id: c.account });
    return c.gtmCatalog;
  }
  async function options(operation, section) {
    const c = ctx(),
      account = c.account;
    c.gtmOptions ??= {};
    const key = `${account}:${section}`;
    const cached = c.gtmOptions[key];
    if (
      cached &&
      Date.now() - cached.time < (section === "lines" ? 600000 : 30000)
    )
      return cached.promise;
    const promise = api("gtm.options", {
      account_id: account,
      operation,
      unified: true,
      section,
    });
    c.gtmOptions[key] = { time: Date.now(), promise };
    try {
      const result = await promise;
      if (Object.keys(result.errors || {}).length) delete c.gtmOptions[key];
      return result;
    } catch (error) {
      delete c.gtmOptions[key];
      throw error;
    }
  }
  function knownResources() {
    if (nameIndex) return nameIndex;
    const found = new Map();
    const walk = (row) => {
      if (!row || typeof row !== "object") return;
      for (const key of Object.values(ids).concat("InstanceId"))
        if (row[key]) found.set(row[key], row);
      for (const task of row.HealthTasks || [])
        if (task.TemplateName)
          found.set(task.TemplateId, { Name: task.TemplateName });
      for (const child of [
        ...(row.AddressPools || []),
        ...(row.Addresses || []),
      ])
        walk(child);
    };
    for (const row of ctx().items || []) walk(normalizeGtm(row));
    if (ctx().gtmDetail) walk(normalizeGtm(ctx().gtmDetail.resource));
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
    Object.assign(editor.options, result);
    nameIndex = null;
    if (result.errors?.[section]) throw new Error(result.errors[section]);
    if (section === "lines") refreshLineTags();
    return result;
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
      result &&
      value !== undefined &&
      value !== "" &&
      !result.some((row) => String(row.value) === String(value))
    )
      result.unshift({
        value,
        label: displayName(knownResources().get(value)),
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
        if (key === "RequestSource")
          return lineControl(p, Array.isArray(v) ? v : [], spec.label);
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
            return `<fieldset class="gtm-fieldset" data-gtm-nodes><legend>${e(spec.label)}</legend>${visibleNodes.length ? visibleNodes.map((node, i) => `<label class="check"><input type="checkbox" data-gtm-node="${i}" data-city="${e(node.CityCode)}" data-isp="${e(node.IspCode)}" ${items.some((item) => item.CityCode === node.CityCode && item.IspCode === node.IspCode) ? "checked" : ""}>${e([node.GroupName, node.CityName, node.IspName].filter(Boolean).join(" / "))}</label>`).join("") : '<p class="help">' + (editor.options.nodes ? "没有可用探测节点，请检查查询权限。" : "正在加载探测节点…") + "</p>"}</fieldset>`;
          }
          return `<fieldset class="gtm-fieldset"><legend>${e(spec.label)}${spec.required ? " *" : ""}</legend>${["Addresses", "AddressPools"].includes(key) ? '<p class="help">这里只修改当前关联列表，不会全局删除资源。上移/下移会重排优先序号；顺序/主备策略才按该序号调度，权重和线路保持不变。</p>' : ""}${items.length ? items.map((item, i) => `<div class="gtm-repeat"><div class="gtm-repeat-head"><strong>第 ${i + 1} 项</strong><span class="toolbar">${["Addresses", "AddressPools"].includes(key) && ["sequence", "source_nearest"].includes(strategy) ? button("上移", "gtm.row.move", { path: encoded, index: i, to: i - 1 }, "text") + button("下移", "gtm.row.move", { path: encoded, index: i, to: i + 1 }, "text") : ""}${button(["Addresses", "AddressPools"].includes(key) ? "解除引用" : "移除", "gtm.row.remove", { path: encoded, index: i }, "text danger")}</span></div>${spec.items.type === "object" ? controls(spec.items.properties, item, [...p, String(i)]) : scalar(key, spec.items, item, [...p, String(i)])}</div>`).join("") : '<p class="help">暂无关联项。清空关联列表会解除全部引用。</p>'}${button("添加一项", "gtm.row.add", { path: encoded }, "text", "plus")}</fieldset>`;
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
    return `<div data-gtm-path="${e(encoded)}" data-type="${spec.type}" data-required="${spec.required ? "1" : "0"}" data-input="${e(name)}" ${sourceKind(key) && !identity ? `data-gtm-source="${sourceKind(key)}"` : ""}>${input}</div>`;
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
    return `${editor.bindingHint ? `<div class="notice">${e(editor.bindingHint)}</div>` : ""}${!editor.spec.create && editor.spec.kind !== "list" ? '<div class="notice warn">共享资源的修改会影响其他引用，提交预览时将核对引用范围。</div>' : ""}<p id="gtm-aux-status" class="help" role="status"></p>${errors.length ? `<details class="notice warn"><summary>部分辅助资源读取失败</summary>${errors.map(e).join("<br>")}</details>` : ""}<div class="gtm-form-grid">${controls(editor.spec.fields, editor.values)}</div>${Object.hasOwn(editor.spec.fields, "ExtendInfo") && protocol !== "tcp" ? `<fieldset class="gtm-fieldset"><legend>${e(protocol?.toUpperCase() || "")} 协议参数</legend><div class="gtm-form-grid">${controls(Object.fromEntries(protocolFields.map((key) => [key, extensionSchema[key]])), editor.values.ExtendInfo || {}, ["ExtendInfo"])}</div></fieldset>` : ""}`;
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
    const canvas = document.querySelector(".gtm-canvas");
    const scroll = {
      left: canvas?.scrollLeft || 0,
      top: canvas?.scrollTop || 0,
    };
    render();
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
                "gtm.edit",
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
    box.innerHTML = `<form id="gtm-editor-form"><header class="gtm-inspector-head"><div><small>${e(kinds[editor.spec.kind])}</small><h3>${e(gtmName(editor.resource))}</h3></div>${button("×", "gtm.inspector.close", {}, "icon-only")}</header><div class="gtm-inspector-body"><div class="toolbar">${extra}</div><div class="dialog-error" role="alert"></div>${editorBody()}</div><footer class="gtm-inspector-foot">${button("关闭", "gtm.inspector.close", {}, "text")}<button type="submit" class="button primary">预览并提交</button></footer></form>`;
    const onSubmit = async (_, form) => {
      editor.values = collect(form);
      const parameters = payload();
      let request = { operation: editor.operation, parameters };
      if (!editor.spec.create && !editor.spec.delete) {
        const steps = editor.operations
          .filter(([, op]) => !op.delete)
          .map(([operation, op]) => {
            const parametersForOperation = Object.fromEntries(
              Object.keys(op.fields)
                .filter((key) => Object.hasOwn(parameters, key))
                .map((key) => [key, parameters[key]]),
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
        request = { steps };
      }
      const preview = await api("gtm.preview", {
        account_id: editor.account,
        expected_token: editor.token,
        ...request,
      });
      const savedEditor = editor;
      setTimeout(() => {
        if (editor?.session !== currentSession) return;
        const changes = gtmChanges(
          preview.before,
          preview.parameters,
          savedEditor.spec.fields,
          savedEditor.spec.delete,
        );
        modal(
          "确认 GTM 变更",
          `将对「${gtmName(savedEditor.resource)}」执行「${preview.label}」。${preview.references.length ? `影响 ${preview.references.length} 项引用资源。` : ""}DNS 缓存可能使解析结果延后更新。`,
          table(
            ["参数", "原值", "提交值"],
            changes.map((change) => [
              e(change.path),
              formatValue(change.before),
              formatValue(change.after),
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
              result.partial
                ? "部分变更已提交，后续步骤已停止：" +
                    (result.error || "等待云端确认，请刷新后核对")
                : result.verification === "confirmed"
                  ? "阿里云已确认配置更新"
                  : "请求已提交，请刷新查询云端状态",
            );
            const root = savedEditor.root;
            editor = null;
            c.gtmDetail = null;
            await queryResources();
            if (
              root &&
              !(
                savedEditor.spec.delete &&
                root.kind === savedEditor.spec.kind &&
                root.id === result.resource_id
              )
            )
              await open(root.kind, root.id);
            else if (result.resource_id && !savedEditor.spec.delete)
              await open(savedEditor.spec.kind, result.resource_id);
            if (savedEditor.attach && result.resource_id) {
              const parent = savedEditor.attach;
              await edit(
                parent.kind === "list"
                  ? "ReplaceCloudGtmInstanceConfigAddressPool"
                  : "ReplaceCloudGtmAddressPoolAddress",
                parent.kind,
                parent.id,
              );
              const field =
                  parent.kind === "list" ? "AddressPools" : "Addresses",
                idField =
                  parent.kind === "list" ? "AddressPoolId" : "AddressId";
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
              editor.bindingHint =
                "新资源已创建，当前尚未绑定。请预览并确认此关联。";
              drawEditor();
            }
          },
          "确认提交",
        );
      }, 0);
    };
    const form = document.querySelector("#gtm-editor-form");
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      const btn = event.submitter;
      btn.disabled = true;
      try {
        await onSubmit(new FormData(form), form);
      } catch (error) {
        form.querySelector(".dialog-error").textContent = error.message;
      } finally {
        btn.disabled = false;
      }
    });
    drawLines();
    form
      .querySelector(".gtm-line-search")
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
    form.addEventListener("focusin", async (event) => {
      const container = event.target.closest("[data-gtm-source]");
      if (
        !container ||
        container.dataset.loaded === "1" ||
        container.dataset.loading
      )
        return;
      container.dataset.loading = "1";
      const status = form.querySelector("#gtm-aux-status");
      status.textContent = "正在加载可选资源…";
      try {
        await loadOptions(container.dataset.gtmSource);
        if (!form.isConnected) return;
        const path = JSON.parse(container.dataset.gtmPath),
          key = path.at(-1),
          input = event.target;
        const values = choices(key, {}, input.value) || [],
          selected = input.value;
        input.replaceChildren(
          ...(container.dataset.required === "1"
            ? []
            : [new Option("未设置", "")]),
          ...values.map((row) => new Option(row.label, row.value)),
        );
        input.value = selected;
        container.dataset.loaded = "1";
        status.textContent = "";
      } catch (error) {
        if (form.isConnected) status.textContent = error.message;
      } finally {
        delete container.dataset.loading;
      }
    });
    for (const key of [
      "AddressPoolLbStrategy",
      "AddressLbStrategy",
      "AvailableMode",
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
  function mayDiscard() {
    const form = document.querySelector("#gtm-editor-form");
    if (!editor || !form) return true;
    try {
      if (JSON.stringify(collect(form)) === JSON.stringify(editor.baseline))
        return true;
    } catch {}
    return window.confirm(
      "当前编辑尚未提交，切换操作会放弃这些修改，是否继续？",
    );
  }
  async function edit(operation, kind, id, attach = null) {
    if (!mayDiscard()) return;
    const requested = ++session;
    const c = ctx(),
      selectedAccount = c.account;
    if (!c.account) throw new Error("请先选择阿里云账号");
    const allOperations = await catalog();
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
    const known = knownResources().get(id);
    const detail = op.create
      ? { resource: {}, token: null }
      : await api("gtm.resource", {
          account_id: selectedAccount,
          kind: op.kind,
          resource: id,
          instance_id: known?.InstanceId,
        });
    const resource = normalizeGtm(detail.resource),
      opts = { errors: {} };
    if (requested !== session || selectedAccount !== ctx().account) return;
    editor = {
      session: requested,
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
    // Only monitor forms need auxiliary data without an explicit user interaction.
    if (op.fields.IspCityNodes)
      loadOptions("nodes")
        .then(() => {
          if (editor?.session !== requested) return;
          const form = document.querySelector("#gtm-editor-form");
          editor.values = collect(form);
          const container = document.createElement("div");
          container.innerHTML = controls(
            { IspCityNodes: editor.spec.fields.IspCityNodes },
            editor.values,
          );
          form
            .querySelector("[data-gtm-nodes]")
            ?.replaceWith(container.firstElementChild);
        })
        .catch((error) => {
          if (editor?.session === requested)
            document.querySelector("#gtm-aux-status").textContent =
              error.message;
        });
  }
  async function open(kind, id) {
    if (!mayDiscard()) return;
    editor = null;
    ++session;
    const c = ctx(),
      account = c.account;
    const requested = session,
      known = knownResources().get(id);
    const [detail] = await Promise.all([
      api("gtm.resource", {
        account_id: account,
        kind,
        resource: id,
        instance_id: known?.InstanceId,
      }),
      catalog(),
    ]);
    if (c.account !== account || session !== requested) return;
    c.gtmDetail = detail;
    render();
  }
  function inspectorShell() {
    return editor
      ? '<aside class="gtm-inspector" id="gtm-inspector" aria-label="GTM 参数编辑"></aside>'
      : "";
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
    const card = (type, row, parentStrategy) => {
      const selected =
        editor?.spec.kind === type &&
        editor.resource[ids[type]] === row[ids[type]];
      return `<article role="button" tabindex="0" aria-label="编辑 ${e(gtmName(row))}" data-action="gtm.edit" data-kind="${type}" data-id="${e(row[ids[type]])}" data-operation="${opFor(type, "basic")}" class="gtm-node gtm-node-${type} gtm-state-${gtmStatus(row)} ${selected ? "selected" : ""}"><div class="gtm-node-title"><span class="gtm-node-name">${e(gtmName(row))}</span><span class="gtm-kind">${e(kinds[type])}</span></div><div class="gtm-node-meta">${stateBadge(row)}${row.Ttl ? `<span>TTL ${e(row.Ttl)}s</span>` : ""}</div>${row.Address ? `<div class="gtm-node-address mono">${e(row.Address)}</div>` : ""}<div class="gtm-node-meta gtm-node-policy">${["sequence", "source_nearest"].includes(parentStrategy) && row.SerialNumber !== undefined ? `<span>序 ${e(row.SerialNumber)}</span>` : ""}${parentStrategy === "weight" && row.WeightValue !== undefined ? `<span>权重 ${e(row.WeightValue)}</span>` : ""}${enums[row.AddressPoolLbStrategy || row.AddressLbStrategy] ? `<span>${e(enums[row.AddressPoolLbStrategy || row.AddressLbStrategy])}</span>` : ""}</div>${type === "addresses" && row.HealthTasks?.length ? `<div class="gtm-monitor-chips">${row.HealthTasks.map((task) => `<span>${e(task.TemplateName || displayName(knownResources().get(task.TemplateId)))}${task.Port !== undefined ? ":" + e(task.Port) : ""}</span>`).join("")}</div>` : ""}</article>`;
    };
    const branch = (pool) =>
      `<section class="gtm-lane"><div class="gtm-pool-column">${card("pools", pool, resource.AddressPoolLbStrategy)}</div><div class="gtm-address-grid gtm-tree-addresses">${(pool.Addresses || []).map((address) => `<div class="gtm-address-leaf">${card("addresses", address, pool.AddressLbStrategy)}</div>`).join("") || '<span class="help">暂无地址，点击地址池，在编辑区添加。</span>'}</div></section>`;
    let graph;
    if (kind === "list")
      graph = `<div class="gtm-domain-column">${card("list", resource)}</div><div class="gtm-lanes">${(resource.AddressPools || []).map(branch).join("") || '<p class="help">暂无地址池，点击接入域名，在编辑区添加。</p>'}</div>`;
    else if (kind === "pools") graph = branch(resource);
    else if (kind === "addresses")
      graph = `<div class="gtm-address-grid">${card("addresses", resource)}</div>`;
    else graph = card("monitors", resource);
    return `<section class="gtm-workbench"><header class="gtm-workbench-head"><div class="toolbar">${button("返回列表", "gtm.close", {}, "text")}<strong>${e(gtmName(resource))}</strong>${badge("GTM 3.0")}</div><div class="toolbar">${button("刷新画布", "gtm.canvas.refresh", {}, "text")}${button("新建地址池", "gtm.create", { kind: "pools" }, "text", "plus")}${button("新建地址", "gtm.create", { kind: "addresses" }, "text", "plus")}</div></header><div class="gtm-workspace ${editor ? "editing" : ""}"><div class="gtm-canvas" aria-label="GTM 关联画布"><div class="gtm-canvas-hint">点击节点直接编辑 · 管理关联可增删、排序 · 修改后预览确认</div><div class="gtm-graph gtm-graph-${kind}">${graph}</div></div>${inspectorShell()}</div></section>`;
  }
  function view(c) {
    nameIndex = null;
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
    if (c.gtmDetail) return tabs + topology(c.gtmDetail);
    return (
      tabs +
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
      inspectorShell()
    );
  }
  const actions = {
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
      const input = document.querySelector(".gtm-line-search");
      if (input) input.value = "";
      drawLines();
    },
    "gtm.tab": async (d) => {
      if (!mayDiscard()) return;
      ++session;
      editor = null;
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
            e(item.path),
            formatValue(item.before),
            formatValue(item.after),
          ]),
        ),
        async () => {},
        "关闭",
      );
    },
    "gtm.open": (d) => open(d.kind, d.id),
    "gtm.close": () => {
      if (!mayDiscard()) return;
      ++session;
      editor = null;
      ctx().gtmDetail = null;
      render();
    },
    "gtm.create": async (d) => {
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
    "gtm.edit": (d) => edit(d.operation, d.kind, d.id),
    "gtm.back": () => {
      document.querySelector("#dialog").close();
      drawEditor();
    },
    "gtm.inspector.close": () => {
      if (!mayDiscard()) return;
      ++session;
      editor = null;
      render();
    },
    "gtm.canvas.refresh": () => {
      const detail = ctx().gtmDetail;
      return open(detail.kind, detail.resource[ids[detail.kind]]);
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
      editor.values = collect(document.querySelector("#gtm-editor-form"));
      let list = editor.values;
      for (const key of JSON.parse(d.path)) list = list[key];
      list.splice(Number(d.index), 1);
      drawEditor();
    },
  };
  return {
    view,
    actions,
    leave: () => {
      if (!mayDiscard()) return false;
      ++session;
      editor = null;
      return true;
    },
  };
}
