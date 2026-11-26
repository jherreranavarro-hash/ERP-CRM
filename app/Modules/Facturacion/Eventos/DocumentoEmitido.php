<?php

namespace App\Modules\Facturacion\Eventos;

use App\Models\User;
use App\Modules\Facturacion\Models\Documento;

/**
 * Se despacha dentro de la transacción de emisión: si un módulo que escucha
 * falla (p. ej. Inventario sin stock), la emisión completa se revierte.
 */
class DocumentoEmitido
{
    public function __construct(public Documento $documento, public User $usuario) {}
}
