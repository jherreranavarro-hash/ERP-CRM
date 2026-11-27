<?php

namespace App\Modules\Marketing;

use App\Modules\ContactCenter\Models\Campana;
use App\Modules\ContactCenter\Models\Lead;
use App\Modules\ContactCenter\Models\ListaNegra;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Integraciones\MetaAds\MetaAds;
use App\Modules\Integraciones\Models\Integracion;
use App\Modules\Marketing\Models\AdsCampana;
use App\Modules\Marketing\Models\AdsLead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sincroniza Meta Ads: actualiza las métricas de cada campaña y trae los prospectos
 * de sus formularios. Cada prospecto se registra una sola vez (por su id en Meta) y,
 * si la campaña tiene destino, se crea como lead del contact center respetando la
 * lista negra y sin duplicar teléfonos en la misma campaña.
 */
class Publicidad
{
    public function __construct(private MetaAds $meta, private Auditoria $auditoria) {}

    /** @return array{campanas:int, recibidos:int, importados:int, duplicados:int, bloqueados:int, sin_destino:int, invalidos:int} */
    public function sincronizar(): array
    {
        if (! Integracion::activa('meta_ads')) {
            throw ValidationException::withMessages(['meta_ads' => 'Activa la integración de Meta Ads en Configuración → Integraciones.']);
        }
        $r = ['campanas' => 0, 'recibidos' => 0, 'importados' => 0, 'duplicados' => 0, 'bloqueados' => 0, 'sin_destino' => 0, 'invalidos' => 0];

        try {
            $remotas = $this->meta->campanas();
        } catch (\Throwable $e) {
            Integracion::where('tipo', 'meta_ads')->update(['salud' => 'caida', 'salud_at' => now()]);
            throw ValidationException::withMessages(['meta_ads' => 'Meta Ads no respondió: '.mb_substr($e->getMessage(), 0, 150)]);
        }

        foreach ($remotas as $c) {
            $camp = AdsCampana::firstOrNew(['externo_id' => $c['id']]);
            $desde = $camp->sincronizada_at?->copy()->subHour() ?? now()->subDays(14);
            $camp->fill(['nombre' => mb_substr($c['nombre'], 0, 120), 'estado' => $c['estado'], 'objetivo' => $c['objetivo'], 'presupuesto_diario' => $c['presupuesto_diario'],
                'gasto' => $c['gasto'], 'impresiones' => $c['impresiones'], 'clics' => $c['clics'], 'leads' => $c['leads']])->save();
            $r['campanas']++;

            foreach ($this->meta->prospectos($c['id'], $desde) as $p) {
                if (AdsLead::where('externo_id', $p['id'])->exists()) {
                    continue;
                }
                $r['recibidos']++;
                $resultado = $this->registrar($camp, $p);
                $r[$resultado === 'sin_destino' ? 'sin_destino' : $resultado.'s']++;
            }
            $camp->forceFill(['sincronizada_at' => now()])->save();
        }
        Integracion::where('tipo', 'meta_ads')->update(['salud' => 'ok', 'salud_at' => now()]);
        $this->auditoria->registrar('meta_ads.sincronizar', null, null, $r);

        return $r;
    }

    /** Asigna (o quita) la campaña del contact center que recibe los prospectos. */
    public function destino(AdsCampana $camp, ?Campana $destino): void
    {
        $antes = $camp->campana_id;
        $camp->update(['campana_id' => $destino?->id]);
        $this->auditoria->registrar('meta_ads.destino', $camp, ['campana_id' => $antes], ['campana_id' => $destino?->id]);
    }

    /** Reintenta los prospectos que llegaron sin destino, ahora que la campaña lo tiene. */
    public function reprocesar(AdsCampana $camp): int
    {
        if (! $camp->campana_id) {
            return 0;
        }
        $n = 0;
        foreach (AdsLead::where('ads_campana_id', $camp->id)->where('resultado', 'sin_destino')->orderBy('id')->get() as $al) {
            $res = $this->crearLead($camp, $al->only(['nombre', 'telefono', 'email', 'comuna']));
            $al->update(['resultado' => $res[0], 'lead_id' => $res[1]]);
            $n += $res[0] === 'importado' ? 1 : 0;
        }

        return $n;
    }

    private function registrar(AdsCampana $camp, array $p): string
    {
        return DB::transaction(function () use ($camp, $p) {
            $datos = ['nombre' => mb_substr(trim($p['nombre']), 0, 120), 'telefono' => preg_replace('/[^\d+]/', '', (string) $p['telefono']),
                'email' => $p['email'] ? mb_substr($p['email'], 0, 120) : null, 'comuna' => $p['comuna'] ? mb_substr($p['comuna'], 0, 60) : null];
            [$resultado, $leadId] = $camp->campana_id ? $this->crearLead($camp, $datos) : ['sin_destino', null];
            if ($datos['nombre'] === '' || strlen(preg_replace('/\D/', '', $datos['telefono'])) < 8) {
                [$resultado, $leadId] = ['invalido', null];
            }
            AdsLead::create(['ads_campana_id' => $camp->id, 'externo_id' => $p['id'], 'resultado' => $resultado, 'lead_id' => $leadId,
                'recibido_at' => Carbon::parse($p['creado'])] + $datos);

            return $resultado;
        });
    }

    /** @return array{0:string, 1:?int} */
    private function crearLead(AdsCampana $camp, array $d): array
    {
        if ($d['nombre'] === '' || strlen(preg_replace('/\D/', '', $d['telefono'])) < 8) {
            return ['invalido', null];
        }
        if (Lead::where('campana_id', $camp->campana_id)->where('telefono', $d['telefono'])->exists()) {
            return ['duplicado', null];
        }
        if (ListaNegra::bloqueado($d['telefono'])) {
            return ['bloqueado', null];
        }
        $lead = Lead::create(['campana_id' => $camp->campana_id, 'estado' => 'nuevo', 'notas' => 'Meta Ads: '.$camp->nombre] + $d);

        return ['importado', $lead->id];
    }
}
