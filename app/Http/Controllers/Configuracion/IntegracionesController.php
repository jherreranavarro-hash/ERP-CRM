<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Integraciones\Ia\AsistenteIa;
use App\Modules\Integraciones\Mapas\Mapas;
use App\Modules\Integraciones\Models\Integracion;
use App\Modules\Integraciones\Whatsapp\Whatsapp;
use App\Modules\ContactCenter\Telefonia\Pbx;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** Configuración → Integraciones: una ficha por integración. El secreto solo se reemplaza, nunca se muestra. */
class IntegracionesController extends Controller
{
    public function index(Parametros $parametros)
    {
        $fichas = Integracion::all()->keyBy('tipo');
        $dias = (int) $parametros->valor('integraciones.rotacion_dias');

        return Inertia::render('Configuracion/Integraciones', [
            'integraciones' => collect(Integracion::TIPOS)->map(function ($info, $tipo) use ($fichas, $dias) {
                $f = $fichas[$tipo] ?? null;

                return ['tipo' => $tipo, 'nombre' => $info['nombre'], 'proposito' => $info['proposito'], 'simulada' => $this->simulada($tipo),
                    'activa' => (bool) $f?->activa, 'ambiente' => $f?->ambiente ?? 'certificacion', 'url' => $f?->url, 'identificador' => $f?->identificador,
                    'tiene_secreto' => (bool) $f?->secreto_cifrado, 'rotado' => $f?->secreto_rotado_at?->toIso8601String(),
                    'rotar' => $f?->secreto_cifrado && (! $f->secreto_rotado_at || $f->secreto_rotado_at->lt(now()->subDays($dias))),
                    'reintentos' => $f?->reintentos ?? 5, 'timeout_seg' => $f?->timeout_seg ?? 15, 'salud' => $f?->salud, 'salud_at' => $f?->salud_at?->toIso8601String()];
            })->values(),
            'rotacionDias' => $dias,
            'webhooks' => ['whatsapp' => url('/api/whatsapp/webhook'), 'pbx' => url('/api/pbx/eventos')],
        ]);
    }

    public function update(Request $request, string $tipo, Auditoria $auditoria)
    {
        $d = $request->validate([
            'activa' => ['boolean'], 'ambiente' => ['required', Rule::in(['certificacion', 'produccion'])],
            'url' => ['nullable', 'url:https', 'max:200'], 'identificador' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            'secreto' => ['nullable', 'string', 'min:8', 'max:2000'],
            'reintentos' => ['required', 'integer', 'between:0,20'], 'timeout_seg' => ['required', 'integer', 'between:1,120'],
        ], ['url.url' => 'Usa una URL https.']);
        $i = Integracion::firstOrNew(['tipo' => $tipo]);
        $antes = $i->exists ? $i->only(['activa', 'ambiente', 'url', 'identificador', 'reintentos', 'timeout_seg']) : null;
        $i->fill(collect($d)->except('secreto')->all());
        if (! empty($d['secreto'])) {
            $i->reemplazarSecreto($d['secreto']);
        }
        $i->save();
        $auditoria->registrar('integracion.guardar', $i, $antes, $i->only(['activa', 'ambiente', 'url', 'identificador', 'reintentos', 'timeout_seg']) + ['secreto_reemplazado' => ! empty($d['secreto'])]);

        return back()->with('exito', Integracion::TIPOS[$tipo]['nombre'].' guardada.'.(empty($d['secreto']) ? '' : ' El secreto quedó cifrado.'));
    }

    /** Prueba de salud. Con el adaptador simulado basta con que la ficha esté activa. */
    public function probar(string $tipo)
    {
        $i = Integracion::firstOrNew(['tipo' => $tipo]);
        $ok = $i->exists && $i->activa && ($this->simulada($tipo) || ($i->secreto_cifrado && $i->url));
        if ($i->exists) {
            $i->forceFill(['salud' => $ok ? 'ok' : 'caida', 'salud_at' => now()])->save();
        }

        return back()->with($ok ? 'exito' : 'error', $ok ? 'Integración respondiendo.'.($this->simulada($tipo) ? ' (simulada)' : '') : 'La integración no responde o no está activa.');
    }

    private function simulada(string $tipo): bool
    {
        return match ($tipo) {
            'whatsapp' => app(Whatsapp::class)->simulado(),
            'ia' => app(AsistenteIa::class)->simulado(),
            'mapas' => app(Mapas::class)->simulado(),
            'pbx' => app(Pbx::class)->simulada(),
            'meta_ads' => app(\App\Modules\Integraciones\MetaAds\MetaAds::class)->simulado(),
            'linkedin' => app(\App\Modules\Integraciones\Linkedin\Linkedin::class)->simulado(),
            default => true,
        };
    }
}
