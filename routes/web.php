<?php

use App\Http\Controllers\Caja\DocumentosController;
use App\Http\Controllers\Configuracion\ClientesController;
use App\Http\Controllers\Configuracion\FoliosController;
use App\Http\Controllers\Configuracion\ProductosController;
use App\Http\Controllers\Finanzas\CuentasPorCobrarController;
use App\Http\Controllers\Finanzas\LibroVentasController;
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

        // Clientes
        Route::get('clientes', [ClientesController::class, 'index'])->middleware('permiso:configuracion.clientes.ver')->name('clientes.index');
        Route::post('clientes', [ClientesController::class, 'store'])->middleware('permiso:configuracion.clientes.crear')->name('clientes.store');
        Route::put('clientes/{cliente}', [ClientesController::class, 'update'])->middleware('permiso:configuracion.clientes.editar')->name('clientes.update');
        Route::delete('clientes/{cliente}', [ClientesController::class, 'destroy'])->middleware('permiso:configuracion.clientes.eliminar')->name('clientes.destroy');

        // Productos y categorías
        Route::get('productos', [ProductosController::class, 'index'])->middleware('permiso:configuracion.producto.ver')->name('productos.index');
        Route::post('productos', [ProductosController::class, 'store'])->middleware('permiso:configuracion.producto.crear')->name('productos.store');
        Route::put('productos/{producto}', [ProductosController::class, 'update'])->middleware('permiso:configuracion.producto.editar')->name('productos.update');
        Route::delete('productos/{producto}', [ProductosController::class, 'destroy'])->middleware('permiso:configuracion.producto.eliminar')->name('productos.destroy');
        Route::post('categorias', [ProductosController::class, 'guardarCategoria'])->middleware('permiso:configuracion.categoria.crear')->name('categorias.store');

        // Folios (CAF)
        Route::get('folios', [FoliosController::class, 'index'])->middleware('permiso:configuracion.folios.ver')->name('folios.index');
        Route::post('folios', [FoliosController::class, 'store'])->middleware('permiso:configuracion.folios.crear')->name('folios.store');
        Route::post('folios/{rango}/desactivar', [FoliosController::class, 'desactivar'])->middleware('permiso:configuracion.folios.crear')->name('folios.desactivar');
    });

    // Búsquedas para formularios
    Route::get('buscar/clientes', [ClientesController::class, 'buscar'])->middleware('permiso:caja.facturas-de-ventas.crear')->name('buscar.clientes');
    Route::get('buscar/productos', [ProductosController::class, 'buscar'])->middleware('permiso:caja.facturas-de-ventas.crear')->name('buscar.productos');

    // Caja → Facturas de Ventas
    Route::prefix('caja/documentos')->name('caja.documentos.')->group(function () {
        Route::get('/', [DocumentosController::class, 'index'])->middleware('permiso:caja.facturas-de-ventas.ver')->name('index');
        Route::get('crear', [DocumentosController::class, 'create'])->middleware('permiso:caja.facturas-de-ventas.crear')->name('create');
        Route::post('/', [DocumentosController::class, 'store'])->middleware('permiso:caja.facturas-de-ventas.crear')->name('store');
        Route::post('calcular', [DocumentosController::class, 'calcular'])->middleware(['permiso:caja.facturas-de-ventas.crear', 'throttle:120,1'])->name('calcular');
        Route::get('{documento}', [DocumentosController::class, 'show'])->middleware('permiso:caja.facturas-de-ventas.ver')->name('show');
        Route::get('{documento}/editar', [DocumentosController::class, 'edit'])->middleware('permiso:caja.facturas-de-ventas.editar')->name('edit');
        Route::put('{documento}', [DocumentosController::class, 'update'])->middleware('permiso:caja.facturas-de-ventas.editar')->name('update');
        Route::delete('{documento}', [DocumentosController::class, 'destroy'])->middleware('permiso:caja.facturas-de-ventas.eliminar')->name('destroy');
        Route::post('{documento}/aprobar', [DocumentosController::class, 'aprobar'])->middleware('permiso:caja.facturas-de-ventas.aprobar')->name('aprobar');
        Route::post('{documento}/emitir', [DocumentosController::class, 'emitir'])->middleware('permiso:caja.facturas-de-ventas.emitir')->name('emitir');
        Route::post('{documento}/pagos', [DocumentosController::class, 'pagar'])->middleware('permiso:caja.caja.crear')->name('pagar');
        Route::post('{documento}/nota', [DocumentosController::class, 'nota'])->middleware('permiso:caja.facturas-de-ventas.anular')->name('nota');
        Route::get('{documento}/pdf', [DocumentosController::class, 'pdf'])->middleware('permiso:caja.facturas-de-ventas.ver')->name('pdf');
    });

    // Finanzas
    Route::get('finanzas/libro-de-ventas', [LibroVentasController::class, 'index'])->middleware('permiso:finanzas.libro-de-ventas.ver')->name('finanzas.libro-ventas');
    Route::get('finanzas/cuentas-por-cobrar', [CuentasPorCobrarController::class, 'index'])->middleware('permiso:finanzas.cuentas-por-cobrar.ver')->name('finanzas.cuentas-por-cobrar');
});
