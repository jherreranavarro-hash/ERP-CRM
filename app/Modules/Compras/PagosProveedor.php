<?php

namespace App\Modules\Compras;

use App\Models\User;
use App\Modules\Compras\Models\FacturaProveedor;
use App\Modules\Compras\Models\PagoProveedor;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pagos a proveedores contra facturas por pagar. Sobre el monto configurado
 * el pago espera la aprobación de otra persona; al aplicarse rebaja el saldo
 * y la factura queda pagada cuando llega a cero. Un pago resuelto no cambia.
 */
class PagosProveedor
{
    public function __construct(private Parametros $parametros, private Auditoria $auditoria) {}

    /** @param array{monto:int, fecha:string, metodo:string, referencia?:?string} $d */
    public function registrar(FacturaProveedor $factura, array $d, User $user): PagoProveedor
    {
        if (! array_key_exists($d['metodo'], PagoProveedor::METODOS)) {
            throw ValidationException::withMessages(['metodo' => 'Medio de pago no válido.']);
        }
        $monto = (int) $d['monto'];

        return DB::transaction(function () use ($factura, $d, $user, $monto) {
            $f = FacturaProveedor::whereKey($factura->id)->lockForUpdate()->firstOrFail();
            if ($f->estado !== 'por_pagar') {
                throw ValidationException::withMessages(['factura' => 'Solo se paga una factura por pagar.']);
            }
            $enTramite = (int) $f->pagos()->where('estado', 'por_aprobar')->sum('monto');
            $disponible = $f->saldo - $enTramite;
            if ($monto <= 0 || $monto > $disponible) {
                throw ValidationException::withMessages(['monto' => 'El pago debe ser mayor que cero y hasta $'.number_format(max(0, $disponible), 0, ',', '.').($enTramite ? ' (hay pagos esperando aprobación).' : '.')]);
            }
            $limite = (int) $this->parametros->valor('aprobaciones.pago_proveedor_monto');
            $pago = PagoProveedor::create([
                'factura_proveedor_id' => $f->id, 'monto' => $monto, 'fecha' => $d['fecha'], 'metodo' => $d['metodo'],
                'referencia' => isset($d['referencia']) ? mb_substr(trim((string) $d['referencia']), 0, 60) : null,
                'estado' => $monto > $limite ? 'por_aprobar' : 'aplicado', 'registrado_por' => $user->id,
                'aprobado_at' => $monto > $limite ? null : now(),
            ]);
            if ($pago->estado === 'aplicado') {
                $this->aplicar($f, $pago);
            }
            $this->auditoria->registrar('pago_proveedor.registrar', $pago, null, ['factura' => $f->nombre(), 'monto' => $monto, 'estado' => $pago->estado]);

            return $pago;
        });
    }

    public function aprobar(PagoProveedor $pago, User $user): PagoProveedor
    {
        return DB::transaction(function () use ($pago, $user) {
            $p = $this->porAprobar($pago, $user, 'aprueba');
            $f = FacturaProveedor::whereKey($p->factura_proveedor_id)->lockForUpdate()->firstOrFail();
            if ($f->estado !== 'por_pagar' || $p->monto > $f->saldo) {
                throw ValidationException::withMessages(['pago' => 'El saldo de la factura ya no admite este pago: recházalo.']);
            }
            $p->forceFill(['estado' => 'aplicado', 'aprobado_por' => $user->id, 'aprobado_at' => now()])->save();
            $this->aplicar($f, $p);
            $this->auditoria->registrar('pago_proveedor.aprobar', $p, null, ['factura' => $f->nombre(), 'monto' => $p->monto]);

            return $p;
        });
    }

    public function rechazar(PagoProveedor $pago, User $user, string $motivo): PagoProveedor
    {
        return DB::transaction(function () use ($pago, $user, $motivo) {
            $p = $this->porAprobar($pago, $user, 'rechaza');
            $p->forceFill(['estado' => 'rechazado', 'aprobado_por' => $user->id, 'motivo_rechazo' => mb_substr(trim($motivo), 0, 200)])->save();

            return $p;
        });
    }

    private function aplicar(FacturaProveedor $f, PagoProveedor $p): void
    {
        $saldo = $f->saldo - $p->monto;
        $f->forceFill(['saldo' => $saldo, 'estado' => $saldo === 0 ? 'pagada' : 'por_pagar'])->save();
    }

    private function porAprobar(PagoProveedor $pago, User $user, string $verbo): PagoProveedor
    {
        $p = PagoProveedor::whereKey($pago->id)->lockForUpdate()->firstOrFail();
        if ($p->estado !== 'por_aprobar') {
            throw ValidationException::withMessages(['pago' => 'El pago ya está resuelto.']);
        }
        if ($p->registrado_por === $user->id) {
            throw ValidationException::withMessages(['pago' => "Nadie {$verbo} un pago que registró: debe revisarlo otra persona."]);
        }

        return $p;
    }
}
