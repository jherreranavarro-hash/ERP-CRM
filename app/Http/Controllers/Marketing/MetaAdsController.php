<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Modules\ContactCenter\Models\Campana;
use App\Modules\Integraciones\Models\Integracion;
use App\Modules\Marketing\Models\AdsCampana;
use App\Modules\Marketing\Models\AdsLead;
use App\Modules\Marketing\Publicidad;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** VentasCRM → Meta Ads: métricas de campañas y prospectos de formularios hacia el contact center. */
class MetaAdsController extends Controller
{
    public function index()
    {
        $campanas = AdsCampana::with('destino:id,nombre')->orderByRaw("estado = 'activa' desc")->orderBy('nombre')->get();
        $porResultado = AdsLead::selectRaw('ads_campana_id, resultado, count(*) as n')->groupBy('ads_campana_id', 'resultado')->get()->groupBy('ads_campana_id');

        return Inertia::render('Marketing/MetaAds', [
            'activa' => Integracion::activa('meta_ads'),
            'campanas' => $campanas->map(fn (AdsCampana $c) => $c->only(['id', 'externo_id', 'nombre', 'estado', 'objetivo', 'presupuesto_diario', 'gasto', 'impresiones', 'clics', 'leads', 'campana_id']) + [
                'destino' => $c->destino?->nombre, 'sincronizada_at' => $c->sincronizada_at?->toIso8601String(),
                'importados' => (int) ($porResultado[$c->id] ?? collect())->where('resultado', 'importado')->sum('n'),
                'sin_destino' => (int) ($porResultado[$c->id] ?? collect())->where('resultado', 'sin_destino')->sum('n'),
            ]),
            'prospectos' => AdsLead::orderByDesc('recibido_at')->limit(30)->get()->map(fn (AdsLead $l) => $l->only(['id', 'ads_campana_id', 'nombre', 'comuna', 'resultado', 'lead_id']) + [
                'telefono' => '•••• '.substr($l->telefono, -4), 'recibido_at' => $l->recibido_at->toIso8601String(),
            ]),
            'resultados' => AdsLead::RESULTADOS,
            'destinos' => Campana::whereIn('estado', ['borrador', 'activa', 'pausada'])->orderBy('nombre')->get(['id', 'nombre', 'estado']),
            'ultima' => AdsCampana::max('sincronizada_at'),
        ]);
    }

    public function sincronizar(Publicidad $publicidad)
    {
        $r = $publicidad->sincronizar();

        return back()->with('exito', "Sincronizado: {$r['campanas']} campañas, {$r['recibidos']} prospectos nuevos ({$r['importados']} importados".
            ($r['duplicados'] ? ", {$r['duplicados']} ya existían" : '').($r['bloqueados'] ? ", {$r['bloqueados']} en lista negra" : '').($r['sin_destino'] ? ", {$r['sin_destino']} sin campaña de destino" : '').').');
    }

    public function destino(Request $request, AdsCampana $campana, Publicidad $publicidad)
    {
        $d = $request->validate(['campana_id' => ['nullable', 'integer', Rule::exists('campanas', 'id')]]);
        $publicidad->destino($campana, $d['campana_id'] ? Campana::findOrFail($d['campana_id']) : null);
        $n = $publicidad->reprocesar($campana->fresh());

        return back()->with('exito', 'Destino guardado.'.($n ? " Se importaron {$n} prospectos que esperaban destino." : ''));
    }
}
