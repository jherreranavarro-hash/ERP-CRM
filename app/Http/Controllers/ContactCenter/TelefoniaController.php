<?php

namespace App\Http\Controllers\ContactCenter;

use App\Http\Controllers\Controller;
use App\Modules\ContactCenter\Models\Cola;
use App\Modules\ContactCenter\Models\Ivr;
use App\Modules\ContactCenter\Models\ListaNegra;
use App\Modules\ContactCenter\Models\NumeroEntrante;
use App\Modules\ContactCenter\Models\ProveedorIp;
use App\Modules\ContactCenter\Telefonia\Pbx;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Configuración → telefonía (ProveedorIP, NumeroEntrante, Ivr, ListaNegra),
 * aislada del modelo de usuario. El secreto del usuario de servicio AMI/ARI se
 * guarda cifrado, nunca se muestra ni se exporta: solo se reemplaza.
 */
class TelefoniaController extends Controller
{
    private function e(): int
    {
        return (int) app(Tenancy::class)->id();
    }

    public function proveedores()
    {
        return Inertia::render('ContactCenter/Telefonia/Proveedores', [
            'proveedores' => ProveedorIp::orderBy('nombre')->get()->map(fn ($p) => $p->only(['id', 'nombre', 'tipo', 'host', 'puerto', 'protocolo', 'usuario_servicio', 'activo', 'salud'])
                + ['tiene_secreto' => $p->secreto_cifrado !== null, 'rotado' => $p->secreto_rotado_at?->toIso8601String(), 'salud_at' => $p->salud_at?->toIso8601String(),
                    'rotar' => ! $p->secreto_rotado_at || $p->secreto_rotado_at->lt(now()->subDays(90))]),
            'tipos' => ProveedorIp::TIPOS, 'protocolos' => ProveedorIp::PROTOCOLOS,
        ]);
    }

    public function guardarProveedor(Request $request, Auditoria $auditoria, ?ProveedorIp $proveedor = null)
    {
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:80', Rule::unique('proveedores_ip')->where('empresa_id', $this->e())->ignore($proveedor?->id)],
            'tipo' => ['required', Rule::in(array_keys(ProveedorIp::TIPOS))],
            'host' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'puerto' => ['required', 'integer', 'between:1,65535'],
            'protocolo' => ['required', Rule::in(array_keys(ProveedorIp::PROTOCOLOS))],
            'usuario_servicio' => ['required', 'string', 'max:60'],
            'secreto' => [$proveedor ? 'nullable' : 'required', 'string', 'min:8', 'max:200'],
            'activo' => ['boolean'],
        ], ['host.regex' => 'Indica solo el nombre o la IP del servidor, sin protocolo ni ruta.']);
        $p = $proveedor ?? new ProveedorIp;
        $p->fill(collect($d)->except('secreto')->all());
        if (! empty($d['secreto'])) {
            $p->forceFill(['secreto_cifrado' => Crypt::encryptString($d['secreto']), 'secreto_rotado_at' => now()]);
        }
        $p->save();
        if (! empty($d['secreto'])) {
            $auditoria->registrar('telefonia.secreto_reemplazado', $p);
        }

        return back()->with('exito', 'Proveedor guardado.'.(empty($d['secreto']) ? '' : ' El secreto quedó cifrado.'));
    }

    public function probarProveedor(ProveedorIp $proveedor, Pbx $pbx)
    {
        $ok = $pbx->probar($proveedor->host, $proveedor->puerto, $proveedor->usuario_servicio, $proveedor->secreto_cifrado ? Crypt::decryptString($proveedor->secreto_cifrado) : null);
        $proveedor->forceFill(['salud' => $ok ? 'ok' : 'caida', 'salud_at' => now()])->save();

        return back()->with($ok ? 'exito' : 'error', $ok ? 'Conexión correcta con la central.' : 'No se pudo conectar con la central.');
    }

    public function numeros()
    {
        return Inertia::render('ContactCenter/Telefonia/Numeros', [
            'numeros' => NumeroEntrante::with('proveedor:id,nombre')->orderBy('numero')->get()->map(fn ($n) => $n->only(['id', 'numero', 'descripcion', 'proveedor_ip_id', 'destino_tipo', 'destino_id', 'activo'])
                + ['proveedor' => $n->proveedor?->nombre]),
            'proveedores' => ProveedorIp::orderBy('nombre')->get(['id', 'nombre']),
            'ivrs' => Ivr::orderBy('nombre')->get(['id', 'nombre']),
            'colas' => Cola::orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    public function guardarNumero(Request $request, ?NumeroEntrante $numero = null)
    {
        $d = $request->validate([
            'numero' => ['required', 'regex:/^\+?\d{8,15}$/', Rule::unique('numeros_entrantes')->where('empresa_id', $this->e())->ignore($numero?->id)],
            'descripcion' => ['nullable', 'string', 'max:120'],
            'proveedor_ip_id' => ['nullable', 'integer', Rule::exists('proveedores_ip', 'id')->where('empresa_id', $this->e())],
            'destino_tipo' => ['required', 'in:ivr,cola'],
            'destino_id' => ['required', 'integer', Rule::exists($request->input('destino_tipo') === 'ivr' ? 'ivrs' : 'colas', 'id')->where('empresa_id', $this->e())],
            'activo' => ['boolean'],
        ], ['numero.regex' => 'Número en formato internacional, por ejemplo +56222345678.']);
        $numero ? $numero->update($d) : NumeroEntrante::create($d);

        return back()->with('exito', 'Número guardado.');
    }

    public function ivrs()
    {
        return Inertia::render('ContactCenter/Telefonia/Ivrs', [
            'ivrs' => Ivr::orderBy('nombre')->get()->map(fn ($i) => $i->only(['id', 'nombre', 'bienvenida', 'fuera_de_horario', 'opciones', 'horario', 'activo'])),
            'colas' => Cola::orderBy('nombre')->get(['id', 'nombre']),
            'acciones' => Ivr::ACCIONES,
        ]);
    }

    public function guardarIvr(Request $request, ?Ivr $ivr = null)
    {
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:80', Rule::unique('ivrs')->where('empresa_id', $this->e())->ignore($ivr?->id)],
            'bienvenida' => ['required', 'string', 'max:1000'],
            'fuera_de_horario' => ['nullable', 'string', 'max:1000'],
            'opciones' => ['required', 'array', 'min:1', 'max:10'],
            'opciones.*.tecla' => ['required', 'regex:/^[0-9*#]$/', 'distinct'],
            'opciones.*.etiqueta' => ['required', 'string', 'max:60'],
            'opciones.*.accion' => ['required', Rule::in(array_keys(Ivr::ACCIONES))],
            'opciones.*.destino' => ['nullable', 'integer'],
            'horario.dias' => ['nullable', 'array'], 'horario.dias.*' => ['integer', 'between:1,7'],
            'horario.desde' => ['nullable', 'date_format:H:i'], 'horario.hasta' => ['nullable', 'date_format:H:i'],
            'activo' => ['boolean'],
        ], ['opciones.*.tecla.distinct' => 'Cada tecla se usa una sola vez.']);
        foreach ($d['opciones'] as $i => $o) {
            $tabla = ['cola' => 'colas', 'ivr' => 'ivrs'][$o['accion']] ?? null;
            if ($tabla && ! \DB::table($tabla)->where('empresa_id', $this->e())->where('id', $o['destino'] ?? 0)->exists()) {
                throw ValidationException::withMessages(["opciones.{$i}.destino" => 'Elige el destino de la tecla '.$o['tecla'].'.']);
            }
            if ($o['accion'] === 'ivr' && $ivr && (int) $o['destino'] === $ivr->id) {
                throw ValidationException::withMessages(["opciones.{$i}.destino" => 'Un menú no puede llevarse a sí mismo.']);
            }
        }
        $ivr ? $ivr->update($d) : Ivr::create($d);

        return back()->with('exito', 'Menú de voz guardado.');
    }

    public function listaNegra(Request $request)
    {
        $q = ListaNegra::with('creador:id,name')->orderByDesc('id');
        if ($b = preg_replace('/\D/', '', (string) $request->input('buscar'))) {
            $q->where('numero', 'like', "%{$b}%");
        }

        return Inertia::render('ContactCenter/Telefonia/ListaNegra', [
            'numeros' => $q->paginate(50)->withQueryString()->through(fn ($n) => ['id' => $n->id, 'numero' => $n->numero, 'motivo' => $n->motivo, 'hasta' => $n->hasta?->toDateString(),
                'creador' => $n->creador?->name, 'fecha' => $n->created_at->toIso8601String()]),
            'filtros' => $request->only('buscar'),
        ]);
    }

    public function agregarListaNegra(Request $request)
    {
        $d = $request->validate([
            'numero' => ['required', 'regex:/^\+?\d{8,15}$/', Rule::unique('lista_negra')->where('empresa_id', $this->e())],
            'motivo' => ['required', 'string', 'min:5', 'max:200'],
            'hasta' => ['nullable', 'date', 'after:today'],
        ], ['numero.unique' => 'Ese número ya está en la lista.', 'numero.regex' => 'Número no válido.']);
        ListaNegra::create($d + ['creado_por' => $request->user()->id]);

        return back()->with('exito', 'Número bloqueado: no se le llamará desde ninguna campaña.');
    }

    public function quitarListaNegra(ListaNegra $numero, Auditoria $auditoria)
    {
        $auditoria->registrar('lista_negra.quitar', $numero, $numero->only(['numero', 'motivo']));
        $numero->delete();

        return back()->with('exito', 'Número quitado de la lista negra.');
    }
}
