<?php

use App\Http\Controllers\Configuracion\AuditoriaController;
use App\Http\Controllers\Configuracion\EmpresasController;
use App\Http\Controllers\Configuracion\LocalesController;
use App\Http\Controllers\Configuracion\ParametrosController;
use App\Http\Controllers\Configuracion\RolesController;
use App\Http\Controllers\Configuracion\TokensController;
use App\Http\Controllers\Configuracion\UsuariosController;
use App\Http\Controllers\EmpresaActivaController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\InvitacionController;
use App\Http\Controllers\PendienteController;
use App\Http\Controllers\PerfilController;
use Illuminate\Support\Facades\Route;

// Alta de usuarios: solo por invitación (no hay /register).
Route::middleware('guest')->group(function () {
    Route::get('/invitacion/{token}', [InvitacionController::class, 'mostrar'])->name('invitacion.mostrar');
    Route::post('/invitacion/{token}', [InvitacionController::class, 'aceptar'])->middleware('throttle:10,1')->name('invitacion.aceptar');
});

Route::middleware('auth')->group(function () {
    Route::get('/', InicioController::class)->name('inicio');
    Route::get('/dashboard', InicioController::class)->name('dashboard');
    Route::post('/empresa-activa', EmpresaActivaController::class)->name('empresa.cambiar');
    Route::get('/pendiente/{modulo}/{submodulo}', PendienteController::class)->name('pendiente');

    Route::get('/perfil/seguridad', [PerfilController::class, 'seguridad'])->name('perfil.seguridad');
    Route::post('/perfil/codigos', [PerfilController::class, 'mostrarCodigos'])->middleware('password.confirm')->name('perfil.codigos');
    Route::post('/perfil/cerrar-otras-sesiones', [PerfilController::class, 'cerrarOtrasSesiones'])->name('perfil.cerrar-sesiones');

    Route::prefix('configuracion')->name('configuracion.')->group(function () {
        // Negocio (empresas)
        Route::get('empresas', [EmpresasController::class, 'index'])->middleware('permiso:configuracion.negocio.ver')->name('empresas.index');
        Route::get('empresas/crear', [EmpresasController::class, 'create'])->middleware('permiso:configuracion.negocio.crear')->name('empresas.create');
        Route::post('empresas', [EmpresasController::class, 'store'])->middleware('permiso:configuracion.negocio.crear')->name('empresas.store');
        Route::get('empresas/{empresa}/editar', [EmpresasController::class, 'edit'])->middleware('permiso:configuracion.negocio.editar')->name('empresas.edit');
        Route::put('empresas/{empresa}', [EmpresasController::class, 'update'])->middleware('permiso:configuracion.negocio.editar')->name('empresas.update');
        Route::delete('empresas/{empresa}', [EmpresasController::class, 'destroy'])->middleware('permiso:configuracion.negocio.eliminar')->name('empresas.destroy');

        // Locales
        Route::get('locales', [LocalesController::class, 'index'])->middleware('permiso:configuracion.local.ver')->name('locales.index');
        Route::post('locales', [LocalesController::class, 'store'])->middleware('permiso:configuracion.local.crear')->name('locales.store');
        Route::put('locales/{local}', [LocalesController::class, 'update'])->middleware('permiso:configuracion.local.editar')->name('locales.update');
        Route::delete('locales/{local}', [LocalesController::class, 'destroy'])->middleware('permiso:configuracion.local.eliminar')->name('locales.destroy');
        Route::post('locales/{id}/restaurar', [LocalesController::class, 'restore'])->middleware('permiso:configuracion.local.eliminar')->name('locales.restore');

        // Usuarios
        Route::get('usuarios', [UsuariosController::class, 'index'])->middleware('permiso:configuracion.usuarios.ver')->name('usuarios.index');
        Route::post('usuarios/invitar', [UsuariosController::class, 'invitar'])->middleware('permiso:configuracion.usuarios.crear')->name('usuarios.invitar');
        Route::put('usuarios/{membresia}', [UsuariosController::class, 'update'])->middleware('permiso:configuracion.usuarios.editar')->name('usuarios.update');
        Route::post('usuarios/{membresia}/cerrar-sesiones', [UsuariosController::class, 'cerrarSesiones'])->middleware('permiso:configuracion.usuarios.editar')->name('usuarios.cerrar-sesiones');
        Route::delete('invitaciones/{invitacion}', [UsuariosController::class, 'revocarInvitacion'])->middleware('permiso:configuracion.usuarios.crear')->name('invitaciones.revocar');

        // Perfiles / roles (RBAC)
        Route::get('roles', [RolesController::class, 'index'])->middleware('permiso:configuracion.perfiles.ver')->name('roles.index');
        Route::get('roles/crear', [RolesController::class, 'create'])->middleware('permiso:configuracion.perfiles.crear')->name('roles.create');
        Route::post('roles', [RolesController::class, 'store'])->middleware('permiso:configuracion.perfiles.crear')->name('roles.store');
        Route::get('roles/{rol}/editar', [RolesController::class, 'edit'])->middleware('permiso:configuracion.perfiles.editar')->name('roles.edit');
        Route::put('roles/{rol}', [RolesController::class, 'update'])->middleware('permiso:configuracion.perfiles.editar')->name('roles.update');
        Route::delete('roles/{rol}', [RolesController::class, 'destroy'])->middleware('permiso:configuracion.perfiles.eliminar')->name('roles.destroy');

        // Parámetros del sistema (mantenedores)
        Route::get('parametros', [ParametrosController::class, 'index'])->middleware('permiso:configuracion.parametros.ver')->name('parametros.index');
        Route::get('parametros/exportar', [ParametrosController::class, 'exportar'])->middleware('permiso:configuracion.parametros.exportar')->name('parametros.exportar');
        Route::post('parametros/importar', [ParametrosController::class, 'importar'])->middleware('permiso:configuracion.parametros.proponer')->name('parametros.importar');
        Route::post('parametros/proponer', [ParametrosController::class, 'proponer'])->middleware('permiso:configuracion.parametros.proponer')->name('parametros.proponer');
        Route::post('parametros/cambios/{cambio}/aprobar', [ParametrosController::class, 'aprobar'])->middleware('permiso:configuracion.parametros.aprobar')->name('parametros.aprobar');
        Route::post('parametros/cambios/{cambio}/rechazar', [ParametrosController::class, 'rechazar'])->middleware('permiso:configuracion.parametros.aprobar')->name('parametros.rechazar');
        Route::post('parametros/cambios/{cambio}/revertir', [ParametrosController::class, 'revertir'])->middleware('permiso:configuracion.parametros.proponer')->name('parametros.revertir');
        Route::get('parametros/{mantenedor}', [ParametrosController::class, 'show'])->middleware('permiso:configuracion.parametros.ver')->name('parametros.show');

        // Tracking de actividades (trazabilidad)
        Route::get('auditoria', [AuditoriaController::class, 'index'])->middleware('permiso:configuracion.tracking-de-actividades.ver')->name('auditoria.index');

        // Tokens de API
        Route::get('tokens', [TokensController::class, 'index'])->middleware('permiso:configuracion.tokens-api.ver')->name('tokens.index');
        Route::post('tokens', [TokensController::class, 'store'])->middleware('permiso:configuracion.tokens-api.crear')->name('tokens.store');
        Route::delete('tokens/{token}', [TokensController::class, 'destroy'])->middleware('permiso:configuracion.tokens-api.eliminar')->name('tokens.destroy');
    });
});
