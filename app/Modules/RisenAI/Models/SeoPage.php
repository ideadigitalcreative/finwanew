<?php

namespace App\Modules\RisenAI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SeoPage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'title',
        'meta_description',
        'h1',
        'content_json',
        'primary_keyword',
        'intent',
        'status',
        'intent_score',
        'service_var',
        'location_var',
        'cluster_id',
        'schema_markup',
        'internal_links',
        'thumbnail',
        'category',
        'tags',
    ];

    protected $casts = [
        'content_json' => 'array',
        'schema_markup' => 'array',
        'internal_links' => 'array',
        'tags' => 'array',
    ];
}
