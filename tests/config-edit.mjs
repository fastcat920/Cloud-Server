import assert from "node:assert/strict";
import {
  deleteParameter,
  moveParameter,
  orderedKeys,
} from "../assets/config-edit.js";
const shared = {
  type: "object",
  properties: {
    update: {
      type: "object",
      properties: { platforms: { type: "object", properties: {} } },
      required: ["platforms"],
    },
    domains: { type: "array", items: { type: "string" } },
  },
};
const original = {
  schema: structuredClone(shared),
  content: {
    update: { platforms: { android: { enabled: true } } },
    domains: ["a", "b", "c"],
  },
  field_order: {},
};
const r = structuredClone(original);
deleteParameter(r, ["update", "platforms"]);
assert(!Object.hasOwn(r.content.update, "platforms"));
assert(!Object.hasOwn(r.schema.properties.update.properties, "platforms"));
assert.deepEqual(r.schema.properties.update.required, []);
assert(Object.hasOwn(shared.properties.update.properties, "platforms"));
assert(original.content.update.platforms.android.enabled);
moveParameter(r, ["domains"], 0);
assert.deepEqual(orderedKeys(r.content, r.schema, r.field_order), [
  "domains",
  "update",
]);
moveParameter(r, ["domains", "2"], 0);
assert.deepEqual(r.content.domains, ["c", "a", "b"]);
const nested = {
  content: {
    rows: [
      { a: 1, b: 2 },
      { a: 3, b: 4 },
    ],
  },
  schema: {
    type: "object",
    properties: {
      rows: {
        type: "array",
        items: {
          type: "object",
          properties: { a: { type: "integer" }, b: { type: "integer" } },
        },
      },
    },
  },
  field_order: { '["rows","0"]': ["b", "a"], '["rows","1"]': ["a", "b"] },
};
moveParameter(nested, ["rows", "0"], 1);
assert.deepEqual(nested.field_order['["rows","1"]'], ["b", "a"]);
deleteParameter(nested, ["rows", "0"]);
assert.deepEqual(nested.field_order['["rows","0"]'], ["b", "a"]);
assert.equal(nested.content.rows[0].a, 1);
console.log(
  "PASS: permanent schema deletion, required constraints, isolated templates, field ordering, array moves and nested order remapping",
);
const { orderedContent } = await import("../assets/config-edit.js");
const source = {
  z: 1,
  a: { first: 1, second: 2 },
  list: [
    { x: 1, y: 2 },
    { x: 3, y: 4 },
  ],
};
const ordered = orderedContent(source, {
  "[]": ["a", "z", "list"],
  '["a"]': ["second", "first"],
  '["list","0"]': ["y", "x"],
});
assert.deepEqual(Object.keys(ordered), ["a", "z", "list"]);
assert.deepEqual(Object.keys(ordered.a), ["second", "first"]);
assert.deepEqual(Object.keys(ordered.list[0]), ["y", "x"]);
assert.equal(ordered.list[1].x, 3);
assert.deepEqual(Object.keys(source), ["z", "a", "list"]);
const legacyOrder = {
  content: { a: 1, b: 2 },
  schema: { type: "object", properties: {} },
  field_order: [],
};
moveParameter(legacyOrder, ["b"], 0);
assert(!Array.isArray(legacyOrder.field_order));
assert.deepEqual(JSON.parse(JSON.stringify(legacyOrder.field_order))["[]"], [
  "b",
  "a",
]);
assert.deepEqual(Object.keys(orderedContent({ z: 1, a: 2 })), ["z", "a"]);
