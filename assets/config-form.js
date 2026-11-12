import { orderedKeys } from "./config-edit.js";
import { escape as e, button, field, select, check } from "./ui.js";
const labels = {
  update: "客户端更新配置",
  platforms: "平台",
  android: "Android",
  ios: "iOS",
  tvos: "tvOS",
  windows: "Windows",
  macos: "macOS",
  linux: "Linux",
  enabled: "启用",
  source: "更新来源",
  latest_version: "最新版本",
  build: "构建号",
  force: "强制更新",
  changelog: "更新说明",
  zh_CN: "简体中文",
  en_US: "英文",
  artifacts: "安装包",
  url: "下载地址",
  package_type: "安装包类型",
  sha256: "SHA256 校验值",
  size: "文件大小（字节）",
  min_supported_version: "最低支持版本",
  app_id: "App Store ID",
  domains: "接口域名",
  gateway_urls: "网关地址",
  contact: "联系方式",
  invite_domain: "邀请链接域名",
  fastcat_support: "FastCat 客服",
  base_url: "客服服务地址",
  app_key: "客服 AppKey",
  arm64: "ARM64 安装包",
  universal: "通用安装包",
  x64_deb: "x64 DEB 安装包",
  x64_rpm: "x64 RPM 安装包",
  features: "功能开关",
  legal: "法律信息",
  schema_version: "结构版本",
  config_version: "配置版本",
  panel_type: "面板类型",
  api_prefix: "接口前缀",
  website: "网站",
  privacy_policy_url: "隐私政策地址",
  terms_of_service_url: "服务条款地址",
};
export const valueType = (v) =>
  v === null
    ? "null"
    : Array.isArray(v)
      ? "array"
      : typeof v === "object"
        ? "object"
        : typeof v;
export function defaultValue(spec = {}) {
  if (Object.hasOwn(spec, "default")) return structuredClone(spec.default);
  return {
    object: {},
    array: [],
    boolean: false,
    number: 0,
    integer: 0,
    null: null,
    string: "",
  }[spec.type || "string"];
}
export function configFields(content, schema = {}, path = [], orders = {}) {
  const array = Array.isArray(content),
    properties = schema.properties || {};
  const keys = array
    ? content.map((_, i) => String(i))
    : orderedKeys(content, schema, orders, path);
  const missing = [];
  const fields = keys
    .map((key) => {
      const spec = array ? schema.items || {} : properties[key] || {},
        p = [...path, key],
        encoded = JSON.stringify(p),
        levelClass = `config-level-${(path.length % 7) + 1}`,
        label =
          spec.title ||
          labels[key] ||
          (array ? `第 ${Number(key) + 1} 项` : key);
      if (!Object.hasOwn(content, key)) {
        missing.push(
          `<div class="config-node ${levelClass}">${e(label)} ${button("添加参数", "tree.add", { path: JSON.stringify(path), key }, "text")}${button("删除", "tree.remove", { path: encoded }, "text")}${schema.required?.includes(key) ? '<small class="help">必填，保存前需添加</small>' : ""}</div>`,
        );
        return "";
      }
      const value = content[key],
        type = valueType(value),
        name = "cfg_" + encodeURIComponent(encoded);
      const sorting =
        button(
          "上移",
          "tree.move",
          { path: encoded, index: keys.indexOf(key) - 1 },
          "text",
        ) +
        button(
          "下移",
          "tree.move",
          { path: encoded, index: keys.indexOf(key) + 1 },
          "text",
        );
      const controls =
        sorting + button("删除", "tree.remove", { path: encoded }, "text");
      const drag = `<span draggable="true" class="config-drag" data-drag-path="${e(encoded)}" title="拖动调整同组顺序" aria-label="拖动调整顺序">↕</span>`;
      const heading = `<span class="config-heading">${e(label)} <small>${e(key)}</small></span>`;
      const actions = `<span class="config-actions">${controls}${drag}</span>`;
      if (type === "object" || type === "array")
        return `<details class="config-group ${levelClass}" data-tree-path="${e(encoded)}" open><summary><span class="config-heading">${e(label)} <small>${e(key)} · ${type === "array" ? value.length + " 项" : "参数组"}</small></span>${actions}</summary><div class="config-children">${configFields(value, spec, p, orders)}<div class="toolbar">${button(type === "array" ? "添加一项" : "添加参数", "tree.add", { path: encoded }, "text", "plus")}</div></div></details>`;
      let control = spec.enum
        ? select(
            name,
            label,
            spec.enum.map((v) => ({
              value: JSON.stringify(v),
              label: String(v),
            })),
            JSON.stringify(value),
          )
        : type === "boolean"
          ? check(name, label, value)
          : type === "null"
            ? `<span>${e(label)}：空值（null）</span>`
            : field(
                name,
                label,
                value,
                type === "number"
                  ? "number"
                  : /changelog|zh_CN|en_US/.test(key)
                    ? "textarea"
                    : "text",
                type === "number" ? 'step="any" required' : "",
              );
      if (type === "boolean" && !spec.enum)
        control = control.replace(
          '<input type="checkbox"',
          `<input id="${e(name)}" aria-labelledby="${e(name)}_label" type="checkbox"`,
        );
      // Keep the native input association while moving its visible label into the header.
      control = control.replace(/<label for="[^"]*">[^<]*<\/label>/, "");
      if (type === "boolean" && !spec.enum)
        control = control.replace(e(label) + "</label>", "开启</label>");
      return `<div class="config-node ${levelClass}" data-tree-path="${e(encoded)}" data-config-path="${e(encoded)}" data-type="${e(type)}" data-enum="${spec.enum ? "1" : "0"}" data-name="${e(name)}"><div class="config-header"><label for="${e(name)}" id="${e(name)}_label">${heading}</label>${actions}</div>${control}${spec.description ? `<small class="help">${e(spec.description)}</small>` : ""}</div>`;
    })
    .join("");
  return (
    fields +
    (missing.length
      ? `<details class="config-available"><summary>未加入配置的模板字段（${missing.length}）</summary><p class="help">这些字段当前没有值，不会输出到配置文件。需要时再添加。</p>${missing.join("")}</details>`
      : "")
  );
}
export function readFields(form, original) {
  const result = structuredClone(original);
  for (const node of form.querySelectorAll("[data-config-path]")) {
    const path = JSON.parse(node.dataset.configPath),
      input = form.elements[node.dataset.name],
      type = node.dataset.type;
    let value =
      type === "null"
        ? null
        : node.dataset.enum === "1"
          ? JSON.parse(input.value)
          : type === "boolean"
            ? input.checked
            : input.value;
    if (type === "number" && node.dataset.enum !== "1") {
      if (value === "" || !Number.isFinite(Number(value)))
        throw new Error(path.join(".") + " 需要填写有效数值");
      value = Number(value);
    }
    let parent = result;
    for (const key of path.slice(0, -1)) parent = parent[key];
    Object.defineProperty(parent, path.at(-1), {
      value,
      writable: true,
      enumerable: true,
      configurable: true,
    });
  }
  return result;
}

export function parameterInput(spec = {}) {
  const value = defaultValue(spec),
    type = spec.type || "string";
  if (spec.enum)
    return select(
      "value",
      "参数值",
      spec.enum.map((v) => ({ value: JSON.stringify(v), label: String(v) })),
      JSON.stringify(spec.enum.includes(value) ? value : spec.enum[0]),
    );
  if (type === "object" || type === "array")
    return (
      '<p class="help">创建后会展开' +
      (type === "array"
        ? "列表，点击「添加一项」填写内容。"
        : "参数组，点击「添加参数」逐项填写内容。") +
      "</p>"
    );
  if (type === "null")
    return '<p class="help">此参数将保存为空值（null）。</p>';
  if (type === "boolean") return check("value", "参数值：开启", value);
  return field(
    "value",
    "参数值",
    value,
    type === "number" || type === "integer" ? "number" : "text",
    type === "number"
      ? 'step="any" required'
      : type === "integer"
        ? 'step="1" required'
        : "",
  );
}
export function parameterValue(data, spec = {}) {
  if (spec.enum) return JSON.parse(data.get("value"));
  const type = spec.type || "string";
  if (type === "object" || type === "array" || type === "null")
    return defaultValue(spec);
  if (type === "boolean") return data.has("value");
  const value = data.get("value") ?? "";
  if (type === "number" || type === "integer") {
    const number = Number(value);
    if (
      value === "" ||
      !Number.isFinite(number) ||
      (type === "integer" && !Number.isInteger(number))
    )
      throw new Error("请填写有效" + (type === "integer" ? "整数" : "数字"));
    return number;
  }
  return value;
}
