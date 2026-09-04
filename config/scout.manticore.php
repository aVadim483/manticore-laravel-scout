<?php

/**
 * Defaults of the "manticore" section of config/scout.php
 *
 * The file is merged into scout.manticore by the service provider, so an application only has to
 * write down the keys it wants to change - either as a "manticore" section of config/scout.php or
 * by publishing this file, which lands as config/scout.manticore.php. The dot of that name is what
 * makes it work: the config loader of Laravel takes the key of a file from its name and sets it
 * with the dot notation, so the file is read as the "manticore" section of scout rather than as a
 * config of its own. What is published wins over the defaults here, key by key.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | Name of a connection of config/manticore.php, i.e. of the query builder package. Null takes
    | the one named there as manticore.defaultConnection.
    |
    */

    'connection' => env('SCOUT_MANTICORE_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Limit of a search without take()
    |--------------------------------------------------------------------------
    |
    | Manticore answers with 20 rows when a query says nothing about it, which is not what a
    | ->get() of Scout is expected to do. This is the limit such a query is given instead.
    |
    */

    'limit' => env('SCOUT_MANTICORE_LIMIT', 1000),

    /*
    |--------------------------------------------------------------------------
    | max_matches
    |--------------------------------------------------------------------------
    |
    | The number of rows the server keeps per query, i.e. how deep paging can go and how far
    | total_found is counted. Null leaves the default of the server (1000). The driver raises it
    | on its own when a page reaches beyond it, so that paging does not break on page 51.
    |
    */

    'max_matches' => env('SCOUT_MANTICORE_MAX_MATCHES'),

    /*
    |--------------------------------------------------------------------------
    | Escaping of the search phrase
    |--------------------------------------------------------------------------
    |
    | What a user typed is text, not an expression: a dash in "iPhone -Pro" would exclude "Pro"
    | and an unpaired quote makes the server reject the query. Turn this off to pass the query
    | language of Manticore through, and escape untrusted input yourself.
    |
    */

    'escape_query' => env('SCOUT_MANTICORE_ESCAPE_QUERY', true),

    /*
    |--------------------------------------------------------------------------
    | Creation of a missing index
    |--------------------------------------------------------------------------
    |
    | Manticore has no schema of its own to fall back on - a write to a table that does not exist
    | is an error. With this on, the driver creates the table of a model on the first write: out
    | of the schema below, out of a manticoreSchema() of the model, or out of the values written.
    | Turn it off to keep the schema of the indexes entirely in your own hands.
    |
    */

    'auto_create' => env('SCOUT_MANTICORE_AUTO_CREATE', true),

    /*
    |--------------------------------------------------------------------------
    | Columns added to an index that is already there
    |--------------------------------------------------------------------------
    |
    | A column added to toSearchableArray() of a model has no place in the index yet, and the
    | server rejects the whole write because of it. With this on, the driver adds the column
    | (ALTER TABLE ... ADD COLUMN) and writes again. Rows written earlier keep the values they
    | had, i.e. the new column is empty for them until they are imported again.
    |
    */

    'auto_columns' => env('SCOUT_MANTICORE_AUTO_COLUMNS', true),

    /*
    |--------------------------------------------------------------------------
    | Rows of one statement
    |--------------------------------------------------------------------------
    |
    | "php artisan scout:import" hands over 500 models at a time (scout.chunk.searchable), and a
    | REPLACE carrying that many rows of text runs into max_packet_size of the server, which drops
    | the connection instead of naming a reason. The rows are written in statements of this many
    | rows; 0 turns the limit off.
    |
    */

    'batch_size' => env('SCOUT_MANTICORE_BATCH_SIZE', 100),

    /*
    |--------------------------------------------------------------------------
    | Semantic and hybrid search
    |--------------------------------------------------------------------------
    |
    | ->semantic() of Scout searches by meaning rather than by words, and ->hybrid() by both at
    | once - which Manticore answers in one statement, knn() and MATCH() side by side. What the
    | server compares are vectors, so the phrase has to be turned into one: "embedder" is a
    | callable, or the name of an invokable class the container builds, taking the phrase and the
    | model and answering with an array of numbers.
    |
    |     'embedder' => \App\Search\Embedder::class,
    |
    | "column" is the float_vector column of the index the vectors live in, and "k" how many
    | neighbours the server is asked for - null asks for as many as the page needs.
    |
    | The distance comes back as $model->scoutMetadata()['_similarity'], counted as 1 - the
    | distance of the server, i.e. 1 for the vector itself. That is a similarity of 0 to 1 for a
    | cosine index, which is what ->semantic($minSimilarity) filters by.
    |
    */

    'semantic' => [
        'column'   => env('SCOUT_MANTICORE_SEMANTIC_COLUMN', 'embedding'),
        'k'        => env('SCOUT_MANTICORE_SEMANTIC_K'),
        'embedder' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Indexes of "php artisan scout:sync-index-settings"
    |--------------------------------------------------------------------------
    |
    | The command brings an index in line with the schema it is described by: a column that is
    | missing is added, the options of the table are applied. It walks what is named here, either
    | as a list of index names and model classes or as a map of name to schema:
    |
    |     'index-settings' => ['posts', \App\Models\Comment::class],
    |
    | Left empty, it walks the indexes named in "schemas" above, so a schema written down once
    | does not have to be repeated here.
    |
    */

    'index-settings' => [],

    /*
    |--------------------------------------------------------------------------
    | Schemas of the indexes
    |--------------------------------------------------------------------------
    |
    | Scout knows nothing of a schema, so createIndex() - i.e. "php artisan scout:index" - takes
    | it from here, keyed by index name. The columns are those of the query builder:
    |
    |     'posts' => [
    |         'columns' => [
    |             'title'      => 'text',
    |             'body'       => 'text',
    |             'author_id'  => 'int',
    |             'created_at' => 'timestamp',
    |         ],
    |         'options' => ['min_infix_len' => 3],
    |     ],
    |
    | An index missing from this list is created out of the values of the first write, where
    | every string becomes a full-text field - see auto_create above. A column to be filtered or
    | sorted by has to be described here or by a manticoreSchema() of the model, because a text
    | field answers to search() but not to where().
    |
    */

    'schemas' => [],

];
