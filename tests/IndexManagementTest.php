<?php

declare(strict_types=1);

namespace avadim\Manticore\Scout\Tests;

use avadim\Manticore\QueryBuilder\QueryErrorException;
use avadim\Manticore\Scout\Tests\Support\Post;
use avadim\Manticore\Scout\Tests\Support\SchemaPost;

/**
 * Creating, dropping and emptying the indexes - what "scout:index", "scout:delete-index" and
 * "scout:flush" end up calling.
 */
class IndexManagementTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresServer();
    }

    public function testCreateIndexTakesTheSchemaOfTheConfig(): void
    {
        $name = $this->indexName('created');
        $engine = $this->engine([
            'schemas' => [
                $name => [
                    'columns' => ['title' => 'text', 'author_id' => 'int'],
                    'options' => ['min_infix_len' => 3],
                ],
            ],
        ]);

        $engine->createIndex($name);

        $connection = $this->app->make('manticore');
        $this->assertTrue($connection->hasTable($name));

        $columns = array_column($connection->tableDescribe($name), 'Type', 'Field');
        $this->assertArrayHasKey('title', $columns);
        $this->assertArrayHasKey('author_id', $columns);
        $this->assertStringContainsString('min_infix_len', $connection->showCreateTable($name));
    }

    public function testCreateIndexWithoutASchemaSaysWhereToPutOne(): void
    {
        $engine = $this->engine(['schemas' => []]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('scout.manticore.schemas');

        $engine->createIndex($this->indexName('no_schema'));
    }

    public function testDeleteIndexDropsTheTable(): void
    {
        $this->makePost(['title' => 'manticore to be dropped']);
        $connection = $this->app->make('manticore');
        $this->assertTrue($connection->hasTable(Post::$searchableAs));

        $this->engine()->deleteIndex(Post::$searchableAs);

        $this->assertFalse($connection->hasTable(Post::$searchableAs));
    }

    public function testFlushEmptiesTheIndexAndKeepsIt(): void
    {
        $this->makePost(['title' => 'manticore to be flushed']);

        $this->engine()->flush(new Post());

        $this->assertTrue($this->app->make('manticore')->hasTable(Post::$searchableAs));
        $this->assertCount(0, Post::search('manticore')->get());
    }

    public function testFlushOfAnIndexThatIsNotThereIsNotAnError(): void
    {
        Post::$searchableAs = $this->indexName('never_created');

        $this->engine()->flush(new Post());

        $this->assertFalse($this->app->make('manticore')->hasTable(Post::$searchableAs));
    }

    public function testTheIndexIsBuiltOfTheSchemaTheModelDescribes(): void
    {
        SchemaPost::$searchableAs = $this->indexName('by_model');

        $post = SchemaPost::create(['title' => 'manticore of a model', 'body' => 'body', 'author_id' => 3]);

        $columns = array_column(
            $this->app->make('manticore')->tableDescribe(SchemaPost::$searchableAs),
            'Type',
            'Field'
        );

        $this->assertSame('text', $columns['title'] ?? null);
        $this->assertSame('uint', $columns['author_id'] ?? null, 'int of the DSL is uint of the server');
        $this->assertSame('string', $columns['slug'] ?? null);

        // a string attribute is filtered by, unlike a text field
        $found = SchemaPost::search('manticore')->where('slug', 'post-' . $post->getKey())->get();
        $this->assertCount(1, $found);
    }

    public function testAGuessedSchemaMakesNumbersFilterable(): void
    {
        $this->makePost(['title' => 'manticore guessed', 'author_id' => 42]);

        $columns = array_column(
            $this->app->make('manticore')->tableDescribe(Post::$searchableAs),
            'Type',
            'Field'
        );

        $this->assertSame('text', $columns['title'] ?? null);
        $this->assertSame('bigint', $columns['author_id'] ?? null);
    }

    public function testWithoutAutoCreateAWriteToAMissingIndexFails(): void
    {
        Post::$searchableAs = $this->indexName('no_auto_create');
        $post = new Post(['title' => 'manticore', 'body' => '', 'author_id' => 1]);
        $post->id = 1;

        $this->expectException(QueryErrorException::class);

        $this->engine(['auto_create' => false])->update($post->newCollection([$post]));
    }

    public function testDeleteAllIndexesDropsWhatCarriesThePrefixOfScout(): void
    {
        $prefix = 'phpunit_dai_' . uniqid() . '_';
        config()->set('scout.prefix', $prefix);

        $connection = $this->app->make('manticore');
        foreach (['one', 'two'] as $suffix) {
            $this->createdIndexes[] = $prefix . $suffix;
            $connection->create($prefix . $suffix, ['title' => 'text'], [], true);
        }
        $untouched = $this->indexName('kept');
        $connection->create($untouched, ['title' => 'text'], [], true);

        $dropped = $this->engine()->deleteAllIndexes();

        $this->assertCount(2, $dropped);
        $this->assertFalse($connection->hasTable($prefix . 'one'));
        $this->assertTrue($connection->hasTable($untouched), 'a table of another prefix is left alone');
    }
}
