<?php

namespace App\Models;

use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Models\UsuarioEmpresa;
use App\Modules\Core\Rbac\Rbac;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use Auditable, HasApiTokens, HasFactory, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /** Campos que no generan evento de auditoría (cambian en cada login). */
    public array $auditarExcluir = ['ultimo_acceso_at', 'remember_token'];

    protected $fillable = ['name', 'email', 'password', 'activo', 'password_changed_at'];

    // es_superadmin no es asignable en masa: solo se cambia de forma explícita.
    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'ultimo_acceso_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password' => 'hashed',
            'es_superadmin' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    public function membresias(): HasMany
    {
        return $this->hasMany(UsuarioEmpresa::class);
    }

    public function empresas(): BelongsToMany
    {
        return $this->belongsToMany(Empresa::class, 'usuario_empresa')
            ->withPivot(['rol_id', 'local_id', 'activo'])
            ->wherePivot('activo', true)
            ->where('empresas.activo', true);
    }

    public function membresiaActual(): ?UsuarioEmpresa
    {
        $empresaId = app(Tenancy::class)->id();

        return $empresaId ? app(Rbac::class)->membresia($this, $empresaId) : null;
    }

    /** Clave del perfil del usuario en la empresa activa ('superadmin' si lo es). */
    public function perfilActual(): ?string
    {
        if ($this->es_superadmin) {
            return 'superadmin';
        }

        return $this->membresiaActual()?->rol?->perfil?->clave;
    }

    public function tienePermiso(string $permiso): bool
    {
        return app(Rbac::class)->permite($this, $permiso);
    }

    public function tiene2fa(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function iniciales(): string
    {
        $partes = preg_split('/\s+/', trim($this->name)) ?: [];

        return mb_strtoupper(mb_substr($partes[0] ?? '', 0, 1).mb_substr($partes[1] ?? '', 0, 1));
    }
}
