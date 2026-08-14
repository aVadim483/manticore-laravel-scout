<?php

declare(strict_types=1);

namespace avadim\Manticore\Scout\Tests;

use avadim\Manticore\Scout\Tests\Support\HiddenPost;
use avadim\Manticore\Scout\Tests\Support\Post;

/**
 * What the observer of Scout does to the index as models are saved and deleted.
 */
class ModelSyncTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresServer();
    }

    public function testASavedModelIsWrittenOverItsOldRowInsteadOfBesideIt(): void
    {
        $post = $this->makePost(['title' => 'manticore original']);

        $post->update(['title' => 'manticore rewritten']);

        $this->assertCount(1, Post::search('manticore')->get(), 'REPLACE, not INSERT');
        $this->assertCount(1, Post::search('rewritten')->get());
        $this->assertCount(0, Post::search('original')->get());
    }

    public function testADeletedModelLeavesTheIndex(): void
    {
        $post = $this->makePost(['title' => 'manticore deleted']);

        $post->forceDelete();

        $this->assertCount(0, Post::search('manticore')->get());
    }

    public function testUnsearchableTakesAModelOutAndSearchablePutsItBack(): void
    {
        $post = $this->makePost(['title' => 'manticore toggled']);

        $post->unsearchable();
        $this->assertCount(0, Post::search('manticore')->get());

        $post->searchable();
        $this->assertCount(1, Post::search('manticore')->get());
    }

    public function testRemoveAllFromSearchEmptiesTheIndex(): void
    {
        $this->makePost(['title' => 'manticore one']);
        $this->makePost(['title' => 'manticore two']);

        Post::removeAllFromSearch();

        $this->assertCount(0, Post::search('manticore')->get());
    }

    public function testAModelWithAnEmptySearchableArrayIsNotIndexedAtAll(): void
    {
        HiddenPost::$searchableAs = $this->indexName('hidden');

        HiddenPost::create(['title' => 'manticore hidden', 'body' => '', 'author_id' => 1]);

        $this->assertFalse(
            $this->app->make('manticore')->hasTable(HiddenPost::$searchableAs),
            'nothing to write means no index to create'
        );
    }

    public function testModelsWithDifferentColumnsAreWrittenInSeparateStatements(): void
    {
        $first = $this->makePost(['title' => 'manticore with a body', 'author_id' => 1]);

        // metadata of the second model only, so the two rows name different columns
        $second = $this->makePost(['title' => 'manticore without', 'author_id' => 2]);
        $second->withScoutMetadata('extra_flag', 1);

        $this->engine()->update($first->newCollection([$first, $second]));

        $this->assertCount(2, Post::search('manticore')->get());
        $this->assertCount(1, Post::search('manticore')->where('extra_flag', 1)->get());
    }

    public function testAColumnAddedToTheSearchableArrayIsAddedToTheIndex(): void
    {
        $post = $this->makePost(['title' => 'manticore growing']);

        $post->withScoutMetadata('rating', 5);
        $this->engine()->update($post->newCollection([$post]));

        $columns = array_column(
            $this->app->make('manticore')->tableDescribe(Post::$searchableAs),
            'Type',
            'Field'
        );
        $this->assertSame('bigint', $columns['rating'] ?? null);
        $this->assertCount(1, Post::search('manticore')->where('rating', 5)->get());
    }

    public function testWithoutAutoColumnsAMissingColumnIsAnError(): void
    {
        $post = $this->makePost(['title' => 'manticore strict']);
        $post->withScoutMetadata('rating', 5);

        $this->expectException(\avadim\Manticore\QueryBuilder\QueryErrorException::class);
        $this->expectExceptionMessage('unknown column');

        $this->engine(['auto_columns' => false])->update($post->newCollection([$post]));
    }

    public function testTheIndexSurvivesBeingDroppedUnderneathTheEngine(): void
    {
        $engine = $this->engine();
        $post = $this->makePost(['title' => 'manticore first']);
        $engine->update($post->newCollection([$post]));

        // the engine now believes the index is there
        $this->app->make('manticore')->drop(Post::$searchableAs, true);

        $second = $this->makePost(['title' => 'manticore second']);
        $engine->update($second->newCollection([$second]));

        $this->assertTrue($this->app->make('manticore')->hasTable(Post::$searchableAs));
        $this->assertCount(1, Post::search('second')->get());
    }
}
