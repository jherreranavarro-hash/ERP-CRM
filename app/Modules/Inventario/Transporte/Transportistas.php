<?php

namespace App\Modules\Inventario\Transporte;

/** Registro de transportistas disponibles; 'propio' es la flota de la empresa (sin integración). */
class Transportistas
{
    /** @var array<string, Transportista> */
    private array $externos = [];

    public function __construct(iterable $externos = [])
    {
        foreach ($externos as $t) {
            $this->externos[$t->codigo()] = $t;
        }
    }

    /** @return array<string, string> codigo => nombre */
    public function opciones(): array
    {
        return ['propio' => 'Flota propia'] + array_map(fn (Transportista $t) => $t->nombre(), $this->externos);
    }

    public function existe(string $codigo): bool
    {
        return $codigo === 'propio' || isset($this->externos[$codigo]);
    }

    public function externo(string $codigo): ?Transportista
    {
        return $this->externos[$codigo] ?? null;
    }
}
