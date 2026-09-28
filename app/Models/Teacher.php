<?php

namespace App\Models;

use App\Support\ImageUploader;
use Illuminate\Database\Eloquent\Model;

class Teacher extends Model
{
    protected $table = 'teachers';

    protected $fillable = [
        'name',
        'email',
        'user_id',
        'gender',
        'last_education',
        'position',
        'phone',
        'address',
        'photo',
        'thumb',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = ['photo_url', 'has_account'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function homeroomAssignments()
    {
        return $this->hasMany(HomeroomAssignment::class);
    }

    public function teachingAssignments()
    {
        return $this->hasMany(TeachingAssignment::class);
    }

    /** Apakah guru ini sudah punya akun login. */
    public function getHasAccountAttribute(): bool
    {
        return $this->user_id !== null;
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('name', 'asc');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return ImageUploader::url($this->photo, $this->thumb);
    }
}
