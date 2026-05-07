<?php

namespace App\Modules\RisenAI\Models;

use Illuminate\Database\Eloquent\Model;

class SeoKeywordCluster extends Model
{
    protected $fillable = [
        'cluster_name',
        'primary_keyword',
        'secondary_keywords',
        'avg_intent',
        'estimated_volume',
        'niche',
    ];

    protected $casts = [
        'secondary_keywords' => 'array',
    ];
}
