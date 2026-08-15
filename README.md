**English** | [Русский](README.ru.md)

[![GitHub Release](https://img.shields.io/github/v/release/aVadim483/manticore-laravel-scout)](https://packagist.org/packages/avadim/manticore-laravel-scout)
[![Packagist Downloads](https://img.shields.io/packagist/dt/avadim/manticore-laravel-scout?color=%23aa00aa)](https://packagist.org/packages/avadim/manticore-laravel-scout)
[![GitHub License](https://img.shields.io/github/license/aVadim483/manticore-laravel-scout)](https://packagist.org/packages/avadim/manticore-laravel-scout)
[![Static Badge](https://img.shields.io/badge/php-%3E%3D7.4-005fc7)](https://packagist.org/packages/avadim/manticore-laravel-scout)
[![Static Badge](https://img.shields.io/badge/laravel-8%20--%2013-ff2d20)](https://packagist.org/packages/avadim/manticore-laravel-scout)
[![Static Badge](https://img.shields.io/badge/scout-9%20--%2011-ff2d20)](https://packagist.org/packages/avadim/manticore-laravel-scout)

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

* [Requirements](#requirements)
* [Installation](#installation)
* [Configuration](#configuration)
* [Quick start](#quick-start)
* [The schema of an index](#the-schema-of-an-index)
* [What a search understands](#what-a-search-understands)
* [Options of a query](#options-of-a-query)
* [The query language of Manticore](#the-query-language-of-manticore)
* [Soft deletes](#soft-deletes)
* [Artisan commands](#artisan-commands)
* [Limits worth knowing](#limits-worth-knowing)
* [Tests](#tests)

## Requirements

* PHP >= 7.4
* Laravel 8 - 13 (or Lumen of the same generation), Laravel Scout 9 - 11
* ManticoreSearch with the MySQL protocol open (port 9306 by default)
* [`avadim/manticore-query-builder-laravel`](https://github.com/aVadim483/manticore-query-builder-laravel) >= 2.0

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

Everything the driver reads lives in the `manticore` section of `config/scout.php`. Its defaults are
merged in by the service provider, so only the keys you change have to be written down:

| Key | Env | Default | Meaning |
|---|---|---|---|
| `connection` | `SCOUT_MANTICORE_CONNECTION` | `null` | name of a connection of `config/manticore.php`; null takes the default one |
| `limit` | `SCOUT_MANTICORE_LIMIT` | `1000` | the limit of a search that says nothing about it - the server would answer with 20 rows |
| `max_matches` | `SCOUT_MANTICORE_MAX_MATCHES` | `null` | rows the server keeps per query, i.e. how deep paging goes and how far `total()` counts |
| `escape_query` | `SCOUT_MANTICORE_ESCAPE_QUERY` | `true` | escape the phrase, so that what a user typed is searched for as it was written |
| `auto_create` | `SCOUT_MANTICORE_AUTO_CREATE` | `true` | create the index on the first write to it |
| `auto_columns` | `SCOUT_MANTICORE_AUTO_COLUMNS` | `true` | add a column the index is missing and write again |
| `schemas` | - | `[]` | schemas of the indexes, by index name |

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

**3. The values.** With neither of the two, the index is built out of the first rows written to it:
a string becomes a `text` field, an integer a `bigint`, a float a `float`, a bool a `bool`, an array
a `json`. Good enough to start with, and enough of a guess that a column you filter or sort by is
better described by hand - see the limits below.

A column added to `toSearchableArray()` later is added to the index as well (`auto_columns`), and
the rows written before it keep an empty value for it until they are imported again.

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
beyond that is the limit itself until `max_matches` is raised.

**The schema cache lives as long as the connection.** In Octane or a queue worker that is a long
time; a table changed elsewhere calls for `\ManticoreDb::forgetSchema()`.

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
