<?php namespace Common\Repositories;

use Carbon\Carbon;
use MongoDB\BSON\ObjectID;
use Common\Models\BaseModel;
use MongoDB\BSON\UTCDatetime;
use Illuminate\Support\Collection;
use Jenssegers\Mongodb\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

abstract class DBBaseRepository implements BaseRepositoryInterface
{

    /**
     * @return \MongoDB\Database
     */
    public static function getDatabase()
    {
        return \DB::getMongoDB();
    }

    /**
     * @param array $filterBy
     * @param array $orderBy
     * @param array $columns
     *
     * @return Collection
     */
    public function getAll(array $filterBy = [], array $orderBy = [], array $columns = ['*'])
    {
        return $this->applyFilterByAndOrderBy($filterBy, $orderBy)->get($columns);
    }

    /**
     * @param array $filterBy
     * @param array $orderBy
     * @param array $columns
     * @return bool
     */
    public function exists(array $filterBy = [], array $orderBy = [], array $columns = ['*'])
    {
        $query = $this->applyFilterByAndOrderBy($filterBy, $orderBy);
        /** @type BaseModel $ret */
        return $query->exists();
    }

    /**
     * @param array $filterBy
     * @param array $orderBy
     * @param array $columns
     * @return BaseModel|null
     */
    public function getOne(array $filterBy = [], array $orderBy = [], array $columns = ['*'])
    {
        $query = $this->applyFilterByAndOrderBy($filterBy, $orderBy);
        /** @type BaseModel $ret */
        $ret = $query->first($columns);

        return $ret;
    }

    /**
     * @param array $filterBy
     * @param array $orderBy
     * @return int
     */
    public function count(array $filterBy = [], array $orderBy = [])
    {
        return $this->applyFilterByAndOrderBy($filterBy, $orderBy)->count();
    }

    /**
     * @param int   $page
     * @param array $filterBy
     * @param array $orderBy
     * @param int   $perPage
     * @return LengthAwarePaginator
     */
    public function paginate($page, array $filterBy, array $orderBy, $perPage)
    {
        $query = $this->applyFilterByAndOrderBy($filterBy, $orderBy);

        return $query->paginate((int)$perPage, ['*'], 'page', (int)$page);
    }

    /**
     * @param string|ObjectID $id
     * @return BaseModel
     */
    public function findById($id)
    {
        $filter = [['operator' => '=', 'key' => '_id', 'value' => $id]];

        return $this->getOne($filter);
    }

    /**
     * @param array $ids
     * @return Collection
     */
    public function findByIds($ids)
    {
        $filter = [['operator' => 'in', 'key' => '_id', 'value' => $ids]];

        return $this->getOne($filter);
    }

    /**
     * @param $id
     *
     * @return BaseModel
     */
    public function findByIdOrFail($id)
    {
        $filter = [['operator' => '=', 'key' => '_id', 'value' => $id]];

        if (! is_null($result = $this->getOne($filter))) {
            return $result;
        }

        throw (new ModelNotFoundException)->setModel($this->model(), $id);
    }

    /**
     * @param array $data
     *
     * @return BaseModel
     */
    public function create(array $data)
    {
        /** @type string|BaseModel $model */
        $model = $this->model();

        return $model::create($data);
    }

    /**
     * @param array $data
     *
     * @return bool
     */
    public function bulkCreate(array $data)
    {
        $now = new UTCDateTime(round(microtime(true) * 1000));

        $data = array_map(function ($item) {
            return $this->normalizeCarbonDates($item);
        }, $data);

        foreach (array_keys($data) as $i) {
            $data[$i]['created_at'] = array_get($data[$i], 'created_at', $now);
        }

        /** @type string|BaseModel $model */
        $model = $this->model();

        return $model::insert($data);
    }

    /**
     * Update a model.
     *
     * @param BaseModel $model
     * @param array     $data
     *
     * @return bool
     */
    public function update($model, array $data)
    {
        $class = $this->model();

        if (starts_with(key($data), '$')) {

            if (array_get($data, '$set', [])) {
                $data['$set'] = $this->normalizeCarbonDates($data['$set']);
                $model->fill($data['$set']);
            }

            return $class::where('_id', $model->_id)->getQuery()->update($data);
        }

        $model->fill($data);

        $data = $this->normalizeCarbonDates($data);

        return $class::where('_id', $model->_id)->update($data);
    }

    /**
     * Delete a model
     *
     * @param BaseModel $model
     */
    public function delete($model)
    {
        $model->delete();
    }

    /**
     * @param array $ids
     */
    public function bulkDelete(array $ids)
    {
        $model = $this->model();
        $model::whereIn('_id', $ids)->delete();
    }

    /**
     * @param array   $filter with the following keys: type, attribute and value
     * @param Builder $query
     *
     * @return Builder
     */
    protected function applyQueryFilter($query, array $filter)
    {
        switch ($filter['operator']) {
            case 'prefix':
                $query->where($filter['key'], 'regexp', "/^{$filter['value']}.*?/i");
                break;

            case 'contains':
                $query->where($filter['key'], 'regexp', "/.*?{$filter['value']}.*?/i");
                break;

            case 'date':
                $query->date($filter['key'], $filter['value']);
                break;

            case 'in':
                $query->whereIn($filter['key'], $filter['value']);
                break;

            default:
                $query->where($filter['key'], $filter['operator'], $filter['value']);
        }

        return $query;
    }

    /**
     * @param array   $orderBy
     * @param Builder $query
     */
    protected function applyOrderBy(Builder $query, array $orderBy)
    {
        foreach ($orderBy as $column => $direction) {
            $query->orderBy($column, $direction);
        }
    }

    /**
     * @param array $filterBy
     * @param array $orderBy
     *
     * @return Builder
     */
    protected function applyFilterByAndOrderBy(array $filterBy = [], array $orderBy = [])
    {
        /** @type string|BaseModel $model */
        $model = $this->model();

        $query = $model::query();

        foreach ($filterBy as $filter) {
            $this->applyQueryFilter($query, $filter);
        }

        $this->applyOrderBy($query, $orderBy);

        return $query;
    }

    /**
     * @param array $data
     * @return array
     */
    protected function normalizeCarbonDates(array $data)
    {
        foreach ($data as $key => &$value) {
            if (is_a($value, Carbon::class)) {
                $value = mongo_date($value);
            }
        }

        return $data;
    }
}
