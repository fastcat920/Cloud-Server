import assert from "node:assert/strict";
import { normalizeGtm, rows, gtmName, moveGtmRow } from "../assets/gtm.js";
const cloud = {
  ConfigId: "config-1",
  AddressPools: {
    AddressPool: [
      {
        AddressPoolId: "pool-1",
        RequestSource: { RequestSource: ["default"] },
        Addresses: {
          Address: [
            {
              AddressId: "addr-1",
              HealthTasks: { HealthTask: [{ TemplateId: "mtp-1", Port: 443 }] },
            },
          ],
        },
      },
    ],
  },
};
const normalized = normalizeGtm(cloud);
assert.equal(normalized.AddressPools[0].Addresses[0].HealthTasks[0].Port, 443);
assert.deepEqual(normalized.AddressPools[0].RequestSource, ["default"]);
assert(!Array.isArray(cloud.AddressPools));
assert.equal(normalizeGtm({ ExtendInfo: '{"code":500}' }).ExtendInfo.code, 500);
assert.throws(() => normalizeGtm({ ExtendInfo: "invalid" }));
assert.deepEqual(
  rows({ Addresses: { Address: [] } }, "Addresses", "Address"),
  [],
);
assert.equal(
  gtmName({ Name: "Health monitor", TemplateId: "id" }),
  "Health monitor",
);
console.log(
  "PASS: GTM nested response normalization, immutable input and protocol decoding",
);

const original = [
  { AddressId: "a", SerialNumber: 1, WeightValue: 17, RequestSource: ["cn"] },
  {
    AddressId: "b",
    SerialNumber: 2,
    WeightValue: 83,
    RequestSource: ["default"],
  },
];
const moved = moveGtmRow(original, 1, 0);
assert.deepEqual(
  moved.map((row) => row.AddressId),
  ["b", "a"],
);
assert.deepEqual(
  moved.map((row) => row.SerialNumber),
  [1, 2],
);
assert.equal(moved[1].WeightValue, 17);
assert.deepEqual(moved[1].RequestSource, ["cn"]);
assert.equal(original[0].SerialNumber, 1);
assert.equal(original[0].AddressId, "a");
const sorted = normalizeGtm({
  AddressPools: {
    AddressPool: [
      { AddressPoolId: "a", SerialNumber: 2 },
      { AddressPoolId: "b", SerialNumber: 1 },
    ],
  },
});
assert.deepEqual(
  sorted.AddressPools.map((row) => row.AddressPoolId),
  ["b", "a"],
);

assert.deepEqual(
  moveGtmRow([{ AddressId: "a" }, { AddressId: "b" }], 1, 0).map(
    (row) => row.SerialNumber,
  ),
  [1, 2],
);
