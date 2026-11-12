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
export function configFields(content, schema = {}, path = []) {
  const array = Array.isArray(content),
    properties = schema.properties || {};
  const keys = array
    ? content.map((_, i) => String(i))
    : [...new Set([...Object.keys(content), ...Object.keys(properties)])];
  return keys
    .map((key) => {
      const spec = array ? schema.items || {} : properties[key] || {},
        p = [...path, key],
        encoded = JSON.stringify(p),
        label =
          spec.title ||
          labels[key] ||
          (array ? `第 ${Number(key) + 1} 项` : key);
      if (!Object.hasOwn(content, key))
        return `<div class="config-node">${e(label)} ${button("添加参数", "tree.add", { path: JSON.stringify(path), key }, "text")}</div>`;
      const value = content[key],
        type = valueType(value),
        name = "cfg_" + encodeURIComponent(encoded);
      const controls = button("移除", "tree.remove", { path: encoded }, "text");
      if (type === "object" || type === "array")
        return `<details class="config-group" open><summary>${e(label)} <small>${e(key)} · ${type === "array" ? value.length + " 项" : "参数组"}</small></summary><div class="config-children">${configFields(value, spec, p)}<div class="toolbar">${button(type === "array" ? "添加一项" : "添加参数", "tree.add", { path: encoded }, "text", "plus")}${controls}</div></div></details>`;
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
      return `<div class="config-node" data-config-path="${e(encoded)}" data-type="${e(type)}" data-enum="${spec.enum ? "1" : "0"}" data-name="${e(name)}">${control}<div class="toolbar"><small class="help">${e(key)}${spec.description ? " · " + e(spec.description) : ""}</small>${controls}</div></div>`;
    })
    .join("");
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
