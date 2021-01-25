<?php

namespace App\Services;

use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;


abstract class BaseService
{
    const TIME_STAMP = ['created_at', 'updated_at', 'deleted_at'];

    /** @var $model Model */
    protected $model;

    /** @var Builder $query */
    protected $query;

    public function __construct()
    {
        $this->setModel();
        $this->setQuery();
    }

    abstract protected function setModel();

    protected function setQuery()
    {
        $this->query = $this->model->query();
        return $this;
    }

    public function findAll($columns = ['*'])
    {
        return $this->model->query()->get(is_array($columns) ? $columns : func_get_args());
    }

    /**
     * Retrieve the specified resource.
     *
     * @param int $id
     * @param array $relations
     * @param array $appends
     * @return Model
     */
    public function show($id, array $relations = [], array $appends = [], array $hiddens = [], $withTrashed = false)
    {
        $this->setQuery();
        if ($withTrashed) {
            $this->query->withTrashed();
        }
        return $this->query->with($relations)->findOrFail($id)->setAppends($appends)->makeHidden($hiddens);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param array $attributes
     * @return Model|bool
     */
    public function store(array $attributes)
    {
        $parent = $this->model->query()->create($attributes);

        foreach (array_filter($attributes, 'is_array') as $key => $models) {
            if (method_exists($parent, $relation = Str::camel($key))) {

                $models = $parent->$relation() instanceof HasOne ? [$models] : $models;

                foreach (array_filter($models) as $model) {
                    $parent->setRelation($key, $parent->$relation()->make($model));
                }

            }
        }

        return $parent->push() ? $parent : false;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param array $attributes
     * @return Model|bool
     *
     * @throws ModelNotFoundException
     */
    public function update($id, array $attributes)
    {
        $parent = $this->model->query()->findOrFail($id)->fill($attributes);

        foreach (array_filter($attributes, 'is_array') as $key => $models) {
            if (method_exists($parent, $relation = Str::camel($key))) {

                $models = $parent->$relation() instanceof HasOne ? [$models] : $models;

                foreach (array_filter($models) as $model) {
                    /** @var Model $relationModel */

                    if (isset($model['id'])) {
                        $relationModel = $parent->$relation()->findOrFail($model['id']);
                    } else {
                        $relationModel = $parent->$relation()->make($model);
                    }
                    $parent->setRelation($key, $relationModel->fill($model));
                }

            }
        }

        return $parent->push() ? $parent : false;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return bool
     *
     * @throws ModelNotFoundException|Exception
     */
    public function destroy($id)
    {
        return $this->model->query()->findOrFail($id)->delete();
    }

    public function restore($id)
    {
        return $this->model->query()->withTrashed()->findOrFail($id)->restore();
    }

    /**
     * @param array $attrs
     * @return Builder|Model|null|object
     */
    public function findBy(array $attrs, $relations = [], $withTrashed = false)
    {
        $this->setQuery();
        if ($relations && count($relations)) {
            $this->query->with($relations);
        }
        if ($withTrashed) {
            $this->query->withTrashed();
        }
        return $this->query->where($attrs)->first();
    }

    /**
     * @param array $attrs
     * @param array $relations
     * @param false $withTrashed
     * @param string[] $columns
     * @return Builder[]|\Illuminate\Database\Eloquent\Collection
     */
    public function getList(array $attrs, $relations = [], $withTrashed = false, $columns = ['*'])
    {
        $this->setQuery();
        if ($relations && count($relations)) {
            $this->query->with($relations);
        }
        if ($withTrashed) {
            $this->query->withTrashed();
        }
        return $this->query->where($attrs)->get($columns);
    }

    public function firstOrCreate(array $attributes, array $values = [])
    {
        return $this->model->query()->firstOrCreate($attributes, $values);
    }

    public function updateOrCreate(array $attributes, array $values = [])
    {
        return $this->model->query()->updateOrCreate($attributes, $values);
    }

    /**
     * @param $params
     * @param array $relations
     * @param bool $withTrashed
     * @return LengthAwarePaginator
     */
    public function buildBasicQuery($params, array $relations = [], $withTrashed = false)
    {
        $params = $params ?: request()->toArray();
        if ($relations && count($relations)) {
            $this->query->with($relations);
        }
        if ($withTrashed) {
            $this->query->withTrashed();
        }
        if (method_exists($this, 'addFilter')) {
            $this->addFilter();
        }
        $this->addDefaultFilter($params);
        $data = $this->query->paginate(isset($params['limit']) ? $params['limit'] : 20);
        return $data;
    }

    /**
     * @param $params
     * @param array $relations
     * @param false $withTrashed
     * @return Builder[]|\Illuminate\Database\Eloquent\Collection
     */
    public function buildBasicQueryWithoutPaginate($params, array $relations = [], $withTrashed = false)
    {
        $params = $params ?: request()->toArray();
        if ($relations && count($relations)) {
            $this->query->with($relations);
        }
        if ($withTrashed) {
            $this->query->withTrashed();
        }
        if (method_exists($this, 'addFilter')) {
            $this->addFilter();
        }
        $this->addDefaultFilter($params);
        return $this->query->get();
    }

    /**
     * @param $params
     */
    protected function addDefaultFilter($params)
    {
        $params = $params ?: request()->toArray();
        if (isset($params['filter']) && $params['filter']) {
            $filters = json_decode($params['filter'], true);
            foreach ($filters as $key => $filter) {
                $this->basicFilter($this->query, $key, $filter);
            }
        }
        if (isset($params['sort']) && $params['sort']) {
            $sort = explode('|', $params['sort']);
            if ($sort && count($sort) == 2) {
                $this->query->orderBy($sort[0], $sort[1]);
            }
        }
    }

    /**
     * @param Builder $query
     * @param $key
     * @param $filter
     */
    protected function basicFilter(Builder $query, $key, $filter)
    {
        if (is_array($filter)) {
            if ($key == 'equal') {
                foreach ($filter as $index => $value) {
                    if ($this->checkParamFilter($value)) {
                        $query->where($index, $value);
                    }
                }
            } else if ($key == 'not_equal') {
                foreach ($filter as $index => $value) {
                    if ($this->checkParamFilter($value)) {
                        $query->where($index, '!=', $value);
                    }
                }
            } else if ($key == 'like') {
                foreach ($filter as $index => $value) {
                    if ($this->checkParamFilter($value)) {
                        $query->where($index, 'LIKE', '%' . $value . '%');
                    }
                }
            } else if ($key == 'less') {
                foreach ($filter as $index => $value) {
                    if ($this->checkParamFilter($value)) {
                        $query->where($index, '<=', $value);
                    }
                }
            } else if ($key == 'greater') {
                foreach ($filter as $index => $value) {
                    if ($this->checkParamFilter($value)) {
                        $query->where($index, '>=', $value);
                    }
                }
            } else if ($key == 'range') {
                foreach ($filter as $index => $value) {
                    if ($this->checkParamFilter($value)) {
                        if (is_array($value) && count($value) == 2 && in_array($index, self::TIME_STAMP)) {
                            $query->whereBetween($index, $value);
                        }
                    }
                }
            } else if ($key == 'within') {
                foreach ($filter as $index => $value) {
                    if (is_array($value)) {
                        $query->whereIn($index, $value);
                    }
                }
            } else if ($key == 'or') {
                $query->where(function(Builder $builder) use ($filter){
                    foreach ($filter as $index => $value) {
                        if (is_array($value)) {
                            foreach ($value as $key => $val) {
                                $builder->orWhere(function (Builder $q) use ($index, $key, $val) {
                                    if ($this->checkParamFilter($val)) {
                                        $this->basicFilter($q, $index, [$key => $val]);
                                    }
                                });
                            }
                        }
                    }
                });
            } else if ($key == 'relation') {
                foreach ($filter as $relation => $relationFilters) {
                    if (is_array($relationFilters) && count($relationFilters)) {
                        foreach ($relationFilters as $index => $value) {
                            if ($value && count($value) && $this->checkRelationFilter($index, $value)) {
                                $query->whereHas($relation, function ($builder) use ($index, $value) {
                                    $this->basicFilter($builder, $index, $value);
                                });
                            }
                        }
                    }
                }
            } else {
                if (count($filter)) {
                    $query->whereIn($key, $filter);
                }
            }
        } else {
            $query->where($key, 'LIKE', '%' . $filter . '%');
        }
    }

    /**
     * @param $value
     * @return bool
     */
    protected function checkParamFilter($value)
    {
        return !in_array($value, ['', null]) || $value === 0;
    }

    /**
     * @param $index
     * @param $value
     * @return bool
     */
    protected function checkRelationFilter($index, $value)
    {
        $check = false;
        foreach ($value as $key => $val) {
            if ($check) {
                break;
            }
            if ($index == 'relation') {
                $check = $this->checkRelationFilter($key, $val);
            } elseif (is_array($val) && count($val)) {
                foreach ($val as $k => $v) {
                    if ($this->checkParamFilter($v)) {
                        $check = true;
                        break;
                    }
                }
            } else {
                $check = $this->checkParamFilter($val);
            }
        }
        return $check;
    }

    /**
     * @param $ids
     * @param $attributes
     */
    public function multiUpdate($ids, $attributes)
    {
        return $this->model->query()->whereIn('id', $ids)->update($attributes);
    }
}
