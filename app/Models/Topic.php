<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A keyword shared by articles, with its own page (/mavzu/raqamli-iqtisodiyot). */
class Topic extends Model
{
    /** Topics with fewer articles get a page but are not offered to search engines (too thin). */
    public const INDEX_MIN = 3;

    protected $guarded = [];

    public function articles()
    {
        return $this->belongsToMany(Article::class, 'article_topic');
    }

    public function indexable(): bool
    {
        return $this->articles_count >= self::INDEX_MIN;
    }
}
