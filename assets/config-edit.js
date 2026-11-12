// Revision-local editing; shared templates are never mutated.
export function atPath(value, path) {
  for (const key of path) value = value[key];
  return value;
}
export function orderedKeys(value, schema = {}, orders = {}, path = []) {
  const keys = [
    ...new Set([
      ...Object.keys(value),
      ...Object.keys(schema.properties || {}),
    ]),
  ];
  const saved = orders[JSON.stringify(path)] || [];
  const sorted = [
    ...saved.filter((key) => keys.includes(key)),
    ...keys.filter((key) => !saved.includes(key)),
  ];
  return [
    ...sorted.filter((key) => Object.hasOwn(value, key)),
    ...sorted.filter((key) => !Object.hasOwn(value, key)),
  ];
}
export function deleteParameter(revision, path) {
  if (!path.length) throw new Error("不能删除配置根节点");
  const parentPath = path.slice(0, -1),
    key = path.at(-1),
    parent = atPath(revision.content, parentPath);
  let schema = revision.schema;
  for (const part of parentPath) {
    schema =
      schema?.type === "array" ? schema.items : schema?.properties?.[part];
  }
  if (Array.isArray(parent)) {
    parent.splice(Number(key), 1);
    remapOrders(revision, parentPath, (index) =>
      index === Number(key) ? null : index > Number(key) ? index - 1 : index,
    );
  } else {
    delete parent[key];
    if (schema?.properties) delete schema.properties[key];
    if (schema?.required)
      schema.required = schema.required.filter((name) => name !== key);
    for (const [encoded, keys] of Object.entries(revision.field_order || {})) {
      const location = JSON.parse(encoded);
      if (path.every((part, i) => location[i] === part))
        delete revision.field_order[encoded];
      else if (encoded === JSON.stringify(parentPath))
        revision.field_order[encoded] = keys.filter((name) => name !== key);
    }
  }
}
function remapOrders(revision, path, remap) {
  const next = Object.create(null);
  for (const [encoded, keys] of Object.entries(revision.field_order || {})) {
    const location = JSON.parse(encoded);
    if (
      path.every((part, i) => location[i] === part) &&
      location.length > path.length
    ) {
      const index = remap(Number(location[path.length]));
      if (index === null) continue;
      location[path.length] = String(index);
    }
    next[JSON.stringify(location)] = keys;
  }
  revision.field_order = next;
}
export function moveParameter(revision, path, targetIndex) {
  const parentPath = path.slice(0, -1),
    parent = atPath(revision.content, parentPath),
    key = path.at(-1);
  let schema = revision.schema;
  for (const part of parentPath)
    schema =
      schema?.type === "array" ? schema.items : schema?.properties?.[part];
  const keys = Array.isArray(parent)
    ? Object.keys(parent)
    : orderedKeys(parent, schema, revision.field_order, parentPath).filter(
        (key) => Object.hasOwn(parent, key),
      );
  const from = keys.indexOf(key);
  if (
    from < 0 ||
    targetIndex < 0 ||
    targetIndex >= keys.length ||
    from === targetIndex
  )
    return;
  if (Array.isArray(parent)) {
    const indices = parent.map((_, i) => i);
    const [index] = indices.splice(from, 1);
    indices.splice(targetIndex, 0, index);
    const [item] = parent.splice(from, 1);
    parent.splice(targetIndex, 0, item);
    remapOrders(revision, parentPath, (old) => indices.indexOf(old));
  } else {
    keys.splice(from, 1);
    keys.splice(targetIndex, 0, key);
    revision.field_order ??= Object.create(null);
    Object.defineProperty(revision.field_order, JSON.stringify(parentPath), {
      value: keys,
      enumerable: true,
      writable: true,
      configurable: true,
    });
  }
}
