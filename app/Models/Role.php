<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    public function translations(): HasMany
    {
        return $this->hasMany(RoleTranslation::class);
    }
    /**
     * Удобный хелпер: получить label и description для текущей локали.
     */
    public function translation(?string $locale = null): ?RoleTranslation
    {
        $locale ??= app()->getLocale();

        return $this->translations->firstWhere('locale', $locale)
            ?? $this->translations->first();
    }
}
