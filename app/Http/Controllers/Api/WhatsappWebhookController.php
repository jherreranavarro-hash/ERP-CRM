<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Mensajeria\Bandeja;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhook de WhatsApp Cloud API. GET: verificación con el token acordado.
 * POST: cada envío trae X-Hub-Signature-256 = HMAC-SHA256 del cuerpo con el
 * secreto de la app; sin firma válida se rechaza. La empresa se ubica por el
 * phone_number_id configurado en su ficha de integración.
 */
class WhatsappWebhookController extends Controller
{
    public function verificar(Request $request)
    {
        $token = (string) config('services.whatsapp.verify_token');
        abort_if($token === '', 503, 'Webhook de WhatsApp no configurado.');
        abort_unless($request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token')), 403);

        return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
    }

    public function recibir(Request $request, Bandeja $bandeja, Tenancy $tenancy)
    {
        $secreto = (string) config('services.whatsapp.app_secret');
        abort_if($secreto === '', 503, 'Webhook de WhatsApp no configurado.');
        $esperada = 'sha256='.hash_hmac('sha256', $request->getContent(), $secreto);
        abort_unless(hash_equals($esperada, (string) $request->header('X-Hub-Signature-256')), 401, 'Firma no válida.');

        $procesados = 0;
        foreach ((array) $request->input('entry', []) as $entrada) {
            foreach ((array) ($entrada['changes'] ?? []) as $cambio) {
                $v = (array) ($cambio['value'] ?? []);
                $phoneId = (string) ($v['metadata']['phone_number_id'] ?? '');
                $empresaId = $phoneId !== '' ? DB::selectOne('select empresa_por_integracion(?, ?) as id', ['whatsapp', $phoneId])?->id : null;
                if (! $empresaId) {
                    Log::notice('WhatsApp: evento para un número no configurado.');

                    continue;
                }
                $tenancy->como(Empresa::findOrFail($empresaId), function () use ($v, $bandeja, &$procesados) {
                    $nombres = collect($v['contacts'] ?? [])->mapWithKeys(fn ($c) => [(string) ($c['wa_id'] ?? '') => $c['profile']['name'] ?? null]);
                    foreach ((array) ($v['messages'] ?? []) as $m) {
                        $texto = match ($m['type'] ?? '') {
                            'text' => (string) ($m['text']['body'] ?? ''),
                            'button' => (string) ($m['button']['text'] ?? ''),
                            'interactive' => (string) ($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? ''),
                            default => '['.($m['type'] ?? 'mensaje').' no soportado en la bandeja]',
                        };
                        if (! empty($m['from']) && ! empty($m['id'])) {
                            $bandeja->recibir((string) $m['from'], $nombres[(string) $m['from']] ?? null, (string) $m['id'], $texto,
                                CarbonImmutable::createFromTimestamp((int) ($m['timestamp'] ?? time())));
                            $procesados++;
                        }
                    }
                    foreach ((array) ($v['statuses'] ?? []) as $s) {
                        $estado = ['sent' => 'enviado', 'delivered' => 'entregado', 'read' => 'leido', 'failed' => 'fallido'][$s['status'] ?? ''] ?? null;
                        if ($estado && ! empty($s['id'])) {
                            $bandeja->estado((string) $s['id'], $estado, $s['errors'][0]['title'] ?? null);
                            $procesados++;
                        }
                    }
                });
            }
        }

        return response()->json(['ok' => true, 'procesados' => $procesados]);
    }
}
