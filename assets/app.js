import {
  escape as e,
  icon,
  button,
  badge,
  field,
  select,
  check,
  empty,
  panel,
  table,
  date,
  bytes,
  toast,
  modal,
  showJson,
  confirm,
} from "./ui.js";
const providers = {
  aliyun: "阿里云",
  tencent: "腾讯云",
  ucloud: "UCloud",
  cloudflare: "Cloudflare",
};
const modes = {
  plain: "明文 JSON",
  xor_base64: "XOR + Base64",
  signed_xor_v2: "Ed25519 签名 + XOR",
};
const statusNames = {
  draft: "草稿",
  publishing: "发布中",
  published: "已发布",
  partial: "部分失败",
  archived: "已归档",
  success: "成功",
  failed: "失败",
};
const defaults = {
  aliyun: "cn-hangzhou",
  tencent: "ap-guangzhou",
  ucloud: "cn-bj2",
};
const pages = {
  overview: ["工作台", "grid", "总览", "所有云资源，一个工作台。"],
  accounts: [
    "云账号",
    "cloud",
    "账号与凭证",
    "统一维护云凭证，各模块按权限使用。",
  ],
  servers: [
    "服务器",
    "server",
    "云服务器",
    "按账号和地域查看实例，管理运行状态。",
  ],
  storage: [
    "对象存储",
    "folder",
    "对象存储",
    "浏览存储桶与文件，管理你的云端内容。",
  ],
  dns: ["云解析", "globe", "云解析 DNS", "管理阿里云域名解析记录。"],
  gtm: [
    "流量调度",
    "activity",
    "GTM 流量调度",
    "管理实例、地址池、地址与健康检查。",
  ],
  cloudflare: [
    "Cloudflare",
    "shield",
    "Cloudflare DNS",
    "管理授权域名与 DNS 记录。",
  ],
  configs: [
    "客户端配置",
    "code",
    "客户端配置",
    "编辑、预览并发布配置到多个云存储目标。",
  ],
  events: ["操作记录", "activity", "操作记录", "追踪配置发布和资源管理操作。"],
  settings: [
    "设置",
    "settings",
    "设置与备份",
    "管理登录密码，备份数据，导入旧版配置。",
  ],
};
const readActions = new Set([
  "boot",
  "overview",
  "regions",
  "instances",
  "buckets",
  "objects",
  "cf.zones",
  "cf.records",
  "dns.list",
  "gtm.list",
  "gtm.detail",
  "gtm.pools",
  "gtm.addresses",
  "gtm.monitors",
]);
let boot = {},
  state = {},
  route = "overview",
  loading = 0,
  configId = 0,
  planId = 0,
  configTab = "editor",
  editorMode = "form",
  resource = {};
const root = document.querySelector("#app");
const dialog = document.querySelector("#dialog");
async function api(action, data = {}, options = {}) {
  loading++;
  showLoading();
  try {
    const url = new URL("index.php", location.href);
    url.searchParams.set("api", action);
    const init = {
      credentials: "same-origin",
      headers: { "X-CSRF-Token": boot.csrf || "" },
    };
    if (readActions.has(action)) {
      for (const [k, v] of Object.entries(data))
        if (v !== undefined && v !== null) url.searchParams.set(k, String(v));
    } else {
      init.method = "POST";
      if (options.file) {
        const form = new FormData();
        for (const [k, v] of Object.entries(data)) form.set(k, String(v));
        form.set("file", options.file);
        init.body = form;
      } else {
        init.headers["Content-Type"] = "application/json";
        init.body = JSON.stringify(data);
      }
    }
    const response = await fetch(url, init);
    if (options.download && response.ok) {
      const blob = await response.blob();
      const link = document.createElement("a");
      link.href = URL.createObjectURL(blob);
      link.download = options.download;
      link.click();
      setTimeout(() => URL.revokeObjectURL(link.href), 1000);
      return true;
    }
    const body = await response.json();
    if (!response.ok) {
      if (response.status === 401 && !["login", "boot"].includes(action)) {
        boot.authenticated = false;
        auth();
      }
      throw new Error(body.error || "请求失败");
    }
    return body.data;
  } finally {
    loading--;
    showLoading();
  }
}
function showLoading() {
  let line = document.querySelector(".loading-line");
  if (!line) {
    line = document.createElement("div");
    line.className = "loading-line";
    document.body.append(line);
  }
  line.hidden = !loading;
}
const accountOptions = (filter = () => true) => [
  { value: "", label: "选择云账号" },
  ...(state.accounts || [])
    .filter((a) => a.enabled && filter(a))
    .map((a) => ({
      value: a.id,
      label: `${a.name} · ${providers[a.provider]}`,
    })),
];
const account = (id) => state.accounts.find((a) => Number(a.id) === Number(id));
const currentVersion = () =>
  state.versions.find(
    (v) =>
      v.id === Number(configId) && (!planId || v.plan_id === Number(planId)),
  ) ||
  state.versions.filter((v) => !planId || v.plan_id === Number(planId)).at(-1);
const ctx = () =>
  (resource[route] ??= {
    account: "",
    region: "cn-hangzhou",
    page: 1,
    items: [],
    total: 0,
    prefix: "",
    marker: "",
    history: [],
    next: "",
    bucket: "",
    domain: "",
    zone: "",
    tab: "list",
  });
const accountLogo = (a) =>
  `<div class="provider-logo ${e(a.provider)}">${e({ aliyun: "阿", tencent: "腾", ucloud: "U", cloudflare: "C" }[a.provider])}</div>`;
function pageHead(actions = "") {
  const p = pages[route];
  return `<div class="page-head"><div><div class="eyebrow">Cloud Desk / ${e(route)}</div><h1>${e(p[2])}</h1><p class="subtitle">${e(p[3])}</p></div><div class="toolbar">${actions}</div></div>`;
}
function normalizeSchema(value, schema) {
  if (schema.type === "object" && value && typeof value === "object") {
    const result = {};
    for (const [key, child] of Object.entries(value))
      Object.defineProperty(result, key, {
        value: normalizeSchema(child, schema.properties?.[key] || {}),
        enumerable: true,
        writable: true,
        configurable: true,
      });
    return result;
  }
  if (schema.type === "array" && Array.isArray(value))
    return value.map((v) => normalizeSchema(v, schema.items || {}));
  return value;
}
async function refresh() {
  state = await api("overview");
  for (const version of state.versions) {
    version.content = normalizeSchema(version.content, version.schema || {});
    version._savedMode = version.mode;
    version._savedSummary = version.summary || "";
  }
  render();
}
function auth() {
  const installed = boot.installed;
  root.innerHTML = `<div class="auth-page"><section class="auth-brand"><div class="brand"><span class="brand-mark">C</span><div>Cloud Desk<small>YOUR CLOUD, CONNECTED</small></div></div><div class="auth-copy"><div class="eyebrow">A CALMER CLOUD WORKSPACE</div><h1>云端事务，<br>在这里井然有序。</h1><p>把账号、服务器、文件和解析放在一起。<br>少一些切换，多一点掌控。</p><div class="auth-lines"><span>${icon("key")} 一次添加凭证，连接所有资源</span><span>${icon("shield")} 数据与密钥，只保存在你的服务器</span><span>${icon("code")} Git 拉取即用，更新无需构建</span></div></div><div class="help">Cloud Desk · 自托管云资源工作台</div></section><section class="auth-side"><form class="auth-form" id="auth-form"><div class="eyebrow">${installed ? "WELCOME BACK" : "LET’S GET STARTED"}</div><h1>${installed ? "登录你的工作台" : "创建管理员账号"}</h1><p class="subtitle">${installed ? "欢迎回来，继续管理你的云资源。" : "完成这一步，就可以开始连接云资源。"}</p>${boot.environment?.missing?.length ? `<div class="notice warn">云功能缺少扩展：${e(boot.environment.missing.join("、"))}。请在宝塔 PHP 设置中启用。</div>` : ""}<div class="dialog-error" role="alert"></div>${field("email", "邮箱", "", "email", 'required autocomplete="username" maxlength="64"')}${field("password", "密码", "", "password", `required autocomplete="${installed ? "current-password" : "new-password"}" ${installed ? "" : 'minlength="12" placeholder="至少 12 位"'}`)}${!installed ? field("password_confirmation", "确认密码", "", "password", 'required minlength="12" autocomplete="new-password"') : ""}<button class="button primary" type="submit">${installed ? "登录工作台" : "创建账号并开始"} ${icon("arrow")}</button><p class="auth-foot">无需数据库 · 不依赖第三方登录</p></form></section></div>`;
  document.querySelector("#auth-form").onsubmit = async (event) => {
    event.preventDefault();
    const form = event.target,
      data = Object.fromEntries(new FormData(form));
    if (!installed && data.password !== data.password_confirmation) {
      form.querySelector(".dialog-error").textContent = "两次密码不一致";
      return;
    }
    event.submitter.disabled = true;
    try {
      await api(installed ? "login" : "setup", data);
      boot = await api("boot");
      if (!installed) {
        toast("管理员已创建，请登录");
        auth();
      } else await refresh();
    } catch (error) {
      form.querySelector(".dialog-error").textContent = error.message;
    } finally {
      if (event.submitter) event.submitter.disabled = false;
    }
  };
}
function shell(content) {
  root.innerHTML = `<div class="layout"><aside class="sidebar"><a class="brand" href="#/overview"><span class="brand-mark">C</span><div>Cloud Desk<small>CLOUD WORKSPACE</small></div></a><nav aria-label="主导航">${Object.entries(
    pages,
  )
    .map(
      ([key, p], i) =>
        `${[0, 1, 7, 8].includes(i) ? `<div class="nav-section">${{ 0: "Workspace", 1: "Cloud services", 7: "Configuration", 8: "Management" }[i]}</div>` : ""}<a href="#/${key}" class="nav-item ${route === key ? "active" : ""}" ${route === key ? 'aria-current="page"' : ""}>${icon(p[1])}${e(p[0])}</a>`,
    )
    .join(
      "",
    )}</nav><div class="sidebar-footer"><span class="status-dot"></span>独立运行 · v${e(boot.version)}<div style="margin-top:8px">数据保存在本机</div></div></aside><main class="main"><header class="topbar"><div class="breadcrumb">工作空间<span>/ ${e(pages[route][0])}</span></div><div class="top-actions"><a class="button text" href="https://github.com/fastcat920/Cloud-Server" target="_blank" rel="noreferrer">GitHub ${icon("arrow")}</a><div class="user"><div class="avatar">${e((boot.email || "A")[0].toUpperCase())}</div><span>${e(boot.email)}</span></div>${button("退出", "logout", {}, "icon-only", "logout")}</div></header><div class="content">${content}</div></main></div>`;
}
function render() {
  if (!boot.authenticated) return auth();
  route = location.hash.replace(/^#\//, "").split("?")[0] || "overview";
  if (!pages[route]) route = "overview";
  const views = {
    overview: overviewView,
    accounts: accountsView,
    servers: serversView,
    storage: storageView,
    dns: dnsView,
    gtm: gtmView,
    cloudflare: cfView,
    configs: configsView,
    events: eventsView,
    settings: settingsView,
  };
  shell(views[route]());
}
function stat(label, value, note, name) {
  return `<div class="stat"><div class="stat-label">${e(label)}${icon(name)}</div><div class="stat-value">${e(value)}</div><div class="stat-note">${e(note)}</div></div>`;
}
function eventsRows(events, compact = false) {
  if (!events.length)
    return empty("还没有操作记录", "连接第一个云账号，开始你的工作台。");
  const labels = {
    "account.save": "保存云账号",
    "account.delete": "删除云账号",
    "instance.start": "提交开机",
    "instance.stop": "提交关机",
    "instance.reboot": "提交重启",
    "config.save": "保存配置",
    "config.publish": "发布配置",
    "object.upload": "上传文件",
    "object.delete": "删除文件",
    setup: "创建工作台",
    restore: "恢复备份",
    "import.v1": "导入旧版",
  };
  return compact
    ? `<div class="panel-body">${events
        .slice(0, 5)
        .map(
          (r) =>
            `<div class="event"><div class="event-icon">${icon(r.status === "failed" ? "activity" : "check")}</div><div><strong>${e(labels[r.action] || r.action)}</strong><p>${e(r.resource)}</p></div><time>${e(date(r.time))}</time></div>`,
        )
        .join("")}</div>`
    : table(
        ["操作", "资源", "结果", "时间"],
        events.map((r) => [
          e(labels[r.action] || r.action),
          `<span class="mono">${e(r.resource)}</span>`,
          badge(
            r.status === "failed" ? "失败" : "已记录",
            r.status === "failed" ? "bad" : "good",
          ),
          e(date(r.time)),
        ]),
      );
}
function overviewView() {
  const published = state.versions
    .filter((v) => v.status === "published")
    .at(-1);
  return (
    pageHead(button("连接云账号", "account.edit", {}, "primary", "plus")) +
    `<div class="stats">${stat("已连接账号", state.accounts.length, "统一凭证，按权限访问", "cloud")}${stat("可用发布目标", state.targets.filter((t) => t.enabled).length, "连接你的对象存储空间", "folder")}${stat("当前配置版本", published ? `v${published.version}` : "—", published ? "最近成功发布" : "尚未发布客户端配置", "code")}${stat("最近管理操作", state.events.length, "本地保留最近 1,000 条", "activity")}</div><div class="welcome"><div class="eyebrow">A CONNECTED WORKSPACE</div><h2>让每一项云端事务，都有自己的位置。</h2><p>连接云账号后，就能查看服务器与存储资源。你的访问密钥统一加密保存，解析和配置发布无需重复录入。</p><a href="#/accounts" class="button">管理云账号 ${icon("arrow")}</a></div><div class="dashboard-grid">${panel("最近动态", eventsRows(state.events, true), '<a class="button text" href="#/events">全部记录 →</a>')}${panel(
      "常用入口",
      `<div class="panel-body">${[
        ["servers", "server", "查看云服务器"],
        ["storage", "folder", "浏览云端文件"],
        ["dns", "globe", "管理域名解析"],
        ["configs", "code", "发布客户端配置"],
      ]
        .map(
          ([key, img, label]) =>
            `<a class="quick-link" href="#/${key}">${icon(img)}${e(label)}<span>↗</span></a>`,
        )
        .join("")}</div>`,
    )}</div>`
  );
}
function accountsView() {
  return (
    pageHead(button("添加云账号", "account.edit", {}, "primary", "plus")) +
    (!state.accounts.length
      ? panel(
          "已连接账号",
          empty(
            "连接你的第一个云账号",
            "支持阿里云、腾讯云、UCloud 和 Cloudflare。凭证添加一次，各模块直接使用。",
            "account.edit",
            "添加云账号",
          ),
        )
      : `<div class="account-grid">${state.accounts
          .map((a) => {
            const bill = a.billing;
            return `<article class="account-card"><div class="account-top"><div class="account-name">${accountLogo(a)}<div><h3>${e(a.name)}</h3><span class="help">${e(providers[a.provider])} · ${a.site === "international" ? "国际站" : "国内站"}</span></div></div>${badge(a.enabled ? "已启用" : "已停用", a.enabled ? "good" : "")}</div><div class="balance">${bill ? e(new Intl.NumberFormat("zh-CN", { style: "currency", currency: bill.currency }).format(bill.balance)) : "—"}</div><div class="balance-label">${a.provider === "cloudflare" ? "使用 API Token 访问授权域名" : "可用余额 · " + (bill ? date(bill.synced_at) : "尚未查询")}</div>${a.billing_error ? `<div class="notice warn">账单：${e(a.billing_error)}。不影响其他已授权功能。</div>` : ""}<div class="account-meta"><div><label>本月消费</label>${bill ? e(bill.current.toFixed(2)) : "—"}</div><div><label>上月消费</label>${bill ? e(bill.previous.toFixed(2)) : "—"}</div><div><label>配置引用</label>${a.references.length}</div></div><div class="account-actions">${a.provider === "cloudflare" ? button("管理解析", "account.open", { id: a.id, route: "cloudflare" }, "text") : button("查看资源", "account.open", { id: a.id, route: "servers" }, "text")}${a.provider === "cloudflare" ? button("验证", "account.test", { id: a.id }, "text") : button("余额", "account.billing", { id: a.id }, "text")}${button("编辑", "account.edit", { id: a.id }, "text")}${button("删除", "account.delete", { id: a.id }, "text")}</div></article>`;
          })
          .join("")}</div>`)
  );
}
function resourceControls(type, extra = "") {
  const c = ctx(),
    options = accountOptions((a) =>
      type === "cloudflare"
        ? a.provider === "cloudflare"
        : type === "dns" || type === "gtm"
          ? a.provider === "aliyun"
          : a.provider !== "cloudflare",
    );
  return `<form id="resource-form" class="resource-controls">${select("account_id", "云账号", options, c.account)}${["servers", "storage"].includes(type) ? field("region", "地域", c.region, "text", 'required placeholder="例如 cn-hangzhou"') : ""}${type === "servers" ? field("project", "UCloud 项目 ID（可选）", c.project || "") : ""}${type === "dns" ? field("domain", "域名（留空列出全部）", c.domain || "") : ""}${extra}<button type="submit" class="button primary">${icon("refresh")}查询</button></form>`;
}
function resourceError() {
  return ctx().error
    ? `<div class="notice error" role="alert">${e(ctx().error)}</div>`
    : "";
}
function serversView() {
  const c = ctx();
  return (
    pageHead() +
    resourceControls("servers") +
    `<div class="notice">按地域查询授权实例。开关机和重启会改变云端运行状态，提交后请刷新确认结果。</div>` +
    resourceError() +
    panel(
      "实例列表",
      table(
        ["服务器", "运行状态", "IP 地址", "配置", "可用区", "到期时间", "操作"],
        c.items.map((r) => [
          `<strong>${e(r.name)}</strong><span class="secondary mono">${e(r.id)}</span>`,
          badge(r.status, /Running|RUNNING/.test(r.status) ? "good" : ""),
          `<span class="mono">${e(r.ip || "无公网 IP")}</span><span class="secondary mono">${e(r.private_ip || "")}</span>`,
          e(`${r.cpu ?? "—"} 核 / ${r.memory ?? "—"} GB`),
          e(r.zone),
          e(date(r.expires)),
          `<div class="toolbar">${button("开机", "instance.action", { id: r.id, operation: "start" }, "text")}${button("关机", "instance.action", { id: r.id, operation: "stop" }, "text")}${button("重启", "instance.action", { id: r.id, operation: "reboot" }, "text")}</div>`,
        ]),
      ),
      badge(`${c.total || 0} 台`),
    ) +
    pagination(c.total, 20, c.page)
  );
}
function pagination(total, size, page) {
  if (!total || total <= size) return "";
  return `<div class="pagination">共 ${total} 项 · 第 ${page} 页 ${page > 1 ? button("上一页", "page", { page: page - 1 }) : ""}${page * size < total ? button("下一页", "page", { page: page + 1 }) : ""}</div>`;
}
function storageView() {
  const c = ctx();
  const buckets = c.buckets || [];
  return (
    pageHead(
      c.bucket
        ? button("上传文件", "object.upload", {}, "primary", "plus")
        : "",
    ) +
    resourceControls(
      "storage",
      field("bucket", "Bucket（可直接输入）", c.bucket),
    ) +
    resourceError() +
    (!c.bucket
      ? panel(
          "存储桶",
          table(
            ["名称", "地域", "创建时间", "操作"],
            buckets.map((b) => [
              `<span class="file-name">${icon("folder")}<strong>${e(b.name)}</strong></span>`,
              e(b.region),
              e(b.created),
              button(
                "打开",
                "bucket.open",
                { name: b.name, region: b.region },
                "text",
              ),
            ]),
          ),
        ) +
        (c.bucketNext
          ? `<div class="pagination">${button("更多存储桶", "bucket.next")}</div>`
          : "")
      : panel(
          c.bucket,
          `<div class="panel-body"><div class="toolbar">${button("返回存储桶", "bucket.back", {}, "", "folder")}${button("根目录", "folder.open", { key: "" }, "text")}${c.prefix ? button("上级目录", "folder.parent", {}, "text") : ""}<form id="prefix-form" class="toolbar"><input name="prefix" aria-label="文件前缀" value="${e(c.prefix)}" placeholder="按完整路径前缀查找"><button class="button" type="submit">查找</button></form></div></div>${table(
            ["文件名", "大小", "更新时间", "操作"],
            c.items.map((r) => [
              `<span class="file-name">${icon(r.directory ? "folder" : "file")}${r.directory ? button(r.key, "folder.open", { key: r.key }, "text") : `<span class="mono">${e(r.key)}</span>`}</span>`,
              r.directory ? "—" : bytes(r.size),
              e(r.modified),
              r.directory
                ? ""
                : `<div class="toolbar">${button("下载", "object.download", { key: r.key }, "text")}${button("删除", "object.delete", { key: r.key }, "text")}</div>`,
            ]),
          )}<div class="pagination">${c.history.length ? button("上一页", "object.prev") : ""}${c.next ? button("下一页", "object.next") : ""}<span>每页最多 100 项</span></div>`,
        ))
  );
}
function dnsView() {
  const c = ctx();
  return (
    pageHead(
      c.domain ? button("新增记录", "dns.edit", {}, "primary", "plus") : "",
    ) +
    resourceControls("dns") +
    resourceError() +
    panel(
      c.domain || "域名列表",
      c.domain
        ? table(
            ["主机记录", "类型", "记录值", "TTL", "操作"],
            c.items.map((r, i) => [
              e(r.RR),
              badge(r.Type),
              `<span class="mono">${e(r.Value)}</span>`,
              e(r.TTL),
              `<div class="toolbar">${button("编辑", "dns.edit", { index: i }, "text")}${button("删除", "dns.delete", { index: i }, "text")}</div>`,
            ]),
          )
        : table(
            ["域名", "域名 ID", "操作"],
            c.items.map((r) => [
              e(r.DomainName),
              e(r.DomainId),
              button("管理解析", "domain.open", { name: r.DomainName }, "text"),
            ]),
          ),
    ) +
    pagination(c.total, 50, c.page)
  );
}
function cfView() {
  const c = ctx();
  return (
    pageHead(
      c.zone ? button("新增记录", "cf.edit", {}, "primary", "plus") : "",
    ) +
    resourceControls(
      "cloudflare",
      c.zone
        ? `<div class="field"><label>当前域名</label><span>${e(c.zoneName)} ${button("返回域名", "zone.back", {}, "text")}</span></div>`
        : "",
    ) +
    resourceError() +
    panel(
      c.zone ? c.zoneName || "解析记录" : "授权域名",
      c.zone
        ? table(
            ["名称", "类型", "内容", "代理", "TTL", "操作"],
            c.items.map((r, i) => [
              e(r.name),
              badge(r.type),
              `<span class="mono">${e(r.content || JSON.stringify(r.data || {}))}</span>`,
              badge(r.proxied ? "已开启" : "DNS only", r.proxied ? "warn" : ""),
              r.ttl === 1 ? "自动" : e(r.ttl),
              `<div class="toolbar">${button("编辑", "cf.edit", { index: i }, "text")}${button("删除", "cf.delete", { index: i }, "text")}</div>`,
            ]),
          )
        : table(
            ["域名", "状态", "操作"],
            c.items.map((r) => [
              e(r.name),
              badge(r.status, r.status === "active" ? "good" : ""),
              button(
                "管理解析",
                "zone.open",
                { id: r.id, name: r.name },
                "text",
              ),
            ]),
          ),
    ) +
    pagination(c.total, 50, c.page)
  );
}
const gtmOps = {
  UpdateCloudGtmInstanceConfigBasic: "实例基本配置",
  UpdateCloudGtmInstanceConfigLbStrategy: "实例负载策略",
  UpdateCloudGtmInstanceConfigEnableStatus: "实例启停",
  ReplaceCloudGtmInstanceConfigAddressPool: "绑定实例地址池",
  CreateCloudGtmAddressPool: "新建地址池",
  UpdateCloudGtmAddressPoolBasicConfig: "地址池基本配置",
  UpdateCloudGtmAddressPoolRemark: "地址池备注",
  UpdateCloudGtmAddressPoolEnableStatus: "地址池启停",
  UpdateCloudGtmAddressPoolLbStrategy: "地址池负载策略",
  DeleteCloudGtmAddressPool: "删除地址池",
  ReplaceCloudGtmAddressPoolAddress: "绑定地址池地址",
  CreateCloudGtmAddress: "新建地址",
  UpdateCloudGtmAddress: "修改地址",
  UpdateCloudGtmAddressRemark: "地址备注",
  UpdateCloudGtmAddressEnableStatus: "地址启停",
  UpdateCloudGtmAddressManualAvailableStatus: "手动可用状态",
  DeleteCloudGtmAddress: "删除地址",
};
function findRows(value) {
  if (!value || typeof value !== "object") return [];
  for (const v of Object.values(value)) if (Array.isArray(v)) return v;
  for (const v of Object.values(value)) {
    const rows = findRows(v);
    if (rows.length) return rows;
  }
  return [];
}
function gtmView() {
  const c = ctx();
  const tabs = {
    list: "实例配置",
    pools: "地址池",
    addresses: "地址资源",
    monitors: "健康检查模板",
  };
  return (
    pageHead(button("管理配置", "gtm.action", {}, "primary", "settings")) +
    resourceControls("gtm") +
    `<div class="tabs">${Object.entries(tabs)
      .map(
        ([k, v]) =>
          `<button class="tab ${c.tab === k ? "active" : ""}" data-action="gtm.tab" data-tab="${k}">${v}</button>`,
      )
      .join("")}</div>` +
    resourceError() +
    panel(
      tabs[c.tab],
      table(
        ["资源名称 / ID", "状态", "详情"],
        c.items.map((r, i) => [
          e(
            r.ConfigName ||
              r.AddressPoolName ||
              r.Name ||
              r.Address ||
              r.ConfigId ||
              r.AddressPoolId ||
              r.AddressId ||
              r.MonitorTemplateId ||
              "资源",
          ),
          badge(r.EnableStatus || r.AvailableStatus || r.Status || "—"),
          button("查看 / 编辑", "gtm.detail", { index: i }, "text"),
        ]),
      ),
      c.raw ? button("完整响应", "gtm.raw", {}, "text") : "",
    ) +
    pagination(c.total, 100, c.page)
  );
}
const features = {
  balance_enabled: "钱包余额",
  devices_enabled: "设备管理",
  gift_card_enabled: "礼品卡",
  join_group_enabled: "加入群组",
  knowledge_base_enabled: "使用文档",
  orders_enabled: "订单记录",
  tickets_enabled: "工单",
  traffic_details_enabled: "流量明细",
};
const platforms = ["android", "windows", "macos", "linux", "ios", "tvos"];
function activePlan() {
  return state.plans?.find((p) => p.id === Number(planId)) || state.plans?.[0];
}
function configsView() {
  const plan = activePlan();
  if (plan) planId = plan.id;
  const r = currentVersion();
  const tabs = {
    editor: "配置与修订",
    targets: "发布目标",
    releases: "发布历史",
    templates: "结构模板",
    security: "加密与签名",
  };
  const controls = `<div class="toolbar">${button("新建方案", "plan.edit", {}, "", "plus")}${button("方案设置", "plan.edit", { id: plan?.id })}${button("新建草稿", "config.new", {}, "", "plus")}${r ? button("对比修订", "config.diff", { id: r.id }) : ""}${r ? button("预览", "config.preview", { id: r.id }) : ""}</div>`;
  const versions = state.versions.filter((v) => v.plan_id === plan?.id);
  return (
    pageHead(controls) +
    `<div class="resource-controls">${select(
      "plan-picker",
      "客户端配置方案",
      (state.plans || []).map((p) => ({
        value: p.id,
        label: p.name + " · " + (p.clients || "未标记客户端版本"),
      })),
      plan?.id,
    )}<div class="notice" style="margin:0">各方案独立发布。编辑历史修订时保留原结构；保存已发布内容会生成新修订。</div></div><div class="tabs">${Object.entries(
      tabs,
    )
      .map(
        ([k, v]) =>
          `<button class="tab ${configTab === k ? "active" : ""}" data-action="config.tab" data-tab="${k}">${v}</button>`,
      )
      .join("")}</div>` +
    (configTab === "targets"
      ? targetsView()
      : configTab === "releases"
        ? releasesView()
        : configTab === "security"
          ? configSecurityView()
          : configTab === "templates"
            ? templatesView()
            : `<div class="config-layout"><aside class="versions">${[
                ...versions,
              ]
                .reverse()
                .map(
                  (v) =>
                    `<button class="version ${r?.id === v.id ? "active" : ""}" data-action="config.select" data-id="${v.id}"><strong>修订 r${v.revision} ${badge(statusNames[v.status] || v.status, v.status === "published" ? "good" : "")}</strong><span>${e(v.summary || "未填写说明")}<br>${e(date(v.created_at))}</span></button>`,
                )
                .join(
                  "",
                )}</aside>${r ? editorView(r) : panel("配置", empty("此方案还没有修订", "从结构模板创建草稿，或从其他方案参考 JSON。", "config.new", "新建草稿", "code"))}</div>`)
  );
}
function schemaFields(r) {
  const properties = r.schema?.properties || {},
    content = r.content || {},
    keys = [...new Set([...Object.keys(properties), ...Object.keys(content)])];
  return keys
    .map((key, i) => {
      const spec = Object.hasOwn(properties, key) ? properties[key] : {},
        exists = Object.hasOwn(content, key),
        value = content[key],
        type =
          spec.type ||
          (typeof value === "boolean"
            ? "boolean"
            : typeof value === "number"
              ? "number"
              : typeof value === "string"
                ? "string"
                : "object");
      if (!exists)
        return `<div class="field"><span>${e(spec.title || key)}</span>${button("添加参数", "schema.add", { key }, "text")}<small class="help">当前修订未包含此参数，不会自动补入。</small></div>`;
      const name = "parameter_" + i;
      let control;
      if (spec.enum)
        control = select(
          name,
          spec.title || key,
          spec.enum.map((v) => ({
            value: JSON.stringify(v),
            label: String(v),
          })),
          JSON.stringify(value),
        );
      else if (type === "boolean")
        control = check(name, spec.title || key, value);
      else
        control = field(
          name,
          spec.title || key,
          ["object", "array", "null"].includes(type)
            ? JSON.stringify(value, null, 2)
            : value,
          ["object", "array", "null"].includes(type)
            ? "textarea"
            : type === "number" || type === "integer"
              ? "number"
              : "text",
          ["object", "array", "null"].includes(type)
            ? 'class="code" style="min-height:110px"'
            : type === "number"
              ? 'step="any"'
              : "",
        );
      return `<div data-parameter="${e(key)}" data-type="${e(type)}" data-name="${name}" data-enum="${spec.enum ? "1" : ""}">${control}<div class="toolbar"><small class="help mono">${e(key)}${spec.description ? " · " + e(spec.description) : ""}${properties[key] ? "" : " · 模板外参数，原样保留"}</small>${button("移除此参数", "schema.remove", { key }, "text")}</div></div>`;
    })
    .join("");
}
function editorView(r) {
  return panel(
    `基于修订 r${r.revision} 编辑`,
    `<form id="config-form" class="panel-body"><input type="hidden" name="id" value="${r.id}"><div class="toolbar" style="justify-content:space-between;margin-bottom:22px">${badge("结构快照 #" + r.template_id)}${button("本次变更", "config.localdiff", {}, "text")}${button("升级配置结构", "schema.upgrade", {}, "text")}${button(editorMode === "json" ? "结构表单" : "完整 JSON", "config.mode", {}, "text", "code")}</div>${select(
      "mode",
      "输出格式",
      Object.entries(modes).map(([value, label]) => ({ value, label })),
      r.mode,
    )}${field("summary", "本次修改说明", r.summary || "")}${editorMode === "json" ? field("content", "完整配置 JSON", JSON.stringify(r.content, null, 2), "textarea", 'class="code" required') : `<div class="form-grid">${schemaFields(r)}</div>`}<div class="toolbar" style="margin-top:24px"><button class="button primary" type="submit">保存修订</button>${button("保存并推送", "config.savepush", {}, "primary", "arrow")}${button("重新推送此修订", "config.publish", { id: r.id })}${button("基于此修订回滚推送", "config.rollback", { id: r.id }, "danger")}${r.status === "draft" ? button("删除草稿", "config.delete", { id: r.id }, "danger") : ""}</div></form>`,
  );
}
function templatesView() {
  return panel(
    "不可变结构模板",
    table(
      ["模板", "字段数量", "操作"],
      (state.templates || []).map((t) => [
        e(t.name),
        Object.keys(t.schema.properties || {}).length,
        button("查看 / 派生新模板", "template.edit", { id: t.id }, "text"),
      ]),
    ),
    button("新增结构模板", "template.edit", {}, "primary", "plus"),
  );
}
function targetsView() {
  return panel(
    "多云发布目标",
    table(
      ["名称", "云账号", "存储位置", "公开地址", "操作"],
      state.targets.map((t) => [
        e(t.name),
        e(account(t.account_id)?.name || "账号已删除"),
        `<span class="mono">${e(t.bucket + "/" + t.key)}</span><span class="secondary">${e(t.region)}</span>`,
        `<a href="${e(t.url)}" target="_blank" rel="noreferrer">打开链接 ↗</a>`,
        `<div class="toolbar">${button("测试", "target.test", { id: t.id }, "text")}${button("编辑", "target.edit", { id: t.id }, "text")}${button("删除", "target.delete", { id: t.id }, "text")}</div>`,
      ]),
    ),
    button("添加目标", "target.edit", {}, "primary", "plus"),
  );
}
function releasesView() {
  return panel(
    "发布记录",
    table(
      ["版本", "目标", "状态", "时间", "校验 / 错误", "操作"],
      [...state.publications]
        .reverse()
        .map((p) => [
          e(
            "v" +
              (state.versions.find((v) => v.id === p.version_id)?.version ||
                p.version_id),
          ),
          e(p.target_name),
          badge(
            statusNames[p.status] || p.status,
            p.status === "success"
              ? "good"
              : p.status === "failed"
                ? "bad"
                : "",
          ),
          e(date(p.finished_at || p.started_at)),
          `<span class="mono" title="${e(p.checksum)}">${e(p.error || p.checksum?.slice(0, 16) || "—")}</span>`,
          p.status === "failed"
            ? button("重试", "config.retry", { id: p.id }, "text")
            : "—",
        ]),
    ),
  );
}
function configSecurityView() {
  return panel(
    "加密与签名",
    `<form id="config-security" class="panel-body"><div class="notice">输出协议保持兼容：明文 JSON、XOR + Base64，以及 fastcat-config-v2 签名封装。</div>${field("xor_key", state.settings.has_xor_key ? "XOR 密钥（已保存，留空不修改）" : "XOR 密钥", "", "password", 'autocomplete="new-password"')}${check("generate_signing", "生成 / 更换 Ed25519 签名密钥")}${state.settings.signing_public ? field("public", "签名公钥", state.settings.signing_public, "textarea", "readonly") : ""}<div class="help">更换签名密钥前，请同步更新客户端信任的公钥。</div><button class="button primary" style="margin-top:20px" type="submit">保存设置</button></form>`,
  );
}
function eventsView() {
  return (
    pageHead(button("刷新", "refresh", {}, "", "refresh")) +
    panel("最近 1,000 条记录", eventsRows(state.events))
  );
}
function settingsView() {
  return (
    pageHead() +
    `<div class="settings-grid">${panel("登录安全", `<form id="password-form" class="panel-body">${field("old", "原密码", "", "password", 'required autocomplete="current-password"')}${field("password", "新密码", "", "password", 'required minlength="12" autocomplete="new-password"')}<button class="button primary" type="submit">修改密码并重新登录</button></form>`)}${panel("数据备份", `<div class="panel-body"><p>备份包含云凭证和解密密钥，请保存在可信位置。Git 更新不会修改你的本地数据。</p><div class="toolbar">${button("下载完整备份", "backup", {}, "", "download")}${button("恢复备份", "restore", {}, "danger")}</div><div class="section-label">从旧版迁移</div><p>支持导入旧版 state.json 和 APP_KEY。请在新安装中导入，不覆盖已添加的云账号。</p>${button("导入旧版数据", "import.v1", {}, "", "folder")}</div>`)}${panel("运行环境", `<div class="panel-body"><p>原生 PHP ${e(boot.environment.php)} · 无数据库 · 无 Composer · 无前端构建</p>${boot.environment.missing.length ? `<div class="notice warn">请启用：${e(boot.environment.missing.join("、"))}</div>` : badge("运行环境就绪", "good")}<div class="section-label">更新项目</div><code>git pull --ff-only origin main</code><p style="margin-top:18px">就在当前网站目录执行，不套子目录。更新前请先从设置下载备份；_core/data 不会被 Git 覆盖。</p></div>`)}</div>`
  );
}
async function queryResources() {
  const page = route,
    c = ctx(),
    serial = (c.serial || 0) + 1;
  c.serial = serial;
  c.error = "";
  try {
    let result;
    if (page === "servers") {
      result = await api("instances", {
        account_id: c.account,
        region: c.region,
        page: c.page,
        project: c.project || "",
      });
      if (c.serial !== serial) return;
      c.items = result.items;
      c.total = result.total;
    } else if (page === "storage") {
      if (!c.bucket) {
        result = await api("buckets", {
          account_id: c.account,
          region: c.region,
          marker: c.bucketMarker || "",
        });
        if (c.serial !== serial) return;
        c.buckets = result.items;
        c.bucketNext = result.next;
        c.items = [];
      } else {
        result = await api("objects", {
          account_id: c.account,
          region: c.region,
          bucket: c.bucket,
          prefix: c.prefix,
          marker: c.marker,
        });
        if (c.serial !== serial) return;
        c.items = result.items;
        c.next = result.next;
      }
    } else if (page === "dns") {
      result = await api("dns.list", {
        account_id: c.account,
        domain: c.domain,
        page: c.page,
      });
      if (c.serial !== serial) return;
      c.items = c.domain
        ? result.DomainRecords?.Record || []
        : result.Domains?.Domain || [];
      c.total = result.TotalCount || c.items.length;
    } else if (page === "cloudflare") {
      result = await api(c.zone ? "cf.records" : "cf.zones", {
        account_id: c.account,
        zone: c.zone,
        page: c.page,
      });
      if (c.serial !== serial) return;
      c.items = result.result || [];
      c.total = result.result_info?.total_count || c.items.length;
    } else if (page === "gtm") {
      result = await api(`gtm.${c.tab}`, {
        account_id: c.account,
        page: c.page,
      });
      if (c.serial !== serial) return;
      c.raw = result;
      c.items = findRows(result);
      c.total = result.TotalItems || result.TotalCount || c.items.length;
    }
  } catch (error) {
    if (c.serial !== serial) return;
    c.items = [];
    c.error = error.message;
  }
  if (route === page) render();
}
function configContent(form) {
  const data = Object.fromEntries(new FormData(form));
  if (editorMode === "json") return JSON.parse(data.content);
  const content = structuredClone(currentVersion()?.content || {});
  for (const node of form.querySelectorAll("[data-parameter]")) {
    const { parameter: key, type, name, enum: isEnum } = node.dataset;
    const value = data[name];
    if (isEnum) content[key] = JSON.parse(value);
    else if (type === "boolean") content[key] = data[name] === "on";
    else if (["object", "array", "null"].includes(type))
      content[key] = JSON.parse(value);
    else if (type === "number" || type === "integer") {
      if (value === "") throw new Error(key + " 需要填写数值");
      content[key] = Number(value);
    } else content[key] = value;
  }
  return content;
}
function dirtyConfig() {
  const form = document.querySelector("#config-form"),
    r = currentVersion();
  if (!form || !r) return false;
  try {
    return (
      JSON.stringify(configContent(form)) !==
        JSON.stringify(r._savedContent || r.content) ||
      form.elements.mode.value !== (r._savedMode || r.mode) ||
      form.elements.summary.value !== (r._savedSummary ?? r.summary ?? "")
    );
  } catch {
    return true;
  }
}
async function saveCurrentConfig() {
  const form = document.querySelector("#config-form");
  if (!form.reportValidity()) throw new Error("请检查配置字段");
  const data = Object.fromEntries(new FormData(form));
  configId = await api("config.save", {
    id: Number(data.id),
    plan_id: planId,
    template_id: currentVersion().template_id,
    content: configContent(form),
    mode: data.mode,
    summary: data.summary,
  });
  await refresh();
  return configId;
}
function editAccount(id) {
  const a = account(id);
  modal(
    a ? "编辑云账号" : "连接云账号",
    "凭证仅加密保存在本机。编辑时留空表示保留；不要求同时拥有账单、服务器和存储权限。",
    `<div class="form-grid">${field("name", "账号名称", a?.name || "", "text", 'required maxlength="100"')}${select(
      "provider",
      "云厂商",
      Object.entries(providers).map(([value, label]) => ({ value, label })),
      a?.provider || "aliyun",
    )}${select(
      "site",
      "账号站点",
      [
        { value: "china", label: "国内站" },
        { value: "international", label: "国际站" },
      ],
      a?.site || "china",
    )}${field("key", "AccessKey ID / SecretId / 公钥", "", "password", 'autocomplete="new-password"')}${field("secret", "AccessKey Secret / SecretKey / 私钥", "", "password", 'autocomplete="new-password"')}<div class="span-2">${field("token", "Cloudflare API Token / 临时安全令牌", "", "password", 'autocomplete="new-password"')}</div></div>${check("clear_token", "清除临时令牌（Cloudflare 不适用）")}${check("enabled", "启用此账号", a?.enabled ?? true)}`,
    async (data) => {
      await api("account.save", {
        ...Object.fromEntries(data),
        id: a?.id || 0,
        enabled: data.has("enabled"),
        clear_token: data.has("clear_token"),
      });
      toast("账号已保存");
      await refresh();
    },
  );
}
function editTarget(id) {
  const t = state.targets.find((t) => t.id === Number(id));
  modal(
    t ? "编辑发布目标" : "添加发布目标",
    "从统一账号中选择凭证。发布前请确保客户端可以读取公开地址。",
    `<div class="form-grid">${field("name", "目标名称", t?.name || "", "text", "required")}${select(
      "account_id",
      "云账号",
      accountOptions((a) => a.provider !== "cloudflare"),
      t?.account_id || "",
      "required",
    )}${field("region", "存储地域", t?.region || "cn-hangzhou", "text", "required")}${field("bucket", "Bucket", t?.bucket || "", "text", "required")}</div><div class="toolbar" style="margin-bottom:18px">${button("读取已有 Bucket", "target.buckets", {}, "", "folder")}<div id="target-buckets"></div></div>${field("key", "发布对象路径", t?.key || "config.json", "text", "required")}${field("url", "公开访问地址 / CDN（留空自动生成）", t?.url || "", "url")}${check("public_read", "发布时将配置对象设为公开可读", t?.public_read ?? false)}${check("enabled", "启用此目标", t?.enabled ?? true)}`,
    async (data) => {
      await api("target.save", {
        ...Object.fromEntries(data),
        id: t?.id || 0,
        enabled: data.has("enabled"),
        public_read: data.has("public_read"),
      });
      toast("发布目标已保存");
      await refresh();
    },
  );
}
function editDns(index, cf) {
  const c = ctx(),
    r = index !== undefined ? c.items[Number(index)] : null;
  const types = ["A", "AAAA", "CNAME", "TXT", "MX", "NS", "SRV", "CAA"];
  modal(
    r ? "编辑解析记录" : "添加解析记录",
    cf
      ? "保存后将直接更新 Cloudflare 上的记录。"
      : "保存后将直接更新阿里云上的解析记录。",
    `<div class="form-grid">${field("name", cf ? "完整域名" : "主机记录", r ? (cf ? r.name : r.RR) : cf ? c.zoneName : "@", "text", "required")}${select(
      "type",
      "记录类型",
      types.map((v) => ({ value: v, label: v })),
      r ? (cf ? r.type : r.Type) : "A",
    )}</div>${field("value", "记录值", r ? (cf ? r.content : r.Value) : "", "textarea")}${field("ttl", "TTL（Cloudflare 的 1 表示自动）", r ? (cf ? r.ttl : r.TTL) : cf ? 1 : 600, "number", 'required min="1"')}${field("priority", "优先级（MX / SRV 等）", r?.priority ?? r?.Priority ?? 10, "number", 'min="0"')}${cf ? check("proxied", "启用 Cloudflare 代理", r?.proxied) : ""}${cf ? `<details><summary>高级记录参数（SRV / CAA 等）</summary>${field("advanced", "JSON 参数", r?.data ? JSON.stringify({ data: r.data }, null, 2) : "{}", "textarea", 'class="code"')}</details>` : ""}`,
    async (data) => {
      if (cf) {
        const extra = JSON.parse(String(data.get("advanced") || "{}"));
        const payload = {
          type: data.get("type"),
          name: data.get("name"),
          content: data.get("value"),
          ttl: Number(data.get("ttl")),
          proxied: data.has("proxied"),
          ...extra,
        };
        if (["MX", "SRV"].includes(payload.type))
          payload.priority = Number(data.get("priority"));
        if (payload.data) delete payload.content;
        await api("cf.save", {
          account_id: c.account,
          zone: c.zone,
          record: r?.id || "",
          payload,
        });
      } else
        await api("dns.save", {
          account_id: c.account,
          domain: c.domain,
          record: r?.RecordId || "",
          payload: {
            RR: data.get("name"),
            Type: data.get("type"),
            Value: data.get("value"),
            TTL: Number(data.get("ttl")),
            Priority: Number(data.get("priority")),
          },
        });
      toast("解析记录已保存");
      await queryResources();
    },
  );
}
function editGtm(row) {
  const c = ctx();
  const suggestions = {
    InstanceId: row?.InstanceId || "",
    ConfigId: row?.ConfigId || "",
  };
  if (row?.AddressPoolId) {
    delete suggestions.InstanceId;
    delete suggestions.ConfigId;
    suggestions.AddressPoolId = row.AddressPoolId;
  }
  if (row?.AddressId) {
    delete suggestions.InstanceId;
    delete suggestions.ConfigId;
    suggestions.AddressId = row.AddressId;
  }
  modal(
    "GTM 配置管理",
    "完整保留实例策略、地址池绑定、地址编辑和手动可用状态操作；高级参数使用云 API 原始字段。",
    `${select(
      "operation",
      "操作",
      Object.entries(gtmOps).map(([value, label]) => ({ value, label })),
      row?.AddressPoolId
        ? "UpdateCloudGtmAddressPoolBasicConfig"
        : row?.AddressId
          ? "UpdateCloudGtmAddress"
          : "UpdateCloudGtmInstanceConfigBasic",
    )}${field("parameters", "操作参数 JSON", JSON.stringify(suggestions, null, 2), "textarea", 'class="code" required')}<div class="notice warn">删除和绑定替换直接修改云端资源。请核对参数后再提交。</div>`,
    async (data) => {
      const operation = String(data.get("operation"));
      const parameters = JSON.parse(String(data.get("parameters")));
      await api("gtm.action", {
        account_id: c.account,
        operation,
        parameters,
        confirmation: operation,
      });
      toast("GTM 配置已提交");
      await queryResources();
    },
    "确认提交",
  );
}
const actions = {
  "modal.close": () => dialog.close(),
  refresh: () => refresh(),
  logout: async () => {
    await api("logout");
    boot = await api("boot");
    auth();
  },
  "account.edit": (d) => editAccount(d.id),
  "account.open": async (d) => {
    resource[d.route] = {
      account: d.id,
      region: defaults[account(d.id).provider] || "cn-hangzhou",
      page: 1,
      items: [],
      history: [],
      prefix: "",
      marker: "",
      bucket: "",
      total: 0,
      tab: "list",
    };
    location.hash = "#/" + d.route;
    await new Promise((resolve) => setTimeout(resolve, 0));
    route = d.route;
    await queryResources();
  },
  "account.billing": async (d) => {
    await api("billing", { account_id: d.id });
    toast("余额与账单已更新");
    await refresh();
  },
  "account.test": async (d) => {
    await api("cf.test", { account_id: d.id });
    toast("Cloudflare Token 验证通过");
  },
  "account.delete": (d) => {
    const a = account(d.id);
    confirm(
      "删除云账号",
      "删除本地凭证，不会删除云端资源。被发布目标引用的账号不能删除。",
      a.name,
      async (confirmation) => {
        await api("account.delete", { id: a.id, confirmation });
        toast("云账号已删除");
        await refresh();
      },
    );
  },
  page: async (d) => {
    ctx().page = Number(d.page);
    await queryResources();
  },
  "bucket.open": async (d) => {
    Object.assign(ctx(), {
      bucket: d.name,
      region: d.region,
      prefix: "",
      marker: "",
      history: [],
    });
    await queryResources();
  },
  "bucket.back": async () => {
    ctx().bucket = "";
    ctx().bucketMarker = "";
    await queryResources();
  },
  "bucket.next": async () => {
    ctx().bucketMarker = ctx().bucketNext;
    await queryResources();
  },
  "folder.open": async (d) => {
    Object.assign(ctx(), { prefix: d.key, marker: "", history: [] });
    await queryResources();
  },
  "folder.parent": async () => {
    const parts = ctx().prefix.replace(/\/$/, "").split("/");
    parts.pop();
    Object.assign(ctx(), {
      prefix: parts.length ? parts.join("/") + "/" : "",
      marker: "",
      history: [],
    });
    await queryResources();
  },
  "object.next": async () => {
    const c = ctx();
    c.history.push(c.marker);
    c.marker = c.next;
    await queryResources();
  },
  "object.prev": async () => {
    ctx().marker = ctx().history.pop() || "";
    await queryResources();
  },
  "object.upload": () => {
    const c = { ...ctx() };
    modal(
      "上传文件",
      `上传到 ${c.bucket}。同名对象会被覆盖；单文件最大 20 MB，不修改公开访问权限。`,
      field("file", "选择文件", "", "file", "required") +
        field("key", "对象路径（留空使用当前目录 + 文件名）", c.prefix),
      async (data, form) => {
        const file = form.querySelector("[type=file]").files[0];
        if (file.size > 20 * 1024 * 1024) throw new Error("单文件最大 20 MB");
        let key = String(data.get("key") || "");
        if (!key || key.endsWith("/")) key += file.name;
        await api(
          "object.upload",
          {
            account_id: c.account,
            region: c.region,
            bucket: c.bucket,
            key,
            confirmation: key,
          },
          { file },
        );
        toast("文件上传完成");
        await queryResources();
      },
      "上传并确认覆盖",
    );
  },
  "object.download": async (d) => {
    const c = ctx();
    await api(
      "object.download",
      { account_id: c.account, region: c.region, bucket: c.bucket, key: d.key },
      { download: d.key.split("/").at(-1) },
    );
  },
  "object.delete": (d) => {
    const c = { ...ctx() };
    confirm(
      "删除云端文件",
      "此操作直接删除云端对象，请确认你已有需要的备份。",
      d.key,
      async (confirmation) => {
        await api("object.delete", {
          account_id: c.account,
          region: c.region,
          bucket: c.bucket,
          key: d.key,
          confirmation,
        });
        toast("文件已删除");
        await queryResources();
      },
    );
  },
  "instance.action": (d) => {
    const c = { ...ctx() };
    confirm(
      { start: "开机", stop: "关机", reboot: "重启" }[d.operation] + "实例",
      `实例 ${d.id} · ${c.region}。操作可能产生费用或中断业务，提交后请刷新确认最终状态。`,
      d.id,
      async (confirmation) => {
        await api("instance.action", {
          account_id: c.account,
          region: c.region,
          project: c.project || "",
          instance: d.id,
          operation: d.operation,
          confirmation,
        });
        toast("请求已提交，请刷新实例状态");
        await queryResources();
      },
    );
  },
  "domain.open": async (d) => {
    ctx().domain = d.name;
    ctx().page = 1;
    await queryResources();
  },
  "dns.edit": (d) => editDns(d.index, false),
  "dns.delete": (d) => {
    const c = { ...ctx() },
      r = c.items[Number(d.index)];
    confirm(
      "删除解析记录",
      `${r.RR} / ${r.Type} / ${r.Value}`,
      r.RecordId,
      async (confirmation) => {
        await api("dns.delete", {
          account_id: c.account,
          record: r.RecordId,
          confirmation,
        });
        await queryResources();
      },
    );
  },
  "zone.open": async (d) => {
    Object.assign(ctx(), { zone: d.id, zoneName: d.name, page: 1 });
    await queryResources();
  },
  "zone.back": async () => {
    ctx().zone = "";
    ctx().page = 1;
    await queryResources();
  },
  "cf.edit": (d) => editDns(d.index, true),
  "cf.delete": (d) => {
    const c = { ...ctx() },
      r = c.items[Number(d.index)];
    confirm(
      "删除 Cloudflare 记录",
      `${r.name} / ${r.type} / ${r.content || ""}`,
      r.id,
      async (confirmation) => {
        await api("cf.delete", {
          account_id: c.account,
          zone: c.zone,
          record: r.id,
          confirmation,
        });
        await queryResources();
      },
    );
  },
  "gtm.tab": async (d) => {
    ctx().tab = d.tab;
    ctx().page = 1;
    if (ctx().account) await queryResources();
    else render();
  },
  "gtm.raw": () => showJson("GTM 完整响应", ctx().raw),
  "gtm.editrow": (d) => editGtm(ctx().items[Number(d.index)]),
  "gtm.action": () => {
    if (!ctx().account) throw new Error("请先选择并查询阿里云账号");
    editGtm();
  },
  "gtm.detail": async (d) => {
    const r = ctx().items[Number(d.index)];
    if (r.InstanceId && r.ConfigId) {
      const detail = await api("gtm.detail", {
        account_id: ctx().account,
        instance: r.InstanceId,
        config: r.ConfigId,
      });
      modal(
        "实例完整拓扑",
        "可以查看完整资源结构，或继续编辑当前实例配置。",
        field(
          "details",
          "完整 JSON",
          JSON.stringify(detail, null, 2),
          "textarea",
          'class="code" readonly',
        ) + button("编辑该实例", "gtm.editrow", { index: d.index }, "primary"),
        async () => {},
        "关闭",
      );
    } else
      modal(
        "资源详情",
        "查看当前字段，选择配置管理进行修改。",
        field(
          "details",
          "资源 JSON",
          JSON.stringify(r, null, 2),
          "textarea",
          'class="code" readonly',
        ) + button("编辑该资源", "gtm.editrow", { index: d.index }, "primary"),
        async () => {},
        "关闭",
      );
  },
  "config.tab": (d) => {
    configTab = d.tab;
    render();
  },
  "config.select": (d) => {
    if (dirtyConfig() && !window.confirm("当前配置尚未保存，确定切换修订？"))
      return;
    configId = Number(d.id);
    render();
  },
  "config.mode": () => {
    const form = document.querySelector("#config-form");
    if (currentVersion()) {
      try {
        const content = configContent(form);
        const r = currentVersion();
        r._savedContent ??= structuredClone(r.content);
        r.content = content;
        r.mode = form.elements.mode.value;
        r.summary = form.elements.summary.value;
      } catch (error) {
        throw new Error("JSON 格式错误，请修正后再切换");
      }
    }
    editorMode = editorMode === "json" ? "form" : "json";
    render();
  },
  "config.new": async () => {
    const base = currentVersion();
    configId = await api("config.save", {
      plan_id: planId,
      template_id: activePlan()?.template_id,
      content:
        base?.content ||
        Object.fromEntries(
          Object.entries(
            state.templates.find((t) => t.id === activePlan()?.template_id)
              ?.schema.properties || {},
          )
            .filter(([, v]) => Object.hasOwn(v, "default"))
            .map(([k, v]) => [k, v.default]),
        ),
      mode: base?.mode || "plain",
      summary: "新建草稿",
    });
    await refresh();
  },
  "config.clone": async (d) => {
    configId = await api("config.clone", { id: d.id });
    await refresh();
  },
  "config.delete": (d) =>
    confirm(
      "删除草稿",
      "已发布版本不会受到影响。",
      `v${currentVersion().version}`,
      async () => {
        await api("config.delete", { id: d.id });
        configId = 0;
        await refresh();
      },
    ),
  "config.preview": async (d) => {
    const preview = await api("config.preview", { id: d.id });
    modal(
      "输出预览",
      `${bytes(preview.bytes)} · SHA-256 ${preview.checksum}`,
      field(
        "preview",
        "配置输出",
        preview.payload,
        "textarea",
        'class="code" readonly',
      ),
      async () => {},
      "关闭",
    );
  },
  "config.publish": (d) => {
    const plan = activePlan();
    const targets = state.targets.filter(
      (t) => t.enabled && plan?.targets.includes(t.id),
    );
    if (!targets.length) throw new Error("请在方案设置中绑定独立发布目标");
    modal(
      "发布客户端配置",
      "会上传版本文件并更新固定路径，然后校验云端内容。请先保存草稿；未保存的编辑不会发布。",
      targets
        .map((t) =>
          check("target_" + t.id, `${t.name} · ${t.bucket}/${t.key}`, true),
        )
        .join(""),
      async (data) => {
        const selected = targets
          .filter((t) => data.has("target_" + t.id))
          .map((t) => t.id);
        const results = await api("config.publish", {
          id: d.id,
          targets: selected,
        });
        await refresh();
        const failed = results.filter((r) => r.status === "failed");
        if (failed.length) {
          toast(`${failed.length} 个目标发布失败，请到发布历史查看原因`, true);
          configTab = "releases";
          render();
        } else toast("所有目标发布完成");
      },
      "确认发布",
    );
  },
  "config.retry": async (d) => {
    await api("config.retry", { id: d.id });
    toast("重试结束，请查看发布记录");
    await refresh();
  },
  "target.edit": (d) => editTarget(d.id),
  "target.test": async (d) => {
    await api("target.test", { id: d.id });
    toast("上传、读取与清理测试通过");
  },
  "target.delete": (d) => {
    const t = state.targets.find((t) => t.id === Number(d.id));
    confirm(
      "删除发布目标",
      "仅删除本地配置，不删除存储桶或云端文件。",
      t.name,
      async (confirmation) => {
        await api("target.delete", { id: t.id, confirmation });
        await refresh();
      },
    );
  },
  "target.buckets": async () => {
    const form = document.querySelector("#modal-form"),
      data = Object.fromEntries(new FormData(form));
    const result = await api("buckets", {
      account_id: data.account_id,
      region: data.region,
    });
    const box = dialog.querySelector("#target-buckets");
    box.innerHTML = select("picked_bucket", "选择已有 Bucket", [
      { value: "", label: "请选择" },
      ...result.items.map((r) => ({
        value: r.name,
        label: r.name + " · " + r.region,
      })),
    ]);
    box.querySelector("select").onchange = (event) => {
      const chosen = result.items.find((r) => r.name === event.target.value);
      if (chosen) {
        form.elements.bucket.value = chosen.name;
        form.elements.region.value = chosen.region;
        form.elements.url.value = "";
      }
    };
  },
  backup: async () => {
    await api(
      "backup",
      {},
      {
        download:
          "cloud-desk-backup-" +
          new Date().toISOString().slice(0, 10) +
          ".json",
      },
    );
  },
  restore: () =>
    modal(
      "恢复完整备份",
      "将覆盖当前云账号、配置和发布记录，保留当前管理员登录信息。请先下载当前备份。",
      field("file", "备份文件", "", "file", 'required accept=".json"') +
        field("confirmation", "输入「恢复备份」确认", "", "text", "required"),
      async (data, form) => {
        await api(
          "restore",
          { confirmation: data.get("confirmation") },
          { file: form.querySelector("[type=file]").files[0] },
        );
        toast("备份已恢复");
        await refresh();
      },
      "覆盖并恢复",
      true,
    ),
  "import.v1": () =>
    modal(
      "导入旧版数据",
      "选择旧项目 _app/storage/app/private/state.json，并填写 _app/.env 中的 APP_KEY。请在未添加云账号的新安装中导入。",
      field("file", "旧版 state.json", "", "file", 'required accept=".json"') +
        field(
          "legacy_key",
          "旧版 APP_KEY",
          "",
          "password",
          'required autocomplete="off"',
        ) +
        field("confirmation", "输入「导入旧版」确认", "", "text", "required"),
      async (data, form) => {
        const result = await api(
          "import.v1",
          {
            legacy_key: data.get("legacy_key"),
            confirmation: data.get("confirmation"),
          },
          { file: form.querySelector("[type=file]").files[0] },
        );
        toast(
          `已导入 ${result.accounts} 个账号、${result.targets} 个目标、${result.versions} 个版本`,
        );
        await refresh();
      },
      "导入数据",
    ),
};
function schemaDefaults(value, schema) {
  if (
    schema.type === "object" &&
    value &&
    typeof value === "object" &&
    !Array.isArray(value)
  ) {
    const out = structuredClone(value);
    for (const [k, spec] of Object.entries(schema.properties || {})) {
      if (!Object.hasOwn(out, k)) {
        if (Object.hasOwn(spec, "default"))
          Object.defineProperty(out, k, {
            value: structuredClone(spec.default),
            enumerable: true,
            writable: true,
            configurable: true,
          });
      } else out[k] = schemaDefaults(out[k], spec);
    }
    return out;
  }
  if (schema.type === "array" && Array.isArray(value))
    return value.map((item) => schemaDefaults(item, schema.items || {}));
  return value;
}
function removeParameter(content, path) {
  const parts = path.split(".");
  let node = content;
  for (const key of parts.slice(0, -1)) {
    if (!node || typeof node !== "object" || !Object.hasOwn(node, key)) return;
    node = node[key];
  }
  if (node && typeof node === "object") delete node[parts.at(-1)];
}
function localDiff(a, b, path = "$") {
  if (JSON.stringify(a) === JSON.stringify(b)) return [];
  if (
    a &&
    b &&
    typeof a === "object" &&
    typeof b === "object" &&
    !Array.isArray(a) &&
    !Array.isArray(b)
  )
    return [...new Set([...Object.keys(a), ...Object.keys(b)])].flatMap((k) =>
      !Object.hasOwn(a, k)
        ? [{ path: path + "." + k, kind: "新增", after: b[k] }]
        : !Object.hasOwn(b, k)
          ? [{ path: path + "." + k, kind: "删除", before: a[k] }]
          : localDiff(a[k], b[k], path + "." + k),
    );
  return [{ path, kind: "修改", before: a, after: b }];
}
Object.assign(actions, {
  "config.localdiff": () => {
    const r = currentVersion();
    showJson(
      "尚未保存的参数变化",
      localDiff(
        r._savedContent || r.content,
        configContent(document.querySelector("#config-form")),
      ),
    );
  },
  "plan.edit": (d) => {
    const p = state.plans.find((p) => p.id === Number(d.id)),
      source = currentVersion();
    modal(
      p ? "编辑配置方案" : "创建配置方案",
      "不同客户端结构使用独立方案与对象路径。模板仅用于新草稿，不会修改已有修订。",
      field("name", "方案名称", p?.name || "", "text", "required") +
        (!p && source
          ? check(
              "copy_source",
              `以当前已保存修订 r${source.revision} 为基础（保留原结构）`,
              true,
            )
          : "") +
        field(
          "clients",
          "适用客户端版本",
          p?.clients || "",
          "text",
          'placeholder="例如 1.x / >=2.0"',
        ) +
        select(
          "template_id",
          "新修订默认结构模板",
          state.templates.map((t) => ({ value: t.id, label: t.name })),
          p?.template_id || source?.template_id,
        ) +
        state.targets
          .map((t) =>
            check(
              "target_" + t.id,
              t.name + " · " + t.bucket + "/" + t.key,
              p?.targets.includes(t.id),
            ),
          )
          .join(""),
      async (data) => {
        planId = await api("plan.save", {
          id: p?.id || 0,
          name: data.get("name"),
          clients: data.get("clients"),
          template_id: Number(data.get("template_id")),
          targets: state.targets
            .filter((t) => data.has("target_" + t.id))
            .map((t) => t.id),
        });
        configId =
          !p && source && data.has("copy_source")
            ? await api("config.clone", { id: source.id, plan_id: planId })
            : 0;
        await refresh();
      },
    );
  },
  "template.edit": (d) => {
    const t = state.templates.find((t) => t.id === Number(d.id));
    modal(
      "创建结构模板版本",
      "保存会创建新模板，不会修改旧结构。支持 type、properties、required、enum、items、minimum、minLength 与 additionalProperties。",
      field(
        "name",
        "模板名称",
        t ? t.name + "（新版）" : "",
        "text",
        "required",
      ) +
        field(
          "schema",
          "结构 JSON",
          JSON.stringify(
            t?.schema || {
              type: "object",
              properties: {},
              additionalProperties: true,
            },
            null,
            2,
          ),
          "textarea",
          'class="code" required',
        ),
      async (data) => {
        await api("template.save", {
          name: data.get("name"),
          schema: JSON.parse(data.get("schema")),
        });
        await refresh();
      },
    );
  },
  "schema.add": (d) => {
    const r = currentVersion();
    r._savedContent ??= structuredClone(r.content);
    r.content = configContent(document.querySelector("#config-form"));
    const spec = r.schema.properties[d.key] || {};
    const defaults = {
      object: {},
      array: [],
      boolean: false,
      integer: 0,
      number: 0,
      null: null,
      string: "",
    };
    const value = Object.hasOwn(spec, "default")
      ? structuredClone(spec.default)
      : Object.hasOwn(defaults, spec.type)
        ? defaults[spec.type]
        : "";
    Object.defineProperty(r.content, d.key, {
      value,
      enumerable: true,
      writable: true,
      configurable: true,
    });
    render();
  },
  "schema.remove": (d) => {
    const r = currentVersion();
    r._savedContent ??= structuredClone(r.content);
    r.content = configContent(document.querySelector("#config-form"));
    delete r.content[d.key];
    render();
  },
  "schema.upgrade": () => {
    const r = currentVersion(),
      before = configContent(document.querySelector("#config-form"));
    modal(
      "显式升级结构",
      "仅在确认后切换结构。默认保留未知和旧参数；要删除的参数必须手工列出。升级内容保存后才生效。",
      select(
        "template_id",
        "目标结构",
        state.templates.map((t) => ({ value: t.id, label: t.name })),
        activePlan().template_id,
      ) +
        field(
          "remove",
          "明确删除的参数路径（每行一个，如 contact.old_key）",
          "",
          "textarea",
        ),
      async (data) => {
        const t = state.templates.find(
            (t) => t.id === Number(data.get("template_id")),
          ),
          after = schemaDefaults(before, t.schema);
        for (const key of String(data.get("remove"))
          .split(/\r?\n/)
          .map((k) => k.trim())
          .filter(Boolean))
          removeParameter(after, key);
        setTimeout(
          () =>
            modal(
              "确认结构升级差异",
              "只更改编辑内容；确认后仍需保存修订，历史记录不会被修改。",
              field(
                "diff",
                "本次变化",
                JSON.stringify(localDiff(before, after), null, 2),
                "textarea",
                'class="code" readonly',
              ),
              async () => {
                r._savedContent ??= structuredClone(r.content);
                r.content = after;
                r.schema = structuredClone(t.schema);
                r.template_id = t.id;
                render();
                toast("结构已切换，请保存修订");
              },
              "确认应用",
            ),
          0,
        );
      },
      "确认升级",
    );
  },
  "config.diff": (d) => {
    const r = currentVersion(),
      versions = state.versions.filter(
        (v) => v.plan_id === r.plan_id && v.id !== r.id,
      );
    if (!versions.length) throw new Error("当前方案还没有其他修订");
    modal(
      "修订差异",
      "对比已保存修订，不包含尚未保存的编辑。",
      select(
        "from",
        "基准修订",
        versions.map((v) => ({
          value: v.id,
          label: "r" + v.revision + " · " + v.summary,
        })),
        r.parent_id || versions.at(-1).id,
      ),
      async (data) => {
        const differences = await api("config.diff", {
          id: d.id,
          from: Number(data.get("from")),
        });
        setTimeout(() => showJson("参数差异", differences), 0);
      },
      "查看差异",
    );
  },
  "config.savepush": async () => {
    const id = await saveCurrentConfig();
    actions["config.publish"]({ id });
  },
  "config.rollback": (d) =>
    confirm(
      "回滚并推送",
      "创建与所选历史修订内容一致的新修订，重新推送到该方案当前绑定的全部目标。",
      "r" + currentVersion().revision,
      async () => {
        const targets = activePlan().targets;
        if (!targets.length) throw new Error("请先绑定发布目标");
        configId = await api("config.clone", { id: d.id });
        const results = await api("config.publish", { id: configId, targets });
        await refresh();
        toast(
          results.some((r) => r.status === "failed")
            ? "部分目标回滚失败，请查看发布历史"
            : "回滚推送完成",
          results.some((r) => r.status === "failed"),
        );
      },
    ),
});
document.addEventListener("click", async (event) => {
  const node = event.target.closest("[data-action]");
  if (!node) return;
  const handler = actions[node.dataset.action];
  if (!handler) return;
  node.disabled = true;
  try {
    await handler(node.dataset);
  } catch (error) {
    toast(error.message, true);
  } finally {
    node.disabled = false;
  }
});
document.addEventListener("change", (event) => {
  if (event.target.id === "plan-picker") {
    if (dirtyConfig() && !window.confirm("当前配置尚未保存，确定切换方案？")) {
      event.target.value = String(planId);
      return;
    }
    planId = Number(event.target.value);
    configId = 0;
    render();
    return;
  }
  if (
    event.target.closest("#resource-form") &&
    event.target.name === "account_id"
  ) {
    const c = ctx(),
      a = account(event.target.value);
    c.account = event.target.value;
    c.items = [];
    c.page = 1;
    c.bucket = "";
    c.zone = "";
    c.domain = "";
    c.region =
      route === "storage" && a?.provider === "ucloud"
        ? "cn-bj"
        : defaults[a?.provider] || "cn-hangzhou";
    const region = event.target.form.elements.region;
    if (region) region.value = c.region;
    const bucket = event.target.form.elements.bucket;
    if (bucket) bucket.value = "";
  }
});
document.addEventListener("submit", async (event) => {
  const form = event.target,
    formId = form.getAttribute("id");
  if (
    ![
      "resource-form",
      "prefix-form",
      "config-form",
      "config-security",
      "password-form",
    ].includes(formId)
  )
    return;
  event.preventDefault();
  const submit = event.submitter;
  if (submit) submit.disabled = true;
  try {
    const data = Object.fromEntries(new FormData(form));
    if (formId === "resource-form") {
      const c = ctx();
      Object.assign(c, {
        account: data.account_id,
        region: data.region || c.region,
        project: data.project || "",
        page: 1,
      });
      if (route === "storage") {
        c.bucket = data.bucket || "";
        c.prefix = "";
        c.marker = "";
        c.history = [];
      }
      if (route === "dns") c.domain = data.domain || "";
      await queryResources();
    } else if (formId === "prefix-form") {
      Object.assign(ctx(), { prefix: data.prefix, marker: "", history: [] });
      await queryResources();
    } else if (formId === "config-form") {
      await saveCurrentConfig();
      toast("修订已保存，历史发布内容保持不变");
    } else if (formId === "config-security") {
      await api("config.settings", {
        xor_key: data.xor_key,
        generate_signing: data.generate_signing === "on",
      });
      toast("加密设置已保存");
      await refresh();
    } else {
      await api("password", data);
      toast("密码已修改，请重新登录");
      boot = await api("boot");
      auth();
    }
  } catch (error) {
    toast(error.message, true);
  } finally {
    if (submit) submit.disabled = false;
  }
});
window.addEventListener("hashchange", () => {
  if (boot.authenticated) render();
});
async function start() {
  try {
    boot = await api("boot");
    if (boot.authenticated) await refresh();
    else auth();
  } catch (error) {
    root.innerHTML = `<div class="boot"><span class="brand-mark">C</span><h2>暂时无法打开工作台</h2><p>${e(error.message)}</p><p>请确认使用 PHP 8.2+，并允许网站用户写入 _core/data。</p>${button("重试", "reload", {}, "primary")}</div>`;
    actions.reload = start;
  }
}
start();
