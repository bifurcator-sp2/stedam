<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserData extends Model
{
    protected $table = 'user_data';

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'middle_name',
        'birth_year',
        'gender',
        'country_id',
        'direction',
    ];

    protected $casts = [
        'birth_year' => 'integer',
        'direction' => 'string',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * Полное ФИО.
     */
    public function getFullNameAttribute(): ?string
    {
        $parts = array_filter([
            $this->last_name,
            $this->first_name,
            $this->middle_name,
        ]);

        return $parts ? implode(' ', $parts) : null;
    }

    /**
     * Возраст по году рождения.
     */
    public function getAgeAttribute(): ?int
    {
        return $this->birth_year
            ? now()->year - $this->birth_year
            : null;
    }
}
