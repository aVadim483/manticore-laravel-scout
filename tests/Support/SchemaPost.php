<?php

declare(strict_types=1);

namespace avadim\Manticore\Scout\Tests\Support;

/**
 * A model that describes the schema of its index itself.
 */
class SchemaPost extends Post
{
    /**
     * @var string
     */
    public static $searchableAs = 'phpunit_schema_posts';

    /**
     * The columns of the index, used when it is created on the first write
     *
     * @return array
     */
    public function manticoreSchema(): array
    {
        return [
            'title'     => 'text',
            'body'      => 'text',
            'author_id' => 'int',
            'slug'      => 'string',
        ];
    }

    /**
     * @return array
     */
    public function toSearchableArray(): array
    {
        return array_merge(parent::toSearchableArray(), [
            'slug' => 'post-' . $this->getKey(),
        ]);
    }
}
