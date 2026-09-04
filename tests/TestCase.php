<?php

declare(strict_types=1);

namespace avadim\Manticore\Scout\Tests;

use avadim\Manticore\Laravel\Manager;
use avadim\Manticore\Laravel\ServiceProvider as ManticoreServiceProvider;
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\Scout\ManticoreEngine;
use avadim\Manticore\Scout\ServiceProvider as ScoutManticoreServiceProvider;
use avadim\Manticore\Scout\Tests\Support\Post;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\ScoutServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Base test case: boots a minimal Laravel application with Scout and both Manticore packages.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Name of the Manticore connection used in tests
     */
    protected const CONNECTION = 'testing';

    /**
     * The version of ManticoreSearch a vector column and knn() need
     */
    protected const VECTOR_SEARCH_SINCE = '6.3';

    /**
     * Cached result of the server availability check
     *
     * @var bool|null
     */
    private static $serverAvailable;

    /**
     * Cached version of the server
     *
     * @var string|null
     */
    private static $serverVersion;

    /**
     * Names of the Manticore tables to drop after the test
     *
     * @var array
     */
    protected $createdIndexes = [];

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array
     */
    protected function getPackageProviders($app): array
    {
        return [
            ManticoreServiceProvider::class,
            ScoutServiceProvider::class,
            ScoutManticoreServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return void
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('scout.driver', 'manticore');
        $app['config']->set('scout.queue', false);
        $app['config']->set('scout.manticore.connection', static::CONNECTION);

        $app['config']->set('manticore', [
            'defaultConnection' => static::CONNECTION,
            'connections' => [
                static::CONNECTION => [
                    'host'         => $this->serverHost(),
                    'port'         => $this->serverPort(),
                    'username'     => null,
                    'password'     => null,
                    'timeout'      => 5,
                    'prefix'       => 'phpunit_',
                    'force_prefix' => false,
                ],
            ],
        ]);
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->text('body')->nullable();
            $table->integer('author_id')->default(0);
            $table->timestamp('deleted_at')->nullable();
        });

        Post::$searchableAs = $this->indexName('posts');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->createdIndexes as $index) {
            try {
                ManticoreDb::connection(static::CONNECTION)->drop($index, true);
            }
            catch (\Throwable $e) {
                // the server is gone or the table never existed - nothing to clean up then
            }
        }
        $this->createdIndexes = [];

        // the static builder keeps its state between tests
        ManticoreDb::setLogger(null);
        ManticoreDb::init([]);

        parent::tearDown();
    }

    /**
     * A unique name of a Manticore table, dropped when the test is over
     *
     * @param string $suffix
     *
     * @return string
     */
    protected function indexName(string $suffix = ''): string
    {
        $name = 'phpunit_' . uniqid() . ($suffix ? '_' . $suffix : '');
        $this->createdIndexes[] = $name;

        return $name;
    }

    /**
     * @return string
     */
    protected function serverHost(): string
    {
        return getenv('MANTICORE_TEST_HOST') ?: '127.0.0.1';
    }

    /**
     * @return int
     */
    protected function serverPort(): int
    {
        return (int)(getenv('MANTICORE_TEST_PORT') ?: 9306);
    }

    /**
     * Skip the test when no ManticoreSearch server is listening.
     * Any call that resolves a connection opens a real PDO connection, hence the check.
     *
     * @return void
     */
    protected function requiresServer(): void
    {
        if (null === self::$serverAvailable) {
            $socket = @fsockopen($this->serverHost(), $this->serverPort(), $errno, $errstr, 1);
            self::$serverAvailable = (bool)$socket;
            if ($socket) {
                fclose($socket);
            }
        }

        if (!self::$serverAvailable) {
            $this->markTestSkipped(sprintf(
                'No ManticoreSearch server at %s:%d (set MANTICORE_TEST_HOST/MANTICORE_TEST_PORT to change it).',
                $this->serverHost(),
                $this->serverPort()
            ));
        }
    }

    /**
     * What the server names itself by: the version, and the libraries it loaded
     *
     * @return string
     */
    protected function serverBanner(): string
    {
        if (null === self::$serverVersion) {
            // "28.6.6 e5feb9932@26073104 (columnar 13.8.3 ...) (secondary ...) (knn 13.8.3 ...)"
            $rows = ManticoreDb::connection(static::CONNECTION)->select("SHOW STATUS LIKE 'version'");

            self::$serverVersion = (string)($rows[0]['Value'] ?? '');
        }

        return self::$serverVersion;
    }

    /**
     * The version the server names itself by
     *
     * @return string
     */
    protected function serverVersion(): string
    {
        return preg_match('/^\d+(\.\d+)*/', $this->serverBanner(), $m) ? $m[0] : '0';
    }

    /**
     * Skip the test when the server cannot hold a vector.
     *
     * The version is not the whole of it: the KNN of Manticore lives in a library of its own, and
     * a build without it parses a float_vector column and then answers "knn library not loaded".
     * The banner of the server names what it did load.
     *
     * @return void
     */
    protected function requiresVectorSearch(): void
    {
        $this->requiresServer();

        if (!preg_match('/\(knn\s/i', $this->serverBanner())) {
            $this->markTestSkipped(sprintf(
                'A float_vector column and knn() need the KNN library of ManticoreSearch %s and above, '
                . 'and the server does not report one: %s',
                static::VECTOR_SEARCH_SINCE,
                $this->serverBanner() ?: 'no version'
            ));
        }
    }

    /**
     * An engine of its own, to be given a config the application does not have
     *
     * @param array $config keys of the scout.manticore section to override
     *
     * @return \avadim\Manticore\Scout\ManticoreEngine
     */
    protected function engine(array $config = []): ManticoreEngine
    {
        return new ManticoreEngine(
            $this->app->make(Manager::class),
            array_merge((array)config('scout.manticore'), $config),
            (bool)config('scout.soft_delete')
        );
    }

    /**
     * A row written both to the database and, through Scout, to the index
     *
     * @param array $attributes
     *
     * @return \avadim\Manticore\Scout\Tests\Support\Post
     */
    protected function makePost(array $attributes = []): Post
    {
        return Post::create(array_merge([
            'title'     => 'Untitled',
            'body'      => '',
            'author_id' => 0,
        ], $attributes));
    }

}
