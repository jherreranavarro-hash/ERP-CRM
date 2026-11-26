<?php

namespace App\Modules\Inventario;

use App\Models\User;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Parametros\Parametros;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Inventario\Models\CambioPrecio;
use App\Modules\Inventario\Models\CambioPrecioLinea;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Actualización de precios de venta (mesón y mayorista). Todo cambio queda en
 * el historial (lo escribe un trigger). Si alguna variación supera el
 * porcentaje permitido, o un precio queda bajo el costo, el cambio espera la
 * aprobación de otra persona y recién entonces se aplica.
 */
class Precios
{
    public const MAX_LINEAS = 500;

    public function __construct(private Parametros $parametros, private Auditoria $auditoria) {}

    /**
     * @param  list<array{producto_id:int, campo:string, nuevo:string|int|float|null}>  $cambios
     */
    public function proponer(array $cambios, string $descripcion, User $user): CambioPrecio
    {
        $descripcion = trim($descripcion);
        if ($descripcion === '') {
            throw ValidationException::withMessages(['descripcion' => 'Describe el cambio (por ejemplo, "Alza proveedor cemento").']);
        }
        if (count($cambios) > self::MAX_LINEAS) {
            throw ValidationException::withMessages(['cambios' => 'Máximo '.self::MAX_LINEAS.' cambios por solicitud.']);
        }

        $productos = Producto::whereIn('id', array_column($cambios, 'producto_id'))->get()->keyBy('id');
        $umbral = BigDecimal::of((string) $this->parametros->valor('inventario.precio_variacion_pct'));
        $lineas = [];
        $sobre = [];
        $bajoCosto = [];
        foreach ($cambios as $i => $c) {
            if (! array_key_exists($c['campo'] ?? '', CambioPrecioLinea::CAMPOS)) {
                throw ValidationException::withMessages(["cambios.{$i}" => 'Campo de precio no válido.']);
            }
            $p = $productos[$c['producto_id']] ?? throw ValidationException::withMessages(["cambios.{$i}" => 'Producto no encontrado.']);
            $nuevo = $c['nuevo'] === null || $c['nuevo'] === '' ? null : BigDecimal::of((string) $c['nuevo']);
            if ($nuevo === null && $c['campo'] === 'precio_neto') {
                throw ValidationException::withMessages(["cambios.{$i}" => "{$p->sku}: el precio mesón es obligatorio."]);
            }
            if ($nuevo?->isNegative()) {
                throw ValidationException::withMessages(["cambios.{$i}" => "{$p->sku}: el precio no puede ser negativo."]);
            }
            $anterior = $p->{$c['campo']} === null ? null : BigDecimal::of((string) $p->{$c['campo']});
            if ($this->iguales($anterior, $nuevo)) {
                continue;
            }

            $variacion = $anterior !== null && $nuevo !== null && $anterior->isPositive()
                ? $nuevo->minus($anterior)->multipliedBy(100)->dividedBy($anterior, 2, RoundingMode::HALF_UP)
                : null;
            if ($variacion !== null && $variacion->abs()->isGreaterThan($umbral)) {
                $sobre[] = $p->sku;
            }
            if ($nuevo !== null && $p->costo !== null && $nuevo->isLessThan((string) $p->costo)) {
                $bajoCosto[] = $p->sku;
            }
            $lineas[] = ['producto_id' => $p->id, 'campo' => $c['campo'], 'anterior' => $anterior ? (string) $anterior : null, 'nuevo' => $nuevo ? (string) $nuevo : null, 'variacion_pct' => $variacion ? (string) $variacion : null];
        }
        if (! $lineas) {
            throw ValidationException::withMessages(['cambios' => 'Ningún precio cambia.']);
        }

        $motivos = [];
        if ($sobre) {
            $motivos[] = 'Variación sobre '.$umbral->stripTrailingZeros().' %: '.$this->lista($sobre);
        }
        if ($bajoCosto) {
            $motivos[] = 'Precio bajo el costo: '.$this->lista($bajoCosto);
        }

        return DB::transaction(function () use ($lineas, $descripcion, $motivos, $user) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['cambios_precio:'.app(Tenancy::class)->id()]);
            $cambio = CambioPrecio::create([
                'numero' => (int) CambioPrecio::max('numero') + 1, 'descripcion' => mb_substr($descripcion, 0, 150),
                'estado' => 'por_aprobar', 'motivos' => $motivos ?: null, 'solicitado_por' => $user->id,
            ]);
            foreach ($lineas as $l) {
                $cambio->lineas()->create($l);
            }
            if (! $motivos) {
                $this->aplicar($cambio, $user);
            }

            return $cambio->load('lineas');
        });
    }

    /**
     * Ajuste porcentual por categoría (o a todo el catálogo), redondeando al
     * múltiplo indicado. Pasa por las mismas reglas que un cambio individual.
     */
    public function masivo(?int $categoriaId, string $campo, string $porcentaje, int $redondeo, string $descripcion, User $user): CambioPrecio
    {
        $pct = BigDecimal::of($porcentaje);
        if ($pct->isZero() || $pct->isLessThanOrEqualTo(-100)) {
            throw ValidationException::withMessages(['porcentaje' => 'Indica un porcentaje distinto de cero y mayor que −100.']);
        }
        if (! in_array($redondeo, [1, 10, 50, 100], true)) {
            throw ValidationException::withMessages(['redondeo' => 'Redondeo no válido.']);
        }
        $factor = BigDecimal::one()->plus($pct->dividedBy(100, 6, RoundingMode::HALF_UP));
        $productos = Producto::where('activo', true)->when($categoriaId, fn ($q) => $q->where('categoria_id', $categoriaId))->whereNotNull($campo)->orderBy('id')->get();
        if ($productos->isEmpty()) {
            throw ValidationException::withMessages(['categoria_id' => 'No hay productos con ese precio en la categoría.']);
        }

        $cambios = $productos->map(fn (Producto $p) => [
            'producto_id' => $p->id, 'campo' => $campo,
            'nuevo' => (string) BigDecimal::of((string) $p->{$campo})->multipliedBy($factor)->dividedBy($redondeo, 0, RoundingMode::HALF_UP)->multipliedBy($redondeo),
        ])->all();

        return $this->proponer($cambios, $descripcion, $user);
    }

    public function aprobar(CambioPrecio $cambio, User $user): CambioPrecio
    {
        return DB::transaction(function () use ($cambio, $user) {
            $c = CambioPrecio::whereKey($cambio->id)->lockForUpdate()->firstOrFail();
            if ($c->estado !== 'por_aprobar') {
                throw ValidationException::withMessages(['cambio' => 'Este cambio no está pendiente de aprobación.']);
            }
            if ($c->solicitado_por === $user->id) {
                throw ValidationException::withMessages(['cambio' => 'Nadie aprueba su propio cambio de precios: debe aprobarlo otra persona.']);
            }
            $c->forceFill(['aprobado_por' => $user->id])->save();
            $this->aplicar($c, $user);

            return $c;
        });
    }

    public function rechazar(CambioPrecio $cambio, User $user, string $motivo): CambioPrecio
    {
        if ($cambio->estado !== 'por_aprobar') {
            throw ValidationException::withMessages(['cambio' => 'Este cambio no está pendiente de aprobación.']);
        }
        if ($cambio->solicitado_por === $user->id) {
            throw ValidationException::withMessages(['cambio' => 'Para retirar tu propia solicitud, pide a otra persona que la rechace.']);
        }
        $cambio->forceFill(['estado' => 'rechazado', 'aprobado_por' => $user->id, 'motivo_rechazo' => mb_substr(trim($motivo), 0, 200)])->save();

        return $cambio;
    }

    /** Aplica con las filas de producto bloqueadas; si un precio cambió desde la solicitud, no se aplica nada. */
    private function aplicar(CambioPrecio $c, User $user): void
    {
        $lineas = $c->lineas()->get();
        $productos = Producto::whereIn('id', $lineas->pluck('producto_id'))->lockForUpdate()->get()->keyBy('id');
        foreach ($lineas as $l) {
            $actual = $productos[$l->producto_id]->{$l->campo};
            if (! $this->iguales($actual === null ? null : BigDecimal::of((string) $actual), $l->anterior === null ? null : BigDecimal::of((string) $l->anterior))) {
                throw ValidationException::withMessages(['cambio' => "El precio de {$productos[$l->producto_id]->sku} cambió desde que se pidió este ajuste: recházalo y crea uno nuevo."]);
            }
        }

        DB::select("select set_config('app.origen_precio', 'actualizacion', true), set_config('app.cambio_precio_id', ?, true)", [(string) $c->id]);
        foreach ($lineas->groupBy('producto_id') as $pid => $ls) {
            Producto::whereKey($pid)->update($ls->mapWithKeys(fn ($l) => [$l->campo => $l->nuevo])->all() + ['updated_at' => now()]);
        }
        DB::select("select set_config('app.origen_precio', '', true), set_config('app.cambio_precio_id', '', true)");

        $c->forceFill(['estado' => 'aplicado', 'aplicado_at' => now()])->save();
        $this->auditoria->registrar('precios.aplicar', $c, null, ['numero' => $c->numero, 'lineas' => $lineas->count(), 'aprobado_por' => $c->aprobado_por]);
    }

    private function iguales(?BigDecimal $a, ?BigDecimal $b): bool
    {
        return $a === null || $b === null ? $a === $b : $a->isEqualTo($b);
    }

    private function lista(array $skus): string
    {
        return implode(', ', array_slice($skus, 0, 5)).(count($skus) > 5 ? ' y '.(count($skus) - 5).' más' : '');
    }
}
