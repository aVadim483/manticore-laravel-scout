<?php

declare(strict_types=1);

namespace avadim\Manticore\Scout\Tests;

use avadim\Manticore\Scout\Tests\Support\Post;

/**
 * With scout.soft_delete on, a trashed model stays in the index behind the __soft_deleted flag.
 */
class SoftDeleteTest extends TestCase
{
    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return void
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('scout.soft_delete', true);
    }

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresServer();
    }

    public function testATrashedModelIsOutOfTheDefaultSearch(): void
    {
        $kept = $this->makePost(['title' => 'manticore kept']);
        $trashed = $this->makePost(['title' => 'manticore trashed']);

        $trashed->delete();

        $found = Post::search('manticore')->get();
        $this->assertCount(1, $found);
        $this->assertTrue($found->first()->is($kept));
    }

    public function testWithTrashedAndOnlyTrashedReachTheTrashedOne(): void
    {
        $this->makePost(['title' => 'manticore kept']);
        $trashed = $this->makePost(['title' => 'manticore trashed']);
        $trashed->delete();

        $this->assertCount(2, Post::search('manticore')->withTrashed()->get());

        $only = Post::search('manticore')->onlyTrashed()->get();
        $this->assertCount(1, $only);
        $this->assertTrue($only->first()->is($trashed));
    }

    public function testAForceDeletedModelLeavesTheIndex(): void
    {
        $post = $this->makePost(['title' => 'manticore gone for good']);

        $post->forceDelete();

        $this->assertCount(0, Post::search('manticore')->withTrashed()->get());
    }

    public function testARestoredModelIsSearchableAgain(): void
    {
        $post = $this->makePost(['title' => 'manticore restored']);
        $post->delete();
        $this->assertCount(0, Post::search('manticore')->get());

        $post->restore();

        $this->assertCount(1, Post::search('manticore')->get());
    }
}
