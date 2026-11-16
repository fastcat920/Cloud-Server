import {
  escape as e,
  button,
  field,
  select,
  check,
  table,
  badge,
  panel,
  modal,
  confirm,
  toast,
} from "./ui.js";
export function createNotifications({ api, render }) {
  let data = null,
    loading = false,
    error = "",
    generation = 0;
  const labels = {
    pending: "待发送",
    sending: "发送中",
    retry: "等待重试",
    failed: "失败",
    sent: "已发送",
    cancelled: "已取消",
  };
  async function load() {
    if (loading) return;
    const current = generation;
    loading = true;
    error = "";
    try {
      const result = await api("notify.list");
      if (current === generation) data = result;
    } catch (err) {
      if (current === generation) error = err.message;
    } finally {
      if (current === generation) {
        loading = false;
        render();
      }
    }
  }
  const find = (type, id) => data?.[type].find((row) => row.id === Number(id));
  function callback(source) {
    const url = new URL("index.php", location.href);
    url.hash = "";
    url.search = "";
    url.searchParams.set("api", "notify.webhook");
    url.searchParams.set("source", source.id);
    url.searchParams.set("key", source.webhook_key);
    return url.href;
  }
  function view() {
    if (!data && !loading && !error) queueMicrotask(load);
    const header = `<div class="page-head"><div><div class="eyebrow">NOTIFICATIONS</div><h1>消息通知</h1><p class="subtitle">统一管理通知渠道、告警来源与消息投递。</p></div><div class="toolbar">${button("刷新", "notify.refresh", {}, "", "refresh")}${data ? button("新增渠道", "notify.channel", {}, "primary", "plus") : ""}</div></div>`;
    if (!data)
      return (
        header +
        (error
          ? `<div class="notice error">${e(error)}</div>`
          : '<p role="status">正在读取通知配置…</p>')
      );
    return (
      header +
      (error ? `<div class="notice error">${e(error)}</div>` : "") +
      '<div class="notification-panels">' +
      panel(
        "通知渠道",
        table(
          ["名称", "渠道", "接收目标", "状态", "操作"],
          data.channels.map((row) => [
            e(row.name),
            "Telegram",
            e(row.chat_id) +
              (row.thread_id ? ` · 话题 ${e(row.thread_id)}` : ""),
            row.enabled ? "启用" : "停用",
            `<div class="toolbar">${button("编辑", "notify.channel", { id: row.id }, "text")}${button("验证机器人", "notify.verify", { id: row.id }, "text")}${button("测试发送", "notify.test", { id: row.id }, "text")}${button("删除", "notify.delete", { kind: "channel", id: row.id }, "text danger")}</div>`,
          ]),
        ),
      ) +
      panel(
        "告警来源",
        `<div class="panel-body"><div class="toolbar">${button("添加阿里云告警", "notify.source", {}, "", "plus")}</div><p class="help">每个来源拥有独立回调地址，可转发 GTM 地址、地址池故障及恢复通知；无需保持本页面打开。</p></div>` +
          table(
            ["来源", "发送到", "状态", "操作"],
            data.sources.map((row) => [
              e(row.name),
              e(find("channels", row.channel_id)?.name || "渠道已删除"),
              row.enabled ? "启用" : "停用",
              `<div class="toolbar">${button("编辑", "notify.source", { id: row.id }, "text")}${button("接入信息", "notify.connect", { id: row.id }, "text")}${button("重置密钥", "notify.rotate", { id: row.id }, "text danger")}${button("删除", "notify.delete", { kind: "source", id: row.id }, "text danger")}</div>`,
            ]),
          ),
      ) +
      panel(
        "投递记录",
        `<div class="panel-body"><div class="toolbar">${button("处理待发送消息", "notify.process")}</div><p class="help">保留最近 200 条已处理消息；失败可重试。回调优先保存消息，PHP-FPM 响应后转发；计划任务负责积压和失败重试。</p></div>` +
          table(
            ["时间", "来源", "渠道", "状态", "操作"],
            data.deliveries.map((row) => [
              e(new Date(row.created_at * 1000).toLocaleString()),
              e(row.source_name),
              e(row.channel_name),
              `${badge(labels[row.status] || row.status, row.status === "sent" ? "good" : row.status === "failed" ? "bad" : row.status === "retry" ? "warn" : "")}${row.error ? `<div class="help">${e(row.error)}</div>` : ""}`,
              `<div class="toolbar">${button("详情", "notify.detail", { id: row.id }, "text")}${["failed", "retry"].includes(row.status) ? button("重试", "notify.retry", { id: row.id }, "text") : ""}</div>`,
            ]),
          ),
      ) +
      panel(
        "后台投递",
        `<div class="panel-body"><p>建议在宝塔计划任务中添加 Shell 脚本，每分钟执行一次，用于积压消息及失败重试。</p>${field("notification_worker", "执行命令", `sudo -u www /www/server/php/82/bin/php '${data.worker_path.replace(/'/g, "'\\''")}'`, "text", "readonly")}<p class="help">请以网站运行用户 www 执行；PHP 路径按宝塔实际版本调整。服务器需要能访问 api.telegram.org。无需数据库或常驻进程。</p></div>`,
      ) +
      "</div>"
    );
  }
  function channel(d) {
    const row = find("channels", d.id);
    modal(
      row ? "编辑通知渠道" : "新增 Telegram 渠道",
      "Token 请从 BotFather 完整复制并保存。/start 要发给你自己的机器人；本项目只发送通知，不会回复该命令。Chat ID 是接收者或群组的 ID，不是机器人用户名或 Token 开头的数字。",
      field("name", "渠道名称", row?.name || "", "text", "required") +
        field(
          "token",
          "Bot Token",
          "",
          "password",
          `autocomplete="new-password" ${row ? "" : "required"}`,
        ) +
        field("chat_id", "Chat ID", row?.chat_id || "", "text", "required") +
        field(
          "thread_id",
          "话题 ID（可选）",
          row?.thread_id || "",
          "number",
          'min="1"',
        ) +
        check("enabled", "启用渠道", row?.enabled ?? true),
      async (form) => {
        await api("notify.channel.save", {
          ...Object.fromEntries(form),
          id: row?.id || 0,
          enabled: form.has("enabled"),
        });
        await load();
      },
      "保存",
      false,
      { drawer: true },
    );
  }
  function source(d) {
    if (!data.channels.length) {
      toast("请先添加通知渠道", true);
      return;
    }
    const row = find("sources", d.id);
    modal(
      row ? "编辑告警来源" : "添加阿里云告警",
      "接收云端 Webhook，并转发到所选通知渠道。",
      field("name", "来源名称", row?.name || "阿里云 GTM", "text", "required") +
        select(
          "channel_id",
          "通知渠道",
          data.channels.map((c) => ({ value: c.id, label: c.name })),
          row?.channel_id || data.channels[0].id,
          "required",
        ) +
        check("enabled", "启用接收", row?.enabled ?? true),
      async (form) => {
        const id = await api("notify.source.save", {
          ...Object.fromEntries(form),
          id: row?.id || 0,
          enabled: form.has("enabled"),
        });
        await load();
        toast("告警来源已保存，请打开接入信息配置阿里云回调地址");
      },
      "保存",
      false,
      { drawer: true },
    );
  }
  const actions = {
    "notify.refresh": load,
    "notify.channel": channel,
    "notify.source": source,
    "notify.connect": (d) => {
      const row = find("sources", d.id);
      modal(
        "阿里云告警接入",
        "将此地址配置到阿里云告警联系人的 Webhook；地址包含独立密钥，请只提供给阿里云。",
        field(
          "callback_url",
          "回调地址",
          callback(row),
          "textarea",
          "readonly",
        ) +
          `<ol><li>在阿里云云监控中创建告警联系人或自定义 Webhook，使用 POST，数据格式选择 JSON 或 FORM。</li><li>将联系人加入告警组，在 GTM 告警设置中选择该组，并启用地址/地址池不可用及恢复通知。</li><li>若控制台仅提供机器人模板，可使用钉钉文本或 Markdown 格式，本项目会转发消息内容。</li><li>点击云端测试，随后在本项目刷新投递记录，核对回调是否到达及 Telegram 投递结果。</li></ol><p class="help">不同国际站控制台的入口可能不同，请以实际可选通知方式为准。回调地址必须可从公网访问；只保存本地配置不会自动修改阿里云告警设置。</p>`,
        async () => {},
        "关闭",
      );
    },
    "notify.verify": async (d) => {
      const bot = await api("notify.verify", { id: Number(d.id) });
      modal(
        "机器人验证成功",
        "Token 有效，请确认下面是你要使用的机器人。",
        `<p>${e(bot.name)} · @${e(bot.username)}</p><p><a href="https://t.me/${encodeURIComponent(bot.username)}" target="_blank" rel="noreferrer">打开这个机器人，发送 /start</a></p><p class="help">没有自动回复是正常的。返回本项目，填写接收者 Chat ID 并点击测试发送。</p>`,
        async () => {},
        "关闭",
      );
    },
    "notify.test": async (d) => {
      const result = await api("notify.test", { id: Number(d.id) });
      await load();
      toast(
        result.status === "sent"
          ? "测试消息已发送"
          : ["pending", "sending"].includes(result.status)
            ? "测试消息已入队，请稍后刷新投递记录"
            : result.error || "测试消息未送达，请查看投递记录",
        ["failed", "retry"].includes(result.status),
      );
    },
    "notify.retry": async (d) => {
      await api("notify.retry", { id: Number(d.id) });
      await load();
    },
    "notify.process": async () => {
      await api("notify.process");
      await load();
    },
    "notify.detail": (d) => {
      const row = find("deliveries", d.id);
      modal(
        "消息投递详情",
        `${labels[row.status]} · 已尝试 ${row.attempts} 次${row.part_count > 1 ? ` · 分段已发送 ${row.part_index || 0}/${row.part_count}` : ""}`,
        field(
          "message",
          row.content_mode === "original"
            ? "原始消息正文"
            : row.content_mode === "system"
              ? "系统测试消息"
              : "消息内容（旧版整理）",
          row.message,
          "textarea",
          "readonly",
        ) + (row.error ? `<p class="notice error">${e(row.error)}</p>` : ""),
        async () => {},
        "关闭",
      );
    },
    "notify.delete": (d) =>
      confirm(
        "删除通知配置",
        d.kind === "channel"
          ? "被来源引用的渠道不能删除。"
          : "删除来源后，此回调地址失效，未发送的消息会取消。",
        String(d.id),
        async (confirmation) => {
          await api(`notify.${d.kind}.delete`, {
            id: Number(d.id),
            confirmation,
          });
          await load();
        },
      ),
    "notify.rotate": (d) =>
      confirm(
        "重置回调密钥",
        "原回调地址将立即失效。请在阿里云更新新的地址。",
        String(d.id),
        async (confirmation) => {
          await api("notify.source.rotate", { id: Number(d.id), confirmation });
          await load();
        },
      ),
  };
  return {
    view,
    actions,
    reset: () => {
      generation++;
      data = null;
      error = "";
      loading = false;
    },
  };
}
