# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Breaking

* **Requires `avadim/manticore-query-builder-laravel` 3.0, and with it Laravel 11 and PHP 8.2.**
  The wrapper this driver stands on narrowed its own range to `illuminate/* ^11.0|^12.0|^13.0`
  and `php ^8.2`, so anything below that became unreachable to composer here whatever this
  package declared. The ranges say it now: `php ^8.2`, `illuminate/* ^11.0|^12.0|^13.0`.

  An application on an older Laravel is not broken by this — composer never re-resolves a
  framework that is already installed, so it keeps installing the 2.x line of the family.

* **Requires Laravel Scout 11.** The range said `^9.0|^10.0|^11.0`, and only the last of the
  three ever worked: the driver reads `$builder->wheres` as a list of `['field', 'operator',
  'value']`, which is the shape Scout gave it in 11.0.0 — before that it is a dictionary of
  `[field => value]`, so every `where()` of a search raised an error, and so did the
  `__soft_deleted` the soft delete of Scout adds by itself. Scout 9 is out of reach anyway now
  (it asks for `illuminate/* ^8.0|^9.0|^10.0`), and Scout 11 covers every Laravel of the range
  above, so nothing of what is supported is left behind by narrowing to `^11.0`.

* The ranges of the dev dependencies follow the supported Laravel versions:
  `orchestra/testbench ^9.0|^10.0|^11.0` and `phpunit/phpunit ^10.5|^11.0|^12.0|^13.0`.

### Added

* **Semantic and hybrid search.** The engine answers to `SupportsSemanticSearch` of Scout, so
  `->semantic()` and `->hybrid()` of a search are its own rather than an exception: the first
  searches by the vector alone (`WHERE knn(<column>, <k>, ...)`), the second asks for the vector
  and the words at once, which Manticore takes in one statement — a hybrid search here is one
  query, not two and a merge of the answers. It is ranked by
  `<text weight> * weight() + <semantic weight> * (1 - knn_dist())`, and `->semantic($min)` filters
  by the similarity a row has to reach. The distance, the similarity and the blended score come
  back as `_knn_dist`, `_similarity` and `_hybrid_score` of `scoutMetadata()`.

  What turns the phrase of a search into a vector is the `semantic.embedder` of the config — a
  callable, or the name of an invokable class the container builds — next to the vector column and
  the number of neighbours to ask the server for. A query overrides all three through
  `options(['semantic' => [...]])`.

* **`php artisan scout:sync-index-settings`.** The engine answers to `UpdatesIndexSettings` of
  Scout, so the command of the framework brings an index in line with the schema it is described
  by: a column the index does not have is added and the options of the table are applied, while a
  column that is there keeps its type — Manticore cannot change one in place without losing what is
  written in it. The command walks `scout.manticore.index-settings`, and when that is empty, the
  indexes named in `schemas`, so a schema written down once is not repeated for it. `scout:index`
  does the same for the one index it is given.

* `batch_size` — the rows of one `REPLACE`, 100 by default. `scout:import` hands over 500 models at
  a time, and a statement carrying that many rows of text runs into `max_packet_size` of the
  server, which drops the connection instead of naming a reason. 0 turns the limit off.

* Continuous integration: the whole suite runs against a ManticoreSearch service container on
  every Laravel the package declares — 11 on PHP 8.2, 12 on 8.3, 13 on 8.4 and on 8.5. Both ends
  of the range are checked rather than asserted, which is what the range of a driver is worth.
* `.gitattributes` — the tests and the CI config are no longer part of the package: they are read
  on GitHub, not from `vendor/`. Line endings are pinned to LF as well, so that a file does not
  depend on the machine it was committed from.

* Static analysis and style are checked by the CI as well: `phpstan` at level 5 over `src`, and
  `php-cs-fixer` against `.php-cs-fixer.dist.php` — PSR-12 with the habits of these sources kept,
  `else` and `catch` opening a line of their own. `composer analyse` and `composer style` run the
  two locally. The fixer is a tool of the job rather than a dependency of the package: its own
  requirements would hold back the Symfony that the framework of a test brings with it.

* `CONTRIBUTING.md`, `SECURITY.md` and the issue templates: what makes a report of a wrong answer
  actionable, how to run the suite against a server of your own, and where a vulnerability goes
  instead of into a public issue.

### Changed

* **The driver is added to the engines of Scout as the manager of them is built**, rather than by
  building one in `boot()`. An application that never searches no longer builds the engine manager
  of Scout on every request — and one that had built it before this provider booted is still
  extended, which is what the two branches are for.

* The sources are written for the PHP 8.2 the package now requires: the properties of the engine
  carry their types instead of a `@var` line, and `str_starts_with()` stands where a `strpos()`
  compared against zero.

* `composer.json` names its author and where an issue or the sources are, which is what Packagist
  shows on the page of the package.

* The readme says what the engine does with a call it does not know: it hands it to the connection
  of the query builder, which is how the transactions, `tableDescribe()` and the meta of the search
  that has just run — `lastResultSet()->facets()` — are reached through it.

### Fixed

* A published config is read at last. It went to `config/manticore-scout.php`, which Laravel loads
  under a key of its own (`manticore-scout`), while the driver reads `scout.manticore` — so a
  published file was dead weight and every key edited in it was quietly ignored. It is published as
  `config/scout.manticore.php` now: the config loader takes the key of a file from its name and
  sets it with the dot notation, so the file lands in the `manticore` section of `scout` as it was
  meant to. A `config/manticore-scout.php` published earlier can be renamed to
  `config/scout.manticore.php` — its values start being read once it is.

* **A `max_matches` of the config is the depth of every query.** It was compared against the
  default of the server rather than against itself, so a value below 1000 — a deliberate one, since
  the key exists to be set — was left as it was by a page that reached beyond it, and the server
  answered the page with an error.

* **A key that is not a document id is an error on a removal too.** A write says so and names the
  model; a removal quietly dropped such a key instead, which left the row of a deleted model in the
  index with nobody to hear of it.

* **The answer of the server is read without the statement it is about.** Whether an index is
  missing, and which column is, is read out of what the server said — while the message a client
  raises can carry the statement in front of it, and the statement carries the phrase a user typed.
  A search for "no such table" is a search, not an index that is not there.

* Three fixes of the query builder 2.2 arrive with the wrapper, and they are the driver's as much
  as anyone's: a column named after a PHP function is a column again, so `where('date', ...)`,
  `where('time', ...)` and `where('count', ...)` of a search reach the server instead of calling
  the function; a statement the server refused is never reported as a successful empty answer,
  which is the path `auto_create` and `auto_columns` stand on; and a value that looks like
  `:word` is escaped rather than read as a named parameter, so a phrase a user typed cannot break
  the statement.

## [2.1.0] - 2026-08-15

### Changed

* Requires `avadim/manticore-query-builder-laravel` 2.1, and through it the query builder 2.1,
  where the `CALL *` statements became methods — `callSuggest()`, `callQsuggest()`,
  `callKeywords()`, `callSnippets()` and `callPq()`. The driver does not use them itself; the
  minimum is raised so that an application installing it into a project that already holds the
  2.0 wrapper gets them rather than an "undefined method" of
  `\ManticoreDb::table('?posts')->callSuggest(...)`.

## [2.0.0] - 2026-08-15

The first release. It starts at 2.0.0 rather than at 1.0.0 so that the three packages of the
family carry the same major number: this driver stands on `manticore-query-builder-laravel` 2.0
and, through it, on `manticore-query-builder-php` 2.0.

### Added

- `ManticoreEngine`, the Scout driver for ManticoreSearch: reads, writes, paging and the mapping of
  the answers back onto Eloquent models.
- `ServiceProvider`, which registers the driver under the name `manticore` and merges the defaults
  of the package into the `manticore` section of `config/scout.php`.
- Full-text search of `Post::search(...)` with the `where()`, `whereIn()`, `whereNotIn()`,
  `orderBy()`, `take()`, `paginate()`, `simplePaginate()`, `keys()` and `cursor()` of Scout.
- The phrase of a search is escaped by default, so what a user typed is searched for as it was
  written; `options(['escape' => false])` and `escape_query` of the config pass the query language
  of Manticore through instead.
- Options of a query: `fields` (the full-text fields to search in), `highlight`, and anything else
  as the `OPTION` clause of the SELECT - `ranker`, `field_weights`, `cutoff`.
- The callback of `Model::search()` gets the query of the builder itself, so a query can reach
  everything the driver does not cover (`whereKnn()`, `facet()`, ...).
- The index is created on the first write to it, out of the schema of the config, of a
  `manticoreSchema()` of the model, or of the values written (`auto_create`); a column added to
  `toSearchableArray()` later is added to the index as well (`auto_columns`).
- Support of `scout.soft_delete`: a trashed model stays in the index behind `__soft_deleted`, and
  `withTrashed()` / `onlyTrashed()` reach it.
- `createIndex()`, `deleteIndex()`, `flush()` and `deleteAllIndexes()`, i.e. the `scout:index`,
  `scout:delete-index`, `scout:flush` and `scout:delete-all-indexes` commands.
- A deep page raises `max_matches` on its own, so paging does not break beyond the 1000 rows the
  server keeps per query by default.
- `_score` of the ranker and `_highlight` of a highlighted search come back as
  `$model->scoutMetadata()`.

### Notes

- The package requires `avadim/manticore-query-builder-laravel` 2.0, where reads answer with a
  `Collection` of `Row` objects and a rejected read throws instead of answering with `null`.
- Tested against Laravel 13, Scout 11 and ManticoreSearch 28 on PHP 8.4. The declared range is
  wider (PHP 7.4+, Laravel 8 - 13, Scout 9 - 11) and rests on the constraints of the packages
  themselves, not on a run of the test suite.

[Unreleased]: https://github.com/aVadim483/manticore-laravel-scout/compare/v2.1.0...HEAD
[2.1.0]: https://github.com/aVadim483/manticore-laravel-scout/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/aVadim483/manticore-laravel-scout/releases/tag/v2.0.0
