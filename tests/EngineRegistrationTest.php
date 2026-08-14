<?php

declare(strict_types=1);

namespace avadim\Manticore\Scout\Tests;

use avadim\Manticore\Laravel\Manager;
use avadim\Manticore\Scout\ManticoreEngine;
use avadim\Manticore\Scout\Tests\Support\Post;
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

    public function testTheEngineDoesNotOpenAConnectionUntilItIsAsked(): void
    {
        // a connection opens a PDO socket in its constructor, so an application whose Manticore
        // is down must still boot and resolve the engine
        $this->app->make(EngineManager::class)->engine('manticore');

        $this->assertSame([], $this->app->make(Manager::class)->getConnections());
    }
}
