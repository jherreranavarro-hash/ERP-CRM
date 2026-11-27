<?php

use App\Modules\Core\Tenancy\Tenancy;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (string) $user->id === (string) $id;
});

// Wallboard del contact center: solo la empresa activa del usuario y con permiso de Tiempo Real.
Broadcast::channel('contact-center.{empresa}', function ($user, $empresa) {
    return (int) app(Tenancy::class)->id() === (int) $empresa && $user->tienePermiso('ventascrm.tiempo-real.ver');
});

// Bandeja de WhatsApp: solo avisa que una conversación cambió; el contenido se pide con permisos.
Broadcast::channel('whatsapp.{empresa}', function ($user, $empresa) {
    return (int) app(Tenancy::class)->id() === (int) $empresa && $user->tienePermiso('ventascrm.whatsapp.ver');
});
