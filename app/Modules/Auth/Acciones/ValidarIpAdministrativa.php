<?php

namespace App\Modules\Auth\Acciones;

use App\Models\User;
use App\Modules\Core\Parametros\Parametros;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/** Restricción opcional por IP para cuentas SuperAdmin (mantenedor de login). */
class ValidarIpAdministrativa
{
    public function __construct(private Parametros $parametros) {}

    public function permite(User $user, Request $request): bool
    {
        $ips = $this->parametros->valor('login.ips_admin');

        return ! $user->es_superadmin || $ips === [] || IpUtils::checkIp((string) $request->ip(), $ips);
    }
}
