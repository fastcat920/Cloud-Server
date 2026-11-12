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
