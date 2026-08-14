<?php

declare(strict_types=1);

namespace avadim\Manticore\Scout\Tests\Support;

/**
 * A model that keeps itself out of the index by answering with an empty searchable array.
 */
class HiddenPost extends Post
{
    /**
     * @var string
     */
    public static $searchableAs = 'phpunit_hidden_posts';

    /**
     * @return array
     */
    public function toSearchableArray(): array
    {
        return [];
    }
}
