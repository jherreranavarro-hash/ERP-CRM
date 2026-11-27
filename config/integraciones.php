<?php

/*
 * Adaptadores de integraciones externas. Cada interfaz tiene hoy una implementación
 * simulada (mismo contrato, datos deterministas). Para pasar a producción con un
 * proveedor real: implementa la interfaz en una clase nueva (p. ej.
 * App\Modules\Integraciones\Whatsapp\WhatsappCloud), pon su nombre completo en la
 * variable de entorno y deja el secreto en Configuración → Integraciones (cifrado).
 * Ver docs/despliegue.md, sección "Integraciones reales".
 */
return [
    'adaptadores' => [
        'dte' => env('INTEGRACION_DTE', \App\Modules\Facturacion\Sii\ProveedorDteSimulado::class),
        'pbx' => env('INTEGRACION_PBX', \App\Modules\ContactCenter\Telefonia\PbxSimulada::class),
        'whatsapp' => env('INTEGRACION_WHATSAPP', \App\Modules\Integraciones\Whatsapp\WhatsappSimulado::class),
        'meta_ads' => env('INTEGRACION_META_ADS', \App\Modules\Integraciones\MetaAds\MetaAdsSimulado::class),
        'linkedin' => env('INTEGRACION_LINKEDIN', \App\Modules\Integraciones\Linkedin\LinkedinSimulado::class),
        'ia' => env('INTEGRACION_IA', \App\Modules\Integraciones\Ia\AsistenteSimulado::class),
        'mapas' => env('INTEGRACION_MAPAS', \App\Modules\Integraciones\Mapas\MapasSimulado::class),
        'firma' => env('INTEGRACION_FIRMA', \App\Modules\RRHH\Integraciones\FirmaSimulada::class),
    ],
];
