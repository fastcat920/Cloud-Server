<?php
namespace App\Support\FileStore;

// Reuse Laravel attribute casts and serialization without opening a DB connection.
class Model extends \Illuminate\Database\Eloquent\Model
{
    protected function isGuardableColumn($key) { return true; }
    public function newQuery() { return new Query(static::class); }
    public function save(array $options = [])
    {
        $this->created_at = $this->created_at ?: time();
        $this->updated_at = time();
        $this->setRawAttributes(Store::put($this->getTable(), $this->getAttributes()), true);
        $this->exists = true;
        return true;
    }
    public function delete() { return Store::remove($this->getTable(), $this->id); }
    public function refresh() { $fresh = static::findOrFail($this->id); $this->setRawAttributes($fresh->getAttributes(), true); $this->unsetRelations(); return $this; }
    public function fresh($with = []) { return static::with($with)->find($this->id); }
    public function hasMany($related, $foreignKey = null, $localKey = null) { return $related::where($foreignKey, $this->{$localKey ?: 'id'}); }
    public function belongsTo($related, $foreignKey = null, $ownerKey = null, $relation = null) { return $related::where($ownerKey ?: 'id', $this->{$foreignKey}); }
    public function getRelationValue($key)
    {
        if ($this->relationLoaded($key)) return $this->relations[$key];
        if (in_array($key, ['config', 'target'], true) && method_exists($this, $key)) {
            $value = $this->$key()->first(); $this->setRelation($key, $value); return $value;
        }
        return parent::getRelationValue($key);
    }
}
