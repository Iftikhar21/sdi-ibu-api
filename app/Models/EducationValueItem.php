<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EducationValueItem extends Model
{
    protected $table = 'education_value_items';

    protected $fillable = [
        'education_value_id',
        'title',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function educationValue()
    {
        return $this->belongsTo(EducationValue::class);
    }
}
