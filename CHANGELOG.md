# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[2.0.0]: https://github.com/aVadim483/manticore-laravel-scout/releases/tag/v2.0.0
