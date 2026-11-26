<?php

namespace App\Modules\Finanzas\Escuchas;

use App\Modules\Facturacion\Eventos\DocumentoEmitido;
use App\Modules\Finanzas\AsientosAutomaticos;

/** Al emitir un documento tributario genera su asiento (después de mover el stock, para incluir el costo de lo vendido). */
class ContabilizarDocumento
{
    public function __construct(private AsientosAutomaticos $asientos) {}

    public function handle(DocumentoEmitido $e): void
    {
        $this->asientos->documento($e->documento, $e->usuario);
    }
}
