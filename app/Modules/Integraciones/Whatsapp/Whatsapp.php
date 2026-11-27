<?php

namespace App\Modules\Integraciones\Whatsapp;

/**
 * WhatsApp Business (Cloud API de Meta). El token vive cifrado en la ficha de
 * la integración; los mensajes entrantes y los cambios de estado llegan por el
 * webhook /api/whatsapp/webhook, firmado con X-Hub-Signature-256.
 */
interface Whatsapp
{
    /** Envía texto libre (solo dentro de la ventana de 24 h). Devuelve el id del mensaje en WhatsApp. */
    public function enviarTexto(string $telefono, string $texto): string;

    /** @param  list<string>  $variables */
    public function enviarPlantilla(string $telefono, string $plantilla, string $idioma, array $variables): string;

    public function simulado(): bool;
}
