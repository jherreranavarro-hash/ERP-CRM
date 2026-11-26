<?php

namespace App\Modules\Inventario;

use App\Models\User;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Inventario\Models\Despacho;
use App\Modules\Inventario\Transporte\Transportistas;
use App\Support\Rut;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Despachos de documentos emitidos. Estados:
 * por_aprobar → programado → en_ruta → entregado | fallido → (programado | anulado).
 * La base de datos rechaza cualquier otra transición y cada cambio queda en
 * despacho_eventos. Entregar exige nombre de quien recibe y firma.
 */
class Despachos
{
    public const TIPOS = [33, 34, 39, 41, 52];

    public const FIRMA_MAX_BYTES = 300_000;

    public function __construct(private Parametros $parametros, private Auditoria $auditoria, private Transportistas $transportistas) {}

    /**
     * @param  array{direccion?:?string, comuna?:?string, contacto?:?string, telefono?:?string, fecha_programada:string, ventana?:?string, transportista?:string, cobro?:int, observaciones?:?string}  $d
     */
    public function crear(Documento $doc, array $d, User $user): Despacho
    {
        if (! in_array($doc->tipo_dte, self::TIPOS, true) || ! in_array($doc->estado, ['emitido', 'pagado'], true)) {
            throw ValidationException::withMessages(['documento' => 'Se despacha una factura, boleta o guía ya emitida (no anulada).']);
        }
        if (Despacho::where('documento_id', $doc->id)->where('estado', '<>', 'anulado')->exists()) {
            throw ValidationException::withMessages(['documento' => 'Este documento ya tiene un despacho activo.']);
        }
        $cliente = $doc->cliente;
        $direccion = trim((string) ($d['direccion'] ?? $cliente?->direccion ?? ''));
        $comuna = trim((string) ($d['comuna'] ?? $cliente?->comuna ?? ''));
        if ($direccion === '' || $comuna === '') {
            throw ValidationException::withMessages(['direccion' => 'Indica la dirección y comuna de entrega.']);
        }
        $fecha = Carbon::parse($d['fecha_programada'])->startOfDay();
        if ($fecha->lt(now()->startOfDay())) {
            throw ValidationException::withMessages(['fecha_programada' => 'La fecha de despacho no puede ser anterior a hoy.']);
        }
        $transportista = $d['transportista'] ?? 'propio';
        if (! $this->transportistas->existe($transportista)) {
            throw ValidationException::withMessages(['transportista' => 'Transportista no válido.']);
        }
        $cobro = (int) ($d['cobro'] ?? 0);
        if ($cobro < 0) {
            throw ValidationException::withMessages(['cobro' => 'El cargo de despacho no puede ser negativo.']);
        }

        $motivos = [];
        $gratisDesde = (int) $this->parametros->valor('despacho.gratis_desde', $doc->local_id);
        if ($cobro === 0 && $this->parametros->valor('aprobaciones.despacho_gratis') && $doc->monto_total < $gratisDesde) {
            $motivos[] = 'Despacho gratis en una venta de $'.number_format($doc->monto_total, 0, ',', '.').' (sin aprobación desde $'.number_format($gratisDesde, 0, ',', '.').')';
        }

        return DB::transaction(function () use ($doc, $d, $user, $cliente, $direccion, $comuna, $fecha, $transportista, $cobro, $motivos) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['despachos:'.app(Tenancy::class)->id()]);
            $desp = Despacho::create([
                'numero' => (int) Despacho::max('numero') + 1, 'documento_id' => $doc->id, 'local_id' => $doc->local_id, 'cliente_id' => $cliente?->id,
                'estado' => $motivos ? 'por_aprobar' : 'programado', 'direccion' => mb_substr($direccion, 0, 150), 'comuna' => mb_substr($comuna, 0, 60),
                'contacto' => $d['contacto'] ?? null, 'telefono' => $d['telefono'] ?? null, 'fecha_programada' => $fecha->toDateString(),
                'ventana' => $d['ventana'] ?? null, 'transportista' => $transportista, 'cobro' => $cobro, 'motivos' => $motivos ?: null,
                'observaciones' => $d['observaciones'] ?? null, 'creado_por' => $user->id,
            ]);
            $desp->eventos()->create(['de' => null, 'a' => $desp->estado, 'nota' => $motivos ? implode('; ', $motivos) : null, 'usuario_id' => $user->id]);
            $this->auditoria->registrar('despacho.crear', $desp, null, ['numero' => $desp->numero, 'documento' => $doc->nombre(), 'estado' => $desp->estado]);

            return $desp;
        });
    }

    public function aprobar(Despacho $despacho, User $user): Despacho
    {
        return $this->transicion($despacho, 'programado', $user, function (Despacho $d) use ($user) {
            if ($d->creado_por === $user->id) {
                throw ValidationException::withMessages(['despacho' => 'Nadie aprueba su propio despacho gratis: debe aprobarlo otra persona.']);
            }

            return ['aprobado_por' => $user->id];
        }, 'Despacho gratis aprobado', desde: 'por_aprobar');
    }

    /** Sale a ruta; con transportista externo se crea el envío y queda su número de seguimiento. */
    public function despachar(Despacho $despacho, User $user): Despacho
    {
        return $this->transicion($despacho, 'en_ruta', $user, function (Despacho $d) {
            $externo = $this->transportistas->externo($d->transportista);

            return $externo ? ['tracking' => $externo->crearEnvio($d)] : [];
        });
    }

    /** Entrega con nombre (y RUT opcional) de quien recibe y su firma (PNG en base64, desde el dispositivo). */
    public function entregar(Despacho $despacho, string $nombre, ?string $rut, string $firma, User $user): Despacho
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            throw ValidationException::withMessages(['recibido_nombre' => 'Indica quién recibe.']);
        }
        if ($rut !== null && $rut !== '' && ! Rut::valido($rut)) {
            throw ValidationException::withMessages(['recibido_rut' => 'El RUT de quien recibe no es válido.']);
        }
        $png = $this->decodificarFirma($firma);

        return $this->transicion($despacho, 'entregado', $user, function (Despacho $d) use ($nombre, $rut, $png) {
            $ruta = 'firmas/'.$d->empresa_id.'/despacho-'.$d->numero.'-'.Str::random(8).'.png';
            Storage::disk('local')->put($ruta, $png);

            return ['recibido_nombre' => mb_substr($nombre, 0, 100), 'recibido_rut' => $rut ? Rut::normalizar($rut) : null, 'firma_path' => $ruta, 'entregado_at' => now()];
        }, "Recibe {$nombre}");
    }

    public function fallar(Despacho $despacho, string $motivo, User $user): Despacho
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw ValidationException::withMessages(['motivo' => 'Indica por qué no se pudo entregar.']);
        }

        return $this->transicion($despacho, 'fallido', $user, fn () => ['motivo_fallo' => mb_substr($motivo, 0, 200)], $motivo);
    }

    public function reprogramar(Despacho $despacho, string $fecha, User $user): Despacho
    {
        $f = Carbon::parse($fecha)->startOfDay();
        if ($f->lt(now()->startOfDay())) {
            throw ValidationException::withMessages(['fecha_programada' => 'La nueva fecha no puede ser anterior a hoy.']);
        }

        return $this->transicion($despacho, 'programado', $user, fn (Despacho $d) => ['fecha_programada' => $f->toDateString(), 'intentos' => $d->intentos + 1, 'tracking' => null],
            'Reprogramado para el '.$f->format('d-m-Y'), desde: 'fallido');
    }

    public function anular(Despacho $despacho, string $motivo, User $user): Despacho
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw ValidationException::withMessages(['motivo' => 'Indica el motivo de la anulación.']);
        }

        return $this->transicion($despacho, 'anulado', $user, fn () => [], $motivo);
    }

    /** Contenido de la firma guardada (para mostrarla a quien tiene acceso al despacho). */
    public function firma(Despacho $d): ?string
    {
        return $d->firma_path && Storage::disk('local')->exists($d->firma_path) ? Storage::disk('local')->get($d->firma_path) : null;
    }

    /**
     * @param  callable(Despacho): array  $cambios
     */
    private function transicion(Despacho $despacho, string $a, User $user, callable $cambios, ?string $nota = null, ?string $desde = null): Despacho
    {
        return DB::transaction(function () use ($despacho, $a, $user, $cambios, $nota, $desde) {
            /** @var Despacho $d */
            $d = Despacho::whereKey($despacho->id)->lockForUpdate()->firstOrFail();
            if (($desde && $d->estado !== $desde) || ! $d->puedePasarA($a)) {
                throw ValidationException::withMessages(['despacho' => 'Un despacho '.mb_strtolower(Despacho::ESTADOS[$d->estado]).' no puede pasar a '.mb_strtolower(Despacho::ESTADOS[$a]).'.']);
            }
            $de = $d->estado;
            $d->forceFill(['estado' => $a] + $cambios($d))->save();
            $d->eventos()->create(['de' => $de, 'a' => $a, 'nota' => $nota ? mb_substr($nota, 0, 200) : null, 'usuario_id' => $user->id]);
            $this->auditoria->registrar('despacho.'.$a, $d, ['estado' => $de], ['estado' => $a, 'numero' => $d->numero], $nota);

            return $d;
        });
    }

    private function decodificarFirma(string $firma): string
    {
        $datos = preg_replace('#^data:image/png;base64,#', '', $firma);
        $png = base64_decode((string) $datos, true);
        if ($png === false || ! str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
            throw ValidationException::withMessages(['firma' => 'Falta la firma de quien recibe.']);
        }
        if (strlen($png) > self::FIRMA_MAX_BYTES) {
            throw ValidationException::withMessages(['firma' => 'La imagen de la firma es demasiado grande.']);
        }

        return $png;
    }
}
