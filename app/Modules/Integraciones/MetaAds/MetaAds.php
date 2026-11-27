<?php

namespace App\Modules\Integraciones\MetaAds;

/**
 * Meta Ads (Marketing API). Solo lectura con scopes mínimos (ads_read, leads_retrieval):
 * métricas de campañas y prospectos de los formularios instantáneos.
 */
interface MetaAds
{
    /** @return list<array{id:string, nombre:string, estado:string, objetivo:?string, presupuesto_diario:int, gasto:int, impresiones:int, clics:int, leads:int}> */
    public function campanas(): array;

    /** @return list<array{id:string, nombre:string, telefono:string, email:?string, comuna:?string, creado:string}> */
    public function prospectos(string $campana, \DateTimeInterface $desde): array;

    public function simulado(): bool;
}
