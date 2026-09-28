<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Http\Controllers\Api\RoleController;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]


class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasRoles;
    public function canAccessPanel(Panel $panel): bool
    {

        return $this->hasRole(['admin']);
    }

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function toAuthArray() : array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->getRoleNames(),
            'allRoles'=>$this->rolesTranslated(),
            'permissions' => $this->getAllPermissions()->pluck('name'),
            'rolesCount' => env('PUBLIC_ROLES_COUNT', 1),
            'direction' => $this->userData?->direction ?? 'ltr',
        ];
    }

    public function rolesTranslated(){
        $condensed = [];
        $roles = $this->roles()->whereIn('name', RoleController::getPublicRolesNames())->with('translations')->get()->toArray();
        foreach ($roles as $k=>$v){
            $condensed[$k]['name'] = $v['name'];
            $condensed[$k]['label'] = $v['translations'][0]['label']
                ?? $v['translations'][0]['name']
                ?? $v['name'];
        }
        return $condensed;
    }


    public function userData(): HasOne
    {
        return $this->hasOne(UserData::class);
    }


}
