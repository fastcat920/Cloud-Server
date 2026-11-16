import assert from "node:assert/strict";
import { dnsPrefix, dnsFullName } from "../assets/dns.js";
for (const prefix of ["@", "www", "*.api", "_acme-challenge", "api.test"]) {
  const full = dnsFullName(prefix, "example.com");
  assert.equal(dnsPrefix(full, "example.com"), prefix);
}
assert.equal(dnsPrefix("WWW.Example.Com.", "example.com"), "WWW");
assert.equal(dnsFullName("www.example.com", "example.com"), "www.example.com");
assert.equal(dnsFullName("@", "example.com"), "example.com");
assert.throws(() => dnsPrefix("www.other.com", "example.com"));
assert.throws(() => dnsFullName("bad/name", "example.com"));
assert.throws(() => dnsFullName("api..test", "example.com"));
assert.throws(() => dnsFullName("www", ""));
console.log(
  "PASS: DNS apex, nested/wildcard/service prefixes, suffix boundaries and pinned-domain composition",
);
