<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\ContactCenter\CentroContacto;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Http\Request;

/**
 * Canal de eventos de la central telefónica (AMI/ARI → servicio puente → aquí).
 * Cada evento llega firmado con HMAC-SHA256 del cuerpo y una marca de tiempo;
 * sin firma válida o con más de 5 minutos de antigüedad se rechaza.
 */
class PbxWebhookController extends Controller
{
    public function __invoke(Request $request, CentroContacto $cc, Tenancy $tenancy)
    {
        $secreto = (string) config('services.pbx.webhook_secret');
        abort_if($secreto === '', 503, 'Canal de la central no configurado.');
        $momento = (int) $request->header('X-Pbx-Momento');
        abort_if(abs(time() - $momento) > 300, 401, 'Evento vencido.');
        $esperada = hash_hmac('sha256', $momento.'.'.$request->getContent(), $secreto);
        abort_unless(hash_equals($esperada, (string) $request->header('X-Pbx-Firma')), 401, 'Firma no válida.');

        $d = $request->validate([
            'empresa' => ['required', 'integer'],
            'tipo' => ['required', 'in:entrante,contestada,terminada,no_contesta,ocupado,fallida,grabacion'],
            'pbx_id' => ['required', 'string', 'max:80'],
            'numero_destino' => ['required_if:tipo,entrante', 'nullable', 'string', 'max:20'],
            'origen' => ['required_if:tipo,entrante', 'nullable', 'string', 'max:20'],
            'grabacion' => ['nullable', 'string', 'max:200'],
        ]);
        $empresa = Empresa::findOrFail($d['empresa']);

        $llamada = $tenancy->como($empresa, fn () => $d['tipo'] === 'entrante'
            ? $cc->entrante($d['numero_destino'], $d['origen'], $d['pbx_id'])
            : $cc->evento($d['pbx_id'], $d['tipo'], ['grabacion' => $d['grabacion'] ?? null]));

        return response()->json(['ok' => true, 'llamada' => $llamada?->id]);
    }
}
