<?php

namespace App\Modules\Mensajeria\Trabajos;

use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Mensajeria\Linkedin\BandejaLinkedin;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sincronización asíncrona de la bandeja de LinkedIn de una empresa, sin solaparse. */
class SincronizarLinkedin implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $empresaId) {}

    public function uniqueId(): string
    {
        return 'linkedin-'.$this->empresaId;
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(Tenancy $tenancy, BandejaLinkedin $bandeja): void
    {
        $tenancy->como(Empresa::findOrFail($this->empresaId), fn () => $bandeja->sincronizar());
    }
}
