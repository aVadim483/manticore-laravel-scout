<?php

declare(strict_types=1);

namespace avadim\Manticore\Scout\Tests;

use avadim\Manticore\Laravel\Manager;
use avadim\Manticore\QueryBuilder\ResultSet;
use avadim\Manticore\Scout\ManticoreEngine;

/**
 * What the driver reads out of a rejected statement: whether the index is missing, and which
 * column is. No server is needed for it - the answers are the ones a server gives.
 */
class ErrorReadingTest extends TestCase
{
    /**
     * An engine that says what it made of an answer
     *
     * @return \avadim\Manticore\Scout\ManticoreEngine
     */
    protected function reader(): ManticoreEngine
    {
        return new class($this->app->make(Manager::class), (array)config('scout.manticore'), false) extends ManticoreEngine {
            /**
             * @param ResultSet $result
             *
             * @return bool
             */
            public function readsAsMissingTable(ResultSet $result): bool
            {
                return $this->missingTable($result);
            }

            /**
             * @param ResultSet $result
             *
             * @return string|null
             */
            public function readsAsMissingColumn(ResultSet $result): ?string
            {
                return $this->missingColumn($result);
            }
        };
    }

    /**
     * An answer of the server, worded the way the client of the query builder words it
     *
     * @param string $sql
     * @param string $error
     *
     * @return ResultSet
     */
    protected function rejected(string $sql, string $error): ResultSet
    {
        return new ResultSet([
            'command'  => 'SELECT',
            'query'    => $sql,
            'response' => ['error' => 'SQL: ' . $sql . "\n" . 'Error [42000] ' . $error],
        ]);
    }

    public function testAMissingTableIsReadOutOfTheAnswer(): void
    {
        $reader = $this->reader();

        $this->assertTrue($reader->readsAsMissingTable(
            $this->rejected('SELECT * FROM posts', "unknown local table(s) 'posts' in search request")
        ));
        $this->assertTrue($reader->readsAsMissingTable(
            $this->rejected('REPLACE INTO posts ...', "table 'posts' absent, or does not support INSERT")
        ));
        $this->assertFalse($reader->readsAsMissingTable(
            $this->rejected('SELECT * FROM posts', 'table posts: unknown column: rating')
        ));
    }

    public function testThePhraseOfTheSearchIsNotReadAsTheAnswerOfTheServer(): void
    {
        // the statement carries what a user typed, and the statement is part of the message
        $rejected = $this->rejected(
            "SELECT * FROM posts WHERE MATCH('no such table') AND (rating=1)",
            'table posts: unknown column: rating'
        );

        $this->assertFalse($this->reader()->readsAsMissingTable($rejected));
        $this->assertSame('rating', $this->reader()->readsAsMissingColumn($rejected));
    }

    public function testAMissingColumnIsNamed(): void
    {
        $reader = $this->reader();

        $this->assertSame('rating', $reader->readsAsMissingColumn(
            $this->rejected('REPLACE INTO posts ...', 'table posts: unknown column: rating')
        ));
        $this->assertNull($reader->readsAsMissingColumn(
            $this->rejected('SELECT * FROM posts', "unknown local table(s) 'posts' in search request")
        ));
    }

    public function testAnAnswerThatWentThroughIsNeitherOfTheTwo(): void
    {
        $result = new ResultSet([
            'command' => 'SELECT',
            'meta'    => ['total' => 0, 'total_found' => 0],
            'result'  => ['type' => 'array', 'data' => []],
        ]);

        $this->assertFalse($this->reader()->readsAsMissingTable($result));
        $this->assertNull($this->reader()->readsAsMissingColumn($result));
    }
}
