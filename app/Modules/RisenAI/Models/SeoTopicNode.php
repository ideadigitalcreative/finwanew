<?php

namespace App\Modules\RisenAI\Models;

use Illuminate\Database\Eloquent\Model;

class SeoTopicNode extends Model
{
    protected $fillable = [
        'label',
        'type',
        'url_slug',
        'connected_nodes',
        'target_keywords',
        'semantic_score',
    ];

    protected $casts = [
        'connected_nodes' => 'array',
        'target_keywords' => 'array',
    ];
}
