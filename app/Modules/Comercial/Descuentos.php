<?php

namespace App\Modules\Comercial;

use App\Models\User;
use App\Modules\Comercial\Models\NivelDescuento;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Comercial\Models\ReglaDescuento;
use App\Modules\Core\Parametros\Parametros;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de descuento y niveles de aprobación.
 *  - Una regla vigente (categoría, lista del cliente, cantidad mínima) se aplica sola
 *    cuando la línea no trae descuento, y autoriza ese % sin aprobación.
 *  - Sobre el máximo de la empresa (y sobre la regla), el descuento requiere aprobación
 *    del rol del escalón que lo cubre o de uno superior. Sobre el último escalón no se acepta.
 *  - Sin escalones configurados, aprueba cualquiera con el permiso (comportamiento base).
 */
class Descuentos
{
    /** @var Collection<int, ReglaDescuento>|null */
    private ?Collection $reglas = null;

    public function __construct(private Parametros $parametros) {}

    public function maximo(): BigDecimal
    {
        return BigDecimal::of((string) $this->parametros->valor('aprobaciones.descuento_max_pct'));
    }

    /** @return Collection<int, ReglaDescuento> */
    public function reglasVigentes(): Collection
    {
        $hoy = now()->toDateString();

        return $this->reglas ??= ReglaDescuento::where('activa', true)
            ->where(fn ($q) => $q->whereNull('vigente_desde')->orWhere('vigente_desde', '<=', $hoy))
            ->where(fn ($q) => $q->whereNull('vigente_hasta')->orWhere('vigente_hasta', '>=', $hoy))
            ->get();
    }

    public function olvidar(): void
    {
        $this->reglas = null;
    }

    /** Mejor % de regla para un producto, cantidad y lista; null si ninguna aplica. */
    public function porRegla(?Producto $producto, string $cantidad, ?string $lista): ?string
    {
        if (! $producto) {
            return null;
        }
        $mejor = null;
        foreach ($this->reglasVigentes() as $r) {
            if (($r->categoria_id && $r->categoria_id !== $producto->categoria_id)
                || ($r->lista_precio && $r->lista_precio !== ($lista ?? 'meson'))
                || BigDecimal::of($cantidad)->isLessThan((string) $r->cantidad_minima)) {
                continue;
            }
            if ($mejor === null || BigDecimal::of((string) $r->descuento_pct)->isGreaterThan($mejor)) {
                $mejor = (string) $r->descuento_pct;
            }
        }

        return $mejor === null ? null : rtrim(rtrim($mejor, '0'), '.');
    }

    /** @return Collection<int, NivelDescuento> */
    public function niveles(): Collection
    {
        return NivelDescuento::with('rol:id,nombre')->orderBy('hasta_pct')->get();
    }

    /** % efectivo de descuento de una línea calculada (monto de descuento sobre bruto). */
    public function pctLinea(array $l): BigDecimal
    {
        return $l['bruto'] > 0 ? BigDecimal::of($l['descuento_monto'])->multipliedBy(100)->dividedBy($l['bruto'], 2, RoundingMode::HALF_UP) : BigDecimal::zero();
    }

    /**
     * Mayor descuento que excede lo autorizado (máximo de la empresa o regla), o null
     * si nada requiere aprobación. Rechaza lo que supera el último escalón.
     *
     * @param  array<int, Producto>  $productos
     */
    public function nivelRequerido(array $lineas, string $descGlobal, ?string $lista, array $productos = []): ?string
    {
        $max = $this->maximo();
        $requerido = null;
        foreach ($lineas as $l) {
            $pct = $this->pctLinea($l);
            $regla = $this->porRegla($productos[$l['producto_id'] ?? 0] ?? null, (string) $l['cantidad'], $lista);
            $permitido = $regla !== null && BigDecimal::of($regla)->isGreaterThan($max) ? BigDecimal::of($regla) : $max;
            if ($pct->isGreaterThan($permitido) && ($requerido === null || $pct->isGreaterThan($requerido))) {
                $requerido = $pct;
            }
        }
        $global = BigDecimal::of($descGlobal ?: '0');
        if ($global->isGreaterThan($max) && ($requerido === null || $global->isGreaterThan($requerido))) {
            $requerido = $global;
        }
        if ($requerido === null) {
            return null;
        }

        $tope = NivelDescuento::max('hasta_pct');
        if ($tope !== null && $requerido->isGreaterThan((string) $tope)) {
            throw ValidationException::withMessages(['lineas' => "Un descuento de {$requerido} % supera el máximo autorizable de la empresa (".rtrim(rtrim((string) $tope, '0'), '.').' %).']);
        }

        return (string) $requerido;
    }

    /** Exige que el usuario tenga el rol del escalón que cubre el descuento, o de uno superior. */
    public function exigirAprobador(User $user, ?string $pct, string $campo = 'documento'): void
    {
        if ($pct === null || $user->es_superadmin) {
            return;
        }
        $niveles = $this->niveles();
        if ($niveles->isEmpty()) {
            return;
        }
        $desde = $niveles->search(fn ($n) => BigDecimal::of((string) $n->hasta_pct)->isGreaterThanOrEqualTo($pct));
        if ($desde === false) {
            throw ValidationException::withMessages([$campo => "Ningún escalón cubre un descuento de {$pct} %."]);
        }
        $roles = $niveles->slice($desde)->pluck('rol_id')->all();
        if (! in_array($user->membresiaActual()?->rol_id, $roles, true)) {
            $nombres = $niveles->slice($desde)->map(fn ($n) => $n->rol?->nombre)->filter()->unique()->implode(', ');
            throw ValidationException::withMessages([$campo => "Un descuento de {$pct} % lo aprueba: {$nombres}."]);
        }
    }
}
