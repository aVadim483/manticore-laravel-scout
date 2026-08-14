<?php

declare(strict_types=1);

namespace avadim\Manticore\Scout\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

/**
 * The model of the tests. The index it lives in is unique per test, hence the static property.
 *
 * @property int $id
 * @property string $title
 * @property string $body
 * @property int $author_id
 */
class Post extends Model
{
    use Searchable;
    use SoftDeletes;

    /**
     * Name of the Manticore table of this model, set by the test case
     *
     * @var string
     */
    public static $searchableAs = 'phpunit_posts';

    /**
     * @var string
     */
    protected $table = 'posts';

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array
     */
    protected $guarded = [];

    /**
     * @return string
     */
    public function searchableAs(): string
    {
        return static::$searchableAs;
    }

    /**
     * @return array
     */
    public function toSearchableArray(): array
    {
        return [
            'title'     => (string)$this->title,
            'body'      => (string)$this->body,
            'author_id' => (int)$this->author_id,
        ];
    }
}
