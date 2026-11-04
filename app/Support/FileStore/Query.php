<?php
namespace App\Support\FileStore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
class Query
{
    private $class; private $filters = []; private $orders = []; private $limit; private $relations = [];
    public function __construct($class) { $this->class = $class; }
    public function where($key, $operator = null, $value = null)
    {
        if (is_array($key)) { foreach ($key as $k => $v) $this->where($k, $v); return $this; }
        if (func_num_args() === 2) { $value = $operator; $operator = '='; }
        $this->filters[] = function ($row) use ($key, $operator, $value) {
            $actual = $row[$key] ?? null;
            switch ($operator) { case '=': return $actual == $value; case '<>': case '!=': return $actual != $value; default: throw new \LogicException('不支持的查询条件'); }
        }; return $this;
    }
    public function whereIn($key, $values) { $this->filters[] = function ($row) use ($key, $values) { return in_array($row[$key] ?? null, $values); }; return $this; }
    public function orderBy($key, $direction = 'asc') { $this->orders[] = [$key, strtolower($direction) === 'desc' ? -1 : 1]; return $this; }
    public function orderByDesc($key) { return $this->orderBy($key, 'desc'); }
    public function limit($limit) { $this->limit = $limit; return $this; }
    public function select($columns) { return $this; }
    public function with($relations) { $this->relations = (array)$relations; return $this; }
    public function get($columns = ['*'])
    {
        $class = $this->class; $prototype = new $class;
        $rows = array_values(array_filter(Store::rows($prototype->getTable()), function ($row) { foreach ($this->filters as $filter) if (!$filter($row)) return false; return true; }));
        usort($rows, function ($a, $b) { foreach ($this->orders as [$key, $direction]) { $cmp = ($a[$key] ?? null) <=> ($b[$key] ?? null); if ($cmp) return $cmp * $direction; } return 0; });
        if ($this->limit !== null) $rows = array_slice($rows, 0, $this->limit);
        return new Collection(array_map(function ($attributes) use ($class) {
            $model = new $class; $model->setRawAttributes($attributes, true); $model->exists = true;
            foreach ($this->relations as $relation) {
                [$name, $fields] = array_pad(explode(':', $relation, 2), 2, null);
                $related = $model->$name;
                if ($related && $fields) $related->setVisible(explode(',', $fields));
                $model->setRelation($name, $related);
            }
            return $model;
        }, $rows));
    }
    public function first() { return $this->limit(1)->get()->first(); }
    public function find($id) { return $this->where('id', $id)->first(); }
    public function findOrFail($id) { $row = $this->find($id); if (!$row) throw (new ModelNotFoundException)->setModel($this->class, [$id]); return $row; }
    public function exists() { return $this->first() !== null; }
    public function max($key) { return $this->get()->max($key); }
    public function count() { return $this->get()->count(); }
    public function create(array $attributes) { $class = $this->class; $row = new $class($attributes); $row->save(); return $row; }
    public function firstOrNew(array $attributes, array $values = []) { return $this->where($attributes)->first() ?: new $this->class(array_merge($attributes, $values)); }
    public function firstOrCreate(array $attributes, array $values = []) { return Store::transaction(function () use ($attributes, $values) { $row = $this->firstOrNew($attributes, $values); if (!$row->exists) $row->save(); return $row; }); }
    public function updateOrCreate(array $attributes, array $values = []) { return Store::transaction(function () use ($attributes, $values) { $row = $this->firstOrNew($attributes); $row->fill($values); $row->save(); return $row; }); }
    public function update(array $attributes) { return Store::transaction(function () use ($attributes) { $rows = $this->get(); foreach ($rows as $row) { $row->fill($attributes); $row->save(); } return $rows->count(); }); }
    public function delete() { return Store::transaction(function () { $rows = $this->get(); foreach ($rows as $row) $row->delete(); return $rows->count(); }); }
}
