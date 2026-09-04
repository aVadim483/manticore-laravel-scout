**English** | [Русский](README.ru.md)

[![GitHub Release](https://img.shields.io/github/v/release/aVadim483/manticore-laravel-scout)](https://packagist.org/packages/avadim/manticore-laravel-scout)
[![Packagist Downloads](https://img.shields.io/packagist/dt/avadim/manticore-laravel-scout?color=%23aa00aa)](https://packagist.org/packages/avadim/manticore-laravel-scout)
[![GitHub License](https://img.shields.io/github/license/aVadim483/manticore-laravel-scout)](https://packagist.org/packages/avadim/manticore-laravel-scout)
[![Static Badge](https://img.shields.io/badge/php-%3E%3D8.2-005fc7)](https://packagist.org/packages/avadim/manticore-laravel-scout)
[![Static Badge](https://img.shields.io/badge/laravel-11%20--%2013-ff2d20)](https://packagist.org/packages/avadim/manticore-laravel-scout)
[![Static Badge](https://img.shields.io/badge/scout-11-ff2d20)](https://packagist.org/packages/avadim/manticore-laravel-scout)
[![tests](https://github.com/aVadim483/manticore-laravel-scout/actions/workflows/tests.yml/badge.svg)](https://github.com/aVadim483/manticore-laravel-scout/actions/workflows/tests.yml)

# ManticoreSearch driver for Laravel Scout

Full-text search of your Eloquent models in [ManticoreSearch](https://manticoresearch.com/), through
the API of [Laravel Scout](https://laravel.com/docs/scout): `Post::search('manticore')->get()`.

```sh
composer require avadim/manticore-laravel-scout
```

The driver is a thin layer over
[`avadim/manticore-query-builder-laravel`](https://github.com/aVadim483/manticore-query-builder-laravel):
the connection, the pool and the SQL belong there, and so does `config/manticore.php`. This package
translates what Scout asks for into a query of the builder and maps the answer back onto models.

## Contents

* [Related packages](#related-packages)
* [Requirements](#requirements)
* [Installation](#installation)
* [Configuration](#configuration)
* [Quick start](#quick-start)
* [The schema of an index](#the-schema-of-an-index)
* [Keeping an index in line with its schema](#keeping-an-index-in-line-with-its-schema)
* [What a search understands](#what-a-search-understands)
* [Options of a query](#options-of-a-query)
* [Semantic and hybrid search](#semantic-and-hybrid-search)
* [The query language of Manticore](#the-query-language-of-manticore)
* [Soft deletes](#soft-deletes)
* [Artisan commands](#artisan-commands)
* [Limits worth knowing](#limits-worth-knowing)
* [Tests](#tests)

## Related packages

* [`avadim/manticore-query-builder-laravel`](https://github.com/aVadim483/manticore-query-builder-laravel) —
  the Laravel integration this driver stands on, and where its connection comes from:
  `config/manticore.php`, named connections, the `\ManticoreDb` alias and the facade. Reach for it
  whenever a query needs more than the API of Scout gives.
* [`avadim/manticore-query-builder-php`](https://github.com/aVadim483/manticore-query-builder-php) —
  the query builder itself, with no dependency on Laravel: the syntax of a query and the schema
  DSL live there, and so does everything this driver translates a search into.

## Requirements

* PHP >= 8.2
* Laravel 11 - 13 (or Lumen of the same generation), Laravel Scout 11
* ManticoreSearch with the MySQL protocol open (port 9306 by default)
* [`avadim/manticore-query-builder-laravel`](https://github.com/aVadim483/manticore-query-builder-laravel) >= 3.0

## Installation

```sh
composer require avadim/manticore-laravel-scout
```

Both service providers are discovered by Laravel on their own. Publish the config of the query
builder package and, if you want the defaults of the driver in writing, the config of this one:

```sh
php artisan vendor:publish --provider="avadim\Manticore\Laravel\ServiceProvider" --tag=config
php artisan vendor:publish --provider="avadim\Manticore\Scout\ServiceProvider" --tag=config
```

The second command writes `config/scout.manticore.php`, and the dot of that name is not a typo:
the config loader of Laravel takes the key of a file from its name and sets it with the dot
notation, so the file is read as the `manticore` section of `config/scout.php` rather than as a
config of its own. Publishing it is optional - the same keys can be written into `config/scout.php`
by hand, and what is written wins over the defaults of the package either way.

In Lumen, register the providers in `bootstrap/app.php` and copy the config files by hand.

## Configuration

The connection is the one of `config/manticore.php`:

```php
'connections' => [
    'default' => [
        'host'         => env('MANTICORE_HOST', '127.0.0.1'),
        'port'         => env('MANTICORE_PORT', 9306),
        // ...
    ],
],
```

Scout itself needs two lines in `.env`:

```dotenv
SCOUT_DRIVER=manticore
SCOUT_QUEUE=true
```

Everything the driver reads lives in the `manticore` section of `config/scout.php` - written there
by hand, or published as `config/scout.manticore.php`, which Laravel reads into the same section.
The defaults are merged in by the service provider, so only the keys you change have to be written
down:

| Key | Env | Default | Meaning |
|---|---|---|---|
| `connection` | `SCOUT_MANTICORE_CONNECTION` | `null` | name of a connection of `config/manticore.php`; null takes the default one |
| `limit` | `SCOUT_MANTICORE_LIMIT` | `1000` | the limit of a search that says nothing about it - the server would answer with 20 rows |
| `max_matches` | `SCOUT_MANTICORE_MAX_MATCHES` | `null` | rows the server keeps per query, i.e. how deep paging goes and how far `total()` counts |
| `escape_query` | `SCOUT_MANTICORE_ESCAPE_QUERY` | `true` | escape the phrase, so that what a user typed is searched for as it was written |
| `auto_create` | `SCOUT_MANTICORE_AUTO_CREATE` | `true` | create the index on the first write to it; off, a write to an index that is not there raises |
| `auto_columns` | `SCOUT_MANTICORE_AUTO_COLUMNS` | `true` | add a column the index is missing and write again |
| `batch_size` | `SCOUT_MANTICORE_BATCH_SIZE` | `100` | rows of one `REPLACE`; 0 turns the limit off |
| `schemas` | - | `[]` | schemas of the indexes, by index name |
| `index-settings` | - | `[]` | the indexes `scout:sync-index-settings` walks; empty means the ones of `schemas` |
| `semantic` | - | see below | the vector column, the neighbours asked for, and the embedder of `semantic()` and `hybrid()` |

## Quick start

```php
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class Post extends Model
{
    use Searchable;

    public function toSearchableArray(): array
    {
        return [
            'title'      => $this->title,
            'body'       => $this->body,
            'author_id'  => (int)$this->author_id,
            'created_at' => $this->created_at->getTimestamp(),
        ];
    }
}
```

```sh
php artisan scout:import "App\Models\Post"
```

```php
Post::search('manticore')->get();
Post::search('manticore')->where('author_id', 7)->orderBy('created_at', 'desc')->paginate(20);
Post::search('manticore')->keys();
Post::search('manticore')->cursor();
```

Saving a model writes it to the index, deleting it removes it - that is the observer of Scout, not
this package. `SCOUT_QUEUE=true` moves both onto a queue.

## The schema of an index

Manticore has no schema of its own to fall back on: a write to a table that does not exist is an
error, and so is a column the table has no place for. The driver deals with that in three ways, in
this order.

**1. The config.** Written down once, the same as a migration:

```php
// config/scout.php
'manticore' => [
    'schemas' => [
        'posts' => [
            'columns' => [
                'title'      => 'text',
                'body'       => 'text',
                'author_id'  => 'int',
                'created_at' => 'timestamp',
            ],
            'options' => ['min_infix_len' => 3],
        ],
    ],
],
```

This is what `php artisan scout:index posts` creates; without it the command says where to put a
schema instead of creating an empty table.

**2. The model.** A `manticoreSchema()` keeps the schema next to `toSearchableArray()`:

```php
public function manticoreSchema(): array
{
    return [
        'title'     => 'text',
        'body'      => 'text',
        'author_id' => 'int',
        'slug'      => 'string',
    ];
}
```

With `auto_create` off, a write to an index that is not there raises instead - and the driver asks
the server whether the table is there rather than letting the write answer that, because Manticore
creates a table out of its own guess on a write from version 29 on, which is what turning
`auto_create` off is meant to prevent.

**3. The values.** With neither of the two, the index is built out of the first rows written to it:
a string becomes a `text` field, an integer a `bigint`, a float a `float`, a bool a `bool`, an array
a `json`. Good enough to start with, and enough of a guess that a column you filter or sort by is
better described by hand - see the limits below.

A column added to `toSearchableArray()` later is added to the index as well (`auto_columns`), and
the rows written before it keep an empty value for it until they are imported again.

## Keeping an index in line with its schema

A schema changes: a column is added to `toSearchableArray()`, `min_infix_len` turns out to be
needed. The command of Scout brings the index to what the schema says:

```sh
php artisan scout:sync-index-settings
```

It walks the indexes named in `scout.manticore.index-settings` and, when that is empty, the ones of
`schemas`. A column the index does not have is added and the options of the table are applied; a
column that is already there keeps the type it has, because Manticore cannot change one in place
without losing what is written in it - a changed type is a matter of a new index and an import
into it.

`index-settings` is a list of index names and model classes, or a map of them to a schema of their
own, which wins over `schemas` key by key:

```php
// config/scout.php
'manticore' => [
    'index-settings' => [
        'posts',
        \App\Models\Comment::class,
        'pages' => [
            'columns' => ['slug' => 'string'],
            'options' => ['min_infix_len' => 3],
        ],
    ],
],
```

The same happens to one index at a time on `php artisan scout:index posts`.

## What a search understands

The builder of Scout is a small one, and all of it works here:

```php
Post::search('manticore')                    // WHERE MATCH('manticore')
    ->where('author_id', 7)                  // AND author_id = 7
    ->where('rating', '>', 4)                // AND rating > 4
    ->whereIn('category_id', [1, 2, 3])      // AND category_id IN (1, 2, 3)
    ->whereNotIn('status', [0])              // AND status NOT IN (0)
    ->orderBy('created_at', 'desc')          // ORDER BY created_at DESC
    ->take(50)                               // LIMIT 50
    ->get();
```

Without `orderBy()`, the rows come back in the order the server ranked them, and every model
carries the weight of its row:

```php
$post->scoutMetadata()['_score'];
```

`paginate()` and `simplePaginate()` answer with the paginators of Laravel. A page beyond the 1000
rows Manticore keeps per query raises `max_matches` on its own, so paging does not break on page 51.

## Options of a query

`options()` of the Scout builder is read by the driver first, and what is left of it becomes the
`OPTION` clause of the SELECT:

```php
Post::search('manticore')->options([
    'fields'        => ['title'],            // search these full-text fields only
    'escape'        => false,                // pass the query language through, see below
    'highlight'     => true,                 // or ['options' => [...], 'fields' => [...]]
    'ranker'        => 'sph04',              // OPTION ranker=sph04
    'field_weights' => ['title' => 10],      // OPTION field_weights=(title=10)
    'cutoff'        => 1000,                 // OPTION cutoff=1000
])->get();
```

A highlight comes back as metadata of the model:

```php
$post->scoutMetadata()['_highlight'];
```

Anything the driver does not cover is reached through the callback of `search()`, which gets the
query of the builder itself:

```php
use avadim\Manticore\QueryBuilder\Query;

Post::search('manticore', function (Query $query, string $phrase) {
    return $query->whereKnn('embedding', 5, $vector)->facet('author_id');
})->get();
```

Return the query and the driver runs it, or run it yourself and return the `ResultSet`.

The engine itself hands anything it does not know of to the connection of the query builder, which
is where the transactions, the `DESCRIBE` and the answer of the last statement live:

```php
use Laravel\Scout\EngineManager;

$engine = app(EngineManager::class)->engine('manticore');

$engine->transaction(function () { /* ... */ });
$engine->tableDescribe('posts');

Post::search('manticore')->get();
$engine->lastResultSet()->facets();   // the meta of the search that has just run
```

`$engine->connection()` is the same connection, asked for by name rather than through the
forwarding - and `\ManticoreDb::connection()` of the query builder package answers with it too.

## Semantic and hybrid search

Manticore searches by vectors as well as by words, and takes both in one statement - which is what
makes a hybrid search one query here rather than two and a merge of the answers.

Two things are needed for it: a `float_vector` column in the index, written along with the model,
and something that turns the phrase of a search into a vector.

```php
// config/scout.php
'manticore' => [
    'semantic' => [
        'column'   => 'embedding',
        'k'        => null,                          // neighbours asked for; null: what the page needs
        'embedder' => \App\Search\Embedder::class,
    ],
],
```

The embedder is a callable, or the name of an invokable class the container builds. It is given the
phrase and the model, and answers with an array of numbers:

```php
class Embedder
{
    public function __invoke(string $phrase, $model): array
    {
        return $this->vectors->of($phrase);
    }
}
```

The column belongs in the schema of the index, where a vector takes more than a type name - hence
the callable form of a schema:

```php
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;

public function manticoreSchema(): callable
{
    return function (SchemaTable $table) {
        $table->text('title');
        $table->integer('author_id');
        $table->floatVector('embedding', 1536, 'cosine');
    };
}
```

and in `toSearchableArray()`, as the vector of the model itself:

```php
'embedding' => $this->embedding,     // an array of floats
```

Then the two searches of Scout answer:

```php
Post::search('a fruit that keeps the doctor away')->semantic()->get();
Post::search('apple')->semantic(0.8)->get();
Post::search('apple')->hybrid(1, 2)->get();
```

`semantic()` searches by the vector alone: `WHERE knn(embedding, k, (…))`, and `semantic(0.8)` adds
the similarity the row has to reach. `hybrid($textWeight, $semanticWeight)` asks for both at once,
and `MATCH()` is a condition of its own - a hybrid search keeps to the rows carrying the words, and
ranks them by `<text weight> * weight() + <semantic weight> * (1 - knn_dist())`. An `orderBy()` of
your own is left alone.

What the server answered with is on the model, next to the rest of the metadata:

```php
$post->scoutMetadata()['_knn_dist'];       // the distance, 0 being the vector itself
$post->scoutMetadata()['_similarity'];     // 1 - the distance, i.e. 0 to 1 for a cosine index
$post->scoutMetadata()['_hybrid_score'];   // of a hybrid search, what it was ranked by
```

The weight of a full-text match is the score of the ranker - in the thousands - while the
similarity is 0 to 1, so the weights of `hybrid()` are what brings the two to one scale.

The settings are given per query as well, next to the other options:

```php
Post::search('apple')->semantic()->options([
    'semantic' => ['column' => 'title_vector', 'k' => 100],
])->get();
```

`k` is the number of neighbours the server looks at before anything else narrows the result, so a
`where()` or the words of a hybrid search cut into those `k` rows rather than into the whole index.
Left alone it is as deep as the page reaches.

## The query language of Manticore

What a user typed into a search box is text, not an expression: a dash in `iPhone -Pro` would
exclude "Pro", a pipe would turn into an OR, and an unpaired quote makes the server reject the
query outright. The driver escapes the phrase for that reason, so a search finds what was written.

To use the language on purpose - alternatives, phrases, field operators - turn the escaping off,
either for a query or in the config:

```php
Post::search('"quick brown fox"/2 -lazy')->options(['escape' => false])->get();
```

## Soft deletes

With `scout.soft_delete` on, a trashed model stays in the index behind the `__soft_deleted` flag,
and the search of Scout works as it does everywhere else:

```php
Post::search('manticore')->withTrashed()->get();
Post::search('manticore')->onlyTrashed()->get();
```

The column is written and filtered by the driver; with the schema of an index in the config, add it
yourself as `'__soft_deleted' => 'int'`.

## Artisan commands

The commands of Scout work as they do with any other driver:

```sh
php artisan scout:import "App\Models\Post"     # write every model to the index
php artisan scout:flush "App\Models\Post"      # empty the index, keep the table
php artisan scout:index posts                  # create the index of the config schema
php artisan scout:sync-index-settings          # add what an index is missing of its schema
php artisan scout:delete-index posts           # drop the table
php artisan scout:delete-all-indexes           # drop every table whose name carries scout.prefix
```

`scout:delete-all-indexes` with an empty `scout.prefix` reaches every table of the server, tables of
this application or not - the same as the other drivers of Scout do it.

## Limits worth knowing

**The key of a model has to be a positive integer.** A document id of Manticore is a `bigint`, so a
UUID or a string key is rejected with an exception naming the model. Give such a model an integer
`getScoutKey()`.

**A `text` column is searched, not filtered.** It is the full-text field of Manticore: `search()`
finds words in it, but `where()` and `orderBy()` need an attribute - `int`, `bigint`, `float`,
`bool`, `string`, `timestamp`. A guessed schema makes every string a `text` field, which is why a
column you filter by belongs in a schema of the config or of the model.

**`total()` counts up to `max_matches`.** The server keeps 1000 rows per query by default; a total
beyond that is the limit itself until `max_matches` is raised. What the config says is the depth of
every query, not only of a page that reaches beyond it - a page deeper than that raises it for
itself.

**The schema cache lives as long as the connection.** In Octane or a queue worker that is a long
time; a table changed elsewhere calls for `\ManticoreDb::forgetSchema()`, or for
`forgetSchemas()` of the manager, which reaches every connection it built rather than the default
one alone. The connection itself is dropped by name with `purge()` and opened again by
`reconnect()` - what a worker whose handle the server closed overnight needs:

```php
use avadim\Manticore\Laravel\Manager;

app(Manager::class)->forgetSchemas();
app(Manager::class)->reconnect();
```

## Tests

```sh
composer install
php -d xdebug.mode=off vendor/bin/phpunit
```

The tests of the search itself need a ManticoreSearch at `127.0.0.1:9306`
(`MANTICORE_TEST_HOST` / `MANTICORE_TEST_PORT` in `phpunit.xml.dist`); they are skipped when there
is none. Tables of a test are named `phpunit_<uniqid>_*` and dropped afterwards.

## License

MIT
