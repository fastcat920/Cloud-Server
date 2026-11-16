import assert from "node:assert/strict";
import { normalizeGtm, rows, gtmName, moveGtmRow, initial } from "../assets/gtm.js";
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

const { gtmChanges, gtmStatus } = await import('../assets/gtm.js');
assert.deepEqual(gtmChanges({ AddressId:'a', Name:'same', Remark:'old' }, {AddressId:'a',Name:'same',Remark:'new'}), [{path:'Remark',before:'old',after:'new'}]);
const prior = { Addresses:{Address:[{AddressId:'a',SerialNumber:1,WeightValue:2,HealthStatus:'ok'},{AddressId:'b',SerialNumber:2,WeightValue:3}]} };
const changed = gtmChanges(prior,{Addresses:[{AddressId:'b',SerialNumber:1,WeightValue:3},{AddressId:'a',SerialNumber:2,WeightValue:2}]});
assert.deepEqual(changed.map(v=>v.path), ['Addresses[b].SerialNumber','Addresses[a].SerialNumber']);
assert.deepEqual(gtmChanges(prior,{Addresses:[{AddressId:'a',SerialNumber:1,WeightValue:2}]}).map(v=>v.path),['Addresses[b]（移除）']);
assert.deepEqual(gtmChanges(prior,{Addresses:[{AddressId:'a',SerialNumber:1,WeightValue:2},{AddressId:'b',SerialNumber:2,WeightValue:3},{AddressId:'c',SerialNumber:3}]}).map(v=>v.path),['Addresses[c]（新增）']);
assert.deepEqual(gtmChanges({ExtendInfo:'{"path":"/","code":500,"host":"old"}'},{ExtendInfo:'{"path":"/ready","code":500}'}).map(v=>v.path),['ExtendInfo.path','ExtendInfo.host']);
assert.deepEqual(gtmChanges({Ttl:'60'},{Ttl:60},{Ttl:{type:'integer'}}),[]);
assert.equal(gtmStatus({HealthStatus:'ok_alert',AvailableStatus:'available'}),'warn');
assert.equal(gtmStatus({HealthStatus:'exceptional',AvailableStatus:'available'}),'bad');
assert.equal(gtmStatus({HealthStatus:'ok'}),'good');
assert.equal(gtmStatus({EnableStatus:'enable'}),'unknown');
console.log('PASS: GTM changed-only previews, association identity/order, extension removals and health colors');
assert.deepEqual(gtmChanges({IspCityNodes:[{CityCode:'1',IspCode:'a'},{CityCode:'1',IspCode:'b'}]}, {IspCityNodes:[{CityCode:'1',IspCode:'b'},{CityCode:'2',IspCode:'a'}]}).map(v=>v.path),['IspCityNodes[2/a]（新增）','IspCityNodes[1/a]（移除）']);
assert.deepEqual(gtmChanges({HealthTasks:[{TemplateId:'t',Port:443},{TemplateId:'t',Port:8443}]}, {HealthTasks:[{TemplateId:'t',Port:443},{TemplateId:'t',Port:9443}]}).map(v=>v.path),['HealthTasks[t:9443]（新增）','HealthTasks[t:8443]（移除）']);
assert.deepEqual(gtmChanges({HealthTasks:[{TemplateId:'t',Port:443}]}, {HealthTasks:[{TemplateId:'t'}]}, {HealthTasks:{type:'array',items:{type:'object',properties:{TemplateId:{type:'string'},Port:{type:'integer'}}}}}).map(v=>v.path),['HealthTasks[t].Port']);

const { gtmStateBadge } = await import('../assets/gtm.js');
for (const [row, label] of [
  [{EnableStatus:'enable',HealthStatus:'ok',AvailableStatus:'available'},'正常'],
  [{EnableStatus:'enable',HealthStatus:'ok_alert',AvailableStatus:'available'},'警告'],
  [{EnableStatus:'enable',HealthStatus:'exceptional',AvailableStatus:'available'},'异常'],
  [{EnableStatus:'enable',HealthStatus:'exceptional',AvailableStatus:'unavailable'},'异常'],
  [{EnableStatus:'disable',HealthStatus:'ok',AvailableStatus:'available'},'正常'],
  [{HealthStatus:'ok_no_monitor'},'正常（无探测）'],
  [{},'未知'],
]) {
  const html = gtmStateBadge(row);
  assert.equal((html.match(/<span/g) || []).length, 1, 'Exactly one state badge');
  assert.equal(html.replace(/<[^>]*>/g,''), label);
}
assert.equal(gtmStatus({HealthStatus:'ok',AvailableStatus:'unavailable',EnableStatus:'disable'}),'good');
assert.equal(gtmStatus({AvailableStatus:'available'}),'unknown');
assert.equal(gtmStatus({HealthStatus:'ok_alert',AvailableStatus:'unavailable'}),'warn');
console.log('PASS: automatic-mode colors follow health and ignore stale manual values');

const { gtmUpdateParameters } = await import('../assets/gtm.js');
const editFields = { AddressId:{type:'string',required:true}, Name:{type:'string'}, HealthTasks:{type:'array',items:{type:'object',properties:{TemplateId:{type:'string'},Port:{type:'integer'}}}} };
const editBefore = {AddressId:'a',Name:'old',HealthTasks:[{TemplateId:'t',Port:443}]};
assert.deepEqual(gtmUpdateParameters(editFields,editBefore,{...editBefore,Name:'new'}),{AddressId:'a',Name:'new'});
assert.deepEqual(gtmUpdateParameters(editFields,editBefore,{...editBefore,HealthTasks:[]}),{AddressId:'a',HealthTasks:[]});
assert.deepEqual(gtmUpdateParameters({InstanceId:{type:'string'},ConfigId:{type:'string',required:true},Ttl:{type:'integer'}},{InstanceId:'i',ConfigId:'c',Ttl:60},{InstanceId:'i',ConfigId:'c',Ttl:90}),{InstanceId:'i',ConfigId:'c',Ttl:90});
console.log('PASS: update payloads include only changed fields, required identities and explicit association clears');

assert.deepEqual(gtmUpdateParameters({AddressId:{type:'string',required:true},AvailableMode:{type:'string'},ManualAvailableStatus:{type:'string'}},{AddressId:'a',AvailableMode:'auto',ManualAvailableStatus:'available'},{AddressId:'a',AvailableMode:'manual',ManualAvailableStatus:'available'}),{AddressId:'a',AvailableMode:'manual',ManualAvailableStatus:'available'});
assert.deepEqual(gtmUpdateParameters({ConfigId:{type:'string',required:true},AddressPoolLbStrategy:{type:'string'},SequenceLbStrategyMode:{type:'string'}},{ConfigId:'c',AddressPoolLbStrategy:'weight',SequenceLbStrategyMode:'preemptive'},{ConfigId:'c',AddressPoolLbStrategy:'sequence',SequenceLbStrategyMode:'preemptive'}),{ConfigId:'c',AddressPoolLbStrategy:'sequence',SequenceLbStrategyMode:'preemptive'});

const {gtmProbeNodes,gtmProbeKey}=await import('../assets/gtm.js');
const probeCatalog={Ipv4IspCityNodes:{Ipv4IspCityNode:[{GroupName:'BGP',CityCode:'1',CityName:'上海',IspCode:'a',IspName:'阿里云'},{GroupName:'BGP',CityCode:'1',CityName:'上海',IspCode:'b',IspName:'电信'},{GroupName:'BGP',CityCode:'1',IspCode:'a'}]},Ipv6IspCityNodes:{Ipv6IspCityNode:[{CityCode:2,IspCode:3,CityName:'北京',IspName:'联通'}]}};
assert.equal(gtmProbeNodes(probeCatalog).length,2);
assert.equal(gtmProbeNodes(probeCatalog,'IPv6').length,1);
assert.equal(gtmProbeKey({CityCode:'2',IspCode:'3'}),gtmProbeKey(gtmProbeNodes(probeCatalog,'IPv6')[0]));
assert.equal(probeCatalog.Ipv4IspCityNodes.Ipv4IspCityNode.length,3,'Catalog input must stay immutable');
assert.deepEqual(gtmProbeNodes(undefined),[]);
console.log('PASS: probe node identity, IP-family isolation, duplicate elimination and immutable catalogs');

const { gtmFieldPath } = await import('../assets/gtm.js');
assert.equal(gtmFieldPath('Address'),'服务地址');
assert.equal(gtmFieldPath('HealthTasks[mtp-123].Port',{},()=> 'HTTPS 检查'),'关联探测模板 / HTTPS 检查 / 探测端口');
assert.equal(gtmFieldPath('AddressPools[pool-1]（新增）',{},()=> 'example.com'),'关联地址池 / example.com（新增）');
assert.deepEqual(gtmUpdateParameters({AddressId:{type:'string',required:true},HealthTasks:editFields.HealthTasks,HealthJudgement:{type:'string'}},{AddressId:'a',HealthTasks:[],HealthJudgement:'any_ok'},{AddressId:'a',HealthTasks:[{TemplateId:'t',Port:30001}],HealthJudgement:'any_ok'}),{AddressId:'a',HealthTasks:[{TemplateId:'t',Port:30001}],HealthJudgement:'any_ok'});
const {errorDetails}=await import('../assets/ui.js');
assert(errorDetails({original_message:'<img src=x onerror=alert(1)>'}).includes('&lt;img'));
assert(!errorDetails({original_message:'<img src=x>'}).includes('<img'));
assert(!errorDetails({code:'InvalidParameter'}).includes('<details open'));
console.log('PASS: health-check dependent fields, Chinese preview paths and escaped collapsed diagnostics');

const explicitFields = {
  AvailableMode: { type: "string", required: true, enum: ["auto", "manual"] },
  HealthJudgement: { type: "string", enum: ["any_ok", "all_ok"] },
  ManualAvailableStatus: { type: "string", enum: ["available", "unavailable"] },
};
assert.deepEqual(initial(explicitFields, {}, true), { AvailableMode: "auto", HealthJudgement: "any_ok", ManualAvailableStatus: "available" });
assert.deepEqual(initial(explicitFields, {}, false), { AvailableMode: "auto", HealthJudgement: "any_ok", ManualAvailableStatus: "available" });
assert.deepEqual(
  initial(
    explicitFields,
    {
      AvailableMode: "manual",
      HealthJudgement: "all_ok",
      ManualAvailableStatus: "unavailable",
    },
    false,
  ),
  {
    AvailableMode: "manual",
    HealthJudgement: "all_ok",
    ManualAvailableStatus: "unavailable",
  },
);

assert.equal(initial(explicitFields, {AvailableMode: null, HealthJudgement: ""}, false).HealthJudgement, "any_ok");

const {gtmEdgeInactive} = await import('../assets/gtm.js');
assert.equal(gtmStatus({AvailableMode:'manual', ManualAvailableStatus:'unavailable',HealthStatus:'ok'}),'bad');
assert.equal(gtmStatus({AvailableMode:'manual', ManualAvailableStatus:'available',HealthStatus:'exceptional'}),'good');
assert.equal(gtmStatus({AvailableMode:'auto', ManualAvailableStatus:'unavailable',HealthStatus:'ok'}),'good');
assert.equal(gtmEdgeInactive({EnableStatus:'disable',HealthStatus:'ok'}),true);
assert.equal(gtmEdgeInactive({HealthStatus:'exceptional'}),true);
assert.equal(gtmEdgeInactive({HealthStatus:'ok_alert'}),false);
assert.equal(gtmEdgeInactive({AvailableMode:'manual',ManualAvailableStatus:'unavailable',HealthStatus:'ok'}),true);
assert.equal(initial(explicitFields,{AvailableMode:'unknown'},false).AvailableMode,'auto');

assert.equal(gtmStatus({HealthStatus:'ok_no_monitor'}),'good');
assert.equal(gtmStatus({HealthStatus:'ok_no_monitor',AvailableMode:'manual',ManualAvailableStatus:'unavailable'}),'bad');
assert.equal(gtmStateBadge({HealthStatus:'ok_no_monitor',AvailableMode:'manual',ManualAvailableStatus:'unavailable'}).replace(/<[^>]*>/g,''),'异常');

assert.deepEqual(gtmUpdateParameters({AddressId:{type:'string',required:true},Address:{type:'string'},HealthTasks:{type:'array'},HealthJudgement:{type:'string'}},{AddressId:'a',Address:'143.20.71.28',HealthTasks:[{TemplateId:'t',Port:30001}],HealthJudgement:'any_ok'},{AddressId:'a',Address:'200.180.161.87',HealthTasks:[{TemplateId:'t',Port:30001}],HealthJudgement:'any_ok'}),{AddressId:'a',Address:'200.180.161.87',HealthJudgement:'any_ok'});
