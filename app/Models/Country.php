<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Country extends Model
{
    use SoftDeletes;

    protected $fillable = ['iso2', 'iso3', 'phone_code', 'is_active'];

    protected $casts = [
        'is_active' => 'bool',
    ];

    public function translations(): HasMany
    {
        return $this->hasMany(CountryTranslation::class);
    }

    /**
     * Название для текущей локали с фолбэком на fallback_locale и первое доступное.
     */
    public function getNameAttribute(): ?string
    {
        $locale = app()->getLocale();
        $fallback = config('app.fallback_locale', 'en');

        return $this->translations->firstWhere('locale', $locale)?->name
            ?? $this->translations->firstWhere('locale', $fallback)?->name
            ?? $this->translations->first()?->name;
    }
}
