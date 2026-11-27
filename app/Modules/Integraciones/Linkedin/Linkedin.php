<?php

namespace App\Modules\Integraciones\Linkedin;

/**
 * Mensajería de LinkedIn para prospección B2B (OAuth de la organización). No hay
 * webhook: la bandeja se sincroniza por cola cada pocos minutos.
 */
interface Linkedin
{
    /**
     * Mensajes recibidos desde una fecha, con los datos del remitente.
     *
     * @return list<array{id:string, conversacion:string, nombre:string, cargo:?string, organizacion:?string, perfil:?string, texto:string, fecha:string}>
     */
    public function mensajesDesde(\DateTimeInterface $desde): array;

    /** Envía un mensaje a una conversación. Devuelve el id del mensaje en LinkedIn. */
    public function enviar(string $conversacion, string $texto): string;

    public function simulado(): bool;
}
