<?php

namespace App\Modules\Marketing\Trabajos;

use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Marketing\Publicidad;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sincronización de Meta Ads por empresa, en cola y sin solaparse. */
class SincronizarMetaAds implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public int $empresaId)
    {
        $this->onQueue('reportes'); // sincronizaciones: la prioridad más baja
    }

    public function uniqueId(): string
    {
        return 'meta-ads-'.$this->empresaId;
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(Tenancy $tenancy, Publicidad $publicidad): void
    {
        $tenancy->como(Empresa::findOrFail($this->empresaId), fn () => $publicidad->sincronizar());
    }
}
