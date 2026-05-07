<?php

namespace App\Modules\RisenAI\Models;

use Illuminate\Database\Eloquent\Model;

class SeoPerformanceLog extends Model
{
    protected $fillable = [
        'seo_page_id',
        'impressions',
        'clicks',
        'ctr',
        'avg_position',
        'action_taken',
        'recorded_date',
    ];

    protected $casts = [
        'recorded_date' => 'date',
        'ctr' => 'decimal:2',
        'avg_position' => 'decimal:1',
    ];

    public function page()
    {
        return $this->belongsTo(SeoPage::class, 'seo_page_id');
    }
}
