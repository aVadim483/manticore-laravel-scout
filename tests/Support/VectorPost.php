<?php

declare(strict_types=1);

namespace avadim\Manticore\Scout\Tests\Support;

use avadim\Manticore\QueryBuilder\Schema\SchemaTable;

/**
 * A model whose index carries a vector, for the semantic and the hybrid search.
 *
 * The "embedding" of the tests is a fixed map of a word to an axis: words of one meaning point in
 * one direction, which is what an embedder does for real - without a model to run here.
 */
class VectorPost extends Post
{
    /**
     * @var string
     */
    public static $searchableAs = 'phpunit_vector_posts';

    /**
     * How many dimensions the vector column has
     */
    public const DIMENSIONS = 4;

    /**
     * The direction of every word the tests use
     */
    public const AXES = [
        'apple' => [1.0, 0.0, 0.0, 0.0],
        'fruit' => [1.0, 0.0, 0.0, 0.0],
        'pie'   => [1.0, 0.0, 0.0, 0.0],
        'whale' => [0.0, 0.0, 1.0, 0.0],
        'song'  => [0.0, 0.0, 1.0, 0.0],
        'ocean' => [0.0, 0.0, 1.0, 0.0],
    ];

    /**
     * The vector of a phrase, i.e. what the embedder of the tests answers with
     *
     * @param string $text
     *
     * @return array
     */
    public static function vectorFor(string $text): array
    {
        foreach (self::AXES as $word => $vector) {
            if (stripos($text, $word) !== false) {
                return $vector;
            }
        }

        // a direction of its own, so that an unknown phrase is close to nothing
        return [0.0, 1.0, 0.0, 0.0];
    }

    /**
     * The schema of the index, as a callable - a vector column takes more than a type name
     *
     * @return callable
     */
    public function manticoreSchema(): callable
    {
        return static function (SchemaTable $table) {
            $table->text('title');
            $table->text('body');
            $table->integer('author_id');
            $table->floatVector('embedding', self::DIMENSIONS, 'cosine');
        };
    }

    /**
     * @return array
     */
    public function toSearchableArray(): array
    {
        return array_merge(parent::toSearchableArray(), [
            'embedding' => static::vectorFor((string)$this->title),
        ]);
    }
}
