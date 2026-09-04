[English](README.md) | **Русский**

[![GitHub Release](https://img.shields.io/github/v/release/aVadim483/manticore-laravel-scout)](https://packagist.org/packages/avadim/manticore-laravel-scout)
[![Packagist Downloads](https://img.shields.io/packagist/dt/avadim/manticore-laravel-scout?color=%23aa00aa)](https://packagist.org/packages/avadim/manticore-laravel-scout)
[![GitHub License](https://img.shields.io/github/license/aVadim483/manticore-laravel-scout)](https://packagist.org/packages/avadim/manticore-laravel-scout)
[![Static Badge](https://img.shields.io/badge/php-%3E%3D8.2-005fc7)](https://packagist.org/packages/avadim/manticore-laravel-scout)
[![Static Badge](https://img.shields.io/badge/laravel-11%20--%2013-ff2d20)](https://packagist.org/packages/avadim/manticore-laravel-scout)
[![Static Badge](https://img.shields.io/badge/scout-11-ff2d20)](https://packagist.org/packages/avadim/manticore-laravel-scout)
[![tests](https://github.com/aVadim483/manticore-laravel-scout/actions/workflows/tests.yml/badge.svg)](https://github.com/aVadim483/manticore-laravel-scout/actions/workflows/tests.yml)

# Драйвер ManticoreSearch для Laravel Scout

Полнотекстовый поиск по моделям Eloquent в [ManticoreSearch](https://manticoresearch.com/) через
API [Laravel Scout](https://laravel.com/docs/scout): `Post::search('manticore')->get()`.

```sh
composer require avadim/manticore-laravel-scout
```

Драйвер — тонкий слой поверх
[`avadim/manticore-query-builder-laravel`](https://github.com/aVadim483/manticore-query-builder-laravel):
соединение, пул и генерация SQL живут там, там же и `config/manticore.php`. Этот пакет переводит
запрос Scout в запрос билдера и отображает ответ обратно на модели.

## Содержание

* [Смежные пакеты](#смежные-пакеты)
* [Требования](#требования)
* [Установка](#установка)
* [Конфигурация](#конфигурация)
* [Быстрый старт](#быстрый-старт)
* [Схема индекса](#схема-индекса)
* [Приведение индекса к схеме](#приведение-индекса-к-схеме)
* [Что понимает поиск](#что-понимает-поиск)
* [Опции запроса](#опции-запроса)
* [Семантический и гибридный поиск](#семантический-и-гибридный-поиск)
* [Язык запросов Manticore](#язык-запросов-manticore)
* [Мягкое удаление](#мягкое-удаление)
* [Команды artisan](#команды-artisan)
* [Ограничения, о которых стоит знать](#ограничения-о-которых-стоит-знать)
* [Тесты](#тесты)

## Смежные пакеты

* [`avadim/manticore-query-builder-laravel`](https://github.com/aVadim483/manticore-query-builder-laravel) —
  интеграция с Laravel, на которой стоит драйвер и откуда он берёт соединение:
  `config/manticore.php`, именованные соединения, алиас `\ManticoreDb` и фасад. Нужен всякий раз,
  когда запросу мало возможностей API Scout.
* [`avadim/manticore-query-builder-php`](https://github.com/aVadim483/manticore-query-builder-php) —
  сам построитель запросов, без зависимостей от Laravel: синтаксис запроса и DSL схемы живут там,
  как и всё, во что драйвер переводит поиск.

## Требования

* PHP >= 8.2
* Laravel 11 — 13 (или Lumen того же поколения), Laravel Scout 11
* ManticoreSearch с открытым протоколом MySQL (по умолчанию порт 9306)
* [`avadim/manticore-query-builder-laravel`](https://github.com/aVadim483/manticore-query-builder-laravel) >= 3.0

## Установка

```sh
composer require avadim/manticore-laravel-scout
```

Оба сервис-провайдера Laravel находит сам. Опубликуйте конфиг пакета-билдера и, если хотите иметь
дефолты драйвера в явном виде, конфиг этого пакета:

```sh
php artisan vendor:publish --provider="avadim\Manticore\Laravel\ServiceProvider" --tag=config
php artisan vendor:publish --provider="avadim\Manticore\Scout\ServiceProvider" --tag=config
```

Вторая команда кладёт файл `config/scout.manticore.php`, и точка в имени — не опечатка: загрузчик
конфигов Laravel берёт ключ файла из его имени и записывает его через точечную нотацию, поэтому
файл читается как секция `manticore` файла `config/scout.php`, а не как отдельный конфиг.
Публиковать его необязательно — те же ключи можно вписать в `config/scout.php` руками, и в обоих
случаях написанное перекрывает дефолты пакета.

В Lumen провайдеры регистрируются в `bootstrap/app.php`, а файлы конфигов копируются вручную.

## Конфигурация

Соединение берётся из `config/manticore.php`:

```php
'connections' => [
    'default' => [
        'host'         => env('MANTICORE_HOST', '127.0.0.1'),
        'port'         => env('MANTICORE_PORT', 9306),
        // ...
    ],
],
```

Самому Scout нужны две строки в `.env`:

```dotenv
SCOUT_DRIVER=manticore
SCOUT_QUEUE=true
```

Всё, что читает драйвер, лежит в секции `manticore` файла `config/scout.php` — вписанной туда
руками либо опубликованной как `config/scout.manticore.php`, который Laravel читает в ту же секцию.
Дефолты подмешивает сервис-провайдер, поэтому прописывать нужно только изменяемые ключи:

| Ключ | Env | По умолчанию | Значение |
|---|---|---|---|
| `connection` | `SCOUT_MANTICORE_CONNECTION` | `null` | имя соединения из `config/manticore.php`; null — соединение по умолчанию |
| `limit` | `SCOUT_MANTICORE_LIMIT` | `1000` | лимит поиска, в котором лимит не задан — сервер иначе отдаёт 20 строк |
| `max_matches` | `SCOUT_MANTICORE_MAX_MATCHES` | `null` | сколько строк сервер держит на запрос: глубина пагинации и точность `total()` |
| `escape_query` | `SCOUT_MANTICORE_ESCAPE_QUERY` | `true` | экранировать фразу, чтобы искалось ровно то, что ввёл пользователь |
| `auto_create` | `SCOUT_MANTICORE_AUTO_CREATE` | `true` | создавать индекс при первой записи в него |
| `auto_columns` | `SCOUT_MANTICORE_AUTO_COLUMNS` | `true` | добавлять недостающую колонку и повторять запись |
| `batch_size` | `SCOUT_MANTICORE_BATCH_SIZE` | `100` | строк в одном `REPLACE`; 0 отключает ограничение |
| `schemas` | — | `[]` | схемы индексов, по именам индексов |
| `index-settings` | — | `[]` | индексы, которые обходит `scout:sync-index-settings`; пусто — те, что в `schemas` |
| `semantic` | — | см. ниже | векторная колонка, число соседей и эмбеддер для `semantic()` и `hybrid()` |

## Быстрый старт

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

Сохранение модели пишет её в индекс, удаление — убирает; это делает наблюдатель Scout, а не этот
пакет. `SCOUT_QUEUE=true` переносит и то, и другое в очередь.

## Схема индекса

Своей схемы «по умолчанию» у Manticore нет: запись в несуществующую таблицу — ошибка, как и колонка,
которой в таблице нет. Драйвер решает это тремя способами, в таком порядке.

**1. Конфиг.** Описывается один раз, как миграция:

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

Именно это создаёт `php artisan scout:index posts`; без описания команда сообщает, куда положить
схему, вместо того чтобы создать пустую таблицу.

**2. Модель.** Метод `manticoreSchema()` держит схему рядом с `toSearchableArray()`:

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

**3. Значения.** Если нет ни того, ни другого, индекс строится по первым записанным строкам: строка
становится полнотекстовым полем `text`, целое — `bigint`, дробное — `float`, логическое — `bool`,
массив — `json`. Для старта этого хватает, но угадывание есть угадывание: колонку, по которой вы
фильтруете или сортируете, лучше описать явно — см. ограничения ниже.

Колонка, добавленная в `toSearchableArray()` позже, добавляется и в индекс (`auto_columns`), а
записанные ранее строки остаются с пустым значением, пока их не переиндексируют.

## Приведение индекса к схеме

Схема меняется: в `toSearchableArray()` добавилась колонка, понадобился `min_infix_len`. Команда
Scout приводит индекс к тому, что записано в схеме:

```sh
php artisan scout:sync-index-settings
```

Она обходит индексы из `scout.manticore.index-settings`, а если там пусто — те, что названы в
`schemas`. Недостающая колонка добавляется, опции таблицы применяются, а колонка, которая уже есть,
сохраняет свой тип: Manticore не умеет менять тип на месте, не потеряв записанное, поэтому смена
типа — это новый индекс и импорт в него.

`index-settings` — это список имён индексов и классов моделей либо карта «имя → своя схема»,
которая перекрывает `schemas` ключ за ключом:

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

То же самое происходит с одним индексом при `php artisan scout:index posts`.

## Что понимает поиск

Билдер Scout невелик, и здесь работает всё, что в нём есть:

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

Без `orderBy()` строки приходят в том порядке, в каком их ранжировал сервер, и каждая модель несёт
вес своей строки:

```php
$post->scoutMetadata()['_score'];
```

`paginate()` и `simplePaginate()` отвечают штатными пагинаторами Laravel. Страница, уходящая за
пределы 1000 строк, которые Manticore держит на запрос, сама поднимает `max_matches` — поэтому
пагинация не ломается на 51-й странице.

## Опции запроса

`options()` билдера Scout сначала читает драйвер, а всё оставшееся уходит в секцию `OPTION` запроса
SELECT:

```php
Post::search('manticore')->options([
    'fields'        => ['title'],            // искать только в этих полнотекстовых полях
    'escape'        => false,                // пропустить язык запросов как есть, см. ниже
    'highlight'     => true,                 // или ['options' => [...], 'fields' => [...]]
    'ranker'        => 'sph04',              // OPTION ranker=sph04
    'field_weights' => ['title' => 10],      // OPTION field_weights=(title=10)
    'cutoff'        => 1000,                 // OPTION cutoff=1000
])->get();
```

Подсветка возвращается метаданными модели:

```php
$post->scoutMetadata()['_highlight'];
```

Всё, чего драйвер не покрывает, доступно через колбэк `search()` — он получает сам запрос билдера:

```php
use avadim\Manticore\QueryBuilder\Query;

Post::search('manticore', function (Query $query, string $phrase) {
    return $query->whereKnn('embedding', 5, $vector)->facet('author_id');
})->get();
```

Верните запрос — драйвер его выполнит, либо выполните сами и верните `ResultSet`.

## Семантический и гибридный поиск

Manticore ищет не только по словам, но и по векторам, причём принимает то и другое в одном
запросе — именно поэтому гибридный поиск здесь один запрос, а не два и слияние ответов.

Нужны две вещи: колонка `float_vector` в индексе, которая пишется вместе с моделью, и то, что
превращает фразу запроса в вектор.

```php
// config/scout.php
'manticore' => [
    'semantic' => [
        'column'   => 'embedding',
        'k'        => null,                          // сколько соседей запрашивать; null — сколько нужно странице
        'embedder' => \App\Search\Embedder::class,
    ],
],
```

Эмбеддер — это callable либо имя invokable-класса, который построит контейнер. Он получает фразу и
модель, а отвечает массивом чисел:

```php
class Embedder
{
    public function __invoke(string $phrase, $model): array
    {
        return $this->vectors->of($phrase);
    }
}
```

Колонке место в схеме индекса, а вектор описывается не одним лишь именем типа — отсюда форма схемы
в виде callable:

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

и в `toSearchableArray()` — как вектор самой модели:

```php
'embedding' => $this->embedding,     // массив float'ов
```

После этого работают оба поиска Scout:

```php
Post::search('фрукт, от которого не болеют')->semantic()->get();
Post::search('яблоко')->semantic(0.8)->get();
Post::search('яблоко')->hybrid(1, 2)->get();
```

`semantic()` ищет только по вектору: `WHERE knn(embedding, k, (…))`, а `semantic(0.8)` добавляет
порог похожести, до которого строке нужно дотянуться. `hybrid($textWeight, $semanticWeight)`
запрашивает и то и другое сразу, причём `MATCH()` остаётся отдельным условием — гибридный поиск
не выходит за пределы строк, в которых есть слова, и ранжирует их по
`<вес текста> * weight() + <вес вектора> * (1 - knn_dist())`. Свой `orderBy()` при этом не трогается.

То, чем ответил сервер, лежит на модели рядом с остальными метаданными:

```php
$post->scoutMetadata()['_knn_dist'];       // расстояние, 0 — это сам вектор
$post->scoutMetadata()['_similarity'];     // 1 - расстояние, то есть 0…1 для cosine-индекса
$post->scoutMetadata()['_hybrid_score'];   // по чему ранжировался гибридный поиск
```

Вес полнотекстового совпадения — это оценка ранкера, в тысячах, а похожесть — от 0 до 1, так что
веса `hybrid()` как раз и приводят их к одной шкале.

Настройки задаются и на один запрос, рядом с остальными опциями:

```php
Post::search('яблоко')->semantic()->options([
    'semantic' => ['column' => 'title_vector', 'k' => 100],
])->get();
```

`k` — это сколько соседей сервер рассматривает до того, как результат сузит что-то ещё: `where()`
или слова гибридного поиска режут именно эти `k` строк, а не весь индекс. Без явного значения `k`
берётся такой, какой нужен странице.

## Язык запросов Manticore

То, что пользователь ввёл в строку поиска, — это текст, а не выражение: дефис в `iPhone -Pro`
исключил бы «Pro», вертикальная черта превратилась бы в ИЛИ, а незакрытая кавычка заставила бы
сервер отвергнуть запрос целиком. Поэтому драйвер экранирует фразу, и поиск находит то, что было
написано.

Чтобы воспользоваться языком намеренно — альтернативы, фразы, операторы полей — отключите
экранирование, для запроса или в конфиге:

```php
Post::search('"quick brown fox"/2 -lazy')->options(['escape' => false])->get();
```

## Мягкое удаление

При включённом `scout.soft_delete` удалённая модель остаётся в индексе под флагом `__soft_deleted`,
и поиск Scout работает как везде:

```php
Post::search('manticore')->withTrashed()->get();
Post::search('manticore')->onlyTrashed()->get();
```

Колонку пишет и фильтрует драйвер; если схема индекса задана в конфиге, добавьте её сами —
`'__soft_deleted' => 'int'`.

## Команды artisan

Команды Scout работают так же, как с любым другим драйвером:

```sh
php artisan scout:import "App\Models\Post"     # записать в индекс все модели
php artisan scout:flush "App\Models\Post"      # очистить индекс, оставив таблицу
php artisan scout:index posts                  # создать индекс по схеме из конфига
php artisan scout:sync-index-settings          # добавить индексу то, чего ему не хватает по схеме
php artisan scout:delete-index posts           # удалить таблицу
php artisan scout:delete-all-indexes           # удалить все таблицы с префиксом scout.prefix
```

`scout:delete-all-indexes` при пустом `scout.prefix` дотянется до всех таблиц сервера, в том числе
чужих, — ровно так же ведут себя остальные драйверы Scout.

## Ограничения, о которых стоит знать

**Ключ модели должен быть положительным целым.** Идентификатор документа в Manticore — `bigint`,
поэтому UUID или строковый ключ отвергаются исключением с именем модели. Такой модели нужно
переопределить `getScoutKey()`, чтобы он возвращал целое.

**Колонка `text` ищется, но не фильтруется.** Это полнотекстовое поле Manticore: `search()` находит
в нём слова, а `where()` и `orderBy()` требуют атрибута — `int`, `bigint`, `float`, `bool`,
`string`, `timestamp`. Угаданная схема делает любую строку полем `text`, поэтому колонке, по которой
вы фильтруете, место в схеме конфига или модели.

**`total()` считает до `max_matches`.** По умолчанию сервер держит 1000 строк на запрос; всё, что
за этой границей, показывается как сама граница, пока `max_matches` не поднят. Значение из конфига
— это глубина каждого запроса, а не только той страницы, которая за неё выходит: страница глубже
поднимает её для себя сама.

**Кеш схемы живёт столько же, сколько соединение.** В Octane или воркере очереди это долго; если
таблицу изменили в обход этого соединения, нужен `\ManticoreDb::forgetSchema()` — либо
`forgetSchemas()` менеджера, который сбрасывает кеш всех построенных им соединений, а не только
соединения по умолчанию. Само соединение сбрасывается по имени через `purge()` и открывается
заново через `reconnect()` — это то, что нужно воркеру, у которого сервер закрыл хендл за ночь:

```php
use avadim\Manticore\Laravel\Manager;

app(Manager::class)->forgetSchemas();
app(Manager::class)->reconnect();
```

## Тесты

```sh
composer install
php -d xdebug.mode=off vendor/bin/phpunit
```

Тестам самого поиска нужен ManticoreSearch на `127.0.0.1:9306` (переменные `MANTICORE_TEST_HOST` /
`MANTICORE_TEST_PORT` в `phpunit.xml.dist`); без сервера они пропускаются. Таблицы тестов
называются `phpunit_<uniqid>_*` и удаляются после прогона.

## Лицензия

MIT
