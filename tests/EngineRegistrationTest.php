<?php

declare(strict_types=1);

namespace avadim\Manticore\Scout\Tests;

use avadim\Manticore\Laravel\Manager;
use avadim\Manticore\Scout\ManticoreEngine;
use avadim\Manticore\Scout\ServiceProvider as ScoutManticoreServiceProvider;
use avadim\Manticore\Scout\Tests\Support\Post;
use Illuminate\Config\Repository;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;

/**
 * The driver is where Scout looks for it, and its config has the defaults of the package.
 */
class EngineRegistrationTest extends TestCase
{
    public function testTheDriverIsRegisteredUnderTheNameManticore(): void
    {
        $engine = $this->app->make(EngineManager::class)->engine('manticore');

        $this->assertInstanceOf(ManticoreEngine::class, $engine);
    }

    public function testAModelSearchesThroughTheDriver(): void
    {
        $this->assertInstanceOf(ManticoreEngine::class, (new Post())->searchableUsing());
    }

    public function testTheDefaultsOfThePackageAreMergedIntoTheScoutConfig(): void
    {
        $config = config('scout.manticore');

        $this->assertSame(1000, (int)$config['limit']);
        $this->assertTrue((bool)$config['escape_query']);
        $this->assertSame([], $config['schemas']);
        $this->assertArrayHasKey('max_matches', $config);
    }

    public function testTheApplicationKeepsItsOwnValuesOfTheSection(): void
    {
        // set by getEnvironmentSetUp(), i.e. before the provider merged its defaults in
        $this->assertSame(static::CONNECTION, config('scout.manticore.connection'));
    }

    public function testScoutIsNotBuiltUntilSomethingAsksForIt(): void
    {
        // the driver adds itself to the engines of Scout as the manager of them is built, rather
        // than by building one on every request of an application that may never search
        $this->assertFalse($this->app->resolved(EngineManager::class));
    }

    public function testTheEngineDoesNotOpenAConnectionUntilItIsAsked(): void
    {
        // a connection opens a PDO socket in its constructor, so an application whose Manticore
        // is down must still boot and resolve the engine
        $this->app->make(EngineManager::class)->engine('manticore');

        $this->assertSame([], $this->app->make(Manager::class)->getConnections());
    }

    public function testTheConfigIsPublishedUnderTheNameOfTheSectionItIsReadFrom(): void
    {
        $paths = ServiceProvider::pathsToPublish(ScoutManticoreServiceProvider::class, 'config');

        $this->assertCount(1, $paths);
        $this->assertSame('scout.manticore.php', basename(reset($paths)));
    }

    public function testAPublishedConfigLandsInTheScoutSectionRatherThanBesideIt(): void
    {
        $paths = ServiceProvider::pathsToPublish(ScoutManticoreServiceProvider::class, 'config');
        $source = (string)array_key_first($paths);
        $key = basename((string)reset($paths), '.php');

        // the config loader of Laravel takes the key of a file from its name and sets it with the
        // dot notation, which is the whole reason the file is named with a dot in it
        $config = new Repository(['scout' => ['driver' => 'manticore', 'manticore' => []]]);
        $config->set($key, require $source);

        $this->assertSame(1000, (int)$config->get('scout.manticore.limit'));
        $this->assertSame('manticore', $config->get('scout.driver'));
    }

    public function testTheSectionOfTheFileIsLoadedAfterTheFileOfScoutItself(): void
    {
        // the loader sorts the files naturally and writes them in that order, so "scout" has to
        // come first - the other way round it would overwrite the section with itself
        $files = ['scout.manticore' => 'scout.manticore.php', 'scout' => 'scout.php'];
        ksort($files, SORT_NATURAL);

        $this->assertSame(['scout', 'scout.manticore'], array_keys($files));
    }
}
