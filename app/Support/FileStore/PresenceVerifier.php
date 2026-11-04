<?php
namespace App\Support\FileStore;
class PresenceVerifier implements \Illuminate\Validation\PresenceVerifierInterface
{
    public function getCount($collection, $column, $value, $excludeId = null, $idColumn = null, $extra = [])
    {
        return count(array_filter(Store::rows($collection), function ($row) use ($column, $value, $excludeId, $idColumn, $extra) {
            if (($row[$column] ?? null) != $value) return false;
            if ($excludeId !== null && ($row[$idColumn ?: 'id'] ?? null) == $excludeId) return false;
            foreach ($extra as $key => $expected) if (($row[$key] ?? null) != $expected) return false;
            return true;
        }));
    }
    public function getMultiCount($collection, $column, array $values, array $extra = [])
    {
        return count(array_filter(array_unique($values), function ($value) use ($collection, $column, $extra) { return $this->getCount($collection, $column, $value, null, null, $extra) > 0; }));
    }
    public function setConnection($connection) {}
}
