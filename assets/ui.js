export const escape = (value = "") =>
  String(value ?? "").replace(
    /[&<>"']/g,
    (c) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[
        c
      ],
  );
export const icons = {
  grid: "M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z",
  cloud: "M7 18a5 5 0 0 1-.8-9.9 6 6 0 0 1 11.6 0A5 5 0 0 1 17 18Z",
  server: "M4 3h16v7H4zM4 14h16v7H4zM7 6.5h.1M7 17.5h.1M16 6.5h1M16 17.5h1",
  folder: "M3 7V5h6l2 2h10v13H3z",
  globe:
    "M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0ZM3 12h18M12 3c5 5 5 13 0 18-5-5-5-13 0-18",
  code: "m8 7-5 5 5 5m8-10 5 5-5 5m-3-14-2 20",
  activity: "M3 12h4l3-8 4 16 3-8h4",
  settings:
    "M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8ZM9 3h6l1 3 3 1 2 5-2 5-3 1-1 3H9l-1-3-3-1-2-5 2-5 3-1z",
  plus: "M12 5v14M5 12h14",
  arrow: "M5 12h14m-5-5 5 5-5 5",
  refresh: "M20 7v5h-5M4 17v-5h5M18.5 6a8 8 0 0 0-13 1M5.5 18a8 8 0 0 0 13-1",
  shield: "M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6zM8 12l3 3 5-6",
  logout: "M9 3H4v18h5M9 12h12m-5-5 5 5-5 5",
  file: "M5 3h9l5 5v13H5zM14 3v6h5",
  check: "m5 12 4 4 10-10",
  key: "M14 6a4 4 0 1 0 4 4M14 6l7-3v6l-3 1M11 13l-8 8M5 19l-2-2",
  download: "M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5",
  search: "M15 15l6 6M17 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0Z",
};
export const icon = (name) =>
  `<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="${icons[name] || icons.cloud}"/></svg>`;
export const button = (label, action = "", data = {}, kind = "", name = "") =>
  `<button type="button" class="button ${kind}" ${action ? `data-action="${escape(action)}"` : ""} ${Object.entries(
    data,
  )
    .map(([k, v]) => `data-${k}="${escape(v)}"`)
    .join(" ")}>${name ? icon(name) : ""}${escape(label)}</button>`;
export const badge = (text, kind = "") =>
  `<span class="tag ${kind}">${escape(text)}</span>`;
export const field = (name, label, value = "", type = "text", extra = "") =>
  `<div class="field"><label for="${escape(name)}">${escape(label)}</label>${type === "textarea" ? `<textarea id="${escape(name)}" name="${escape(name)}" ${extra}>${escape(value)}</textarea>` : `<input id="${escape(name)}" name="${escape(name)}" type="${type}" value="${escape(value)}" ${extra}>`}</div>`;
export const select = (name, label, options, value = "", extra = "") =>
  `<div class="field"><label for="${escape(name)}">${escape(label)}</label><select id="${escape(name)}" name="${escape(name)}" ${extra}>${options
    .map((o) => {
      const v = o.value ?? o.id;
      return `<option value="${escape(v)}" ${String(value) === String(v) ? "selected" : ""}>${escape(o.label ?? o.name ?? v)}</option>`;
    })
    .join("")}</select></div>`;
export const check = (name, label, checked = false) =>
  `<label class="check"><input type="checkbox" name="${escape(name)}" ${checked ? "checked" : ""}>${escape(label)}</label>`;
export const empty = (
  title,
  description = "",
  action = "",
  label = "开始添加",
  image = "cloud",
) =>
  `<div class="empty"><div class="empty-icon">${icon(image)}</div><h3>${escape(title)}</h3><p>${escape(description)}</p>${action ? button(label, action, {}, "primary", "plus") : ""}</div>`;
export const panel = (title, body, extra = "") =>
  `<section class="panel"><div class="panel-head"><h2>${escape(title)}</h2>${extra}</div>${body}</section>`;
export const table = (headers, rows, blank = "暂无数据") =>
  rows.length
    ? `<div class="table-wrap"><table><thead><tr>${headers.map((h) => `<th>${escape(h)}</th>`).join("")}</tr></thead><tbody>${rows.map((r) => `<tr>${r.map((c) => `<td>${c ?? ""}</td>`).join("")}</tr>`).join("")}</tbody></table></div>`
    : empty(blank, "查询结果将在这里显示。");
export const date = (n) =>
  n
    ? (typeof n === "number" ? new Date(n * 1000) : new Date(n)).toLocaleString(
        "zh-CN",
        { hour12: false },
      )
    : "—";
export const bytes = (n) =>
  n > 1048576
    ? `${(n / 1048576).toFixed(1)} MB`
    : `${(n / 1024).toFixed(1)} KB`;
export function toast(text, error = false) {
  const node = document.createElement("div");
  node.className = "toast" + (error ? " error" : "");
  node.textContent = text;
  document.querySelector("#toasts").append(node);
  setTimeout(() => node.remove(), 5000);
}
export function modal(
  title,
  description,
  body,
  onSubmit,
  submit = "保存",
  danger = false,
) {
  const dialog = document.querySelector("#dialog");
  dialog.innerHTML = `<form id="modal-form"><div class="dialog-head"><div><h2 id="dialog-title">${escape(title)}</h2><p>${escape(description)}</p></div>${button("×", "modal.close", {}, "icon-only")}</div><div class="dialog-body"><div class="dialog-error" role="alert"></div>${body}</div><div class="dialog-foot">${button("取消", "modal.close")}<button type="submit" class="button ${danger ? "danger" : "primary"}">${escape(submit)}</button></div></form>`;
  dialog.querySelector("form").addEventListener("submit", async (e) => {
    e.preventDefault();
    const btn = e.submitter;
    btn.disabled = true;
    dialog.querySelector(".dialog-error").textContent = "";
    try {
      await onSubmit(new FormData(e.target), e.target);
      dialog.close();
    } catch (error) {
      dialog.querySelector(".dialog-error").textContent = error.message;
    } finally {
      btn.disabled = false;
    }
  });
  if (!dialog.open) dialog.showModal();
  dialog.querySelector("input:not([type=hidden]),textarea,select")?.focus();
}
export const showJson = (title, value) =>
  modal(
    title,
    "完整接口响应，可复制用于检查配置。",
    field(
      "json",
      "JSON",
      JSON.stringify(value, null, 2),
      "textarea",
      'class="code" readonly',
    ),
    async () => {},
    "关闭",
  );
export function confirm(title, description, expected, callback) {
  modal(
    title,
    description,
    field(
      "confirmation",
      `输入「${expected}」确认`,
      "",
      "text",
      'required autocomplete="off"',
    ),
    async (data) => {
      if (data.get("confirmation") !== expected)
        throw new Error("确认内容不一致");
      await callback(expected);
    },
    "确认操作",
    true,
  );
}
