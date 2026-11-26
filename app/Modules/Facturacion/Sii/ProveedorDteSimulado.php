<?php

namespace App\Modules\Facturacion\Sii;

use App\Modules\Facturacion\Models\Documento;
use Illuminate\Support\Facades\Cache;

/**
 * Simulación para desarrollo y pruebas: acepta todo documento con folio y
 * puede simular una caída del proveedor (Cache 'dte.simulado.caido').
 * No genera timbre ni firma válidos.
 */
class ProveedorDteSimulado implements ProveedorDTE
{
    public static function caer(bool $caido = true): void
    {
        $caido ? Cache::forever('dte.simulado.caido', true) : Cache::forget('dte.simulado.caido');
    }

    public function enviar(Documento $documento): ResultadoEnvio
    {
        $this->verificarDisponible();

        if (! $documento->folio) {
            return new ResultadoEnvio('rechazado', null, ['glosa' => 'Documento sin folio']);
        }

        return new ResultadoEnvio('aceptado', sprintf('SIM-%d-%d-%d', $documento->empresa_id, $documento->tipo_dte, $documento->folio), [
            'glosa' => 'Aceptado (simulado)',
            'simulado' => true,
        ]);
    }

    public function consultar(Documento $documento): ResultadoEnvio
    {
        $this->verificarDisponible();

        return new ResultadoEnvio($documento->estado_sii === 'rechazado' ? 'rechazado' : 'aceptado', $documento->sii_track_id, ['simulado' => true]);
    }

    public function pdf(Documento $documento): string
    {
        $texto = sprintf('%s N %d - Total $%s - SIMULADO, SIN VALIDEZ TRIBUTARIA', Documento::TIPOS[$documento->tipo_dte], $documento->folio, number_format($documento->monto_total, 0, ',', '.'));
        $texto = preg_replace('/[^\x20-\x7E]/', '', $texto);
        $contenido = "BT /F1 12 Tf 40 780 Td ({$texto}) Tj ET";

        // PDF mínimo de una página, solo para el flujo de pruebas.
        $objs = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($contenido)." >>\nstream\n{$contenido}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $i => $o) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$o}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }

        return $pdf."trailer\n<< /Size ".(count($objs) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }

    private function verificarDisponible(): void
    {
        if (Cache::get('dte.simulado.caido')) {
            throw new ProveedorNoDisponible('Proveedor DTE simulado fuera de servicio.');
        }
    }
}
