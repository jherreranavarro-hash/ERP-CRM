<?php

namespace App\Modules\Integraciones\Ia;

use App\Models\User;
use App\Modules\Integraciones\Models\Integracion;
use App\Modules\Mensajeria\Models\Conversacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Apoyo de IA para ejecutivos y agentes. Antes de salir hacia el proveedor,
 * todo el texto pasa por el Seudonimizador; la respuesta vuelve con los datos
 * restituidos solo dentro del sistema. Se registra quién lo usó y cuántos datos
 * se ocultaron, nunca el contenido.
 */
class Asistente
{
    private const MENSAJES = 20;

    public function __construct(private AsistenteIa $ia) {}

    public function sugerir(Conversacion $c, User $user): string
    {
        return $this->usar($c, $user, 'sugerir', fn (array $conv) => $this->ia->sugerirRespuesta($conv));
    }

    public function resumir(Conversacion $c, User $user): string
    {
        return $this->usar($c, $user, 'resumir', fn (array $conv) => $this->ia->resumir($conv));
    }

    private function usar(Conversacion $c, User $user, string $accion, callable $llamar): string
    {
        if (! Integracion::activa('ia')) {
            throw ValidationException::withMessages(['ia' => 'El asistente de IA no está activo (Configuración → Integraciones).']);
        }
        $c->loadMissing('cliente:id,razon_social,rut', 'lead:id,nombre,rut');
        $s = new Seudonimizador(array_filter([$c->nombre, $user->name, $c->cliente?->razon_social, $c->lead?->nombre]));
        $mensajes = $c->mensajes()->latest('id')->limit(self::MENSAJES)->get()->reverse()->values();
        if ($mensajes->isEmpty()) {
            throw ValidationException::withMessages(['ia' => 'La conversación no tiene mensajes.']);
        }
        $conv = $mensajes->map(fn ($m) => ['rol' => $m->direccion === 'entrante' ? 'cliente' : 'ejecutivo', 'texto' => $s->ocultar($m->cuerpo)])->all();
        $respuesta = $s->restituir((string) $llamar($conv));
        DB::table('ia_usos')->insert(['empresa_id' => $c->empresa_id, 'user_id' => $user->id, 'accion' => $accion, 'contexto' => 'whatsapp:'.$c->id,
            'caracteres' => array_sum(array_map(fn ($m) => mb_strlen($m['texto']), $conv)), 'datos_ocultados' => $s->ocultados(), 'created_at' => now()]);

        return $respuesta;
    }
}
