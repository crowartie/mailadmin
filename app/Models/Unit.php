<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Подразделение: дерево отделов, адрес отдела, общий календарь и книга. */
class Unit extends Model
{
    protected $fillable = ['parent_id', 'name', 'address', 'lead', 'calendar_id', 'addressbook_id', 'sort'];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('name');
    }

    public function members()
    {
        return $this->hasMany(EmployeeProfile::class, 'unit_id');
    }

    /** Все подразделения-потомки (включая себя). @return int[] */
    public function subtreeIds(): array
    {
        $ids = [$this->id];
        foreach ($this->children as $c) {
            $ids = array_merge($ids, $c->subtreeIds());
        }

        return $ids;
    }
}
