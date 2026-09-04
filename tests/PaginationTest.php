<?php

declare(strict_types=1);

namespace avadim\Manticore\Scout\Tests;

use avadim\Manticore\QueryBuilder\Query;
use avadim\Manticore\Scout\Tests\Support\Post;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Laravel\Scout\Builder;

/**
 * Paging: Scout builds the paginator itself, the driver answers with the page and the total.
 */
class PaginationTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresServer();
    }

    /**
     * @param int $count
     *
     * @return void
     */
    protected function seedPosts(int $count): void
    {
        foreach (range(1, $count) as $n) {
            $this->makePost(['title' => 'manticore number ' . $n, 'author_id' => $n]);
        }
    }

    public function testAPageCarriesTheTotalOfTheWholeSearch(): void
    {
        $this->seedPosts(5);

        $page = Post::search('manticore')->orderBy('author_id', 'asc')->paginate(2);

        $this->assertInstanceOf(LengthAwarePaginator::class, $page);
        $this->assertSame(5, $page->total());
        $this->assertSame(3, $page->lastPage());
        $this->assertCount(2, $page->items());
        $this->assertSame(1, $page->items()[0]->author_id);
    }

    public function testTheSecondPageHoldsTheNextRows(): void
    {
        $this->seedPosts(5);

        $page = Post::search('manticore')->orderBy('author_id', 'asc')->paginate(2, 'page', 2);

        $this->assertSame([3, 4], array_map(function ($post) {
            return $post->author_id;
        }, $page->items()));
    }

    public function testSimplePaginateKnowsWhetherThereIsMore(): void
    {
        $this->seedPosts(5);

        $page = Post::search('manticore')->orderBy('author_id', 'asc')->simplePaginate(2);
        $last = Post::search('manticore')->orderBy('author_id', 'asc')->simplePaginate(2, 'page', 3);

        $this->assertInstanceOf(Paginator::class, $page);
        $this->assertTrue($page->hasMorePages());
        $this->assertFalse($last->hasMorePages());
    }

    public function testADeepPageRaisesMaxMatchesOnItsOwn(): void
    {
        $this->seedPosts(1);
        $sql = null;

        Post::search('manticore', function (Query $query) use (&$sql) {
            $sql = $query->toSql();

            return $query;
        })->paginate(10, 'page', 200);

        // 200 pages of 10 rows reach beyond the 1000 rows the server keeps by default
        $this->assertStringContainsString('max_matches=2000', (string)$sql);
        $this->assertStringContainsString('LIMIT 1990,10', (string)$sql);
    }

    /**
     * A page of an engine of its own, with the SQL it was answered from
     *
     * @param array $config
     * @param int $perPage
     * @param int $page
     *
     * @return string
     */
    protected function sqlOfPage(array $config, int $perPage, int $page): string
    {
        $sql = '';
        $builder = new Builder(new Post(), 'manticore', function (Query $query) use (&$sql) {
            $sql = (string)$query->toSql();

            return $query;
        });

        $this->engine($config)->paginate($builder, $perPage, $page);

        return $sql;
    }

    public function testAMaxMatchesOfTheConfigIsTheDepthOfEveryQuery(): void
    {
        $this->seedPosts(1);

        // how deep paging goes and how far the total is counted, not only for a deep page
        $this->assertStringContainsString('max_matches=5000', $this->sqlOfPage(['max_matches' => 5000], 10, 2));
    }

    public function testAPageBeyondALoweredMaxMatchesRaisesItAllTheSame(): void
    {
        $this->seedPosts(1);

        // a config below the default of the server is a deliberate one, and the page still has to
        // fit into what the query is given
        $this->assertStringContainsString('max_matches=200', $this->sqlOfPage(['max_matches' => 100], 10, 20));
    }

    public function testAShallowPageLeavesMaxMatchesAlone(): void
    {
        $this->seedPosts(1);
        $sql = null;

        Post::search('manticore', function (Query $query) use (&$sql) {
            $sql = $query->toSql();

            return $query;
        })->paginate(10, 'page', 2);

        $this->assertStringNotContainsString('max_matches', (string)$sql);
    }
}
