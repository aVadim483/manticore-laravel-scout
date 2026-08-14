<?php

declare(strict_types=1);

namespace avadim\Manticore\Scout;

use avadim\Manticore\Laravel\Manager;
use avadim\Manticore\QueryBuilder\Connection;
use avadim\Manticore\QueryBuilder\Query;
use avadim\Manticore\QueryBuilder\QueryErrorException;
use avadim\Manticore\QueryBuilder\ResultSet;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;

/**
 * Class ManticoreEngine
 *
 * The Scout driver for ManticoreSearch. Reads and writes go through the query builder package,
 * i.e. through the connection of config/manticore.php - this engine only translates what Scout
 * asks for into a query of the builder and maps the answer back onto Eloquent models.
 *
 * The connection is taken from the Manager on every call rather than kept in a property: the
 * builder holds its connections statically, and a re-init would leave a stale object behind.
 *
 * @package avadim\Manticore\Scout
 */
class ManticoreEngine extends Engine
{
    /**
     * Keys of Builder::options() the driver reads itself; the rest becomes the OPTION clause
     */
    const RESERVED_OPTIONS = ['fields', 'escape', 'highlight'];

    /**
     * The number of rows Manticore keeps per query unless told otherwise
     */
    const SERVER_MAX_MATCHES = 1000;

    /**
     * @var \avadim\Manticore\Laravel\Manager
     */
    protected $manager;

    /**
     * The "manticore" section of config/scout.php
     *
     * @var array
     */
    protected $config;

    /**
     * Whether Scout keeps soft deleted models in the index
     *
     * @var bool
     */
    protected $softDelete;

    /**
     * Indexes known to be there, so that a write asks the server about them once
     *
     * @var array
     */
    protected $knownIndexes = [];

    /**
     * @param \avadim\Manticore\Laravel\Manager $manager
     * @param array $config the scout.manticore section
     * @param bool $softDelete the value of scout.soft_delete
     */
    public function __construct(Manager $manager, array $config = [], bool $softDelete = false)
    {
        $this->manager = $manager;
        $this->config = $config;
        $this->softDelete = $softDelete;
    }

    /**
     * The connection this driver works on
     *
     * @return Connection
     */
    public function connection(): Connection
    {
        return $this->manager->connection($this->config('connection'));
    }

    // +++ WRITE +++ //

    /**
     * Write the given models to the index.
     *
     * REPLACE rather than INSERT, so that a model already there is overwritten instead of
     * doubled - Scout sends the whole model on every save.
     *
     * @param \Illuminate\Database\Eloquent\Collection $models
     *
     * @return void
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        if ($this->softDelete && $this->usesSoftDelete($models->first())) {
            $models->each->pushSoftDeleteMetadata();
        }

        $rows = [];
        foreach ($models as $model) {
            $data = $model->toSearchableArray();
            if (empty($data)) {
                // the model asked not to be indexed
                continue;
            }
            $data = array_merge($data, $model->scoutMetadata());
            // the id of the document is the scout key, whatever the searchable array says
            unset($data['id']);

            $rows[] = array_merge(['id' => $this->documentId($model)], $data);
        }

        if (!$rows) {
            return;
        }

        $model = $models->first();
        $index = $model->indexableAs();
        $this->ensureIndex($index, $model, $rows);

        foreach ($this->batches($rows) as $batch) {
            $this->write($index, $model, $batch);
        }
    }

    /**
     * Write one batch of rows, growing the index to fit them.
     *
     * The server names one missing column per answer, so this repeats until every column of the
     * batch is there - a column added to toSearchableArray() otherwise breaks every write until
     * someone alters the table by hand.
     *
     * @param string $index
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param array $batch
     *
     * @return void
     */
    protected function write(string $index, $model, array $batch)
    {
        // one attempt per column of a row, and one for the table itself
        $attempts = count(reset($batch) ?: []) + 1;

        do {
            $result = $this->connection()->table($index)->replaceResultSet($batch);
            if ($result->success()) {
                return;
            }

            if ($this->missingTable($result) && $this->config('auto_create', true)) {
                // the table was dropped after it had been seen, e.g. by scout:flush
                unset($this->knownIndexes[$index]);
                $this->ensureIndex($index, $model, $batch);
                continue;
            }

            $column = $this->missingColumn($result);
            if ($column === null || !$this->config('auto_columns', true)) {
                break;
            }

            $this->assertSuccess($this->connection()->addColumn(
                $index,
                $column,
                $this->columnType($index, $model, $batch, $column)
            ));
        }
        while (--$attempts > 0);

        $this->assertSuccess($result);
    }

    /**
     * Remove the given models from the index.
     *
     * @param \Illuminate\Database\Eloquent\Collection $models
     *
     * @return void
     */
    public function delete($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $model = $models->first();
        // a queued removal keeps the keys alone, the models themselves are gone by then
        $keys = $models instanceof \Laravel\Scout\Jobs\RemoveableScoutCollection
            ? $models->pluck($model->getScoutKeyName())
            : $models->map(function ($model) {
                return $model->getScoutKey();
            });

        $ids = $keys->filter(function ($key) {
                return $this->isDocumentId($key);
            })
            ->map(function ($key) {
                return (int)$key;
            })
            ->values()
            ->all();

        if (!$ids) {
            return;
        }

        $result = $this->connection()->table($model->indexableAs())->whereIn('id', $ids)->deleteResultSet();

        // nothing to remove from an index that is not there
        if (!$this->missingTable($result)) {
            $this->assertSuccess($result);
        }
    }

    /**
     * Remove every row of the model's index.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return void
     */
    public function flush($model)
    {
        $result = $this->connection()->table($model->indexableAs())->truncate();

        if (!$this->missingTable($result)) {
            $this->assertSuccess($result);
        }
    }

    /**
     * Create the index.
     *
     * Scout knows nothing of a schema, so it is taken from scout.manticore.schemas by the name of
     * the index. Only the name is known here, so a schema of the model is out of reach - a model
     * describing its own is served by the write itself, see ensureIndex().
     *
     * @param string $name
     * @param array $options table options, merged over those of the config
     *
     * @return mixed
     */
    public function createIndex($name, array $options = [])
    {
        $schema = $this->schemaOf($name);

        if (empty($schema['columns'])) {
            throw new \LogicException(
                'No schema of the index "' . $name . '" to create it with. Describe it in '
                . 'scout.manticore.schemas.' . $name . ' or in a manticoreSchema() of the model, '
                . 'or leave the index alone - it is created on the first write to it.'
            );
        }

        return $this->connection()->create(
            $name,
            $schema['columns'],
            array_merge($schema['options'], $options),
            true
        );
    }

    /**
     * Drop the index.
     *
     * @param string $name
     *
     * @return mixed
     */
    public function deleteIndex($name)
    {
        return $this->connection()->drop($name, true);
    }

    /**
     * Drop every table whose name begins with the prefix of Scout ("php artisan scout:delete-all-indexes").
     *
     * An empty scout.prefix means every table of the server, tables of this application or not -
     * the same as the other drivers of Scout do it.
     *
     * @return array names of the dropped tables
     */
    public function deleteAllIndexes(): array
    {
        $prefix = (string)config('scout.prefix');
        $connection = $this->connection();
        $dropped = [];

        foreach ($connection->showTables($prefix !== '' ? $prefix . '%' : null) as $row) {
            // the column of the name is "Index" up to Manticore 6 and "Table" after it
            $name = is_array($row) ? (string)reset($row) : (string)$row;
            if ($name === '') {
                continue;
            }
            $connection->drop($name, true);
            $dropped[] = $name;
        }

        return $dropped;
    }

    // +++ READ +++ //

    /**
     * Run the search.
     *
     * @param \Laravel\Scout\Builder $builder
     *
     * @return mixed
     */
    public function search(Builder $builder)
    {
        $limit = $builder->limit ?: (int)$this->config('limit', 1000);

        return $this->performSearch($builder, ['limit' => (int)$limit, 'offset' => 0]);
    }

    /**
     * Run the search for one page of results.
     *
     * @param \Laravel\Scout\Builder $builder
     * @param int $perPage
     * @param int $page
     *
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, [
            'limit'  => (int)$perPage,
            'offset' => ((int)$page - 1) * (int)$perPage,
        ]);
    }

    /**
     * Build the query out of the Scout builder and run it.
     *
     * @param \Laravel\Scout\Builder $builder
     * @param array $params limit and offset of this call
     *
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $params = [])
    {
        $options = $builder->options;
        $query = $this->connection()->table($builder->index ?: $builder->model->searchableAs());

        $this->applyMatch($builder, $query, $options);
        $this->applyWheres($builder, $query);
        $this->applyOrders($builder, $query);
        $this->applyLimit($query, $params);
        $this->applyOptions($query, $options);

        if ($builder->callback) {
            $result = call_user_func($builder->callback, $query, $builder->query, $params);
            if ($result instanceof Query) {
                $result = $result->search();
            }
        }
        else {
            $result = $query->search();
        }

        if ($result instanceof ResultSet) {
            // an index that has not been imported yet answers with nothing rather than an error,
            // the way a search of the other Scout drivers does
            if ($this->missingTable($result)) {
                return $this->emptyResult();
            }

            $this->assertSuccess($result);
        }

        return $result;
    }

    /**
     * The full-text part of the query.
     *
     * What Scout passes is what a user typed, so it is escaped by default - see the escape_query
     * key of the config and the "escape" option of a single query.
     *
     * @param \Laravel\Scout\Builder $builder
     * @param Query $query
     * @param array $options
     *
     * @return void
     */
    protected function applyMatch(Builder $builder, Query $query, array $options)
    {
        $phrase = (string)$builder->query;
        if ($phrase === '') {
            return;
        }

        $escape = array_key_exists('escape', $options) ? (bool)$options['escape'] : (bool)$this->config('escape_query', true);

        $query->match($escape ? Query::escapeMatch($phrase) : $phrase, $options['fields'] ?? null);
    }

    /**
     * The where(), whereIn() and whereNotIn() constraints of the Scout builder.
     *
     * @param \Laravel\Scout\Builder $builder
     * @param Query $query
     *
     * @return void
     */
    protected function applyWheres(Builder $builder, Query $query)
    {
        foreach ($builder->wheres as $where) {
            $query->where($where['field'], $where['operator'], $this->value($where['value']));
        }

        foreach ($builder->whereIns as $field => $values) {
            $query->whereIn($field, array_map([$this, 'value'], (array)$values));
        }

        foreach ($builder->whereNotIns as $field => $values) {
            $query->whereNotIn($field, array_map([$this, 'value'], (array)$values));
        }
    }

    /**
     * The orderBy() clauses of the Scout builder.
     *
     * @param \Laravel\Scout\Builder $builder
     * @param Query $query
     *
     * @return void
     */
    protected function applyOrders(Builder $builder, Query $query)
    {
        foreach ($builder->orders as $order) {
            $query->orderBy($order['column'], $order['direction']);
        }
    }

    /**
     * LIMIT and OFFSET, along with the max_matches the depth of paging needs.
     *
     * Manticore keeps max_matches rows per query (1000 by default) and rejects a query reaching
     * beyond that, so a deep page raises the value on its own.
     *
     * @param Query $query
     * @param array $params
     *
     * @return void
     */
    protected function applyLimit(Query $query, array $params)
    {
        $limit = isset($params['limit']) ? (int)$params['limit'] : 0;
        $offset = isset($params['offset']) ? (int)$params['offset'] : 0;

        if ($limit > 0) {
            $query->limit($offset, $limit);
        }
        elseif ($offset > 0) {
            $query->offset($offset);
        }

        $maxMatches = (int)$this->config('max_matches', 0);
        $needed = $offset + $limit;
        if ($needed > max($maxMatches, self::SERVER_MAX_MATCHES)) {
            $maxMatches = $needed;
        }

        if ($maxMatches > 0) {
            $query->maxMatches($maxMatches);
        }
    }

    /**
     * What is left of Builder::options() after the keys of the driver: the OPTION clause.
     *
     * @param Query $query
     * @param array $options
     *
     * @return void
     */
    protected function applyOptions(Query $query, array $options)
    {
        if (!empty($options['highlight'])) {
            $highlight = is_array($options['highlight']) ? $options['highlight'] : [];
            $query->highlight($highlight['options'] ?? [], $highlight['fields'] ?? []);
        }

        foreach ($options as $key => $value) {
            if (in_array($key, self::RESERVED_OPTIONS, true)) {
                continue;
            }
            if ($key === 'field_weights') {
                $query->fieldWeights($value);
            }
            else {
                $query->option($key, $value);
            }
        }
    }

    // +++ MAPPING +++ //

    /**
     * The keys of the found rows.
     *
     * @param mixed $results
     *
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        $hits = $this->hits($results);

        return collect($hits)->map(function ($hit) {
            return $this->hitKey($hit);
        })->filter(function ($key) {
            return $key !== null;
        })->values();
    }

    /**
     * The keys of the found rows, under the name the model gives them.
     *
     * @param mixed $results
     * @param string $key
     *
     * @return \Illuminate\Support\Collection
     */
    public function mapIdsFrom($results, $key)
    {
        $hits = $this->hits($results);

        return collect($hits)->map(function ($hit) use ($key) {
            return $this->hitKey($hit, $key);
        })->filter(function ($value) {
            return $value !== null;
        })->values();
    }

    /**
     * The found rows as models, in the order the server ranked them.
     *
     * @param \Laravel\Scout\Builder $builder
     * @param mixed $results
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model)
    {
        $hits = $this->hits($results);
        if (!$hits) {
            return $model->newCollection();
        }

        $keyName = $model->getScoutKeyName();
        $ids = $this->mapIdsFrom($results, $keyName)->all();
        $positions = array_flip($ids);

        return $model->getScoutModelsByIds($builder, $ids)
            ->filter(function ($model) use ($positions) {
                return isset($positions[$model->getScoutKey()]);
            })
            ->map(function ($model) use ($hits, $positions) {
                return $this->withMetadata($model, $hits[$positions[$model->getScoutKey()]] ?? []);
            })
            ->sortBy(function ($model) use ($positions) {
                return $positions[$model->getScoutKey()];
            })
            ->values();
    }

    /**
     * The same as map(), row by row.
     *
     * @param \Laravel\Scout\Builder $builder
     * @param mixed $results
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        $hits = $this->hits($results);
        if (!$hits) {
            return LazyCollection::make($model->newCollection());
        }

        $keyName = $model->getScoutKeyName();
        $ids = $this->mapIdsFrom($results, $keyName)->all();
        $positions = array_flip($ids);

        return $model->queryScoutModelsByIds($builder, $ids)
            ->cursor()
            ->filter(function ($model) use ($positions) {
                return isset($positions[$model->getScoutKey()]);
            })
            ->map(function ($model) use ($hits, $positions) {
                return $this->withMetadata($model, $hits[$positions[$model->getScoutKey()]] ?? []);
            })
            ->sortBy(function ($model) use ($positions) {
                return $positions[$model->getScoutKey()];
            })
            ->values();
    }

    /**
     * The number of rows that matched, not just those of this page.
     *
     * The server counts up to max_matches, so a total beyond that is the limit itself.
     *
     * @param mixed $results
     *
     * @return int
     */
    public function getTotalCount($results)
    {
        if ($results instanceof ResultSet) {
            return $results->total();
        }

        return count($this->hits($results));
    }

    // +++ INTERNALS +++ //

    /**
     * The rows of an answer, whatever the search gave back.
     *
     * @param mixed $results
     *
     * @return array
     */
    protected function hits($results): array
    {
        if ($results instanceof ResultSet) {
            $results = $results->result();
        }

        if ($results instanceof Collection) {
            $results = $results->all();
        }

        return is_array($results) ? array_values($results) : [];
    }

    /**
     * The key of a found row: the column of the model, or the document id of Manticore.
     *
     * @param mixed $hit
     * @param string|null $keyName
     *
     * @return mixed|null
     */
    protected function hitKey($hit, ?string $keyName = null)
    {
        if ($hit instanceof \ArrayAccess || is_array($hit)) {
            if ($keyName !== null && isset($hit[$keyName])) {
                return $hit[$keyName];
            }

            return $hit['id'] ?? null;
        }

        return null;
    }

    /**
     * Hand the model what the search added to the row - the _score of the ranker, a _highlight.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param mixed $hit
     *
     * @return \Illuminate\Database\Eloquent\Model
     */
    protected function withMetadata($model, $hit)
    {
        foreach ((array)$hit as $key => $value) {
            if (is_string($key) && strpos($key, '_') === 0) {
                $model->withScoutMetadata($key, $value);
            }
        }

        return $model;
    }

    /**
     * The document id of a model.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return int
     */
    protected function documentId($model): int
    {
        $key = $model->getScoutKey();

        if (!$this->isDocumentId($key)) {
            throw new \LogicException(
                'The scout key of ' . get_class($model) . ' is "' . (is_scalar($key) ? $key : gettype($key))
                . '", and a document id of Manticore is a positive integer. Give the model an integer '
                . 'key, or override getScoutKey() to answer with one.'
            );
        }

        return (int)$key;
    }

    /**
     * Whether the value can be a document id of Manticore.
     *
     * @param mixed $key
     *
     * @return bool
     */
    protected function isDocumentId($key): bool
    {
        return (is_int($key) || (is_string($key) && ctype_digit($key))) && (int)$key > 0;
    }

    /**
     * A value of a where() as the builder wants it.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    protected function value($value)
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    /**
     * Rows of one REPLACE have to name the same columns, so they are grouped by that.
     *
     * @param array $rows
     *
     * @return array
     */
    protected function batches(array $rows): array
    {
        $batches = [];
        foreach ($rows as $row) {
            $batches[implode(',', array_keys($row))][] = $row;
        }

        return array_values($batches);
    }

    /**
     * The schema of an index, as columns and table options.
     *
     * @param string $name
     *
     * @return array
     */
    protected function schemaOf(string $name): array
    {
        $schemas = (array)$this->config('schemas', []);
        $schema = $schemas[$name] ?? [];

        if (is_array($schema) && (isset($schema['columns']) || isset($schema['options']))) {
            return [
                'columns' => $schema['columns'] ?? null,
                'options' => (array)($schema['options'] ?? []),
            ];
        }

        // a bare list of columns, a callable taking a SchemaTable, or a SchemaTable itself
        return ['columns' => $schema ?: null, 'options' => []];
    }

    /**
     * Create the index of a write when it is not there yet.
     *
     * Manticore has no schema of its own to fall back on: a write to a table that does not exist
     * is an error, and so is a column the table does not have. The schema comes from the config,
     * from a manticoreSchema() of the model, or - failing both - from the values themselves.
     *
     * @param string $index
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param array $rows
     *
     * @return void
     */
    protected function ensureIndex(string $index, $model, array $rows)
    {
        if (isset($this->knownIndexes[$index]) || !$this->config('auto_create', true)) {
            return;
        }

        $connection = $this->connection();
        if (!$connection->hasTable($index)) {
            $schema = $this->schemaOf($index);
            $columns = $schema['columns'] ?: $this->modelSchema($model) ?: $this->guessColumns($rows);

            $this->assertSuccess($connection->create($index, $columns, $schema['options'], true));
        }

        $this->knownIndexes[$index] = true;
    }

    /**
     * The schema a model describes itself, if it does.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return array|callable|null
     */
    protected function modelSchema($model)
    {
        return method_exists($model, 'manticoreSchema') ? $model->manticoreSchema() : null;
    }

    /**
     * The columns of an index guessed from the values written to it.
     *
     * Text becomes a full-text field, i.e. it answers to search() but not to where() - a column
     * to be filtered or sorted by has to be described in the config or by the model.
     *
     * @param array $rows
     *
     * @return array
     */
    protected function guessColumns(array $rows): array
    {
        $columns = [];

        foreach ($rows as $row) {
            foreach ($row as $name => $value) {
                if ($name === 'id' || isset($columns[$name]) || $value === null) {
                    continue;
                }

                if (is_bool($value)) {
                    $columns[$name] = 'bool';
                }
                elseif (is_int($value)) {
                    $columns[$name] = 'bigint';
                }
                elseif (is_float($value)) {
                    $columns[$name] = 'float';
                }
                elseif (is_array($value)) {
                    $columns[$name] = 'json';
                }
                else {
                    $columns[$name] = 'text';
                }
            }
        }

        // a table of no columns cannot be written to, so a name is better than nothing
        return $columns ?: ['scout_document' => 'text'];
    }

    /**
     * The type of a column the index is missing.
     *
     * @param string $index
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param array $rows
     * @param string $column
     *
     * @return string
     */
    protected function columnType(string $index, $model, array $rows, string $column): string
    {
        foreach ([$this->schemaOf($index)['columns'], $this->modelSchema($model)] as $declared) {
            if (is_array($declared) && isset($declared[$column])) {
                return (string)$declared[$column];
            }
        }

        $guessed = $this->guessColumns($rows);

        return (string)($guessed[$column] ?? 'text');
    }

    /**
     * The column the server says the index has no place for, if that is what went wrong.
     *
     * @param ResultSet $result
     *
     * @return string|null
     */
    protected function missingColumn(ResultSet $result): ?string
    {
        if ($result->success()) {
            return null;
        }

        if (preg_match('/unknown column:?\s*[\'"]?([\w.-]+)/i', (string)$result->error(), $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Whether the server rejected the statement because the table is not there.
     *
     * @param ResultSet $result
     *
     * @return bool
     */
    protected function missingTable(ResultSet $result): bool
    {
        if ($result->success()) {
            return false;
        }

        // the server words it differently per statement: "table 'x' absent" of a write, "unknown
        // local table(s) 'x' in search request" of a search, "no such table" of DESCRIBE and
        // "TRUNCATE RTINDEX requires an existing RT table" of TRUNCATE
        return (bool)preg_match(
            '/(absent|no such table|unknown (local )?table|requires an existing)/i',
            (string)$result->error()
        );
    }

    /**
     * An answer of no rows, for a search of an index that has not been created yet.
     *
     * @return ResultSet
     */
    protected function emptyResult(): ResultSet
    {
        return new ResultSet([
            'command' => 'SELECT',
            'meta'    => ['total' => 0, 'total_found' => 0],
            'result'  => ['type' => 'array', 'data' => []],
        ]);
    }

    /**
     * A value of the scout.manticore section, with the given default for a missing or null one.
     *
     * @param string $key
     * @param mixed $default
     *
     * @return mixed
     */
    protected function config(string $key, $default = null)
    {
        return isset($this->config[$key]) ? $this->config[$key] : $default;
    }

    /**
     * Whether the model is soft deleted rather than deleted.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return bool
     */
    protected function usesSoftDelete($model): bool
    {
        return in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model), true);
    }

    /**
     * A statement the server rejected is an error, not an empty answer.
     *
     * @param ResultSet $result
     *
     * @return ResultSet
     */
    protected function assertSuccess(ResultSet $result): ResultSet
    {
        if (!$result->success()) {
            throw new QueryErrorException((string)$result->error(), $result->sqlQuery());
        }

        return $result;
    }

    /**
     * Anything else is asked of the connection - transactions, DESCRIBE, forgetSchema().
     *
     * @param string $method
     * @param array $parameters
     *
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        return call_user_func_array([$this->connection(), $method], $parameters);
    }
}
