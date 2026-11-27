<?php

namespace App\Modules\Integraciones\Models;

use App\Modules\Core\Auditoria\Auditable;
use App\Modules\Core\Tenancy\PerteneceAEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/** Ficha de una integración externa. El secreto se guarda cifrado y nunca se devuelve ni se exporta. */
class Integracion extends Model
{
    use Auditable, PerteneceAEmpresa;

    public const TIPOS = [
        'pbx' => ['nombre' => 'Central telefónica', 'proposito' => 'Llamadas, grabaciones, IVR y click-to-call'],
        'dte' => ['nombre' => 'Facturación electrónica (SII)', 'proposito' => 'Emisión y recepción de DTE y libros'],
        'whatsapp' => ['nombre' => 'WhatsApp Business', 'proposito' => 'Mensajería con clientes y envío de documentos'],
        'meta_ads' => ['nombre' => 'Meta Ads', 'proposito' => 'Campañas publicitarias y plantillas de mensajes'],
        'linkedin' => ['nombre' => 'LinkedIn', 'proposito' => 'Mensajes de prospección B2B'],
        'correo' => ['nombre' => 'Correo transaccional', 'proposito' => 'Cotizaciones, liquidaciones y avisos'],
        'ia' => ['nombre' => 'Asistente de IA', 'proposito' => 'Respuestas sugeridas y resúmenes, con datos seudonimizados'],
        'mapas' => ['nombre' => 'Mapas y ruteo', 'proposito' => 'Ubicación de direcciones para la lista de recorrido'],
    ];

    protected $table = 'integraciones';

    protected $guarded = ['id', 'empresa_id', 'secreto_cifrado', 'secreto_rotado_at', 'salud', 'salud_at'];

    protected $hidden = ['secreto_cifrado'];

    protected function casts(): array
    {
        return ['activa' => 'boolean', 'reintentos' => 'integer', 'timeout_seg' => 'integer', 'secreto_rotado_at' => 'datetime', 'salud_at' => 'datetime'];
    }

    public function reemplazarSecreto(string $secreto): void
    {
        $this->forceFill(['secreto_cifrado' => Crypt::encryptString($secreto), 'secreto_rotado_at' => now()]);
    }

    /** Solo para el adaptador, en el servidor. Nunca se envía al navegador. */
    public function secreto(): ?string
    {
        return $this->secreto_cifrado ? Crypt::decryptString($this->secreto_cifrado) : null;
    }

    public static function de(string $tipo): ?self
    {
        return static::where('tipo', $tipo)->first();
    }

    public static function activa(string $tipo): bool
    {
        return (bool) static::where('tipo', $tipo)->where('activa', true)->exists();
    }
}
