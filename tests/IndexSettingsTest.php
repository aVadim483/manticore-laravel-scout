<?php

declare(strict_types=1);

namespace avadim\Manticore\Scout\Tests;

use Laravel\Scout\Contracts\UpdatesIndexSettings;

/**
 * The settings of an index: "php artisan scout:sync-index-settings" and what it does to a table
 * that is already there.
 */
class IndexSettingsTest extends TestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return void
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // the schemas the provider takes the indexes of the command from, see the first test
        $app['config']->set('scout.manticore.schemas', [
            'phpunit_schemas_default' => ['columns' => ['title' => 'text']],
        ]);
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresServer();
    }

    /**
     * The columns of an index, by name
     *
     * @param string $index
     *
     * @return array
     */
    protected function columnsOf(string $index): array
    {
        return array_column($this->app->make('manticore')->tableDescribe($index), 'Type', 'Field');
    }

    public function testTheEngineAnswersToTheContractOfScout(): void
    {
        $this->assertInstanceOf(UpdatesIndexSettings::class, $this->engine());
    }

    public function testTheIndexesOfTheSchemasAreWhatTheCommandWalksByDefault(): void
    {
        // nothing under index-settings means the indexes the application described anyway
        $this->assertSame(['phpunit_schemas_default'], config('scout.manticore.index-settings'));
    }

    public function testAnIndexThatIsNotThereIsCreatedOfItsSchema(): void
    {
        $index = $this->indexName('settings');

        $this->engine(['schemas' => [$index => ['columns' => ['title' => 'text', 'author_id' => 'int']]]])
            ->updateIndexSettings($index);

        $columns = $this->columnsOf($index);
        $this->assertSame('text', $columns['title'] ?? null);
        $this->assertSame('uint', $columns['author_id'] ?? null);
    }

    public function testAColumnTheIndexDoesNotHaveIsAdded(): void
    {
        $index = $this->indexName('settings');
        $engine = $this->engine(['schemas' => [$index => ['columns' => ['title' => 'text']]]]);
        $engine->updateIndexSettings($index);

        $this->engine(['schemas' => [$index => ['columns' => ['title' => 'text', 'rating' => 'float']]]])
            ->updateIndexSettings($index);

        $this->assertSame('float', $this->columnsOf($index)['rating'] ?? null);
    }

    public function testAColumnThatIsThereKeepsTheTypeItHas(): void
    {
        $index = $this->indexName('settings');
        $this->engine(['schemas' => [$index => ['columns' => ['title' => 'text']]]])->updateIndexSettings($index);

        // Manticore cannot change the type of a column without losing what is written in it
        $this->engine(['schemas' => [$index => ['columns' => ['title' => 'string']]]])->updateIndexSettings($index);

        $this->assertSame('text', $this->columnsOf($index)['title'] ?? null);
    }

    public function testTheOptionsOfTheTableAreApplied(): void
    {
        $index = $this->indexName('settings');

        $this->engine(['schemas' => [$index => [
            'columns' => ['title' => 'text'],
            'options' => ['min_infix_len' => 3],
        ]]])->updateIndexSettings($index);

        // the same schema again, this time against a table that is already there
        $this->engine(['schemas' => [$index => [
            'columns' => ['title' => 'text'],
            'options' => ['min_infix_len' => 4],
        ]]])->updateIndexSettings($index);

        $this->assertStringContainsString(
            "min_infix_len='4'",
            $this->app->make('manticore')->showCreateTable($index)
        );
    }

    public function testWhatTheCommandPassesWinsOverTheConfigAndTheRestIsTakenFromIt(): void
    {
        $index = $this->indexName('settings');

        $this->engine(['schemas' => [$index => ['columns' => ['title' => 'text', 'author_id' => 'int']]]])
            ->updateIndexSettings($index, ['columns' => ['rating' => 'float']]);

        $columns = $this->columnsOf($index);
        $this->assertSame('float', $columns['rating'] ?? null);
        $this->assertSame('text', $columns['title'] ?? null);
    }

    public function testTheSoftDeleteFlagIsMadeRoomForInTheSettings(): void
    {
        $engine = $this->engine();

        $this->assertSame(
            ['columns' => ['__soft_deleted' => 'int'], 'options' => []],
            $engine->configureSoftDeleteFilter()
        );

        $this->assertSame(
            ['columns' => ['title' => 'text', '__soft_deleted' => 'int'], 'options' => ['min_infix_len' => 3]],
            $engine->configureSoftDeleteFilter(['columns' => ['title' => 'text'], 'options' => ['min_infix_len' => 3]])
        );
    }

    public function testTheSchemaIsFoundByTheNameWithoutThePrefixOfScout(): void
    {
        config(['scout.prefix' => 'phpunit_prefixed_']);
        $index = 'phpunit_prefixed_' . uniqid();
        $this->createdIndexes[] = $index;

        // the command hands over the name of the table, the schema is written down without it
        $this->engine(['schemas' => [substr($index, strlen('phpunit_prefixed_')) => ['columns' => ['title' => 'text']]]])
            ->updateIndexSettings($index);

        $this->assertSame('text', $this->columnsOf($index)['title'] ?? null);
    }

    public function testAnIndexWithNoSchemaAnywhereSaysWhereToPutOne(): void
    {
        $index = $this->indexName('settings');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('scout.manticore.index-settings.' . $index);

        $this->engine()->updateIndexSettings($index);
    }

    public function testTheCommandSyncsTheIndexesItIsGiven(): void
    {
        $index = $this->indexName('settings');

        config(['scout.manticore.index-settings' => [
            $index => ['columns' => ['title' => 'text', 'author_id' => 'int']],
        ]]);

        $this->artisan('scout:sync-index-settings')->assertExitCode(0);

        $this->assertSame('text', $this->columnsOf($index)['title'] ?? null);
    }
}
