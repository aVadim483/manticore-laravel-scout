<?php

declare(strict_types=1);

namespace avadim\Manticore\Scout\Tests;

use avadim\Manticore\QueryBuilder\Query;
use avadim\Manticore\Scout\Tests\Support\VectorPost;
use Laravel\Scout\Builder;

/**
 * semantic() and hybrid() of Scout: the vector search of Manticore, and the two of them at once.
 */
class SemanticSearchTest extends TestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return void
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('scout.manticore.semantic', [
            'column'   => 'embedding',
            'k'        => null,
            'embedder' => static function (string $phrase) {
                return VectorPost::vectorFor($phrase);
            },
        ]);
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresServer();

        VectorPost::$searchableAs = $this->indexName('vectors');
    }

    /**
     * Two posts of two meanings: apples and whales
     *
     * @return array
     */
    protected function seedPosts(): array
    {
        return [
            VectorPost::create(['title' => 'red apple pie', 'body' => '', 'author_id' => 1]),
            VectorPost::create(['title' => 'blue whale song', 'body' => '', 'author_id' => 2]),
        ];
    }

    public function testASemanticSearchFindsByMeaningWhereTheWordsFindNothing(): void
    {
        [$apple] = $this->seedPosts();

        // no post carries the word itself
        $this->assertCount(0, VectorPost::search('fruit')->get());

        $found = VectorPost::search('fruit')->semantic()->get();

        $this->assertSame($apple->getKey(), $found->first()->getKey());
    }

    public function testAMinimumSimilarityCutsOffWhatIsFarAway(): void
    {
        [$apple] = $this->seedPosts();

        $found = VectorPost::search('fruit')->semantic(0.9)->get();

        $this->assertCount(1, $found);
        $this->assertSame($apple->getKey(), $found->first()->getKey());
    }

    public function testTheSimilarityOfARowComesBackAsMetadataOfTheModel(): void
    {
        $this->seedPosts();

        $found = VectorPost::search('apple')->semantic(0.5)->get();

        $metadata = $found->first()->scoutMetadata();
        $this->assertArrayHasKey('_similarity', $metadata);
        $this->assertEqualsWithDelta(1.0, (float)$metadata['_similarity'], 0.001);
        // the distance the server answered with is there as well
        $this->assertArrayHasKey('_knn_dist', $metadata);
    }

    public function testAHybridSearchAsksTheServerForTheWordsAndTheVectorAtOnce(): void
    {
        $sql = null;

        $this->seedPosts();

        $found = VectorPost::search('apple', function (Query $query) use (&$sql) {
            $sql = $query->toSql();

            return $query;
        })->hybrid(1, 2)->get();

        $this->assertStringContainsString('knn(embedding,', (string)$sql);
        $this->assertStringContainsString("MATCH('apple')", (string)$sql);
        $this->assertStringContainsString('(1 * weight() + 2 * (1 - knn_dist())) as _hybrid_score', (string)$sql);
        $this->assertStringContainsString('ORDER BY _hybrid_score DESC', (string)$sql);

        // MATCH() is a condition of its own, so a hybrid search keeps to the rows carrying the word
        $this->assertCount(1, $found);
        $this->assertArrayHasKey('_hybrid_score', $found->first()->scoutMetadata());
    }

    public function testAnOrderOfTheCallerIsLeftAloneByAHybridSearch(): void
    {
        $sql = null;

        $this->seedPosts();

        VectorPost::search('apple', function (Query $query) use (&$sql) {
            $sql = $query->toSql();

            return $query;
        })->hybrid()->orderBy('author_id', 'asc')->get();

        $this->assertStringContainsString('ORDER BY author_id ASC', (string)$sql);
        $this->assertStringNotContainsString('ORDER BY _hybrid_score', (string)$sql);
    }

    public function testTheQueryOverridesTheSemanticSettingsOfTheConfig(): void
    {
        $sql = null;

        $this->seedPosts();

        VectorPost::search('apple', function (Query $query) use (&$sql) {
            $sql = $query->toSql();

            return $query;
        })->semantic()->options(['semantic' => ['k' => 3]])->get();

        $this->assertStringContainsString('knn(embedding, 3,', (string)$sql);
        // "semantic" is read by the driver, not passed on as an OPTION of the statement
        $this->assertStringNotContainsString('OPTION', (string)$sql);
    }

    public function testASemanticSearchWithoutAnEmbedderSaysWhereToPutOne(): void
    {
        $builder = (new Builder(new VectorPost(), 'apple'))->semantic();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('scout.manticore.semantic.embedder');

        $this->engine(['semantic' => ['embedder' => null]])->search($builder);
    }

    public function testAnEmbedderThatDoesNotAnswerWithAVectorSaysSo(): void
    {
        $builder = (new Builder(new VectorPost(), 'apple'))->semantic();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('where a vector of numbers was expected');

        $this->engine(['semantic' => ['embedder' => static function () {
            return null;
        }]])->search($builder);
    }
}
