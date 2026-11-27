<?php

namespace App\Modules\Integraciones\Ia;

/** Asistente simulado por reglas: sirve para probar el flujo sin enviar datos a un tercero. */
class AsistenteSimulado implements AsistenteIa
{
    /** Lo último que recibió, para verificar en pruebas que llegó seudonimizado. */
    public array $recibido = [];

    public function sugerirRespuesta(array $conversacion): string
    {
        $this->recibido = $conversacion;
        $ultimo = mb_strtolower(collect($conversacion)->where('rol', 'cliente')->last()['texto'] ?? '');

        return match (true) {
            str_contains($ultimo, 'precio') || str_contains($ultimo, 'cotiz') || str_contains($ultimo, 'valor') => 'Con gusto le preparo una cotización. ¿Me confirma los productos, cantidades y la comuna de despacho?',
            str_contains($ultimo, 'despacho') || str_contains($ultimo, 'entrega') => 'Despachamos a obra en 48 horas hábiles en la Región Metropolitana. ¿A qué dirección sería?',
            str_contains($ultimo, 'gracias') => '¡Gracias a usted! Quedo atento a cualquier consulta.',
            str_contains($ultimo, 'stock') || str_contains($ultimo, 'tienen') => 'Reviso la disponibilidad y le confirmo en unos minutos. ¿Qué cantidad necesita?',
            default => 'Gracias por escribirnos. ¿En qué le puedo ayudar?',
        };
    }

    public function resumir(array $conversacion): string
    {
        $this->recibido = $conversacion;
        $cliente = collect($conversacion)->where('rol', 'cliente');
        $temas = collect(['cotización' => ['precio', 'cotiz', 'valor'], 'despacho' => ['despacho', 'entrega'], 'stock' => ['stock', 'tienen', 'disponib'], 'reclamo' => ['reclamo', 'problema', 'mal']])
            ->filter(fn ($claves) => $cliente->contains(fn ($m) => collect($claves)->contains(fn ($c) => str_contains(mb_strtolower($m['texto']), $c))))->keys();

        return sprintf('%d mensajes del cliente y %d del ejecutivo. Temas: %s. Último mensaje del cliente: «%s».',
            $cliente->count(), count($conversacion) - $cliente->count(), $temas->isEmpty() ? 'consulta general' : $temas->join(', ', ' y '),
            mb_strimwidth($cliente->last()['texto'] ?? '—', 0, 120, '…'));
    }

    public function simulado(): bool
    {
        return true;
    }
}
