# Contributing

Thanks for taking the time. Issues and pull requests are welcome, and so is a question about
whether something is a bug before it is written up as one.

## Reporting a bug

What makes a report actionable, roughly in the order it helps:

* the versions: PHP, Laravel, Laravel Scout, ManticoreSearch, and this package;
* the statement the server was given and what it answered — the query builder logs both when
  `MANTICORE_LOG_CHANNEL` names a channel of the application, see the readme of
  [`avadim/manticore-query-builder-laravel`](https://github.com/aVadim483/manticore-query-builder-laravel);
* the schema of the index: `SHOW CREATE TABLE <index>`;
* the model side of it: `toSearchableArray()`, and a `manticoreSchema()` if the model has one.

A search that answers with the wrong rows is worth a report as much as one that raises: what a
driver is for is the translation between the two APIs, and the wrong translation is the bug.

## Running the tests

```sh
composer install
composer test
```

Most of the suite talks to a real ManticoreSearch over the MySQL protocol, and skips itself when
there is none listening:

```sh
docker run --rm -p 9306:9306 manticoresearch/manticore:latest
```

The address is `MANTICORE_TEST_HOST` / `MANTICORE_TEST_PORT` of `phpunit.xml.dist`. Tables of a test
are named `phpunit_<uniqid>_*` and dropped when it is over, so an existing server is safe to run
them against — although a server of your own is safer still.

## Before opening a pull request

```sh
composer test        # the suite
composer analyse     # phpstan, level 5 over src
composer style       # the style of the sources
```

The style is checked by [PHP CS Fixer](https://cs.symfony.com/), which is not a dependency of the
package: its own requirements would hold back the Symfony that the framework of a test brings with
it. Install it as a tool of its own — `composer global require friendsofphp/php-cs-fixer`, or the
phar — and `composer style` will find it. The CI takes it from `shivammathur/setup-php`.

The rules are in `.php-cs-fixer.dist.php`: PSR-12, with `else` and `catch` opening a line of their
own the way the sources are written. `php-cs-fixer fix` writes the changes rather than listing them.

## What a change looks like here

* A comment says *why*, where the code already says *what*. The sources are written that way
  throughout, and a patch that keeps it reads like the rest of them.
* A fix comes with the test that fails without it. A test that needs no server is worth more than
  one that does, when the same thing can be shown either way.
* `CHANGELOG.md` gets an entry under `[Unreleased]`, in the words of what changed for whoever uses
  the package rather than of what was edited.
* The readme has two versions, English and Russian. A change to one of them that belongs in the
  other is better made in both, but an English-only patch is still welcome — the translation can
  follow.
