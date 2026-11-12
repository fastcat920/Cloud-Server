import assert from "node:assert/strict";
import {
  configFields,
  readFields,
  defaultValue,
} from "../assets/config-form.js";
const original = {
  domains: ["https://old.example"],
  update: { platforms: { android: { enabled: true, build: 9 } } },
  extra: { unknown: "preserved" },
  empty: {},
};
const html = configFields(original, { type: "object", properties: {} });
assert(
  html.includes(
    'data-config-path="[&quot;update&quot;,&quot;platforms&quot;,&quot;android&quot;,&quot;build&quot;]"',
  ),
);
assert(html.includes("添加一项"));
assert(!html.includes("<textarea"));
const nodes = [
  {
    dataset: {
      configPath: '["domains","0"]',
      name: "domain",
      type: "string",
      enum: "0",
    },
  },
  {
    dataset: {
      configPath: '["update","platforms","android","enabled"]',
      name: "enabled",
      type: "boolean",
      enum: "0",
    },
  },
  {
    dataset: {
      configPath: '["update","platforms","android","build"]',
      name: "build",
      type: "number",
      enum: "0",
    },
  },
];
const form = {
  querySelectorAll: () => nodes,
  elements: {
    domain: { value: "https://new.example" },
    enabled: { checked: false },
    build: { value: "10" },
  },
};
const result = readFields(form, original);
assert.equal(result.domains[0], "https://new.example");
assert.equal(result.update.platforms.android.enabled, false);
assert.equal(result.update.platforms.android.build, 10);
assert.equal(result.extra.unknown, "preserved");
assert.deepEqual(result.empty, {});
assert.equal(original.update.platforms.android.build, 9);
form.elements.build.value = "";
assert.throws(() => readFields(form, original), /有效数值/);
assert.equal(defaultValue({ type: "boolean" }), false);
assert.deepEqual(defaultValue({ type: "array" }), []);
assert(!configFields({ "<script>": "<script>" }).includes("<script>"));
console.log(
  "PASS: recursive forms, nested values, arrays, types, preservation and escaping",
);
const { parameterInput, parameterValue } =
  await import("../assets/config-form.js");
assert(parameterInput({ type: "string" }).includes('name="value"'));
assert(parameterInput({ type: "integer" }).includes('step="1"'));
assert(parameterInput({ type: "object" }).includes("逐项填写"));
assert.equal(
  parameterValue(new Map([["value", "42"]]), { type: "integer" }),
  42,
);
assert.throws(() =>
  parameterValue(new Map([["value", "1.5"]]), { type: "integer" }),
);
assert.equal(
  parameterValue(new Map([["value", "hello"]]), { type: "string" }),
  "hello",
);
const removedGroup = configFields(
  { update: {} },
  {
    type: "object",
    properties: {
      update: {
        type: "object",
        properties: { platforms: { type: "object", properties: {} } },
      },
    },
  },
);
assert(removedGroup.includes("未加入配置的模板字段（1）"));
assert(
  !removedGroup.includes(
    'data-tree-path="[&quot;update&quot;,&quot;platforms&quot;]"',
  ),
);
assert(removedGroup.includes("当前没有值，不会输出到配置文件"));
const compact = configFields({ panel_type: "v2board" });
assert(compact.includes('class="config-header"'));
assert(compact.indexOf("面板类型") < compact.indexOf("panel_type</small>"));
assert(compact.indexOf(">上移</button>") < compact.indexOf(">下移</button>"));
assert(compact.indexOf(">下移</button>") < compact.indexOf(">删除</button>"));
assert(compact.indexOf(">删除</button>") < compact.indexOf("data-drag-path="));
assert(!compact.includes("彻底删除"));
