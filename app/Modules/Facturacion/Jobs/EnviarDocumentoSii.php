<?php

namespace App\Modules\Facturacion\Jobs;

use App\Modules\Core\Auditoria\Auditoria;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Facturacion\Models\Documento;
use App\Modules\Facturacion\Sii\ProveedorDTE;
use App\Modules\Facturacion\Sii\ProveedorNoDisponible;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

/**
 * Envío al proveedor DTE después del commit de la emisión. Si el proveedor está
 * caído, el documento queda emitido con estado SII "error" y se reintenta.
 */
class EnviarDocumentoSii implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 6;

    public function __construct(public int $empresaId, public int $documentoId)
    {
        $this->afterCommit();
        $this->onQueue('dte'); // prioridad de colas: dte > caja > correo > default > reportes
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function handle(Tenancy $tenancy, ProveedorDTE $proveedor, Auditoria $auditoria): void
    {
        $tenancy->como(Empresa::findOrFail($this->empresaId), function () use ($proveedor, $auditoria) {
            $doc = Documento::findOrFail($this->documentoId);
            if (in_array($doc->estado_sii, ['aceptado', 'rechazado'], true)) {
                return;
            }

            try {
                $r = $proveedor->enviar($doc);
            } catch (ProveedorNoDisponible $e) {
                $doc->forceFill(['estado_sii' => 'error', 'sii_intentos' => $doc->sii_intentos + 1, 'sii_respuesta' => ['error' => $e->getMessage()]])->save();
                throw $e;
            }

            $doc->forceFill([
                'estado_sii' => $r->estado,
                'sii_track_id' => $r->trackId,
                'sii_respuesta' => $r->detalle,
                'sii_intentos' => $doc->sii_intentos + 1,
            ])->save();

            if ($r->estado === 'aceptado' && ! $doc->pdf_path) {
                $ruta = sprintf('dte/%d/%d-%d.pdf', $doc->empresa_id, $doc->tipo_dte, $doc->folio);
                Storage::disk('local')->put($ruta, $proveedor->pdf($doc));   // disco privado
                $doc->forceFill(['pdf_path' => $ruta])->save();
            }

            $auditoria->registrar('documento.sii_'.$r->estado, $doc, null, ['track_id' => $r->trackId]);

            if ($r->estado === 'rechazado') {
                app(\App\Modules\Operacion\Alertas::class)->emitir('dte_rechazado', "El SII rechazó el DTE {$doc->tipo_dte} folio {$doc->folio}", [
                    'tipo_dte' => $doc->tipo_dte, 'folio' => $doc->folio, 'track_id' => $r->trackId,
                ], "documento:{$doc->id}");
            }
        });
    }
}
