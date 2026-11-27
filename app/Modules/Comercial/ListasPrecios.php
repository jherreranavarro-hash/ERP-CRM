<?php

namespace App\Modules\Comercial;

use App\Modules\Comercial\Models\ListaPrecio;
use App\Modules\Comercial\Models\ListaPrecioItem;
use App\Modules\Comercial\Models\Producto;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * El precio de lista lo decide siempre el backend:
 *  - mesón: precio neto del producto (base);
 *  - porcentaje: mesón menos el % de la lista;
 *  - propio: mayorista usa el precio mayorista del producto, las listas propias su
 *    tabla de precios; sin precio propio cae a mesón.
 * Una lista inactiva o fuera de vigencia también cae a mesón.
 */
class ListasPrecios
{
    /** @var array<int, Collection<string, ListaPrecio>> */
    private array $cache = [];

    /** Mesón y mayorista existen siempre en cada empresa (se crean al primer uso). */
    public function asegurarBase(): void
    {
        foreach ([['meson', 'Mesón', 'base'], ['mayorista', 'Mayorista', 'propio']] as [$codigo, $nombre, $regla]) {
            if (! ListaPrecio::where('codigo', $codigo)->exists()) {
                (new ListaPrecio(['codigo' => $codigo, 'nombre' => $nombre, 'regla' => $regla, 'activa' => true]))->forceFill(['sistema' => true])->save();
            }
        }
        $this->cache = [];
    }

    /** @return Collection<string, ListaPrecio> */
    public function todas(): Collection
    {
        $empresa = (int) app(\App\Modules\Core\Tenancy\Tenancy::class)->id();
        if (! isset($this->cache[$empresa])) {
            if (ListaPrecio::count() < 2) {
                $this->asegurarBase();
            }
            $this->cache[$empresa] = ListaPrecio::orderByDesc('sistema')->orderBy('id')->get()->keyBy('codigo');
        }

        return $this->cache[$empresa];
    }

    public function olvidar(): void
    {
        $this->cache = [];
    }

    /** @return list<string> */
    public function codigos(): array
    {
        return $this->todas()->keys()->all();
    }

    /**
     * Precio neto de lista para varios productos de una vez.
     *
     * @param  iterable<Producto>  $productos
     * @return array<int, string>
     */
    public function precios(iterable $productos, ?string $codigo, ?\DateTimeInterface $fecha = null): array
    {
        $productos = collect($productos);
        $lista = $codigo ? $this->todas()->get($codigo) : null;
        if (! $lista || ! $lista->vigente($fecha)) {
            $lista = null;
        }

        $propios = [];
        if ($lista && $lista->regla === 'propio' && $lista->codigo !== 'mayorista' && $productos->isNotEmpty()) {
            $propios = ListaPrecioItem::where('lista_id', $lista->id)->whereIn('producto_id', $productos->pluck('id'))->pluck('precio_neto', 'producto_id')->all();
        }

        $salida = [];
        foreach ($productos as $p) {
            $salida[$p->id] = $this->normalizar(match (true) {
                $lista === null || $lista->regla === 'base' => (string) $p->precio_neto,
                $lista->regla === 'porcentaje' => (string) BigDecimal::of((string) $p->precio_neto)
                    ->multipliedBy(BigDecimal::of(100)->minus((string) $lista->porcentaje))->dividedBy(100, 2, RoundingMode::HALF_UP),
                $lista->codigo === 'mayorista' => (string) ($p->precio_mayorista ?? $p->precio_neto),
                default => (string) ($propios[$p->id] ?? $p->precio_neto),
            });
        }

        return $salida;
    }

    public function precio(Producto $p, ?string $codigo, ?\DateTimeInterface $fecha = null): string
    {
        return $this->precios([$p], $codigo, $fecha)[$p->id];
    }

    private function normalizar(string $v): string
    {
        return str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
    }
}
