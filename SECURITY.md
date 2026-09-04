# Security

## Reporting a vulnerability

Please do not open a public issue for a vulnerability. Use the private path instead — the
[security advisories](https://github.com/aVadim483/manticore-laravel-scout/security/advisories/new)
of the repository, which reach the maintainer and nobody else.

What helps: the version of the package, what an attacker can reach through it, and the smallest
example that shows it. An answer follows as soon as the report is read; a fix is released as soon
as there is one to release, and the advisory names whoever reported it unless they would rather it
did not.

## What is supported

Fixes go into the current major of the package, which is the one on
[Packagist](https://packagist.org/packages/avadim/manticore-laravel-scout). An older major is
looked at when the issue is serious enough that an application cannot reasonably upgrade first.

## What is in scope

This package translates what Laravel Scout asks for into statements of ManticoreSearch, through
[`avadim/manticore-query-builder-laravel`](https://github.com/aVadim483/manticore-query-builder-laravel).
A value that reaches the server unescaped, a phrase of a search that changes the shape of a
statement rather than being searched for, or a query of one tenant reaching the rows of another
are the kind of thing this repository is the right place for.

The escaping of statements themselves lives in the query builder, so a report about it belongs in
[`avadim/manticore-query-builder-php`](https://github.com/aVadim483/manticore-query-builder-php) —
send it wherever it seems to fit, it will be passed on.

The server itself is not in scope: ManticoreSearch has
[its own](https://manticoresearch.com/) way of taking reports. Neither is an installation that
leaves port 9306 open to the world — the MySQL protocol of Manticore has no authentication of its
own, and the network is what keeps it private.
