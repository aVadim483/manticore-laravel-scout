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
use Laravel\Scout\Contracts\SupportsSemanticSearch;
use Laravel\Scout\Contracts\UpdatesIndexSettings;
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
class ManticoreEngine extends Engine implements UpdatesIndexSettings, SupportsSemanticSearch
{
    /**
     * Keys of Builder::options() the driver reads itself; the rest becomes the OPTION clause
     */
    public const RESERVED_OPTIONS = ['fields', 'escape', 'highlight', 'semantic'];

    /**
     * The number of rows Manticore keeps per query unless told otherwise
     */
    public const SERVER_MAX_MATCHES = 1000;

    /**
     * Rows of one REPLACE, unless batch_size of the config says otherwise
     */
    public const BATCH_SIZE = 100;

    /**
     * The column a vector search adds: the distance of the server as a similarity of 0 to 1
     */
    public const SIMILARITY_COLUMN = '_similarity';

    /**
     * The column a hybrid search ranks by: the weights of Scout over the two scores
     */
    public const HYBRID_COLUMN = '_hybrid_score';

    /**
     * The pool of connections of the query builder package
     */
    protected Manager $manager;

    /**
     * The "manticore" section of config/scout.php
     */
    protected array $config;

    /**
     * Whether Scout keeps soft deleted models in the index
     */
    protected bool $softDelete;

    /**
     * Indexes known to be there, so that a write asks the server about them once
     */
    protected array $knownIndexes = [];

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

        $class = get_class($model);
        $ids = $keys->map(function ($key) use ($class) {
            return $this->assertDocumentId($key, $class);
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

    /**
     * Bring the index in line with the schema it is described by ("php artisan scout:index" and
     * "php artisan scout:sync-index-settings").
     *
     * The settings are a schema of this driver - columns and table options, the same shape the
     * schemas of the config have. What the command passes wins over what the config says, and
     * what it leaves out is taken from there, so an application that has written its schemas
     * down once has nothing to repeat under index-settings.
     *
     * A column the index does not have is added; a column that is there keeps the type it has,
     * because Manticore cannot change one in place without losing what is written in it.
     *
     * @param string $name
     * @param array $settings columns and options, as of scout.manticore.schemas
     *
     * @return void
     */
    public function updateIndexSettings(string $name, array $settings = [])
    {
        $declared = $this->normalizeSchema($settings);
        $configured = $this->schemaOf($name);

        $columns = $declared['columns'] ?: $configured['columns'];
        if (is_array($declared['columns']) && is_array($configured['columns'])) {
            $columns = $declared['columns'] + $configured['columns'];
        }
        $options = array_merge($configured['options'], $declared['options']);

        $connection = $this->connection();

        if (!$connection->hasTable($name)) {
            if (empty($columns)) {
                throw new \LogicException(
                    'No schema of the index "' . $name . '" to create it with. Describe it in '
                    . 'scout.manticore.schemas.' . $name . ', in scout.manticore.index-settings.'
                    . $name . ' or in a manticoreSchema() of the model.'
                );
            }

            $this->assertSuccess($connection->create($name, $columns, $options, true));
            $this->knownIndexes[$name] = true;

            return;
        }

        if (is_array($columns) && $columns) {
            $existing = $connection->tableDescribe($name);
            foreach ($columns as $column => $type) {
                if (!is_string($column) || $column === 'id' || isset($existing[$column])) {
                    continue;
                }

                $this->assertSuccess($connection->addColumn($name, $column, $type));
            }
        }

        if ($options) {
            $this->assertSuccess($connection->alterSettings($name, $options));
        }
    }

    /**
     * Make room in the settings for the flag Scout marks a trashed model with.
     *
     * @param array $settings
     *
     * @return array
     */
    public function configureSoftDeleteFilter(array $settings = [])
    {
        $schema = $this->normalizeSchema($settings);
        $columns = is_array($schema['columns']) ? $schema['columns'] : [];

        if (!isset($columns['__soft_deleted'])) {
            // an int rather than a bool: Scout filters by 0 and 1
            $columns['__soft_deleted'] = 'int';
        }

        return ['columns' => $columns, 'options' => $schema['options']];
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

        $this->applyVectorSearch($builder, $query, $options, $params);
        if (!$builder->semanticSearch) {
            // a semantic search is the vector alone; a hybrid one is the vector and the words
            $this->applyMatch($builder, $query, $options);
        }
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
     * The vector part of a semantic() or hybrid() search: WHERE knn(<column>, <k>, ...).
     *
     * The phrase of a search is a phrase, and what the server compares are vectors, so something
     * has to turn one into the other - see embedding() and the semantic section of the config.
     * Manticore takes knn() and MATCH() in one statement, which is what makes a hybrid search one
     * query rather than two and a merge of the answers.
     *
     * @param \Laravel\Scout\Builder $builder
     * @param Query $query
     * @param array $options
     * @param array $params limit and offset of this call
     *
     * @return void
     */
    protected function applyVectorSearch(Builder $builder, Query $query, array $options, array $params)
    {
        if (!$builder->semanticSearch && empty($builder->hybridSearch)) {
            return;
        }

        $config = array_merge((array)$this->config('semantic', []), (array)($options['semantic'] ?? []));
        $column = (string)($config['column'] ?? 'embedding');

        $neighbours = (int)($config['k'] ?? 0);
        if ($neighbours < 1) {
            // as deep as the page reaches: the server looks no further than the neighbours it was
            // asked for, and a where() of the query cuts into them afterwards
            $neighbours = max(1, (int)($params['offset'] ?? 0) + (int)($params['limit'] ?? 0));
        }

        $query->whereKnn($column, $neighbours, $this->embedding($builder, $config));

        // knn_dist() is a distance - 0 is the vector itself - while Scout speaks of similarity,
        // so a cosine index turns into the 0 to 1 of minimumSimilarity by 1 - dist
        $similarity = '(1 - knn_dist())';

        if ($builder->minimumSimilarity !== null || !empty($builder->hybridSearch)) {
            $query->select('*')->selectRaw($similarity . ' as ' . self::SIMILARITY_COLUMN);
        }

        if ($builder->minimumSimilarity !== null) {
            $query->where(self::SIMILARITY_COLUMN, '>=', (float)$builder->minimumSimilarity);
        }

        if (!empty($builder->hybridSearch)) {
            $query->selectRaw(
                '(' . $this->number($builder->hybridSearch['text_weight'] ?? 1) . ' * weight() + '
                . $this->number($builder->hybridSearch['semantic_weight'] ?? 1) . ' * ' . $similarity
                . ') as ' . self::HYBRID_COLUMN
            );
        }
    }

    /**
     * The phrase of the search as a vector.
     *
     * @param \Laravel\Scout\Builder $builder
     * @param array $config the semantic section, with what the query overrode in it
     *
     * @return array
     */
    protected function embedding(Builder $builder, array $config): array
    {
        $embedder = $config['embedder'] ?? null;
        if (is_string($embedder) && !is_callable($embedder)) {
            // the name of an invokable class, built by the container
            $embedder = app($embedder);
        }

        if (!is_callable($embedder)) {
            throw new \LogicException(
                'A semantic search compares vectors, and there is nothing here to turn the phrase into '
                . 'one. Put a callable - or the name of an invokable class - into '
                . 'scout.manticore.semantic.embedder, or into the "semantic" option of the query.'
            );
        }

        $vector = $embedder((string)$builder->query, $builder->model);
        if ($vector instanceof \Illuminate\Contracts\Support\Arrayable) {
            $vector = $vector->toArray();
        }

        if (!is_array($vector) || !$vector) {
            throw new \LogicException(
                'The embedder of scout.manticore.semantic answered with ' . gettype($vector)
                . ' where a vector of numbers was expected.'
            );
        }

        return array_values($vector);
    }

    /**
     * A number as it goes into an expression, whatever the locale of the application is.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function number($value): string
    {
        $number = rtrim(rtrim(sprintf('%.6F', (float)$value), '0'), '.');

        return $number === '' || $number === '-' ? '0' : $number;
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

        if (!$builder->orders && !empty($builder->hybridSearch)) {
            // the column applyVectorSearch() added: the server ranks by the words alone otherwise
            $query->orderBy(self::HYBRID_COLUMN, 'desc');
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
        // the depth the query has unless it is raised: the value of the config, or the default of
        // the server when there is none. A config lower than the default is a deliberate one, and
        // the page has to fit into it just the same
        $depth = $maxMatches > 0 ? $maxMatches : self::SERVER_MAX_MATCHES;
        $needed = $offset + $limit;
        if ($needed > $depth) {
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
     * Lazy as far as the rows of the database go - the order of the search is not the order of a
     * query, so the models are sorted here, and sorting a LazyCollection walks all of it. The
     * engines of Scout do it the same way.
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
            if (is_string($key) && str_starts_with($key, '_')) {
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
        return $this->assertDocumentId($model->getScoutKey(), get_class($model));
    }

    /**
     * The key as a document id, or an error naming the model it belongs to.
     *
     * A removal goes through here as well as a write: a key silently dropped here would leave the
     * row of a deleted model in the index, and nobody would hear of it.
     *
     * @param mixed $key
     * @param string $class
     *
     * @return int
     */
    protected function assertDocumentId($key, string $class): int
    {
        if (!$this->isDocumentId($key)) {
            throw new \LogicException(
                'The scout key of ' . $class . ' is "' . (is_scalar($key) ? $key : gettype($key))
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
     * Rows of one REPLACE have to name the same columns, so they are grouped by that - and then
     * cut into statements of batch_size rows.
     *
     * The chunk of "php artisan scout:import" is 500 models by default, and a statement carrying
     * that many rows of text runs into max_packet_size of the server (8M), which answers with a
     * lost connection rather than with an error naming the reason.
     *
     * @param array $rows
     *
     * @return array
     */
    protected function batches(array $rows): array
    {
        $size = (int)$this->config('batch_size', self::BATCH_SIZE);

        $groups = [];
        foreach ($rows as $row) {
            $groups[implode(',', array_keys($row))][] = $row;
        }

        $batches = [];
        foreach ($groups as $group) {
            // a size of zero or less is "as many as there are", i.e. the limit turned off
            foreach (($size > 0 ? array_chunk($group, $size) : [$group]) as $batch) {
                $batches[] = $batch;
            }
        }

        return $batches;
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
        // the commands of Scout hand over the name of the table, prefix and all, while a schema
        // is written down under the name of the index
        $schema = $schemas[$name] ?? $schemas[$this->withoutPrefix($name)] ?? [];

        return $this->normalizeSchema($schema);
    }

    /**
     * A schema as the two keys the driver reads it by, whichever way it was written down.
     *
     * @param mixed $schema
     *
     * @return array
     */
    protected function normalizeSchema($schema): array
    {
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
     * The name of the table without the prefix of Scout in front of it.
     *
     * @param string $name
     *
     * @return string
     */
    protected function withoutPrefix(string $name): string
    {
        $prefix = (string)config('scout.prefix');

        return ($prefix !== '' && str_starts_with($name, $prefix)) ? substr($name, strlen($prefix)) : $name;
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

        if (preg_match('/unknown column:?\s*[\'"]?([\w.-]+)/i', $this->serverError($result), $m)) {
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
            $this->serverError($result)
        );
    }

    /**
     * What the server answered, without the statement it was given.
     *
     * A client that reports an error the way the one of the query builder writes it - "SQL: SELECT
     * ...\nError [1064] ..." - puts the statement in front of the answer, and the statement carries
     * the phrase a user typed. Read as a whole, a search for "no such table" would look like a
     * missing index and come back empty instead of raising whatever really went wrong.
     *
     * @param ResultSet $result
     *
     * @return string
     */
    protected function serverError(ResultSet $result): string
    {
        $error = (string)$result->error();

        if (preg_match('/(?:^|\n)Error \[[^]]*]\s*(.*)$/s', $error, $m)) {
            return $m[1];
        }

        return $error;
    }

    /**
     * An answer of no rows, for a search of an index that has not been created yet.
     *
     * @return ResultSet
     */
    protected function emptyResult(): ResultSet
    {
        return $this->resultSet([]);
    }

    /**
     * A ResultSet of the given rows - the one place that knows how one is put together.
     *
     * @param array $rows
     * @param array $meta
     *
     * @return ResultSet
     */
    protected function resultSet(array $rows, array $meta = []): ResultSet
    {
        return new ResultSet([
            'command' => 'SELECT',
            'meta'    => $meta + ['total' => count($rows), 'total_found' => count($rows)],
            'result'  => ['type' => 'array', 'data' => $rows],
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
        return $this->config[$key] ?? $default;
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
