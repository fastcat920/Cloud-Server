const clean = (value) =>
  String(value ?? "")
    .trim()
    .replace(/\.$/, "");
export function dnsPrefix(name, root) {
  name = clean(name);
  root = clean(root);
  if (name.toLowerCase() === root.toLowerCase()) return "@";
  if (!name.toLowerCase().endsWith("." + root.toLowerCase()))
    throw new Error("解析记录不属于当前域名，请刷新后重试");
  return name.slice(0, -root.length - 1);
}
export function dnsFullName(prefix, root) {
  root = clean(root);
  prefix = clean(prefix);
  if (!root) throw new Error("请先选择域名");
  if (
    prefix.toLowerCase() === root.toLowerCase() ||
    prefix.toLowerCase().endsWith("." + root.toLowerCase())
  )
    prefix = dnsPrefix(prefix, root);
  if (!prefix || prefix === "@") return root;
  if (!/^[\p{L}\p{N}_*-]+(?:\.[\p{L}\p{N}_*-]+)*$/u.test(prefix))
    throw new Error("请填写有效的主机记录前缀，例如 @、www 或 api.test");
  const full = prefix + "." + root;
  if (full.length > 253) throw new Error("完整域名不能超过 253 个字符");
  return full;
}
