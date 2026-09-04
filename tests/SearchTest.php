<?php

declare(strict_types=1);

namespace avadim\Manticore\Scout\Tests;

use avadim\Manticore\QueryBuilder\Query;
use avadim\Manticore\QueryBuilder\ResultSet;
use avadim\Manticore\Scout\Tests\Support\Post;
use Laravel\Scout\EngineManager;

/**
 * What a search answers, against a live server.
 */
class SearchTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresServer();
    }

    public function testASavedModelIsFoundByItsText(): void
    {
        $post = $this->makePost(['title' => 'Manticore query builder', 'body' => 'a fast full-text search']);
        $this->makePost(['title' => 'Something else entirely', 'body' => 'no match here']);

        $found = Post::search('manticore')->get();

        $this->assertCount(1, $found);
        $this->assertTrue($found->first()->is($post));
    }

    public function testTheIndexIsCreatedOnTheFirstWrite(): void
    {
        $this->makePost(['title' => 'first row of a brand new index']);

        $this->assertTrue($this->app->make('manticore')->hasTable(Post::$searchableAs));
    }

    public function testTheResultsKeepTheOrderOfTheServer(): void
    {
        $weak = $this->makePost(['title' => 'lorem ipsum', 'body' => 'manticore is mentioned once']);
        $strong = $this->makePost(['title' => 'manticore manticore', 'body' => 'manticore everywhere manticore']);

        $found = Post::search('manticore')->get();

        $this->assertCount(2, $found);
        $this->assertTrue($found->first()->is($strong), 'the better match comes first');
        $this->assertTrue($found->last()->is($weak));
    }

    public function testOrderByOverridesTheRanking(): void
    {
        $first = $this->makePost(['title' => 'manticore one', 'author_id' => 1]);
        $second = $this->makePost(['title' => 'manticore two manticore', 'author_id' => 9]);

        $found = Post::search('manticore')->orderBy('author_id', 'asc')->get();

        $this->assertCount(2, $found);
        $this->assertTrue($found->first()->is($first));
        $this->assertTrue($found->last()->is($second));
    }

    public function testWhereFiltersByAnAttribute(): void
    {
        $mine = $this->makePost(['title' => 'manticore of mine', 'author_id' => 7]);
        $this->makePost(['title' => 'manticore of another', 'author_id' => 8]);

        $found = Post::search('manticore')->where('author_id', 7)->get();

        $this->assertCount(1, $found);
        $this->assertTrue($found->first()->is($mine));
    }

    public function testWhereInAndWhereNotIn(): void
    {
        $this->makePost(['title' => 'manticore a', 'author_id' => 1]);
        $this->makePost(['title' => 'manticore b', 'author_id' => 2]);
        $this->makePost(['title' => 'manticore c', 'author_id' => 3]);

        $this->assertCount(2, Post::search('manticore')->whereIn('author_id', [1, 3])->get());
        $this->assertCount(1, Post::search('manticore')->whereNotIn('author_id', [1, 3])->get());
    }

    public function testTakeLimitsTheAnswer(): void
    {
        foreach (range(1, 5) as $n) {
            $this->makePost(['title' => 'manticore number ' . $n]);
        }

        $this->assertCount(2, Post::search('manticore')->take(2)->get());
    }

    public function testKeysAnswersWithTheScoutKeys(): void
    {
        $post = $this->makePost(['title' => 'manticore keys']);

        $keys = Post::search('manticore')->keys();

        $this->assertSame([$post->getKey()], array_map('intval', $keys->all()));
    }

    public function testAnEmptyQueryTakesTheWholeIndex(): void
    {
        $this->makePost(['title' => 'anything at all']);
        $this->makePost(['title' => 'and another one']);

        $this->assertCount(2, Post::search('')->get());
    }

    public function testWhatAUserTypedIsSearchedForAsItIsWritten(): void
    {
        $post = $this->makePost(['title' => 'iPhone Pro', 'body' => 'a phone']);

        // unescaped, the dash would exclude "Pro" and the quote would break the query outright
        $found = Post::search('iPhone -Pro "')->get();

        $this->assertCount(1, $found);
        $this->assertTrue($found->first()->is($post));
    }

    public function testTheQueryLanguageIsAvailableWithEscapingTurnedOff(): void
    {
        $this->makePost(['title' => 'iPhone Pro']);
        $wanted = $this->makePost(['title' => 'iPhone Mini']);

        $found = Post::search('iPhone -Pro')->options(['escape' => false])->get();

        $this->assertCount(1, $found);
        $this->assertTrue($found->first()->is($wanted));
    }

    public function testTheCallbackGetsTheQueryOfTheBuilder(): void
    {
        $this->makePost(['title' => 'manticore one', 'author_id' => 1]);
        $wanted = $this->makePost(['title' => 'manticore two', 'author_id' => 2]);

        $found = Post::search('manticore', function (Query $query, string $phrase) {
            $this->assertSame('manticore', $phrase);

            return $query->where('author_id', 2);
        })->get();

        $this->assertCount(1, $found);
        $this->assertTrue($found->first()->is($wanted));
    }

    public function testAnUnknownIndexAnswersWithNothingInsteadOfAnError(): void
    {
        Post::$searchableAs = $this->indexName('never_created');

        $this->assertCount(0, Post::search('manticore')->get());
        $this->assertSame(0, Post::search('manticore')->paginate(10)->total());
    }

    public function testTheMetaOfTheLastSearchIsReachableThroughTheEngine(): void
    {
        $this->makePost(['title' => 'manticore meta']);

        $engine = $this->app->make(EngineManager::class)->engine('manticore');
        Post::search('manticore')->get();

        // whatever the engine does not know of goes to the connection of the query builder
        $result = $engine->lastResultSet();

        $this->assertInstanceOf(ResultSet::class, $result);
        $this->assertSame(1, $result->total());
    }

    public function testCursorWalksTheResultsOneByOne(): void
    {
        $this->makePost(['title' => 'manticore one', 'author_id' => 1]);
        $this->makePost(['title' => 'manticore two', 'author_id' => 2]);

        $cursor = Post::search('manticore')->orderBy('author_id', 'asc')->cursor();

        $this->assertSame([1, 2], $cursor->map(function ($post) {
            return $post->author_id;
        })->all());
    }

    public function testTheSearchIsKeptToTheGivenFields(): void
    {
        $this->makePost(['title' => 'a title of nothing', 'body' => 'manticore lives in the body']);

        $this->assertCount(1, Post::search('manticore')->get());
        $this->assertCount(0, Post::search('manticore')->options(['fields' => 'title'])->get());
    }

    public function testHighlightComesBackAsMetadataOfTheModel(): void
    {
        $this->makePost(['title' => 'manticore in the title', 'body' => 'and in the body as well']);

        $found = Post::search('manticore')->options(['highlight' => true])->get();

        $this->assertArrayHasKey('_highlight', $found->first()->scoutMetadata());
        $this->assertStringContainsString('manticore', (string)$found->first()->scoutMetadata()['_highlight']);
    }

    public function testAnOptionOfTheBuilderReachesTheOptionClause(): void
    {
        $this->makePost(['title' => 'manticore ranked']);
        $sql = null;

        Post::search('manticore', function (Query $query) use (&$sql) {
            $sql = $query->toSql();

            return $query;
        })->options(['ranker' => 'sph04'])->get();

        $this->assertStringContainsString('ranker=sph04', (string)$sql);
    }

    public function testTheModelCarriesTheScoreOfTheSearch(): void
    {
        $this->makePost(['title' => 'manticore scoring']);

        $found = Post::search('manticore')->get();

        $this->assertArrayHasKey('_score', $found->first()->scoutMetadata());
        $this->assertGreaterThan(0, $found->first()->scoutMetadata()['_score']);
    }
}
