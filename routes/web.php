<?php

use App\Http\Controllers\Caja\CierresController;
use App\Http\Controllers\Caja\DocumentosController;
use App\Http\Controllers\Caja\TurnoController;
use App\Http\Controllers\Ventas\CotizacionesController;
use App\Http\Controllers\Ventas\OrdenesController;
use App\Http\Controllers\Inventario\DespachosController;
use App\Http\Controllers\Inventario\DevolucionesController;
use App\Http\Controllers\Inventario\MermasController;
use App\Http\Controllers\Inventario\PreciosController;
use App\Http\Controllers\Inventario\RecepcionesController;
use App\Http\Controllers\Inventario\StockController;
use App\Http\Controllers\Compras\FacturasProveedorController;
use App\Http\Controllers\Compras\OrdenesCompraController;
use App\Http\Controllers\Configuracion\ClientesController;
use App\Http\Controllers\Configuracion\ProveedoresController;
use App\Http\Controllers\Configuracion\FoliosController;
use App\Http\Controllers\Configuracion\ProductosController;
use App\Http\Controllers\Finanzas\CuentasPorCobrarController;
use App\Http\Controllers\Finanzas\CuentasPorPagarController;
use App\Http\Controllers\Finanzas\LibroComprasController;
use App\Http\Controllers\Finanzas\LibroVentasController;
use App\Http\Controllers\Finanzas\AsientosController;
use App\Http\Controllers\Finanzas\BalancesController;
use App\Http\Controllers\Finanzas\CentrosCostoController;
use App\Http\Controllers\Finanzas\ConciliacionController;
use App\Http\Controllers\Finanzas\FlujoCajaController;
use App\Http\Controllers\Finanzas\HonorariosController;
use App\Http\Controllers\RRHH;
use App\Http\Controllers\Configuracion\AuditoriaController;
use App\Http\Controllers\Configuracion\EmpresasController;
use App\Http\Controllers\Configuracion\LocalesController;
use App\Http\Controllers\Configuracion\ParametrosController;
use App\Http\Controllers\Configuracion\RolesController;
use App\Http\Controllers\Configuracion\OperacionController;
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
    Route::get('/dashboard', [\App\Http\Controllers\Dashboard\DashboardController::class, 'crm'])->middleware('permiso:dashboard.crm.ver')->name('dashboard');
    Route::get('/ventas/dashboard', [\App\Http\Controllers\Dashboard\DashboardController::class, 'comercial'])->middleware('permiso:ventascrm.dashboard.ver')->name('ventas.dashboard');
    Route::get('/ventas/cuentas-corrientes', [\App\Http\Controllers\Dashboard\CuentasCorrientesController::class, 'index'])->middleware('permiso:ventascrm.cuentas-corrientes.ver|configuracion.cuentas-corrientes.ver')->name('ventas.cuentas-corrientes');
    Route::get('/ventas/cuentas-corrientes/{cliente}', [\App\Http\Controllers\Dashboard\CuentasCorrientesController::class, 'show'])->middleware('permiso:ventascrm.cuentas-corrientes.ver|configuracion.cuentas-corrientes.ver')->name('ventas.cuentas-corrientes.show');
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
        // Marca SuperAdmin: otorgar pasa por cuatro ojos; revocar rige al tiro (el controlador exige SuperAdmin).
        Route::post('usuarios/{membresia}/superadmin', [UsuariosController::class, 'solicitarSuperadmin'])->middleware('permiso:configuracion.usuarios.editar')->name('usuarios.superadmin.solicitar');
        Route::delete('usuarios/{membresia}/superadmin', [UsuariosController::class, 'revocarSuperadmin'])->middleware('permiso:configuracion.usuarios.editar')->name('usuarios.superadmin.revocar');

        // Perfiles / roles (RBAC)
        Route::get('roles', [RolesController::class, 'index'])->middleware('permiso:configuracion.perfiles.ver')->name('roles.index');
        Route::get('roles/crear', [RolesController::class, 'create'])->middleware('permiso:configuracion.perfiles.crear')->name('roles.create');
        Route::post('roles', [RolesController::class, 'store'])->middleware('permiso:configuracion.perfiles.crear')->name('roles.store');
        Route::get('roles/{rol}/editar', [RolesController::class, 'edit'])->middleware('permiso:configuracion.perfiles.editar')->name('roles.edit');
        Route::put('roles/{rol}', [RolesController::class, 'update'])->middleware('permiso:configuracion.perfiles.editar')->name('roles.update');
        Route::delete('roles/{rol}', [RolesController::class, 'destroy'])->middleware('permiso:configuracion.perfiles.eliminar')->name('roles.destroy');
        // Solicitudes de privilegio (cuatro ojos): el servicio valida quién puede resolver cada una.
        Route::post('privilegios/{solicitud}/aprobar', [RolesController::class, 'aprobar'])->middleware('permiso:configuracion.perfiles.ver')->name('privilegios.aprobar');
        Route::post('privilegios/{solicitud}/rechazar', [RolesController::class, 'rechazar'])->middleware('permiso:configuracion.perfiles.ver')->name('privilegios.rechazar');
        Route::post('privilegios/{solicitud}/anular', [RolesController::class, 'anular'])->middleware('permiso:configuracion.perfiles.ver')->name('privilegios.anular');

        // Operación y continuidad: alertas, respaldos y retención
        Route::get('operacion', [OperacionController::class, 'index'])->middleware('permiso:configuracion.operacion.ver')->name('operacion.index');
        Route::post('operacion/alertas/{alerta}/atender', [OperacionController::class, 'atender'])->middleware('permiso:configuracion.operacion.editar')->name('operacion.atender');

        // Parámetros del sistema (mantenedores)
        Route::get('parametros', [ParametrosController::class, 'index'])->middleware('permiso:configuracion.parametros.ver')->name('parametros.index');
        Route::get('parametros/exportar', [ParametrosController::class, 'exportar'])->middleware('permiso:configuracion.parametros.exportar')->name('parametros.exportar');
        Route::post('parametros/importar', [ParametrosController::class, 'importar'])->middleware('permiso:configuracion.parametros.proponer')->name('parametros.importar');
        Route::post('parametros/proponer', [ParametrosController::class, 'proponer'])->middleware('permiso:configuracion.parametros.proponer')->name('parametros.proponer');
        Route::post('parametros/cambios/{cambio}/aprobar', [ParametrosController::class, 'aprobar'])->middleware('permiso:configuracion.parametros.aprobar')->name('parametros.aprobar');
        Route::post('parametros/cambios/{cambio}/rechazar', [ParametrosController::class, 'rechazar'])->middleware('permiso:configuracion.parametros.aprobar')->name('parametros.rechazar');
        Route::post('parametros/cambios/{cambio}/revertir', [ParametrosController::class, 'revertir'])->middleware('permiso:configuracion.parametros.proponer')->name('parametros.revertir');
        Route::get('parametros/{mantenedor}', [ParametrosController::class, 'show'])->middleware('permiso:configuracion.parametros.ver')->name('parametros.show');
        Route::get('despacho', fn () => to_route('configuracion.parametros.show', 'despacho'))->middleware('permiso:configuracion.parametros-de-despacho.ver')->name('despacho');

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
        Route::get('proveedores', [ProveedoresController::class, 'index'])->middleware('permiso:configuracion.proveedores.ver')->name('proveedores.index');
        Route::post('proveedores', [ProveedoresController::class, 'store'])->middleware('permiso:configuracion.proveedores.crear')->name('proveedores.store');
        Route::put('proveedores/{proveedor}', [ProveedoresController::class, 'update'])->middleware('permiso:configuracion.proveedores.editar')->name('proveedores.update');
        Route::delete('proveedores/{proveedor}', [ProveedoresController::class, 'destroy'])->middleware('permiso:configuracion.proveedores.eliminar')->name('proveedores.destroy');

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
    Route::get('buscar/proveedores', [ProveedoresController::class, 'buscar'])->middleware('permiso:compras.orden-de-compra.crear|compras.factura-de-proveedores.crear')->name('buscar.proveedores');
    Route::get('buscar/clientes', [ClientesController::class, 'buscar'])->middleware('permiso:caja.facturas-de-ventas.crear|ventascrm.cotizacion.crear')->name('buscar.clientes');
    Route::get('buscar/productos', [ProductosController::class, 'buscar'])->middleware('permiso:caja.facturas-de-ventas.crear|ventascrm.cotizacion.crear|inventario.recepcion-de-mercaderia.crear|inventario.mermas.crear|inventario.actualizacion-de-precios.crear|compras.orden-de-compra.crear')->name('buscar.productos');

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

    // VentasCRM → Cotización
    Route::prefix('ventas/cotizaciones')->name('ventas.cotizaciones.')->group(function () {
        Route::get('/', [CotizacionesController::class, 'index'])->middleware('permiso:ventascrm.cotizacion.ver')->name('index');
        Route::get('crear', [CotizacionesController::class, 'create'])->middleware('permiso:ventascrm.cotizacion.crear')->name('create');
        Route::post('/', [CotizacionesController::class, 'store'])->middleware('permiso:ventascrm.cotizacion.crear')->name('store');
        Route::post('calcular', [CotizacionesController::class, 'calcular'])->middleware(['permiso:ventascrm.cotizacion.crear', 'throttle:120,1'])->name('calcular');
        Route::get('{cotizacion}', [CotizacionesController::class, 'show'])->middleware('permiso:ventascrm.cotizacion.ver')->name('show');
        Route::get('{cotizacion}/editar', [CotizacionesController::class, 'edit'])->middleware('permiso:ventascrm.cotizacion.editar')->name('edit');
        Route::put('{cotizacion}', [CotizacionesController::class, 'update'])->middleware('permiso:ventascrm.cotizacion.editar')->name('update');
        Route::delete('{cotizacion}', [CotizacionesController::class, 'destroy'])->middleware('permiso:ventascrm.cotizacion.eliminar')->name('destroy');
        Route::post('{cotizacion}/aprobar', [CotizacionesController::class, 'aprobar'])->middleware('permiso:ventascrm.cotizacion.aprobar')->name('aprobar');
        Route::post('{cotizacion}/enviar', [CotizacionesController::class, 'enviar'])->middleware('permiso:ventascrm.cotizacion.editar')->name('enviar');
        Route::post('{cotizacion}/aceptar', [CotizacionesController::class, 'aceptar'])->middleware('permiso:ventascrm.cotizacion.editar')->name('aceptar');
        Route::post('{cotizacion}/rechazar', [CotizacionesController::class, 'rechazar'])->middleware('permiso:ventascrm.cotizacion.editar')->name('rechazar');
        Route::post('{cotizacion}/convertir', [CotizacionesController::class, 'convertir'])->middleware('permiso:ventascrm.cotizacion.editar', 'permiso:caja.facturas-de-ventas.crear')->name('convertir');
    });

    // Caja → Caja (turno) y Cierres de Caja
    Route::get('caja/turno', [TurnoController::class, 'index'])->middleware('permiso:caja.caja.ver')->name('caja.turno');
    Route::post('caja/turno/abrir', [TurnoController::class, 'abrir'])->middleware('permiso:caja.caja.crear')->name('caja.turno.abrir');
    Route::post('caja/turno/cerrar', [TurnoController::class, 'cerrar'])->middleware('permiso:caja.cierres-de-caja.crear')->name('caja.turno.cerrar');
    Route::post('caja/cajas', [TurnoController::class, 'guardarCaja'])->middleware('permiso:configuracion.local.editar')->name('caja.cajas.store');
    Route::get('caja/cierres', [CierresController::class, 'index'])->middleware('permiso:caja.cierres-de-caja.ver')->name('caja.cierres.index');
    Route::get('caja/cierres/{turno}', [CierresController::class, 'show'])->middleware('permiso:caja.cierres-de-caja.ver|caja.caja.ver')->name('caja.cierres.show');
    Route::post('caja/cierres/{turno}/aprobar', [CierresController::class, 'aprobar'])->middleware('permiso:caja.cierres-de-caja.aprobar')->name('caja.cierres.aprobar');

    // VentasCRM → Orden de Venta
    Route::post('ventas/cotizaciones/{cotizacion}/orden', [OrdenesController::class, 'desdeCotizacion'])->middleware('permiso:ventascrm.orden-de-venta.crear')->name('ventas.cotizaciones.orden');
    Route::prefix('ventas/ordenes')->name('ventas.ordenes.')->group(function () {
        Route::get('/', [OrdenesController::class, 'index'])->middleware('permiso:ventascrm.orden-de-venta.ver|inventario.orden-de-venta.ver')->name('index');
        Route::get('{orden}', [OrdenesController::class, 'show'])->middleware('permiso:ventascrm.orden-de-venta.ver|inventario.orden-de-venta.ver')->name('show');
        Route::post('{orden}/facturar', [OrdenesController::class, 'facturar'])->middleware('permiso:ventascrm.orden-de-venta.editar', 'permiso:caja.facturas-de-ventas.crear')->name('facturar');
        Route::post('{orden}/anular', [OrdenesController::class, 'anular'])->middleware('permiso:ventascrm.orden-de-venta.eliminar')->name('anular');
    });

    // Inventario
    Route::prefix('inventario')->name('inventario.')->group(function () {
        Route::get('stock', [StockController::class, 'index'])->middleware('permiso:inventario.stock.ver|caja.stock.ver')->name('stock.index');
        Route::get('stock/{producto}/kardex', [StockController::class, 'kardex'])->middleware('permiso:inventario.stock.ver|caja.stock.ver')->name('stock.kardex');
        Route::post('stock/{producto}/minimo', [StockController::class, 'minimo'])->middleware('permiso:inventario.stock.editar')->name('stock.minimo');

        Route::get('recepciones', [RecepcionesController::class, 'index'])->middleware('permiso:inventario.recepcion-de-mercaderia.ver')->name('recepciones.index');
        Route::get('recepciones/crear', [RecepcionesController::class, 'create'])->middleware('permiso:inventario.recepcion-de-mercaderia.crear')->name('recepciones.create');
        Route::post('recepciones', [RecepcionesController::class, 'store'])->middleware('permiso:inventario.recepcion-de-mercaderia.crear')->name('recepciones.store');
        Route::get('recepciones/{recepcion}', [RecepcionesController::class, 'show'])->middleware('permiso:inventario.recepcion-de-mercaderia.ver')->name('recepciones.show');
        Route::get('recepciones/{recepcion}/editar', [RecepcionesController::class, 'edit'])->middleware('permiso:inventario.recepcion-de-mercaderia.editar')->name('recepciones.edit');
        Route::put('recepciones/{recepcion}', [RecepcionesController::class, 'update'])->middleware('permiso:inventario.recepcion-de-mercaderia.editar')->name('recepciones.update');
        Route::delete('recepciones/{recepcion}', [RecepcionesController::class, 'destroy'])->middleware('permiso:inventario.recepcion-de-mercaderia.eliminar')->name('recepciones.destroy');
        Route::post('recepciones/{recepcion}/confirmar', [RecepcionesController::class, 'confirmar'])->middleware('permiso:inventario.recepcion-de-mercaderia.confirmar')->name('recepciones.confirmar');

        Route::get('mermas', [MermasController::class, 'index'])->middleware('permiso:inventario.mermas.ver')->name('mermas.index');
        Route::post('mermas', [MermasController::class, 'store'])->middleware('permiso:inventario.mermas.crear')->name('mermas.store');
        Route::post('mermas/{merma}/aprobar', [MermasController::class, 'aprobar'])->middleware('permiso:inventario.mermas.aprobar')->name('mermas.aprobar');
        Route::post('mermas/{merma}/rechazar', [MermasController::class, 'rechazar'])->middleware('permiso:inventario.mermas.aprobar')->name('mermas.rechazar');

        Route::get('precios', [PreciosController::class, 'index'])->middleware('permiso:inventario.actualizacion-de-precios.ver')->name('precios.index');
        Route::get('precios/crear', [PreciosController::class, 'create'])->middleware('permiso:inventario.actualizacion-de-precios.crear')->name('precios.create');
        Route::post('precios', [PreciosController::class, 'store'])->middleware('permiso:inventario.actualizacion-de-precios.crear')->name('precios.store');
        Route::post('precios/masivo', [PreciosController::class, 'masivo'])->middleware('permiso:inventario.actualizacion-de-precios.crear')->name('precios.masivo');
        Route::get('precios/{cambio}', [PreciosController::class, 'show'])->middleware('permiso:inventario.actualizacion-de-precios.ver')->name('precios.show');
        Route::post('precios/{cambio}/aprobar', [PreciosController::class, 'aprobar'])->middleware('permiso:inventario.actualizacion-de-precios.aprobar')->name('precios.aprobar');
        Route::post('precios/{cambio}/rechazar', [PreciosController::class, 'rechazar'])->middleware('permiso:inventario.actualizacion-de-precios.aprobar')->name('precios.rechazar');

        Route::get('despachos', [DespachosController::class, 'index'])->middleware('permiso:inventario.despachos.ver')->name('despachos.index');
        Route::get('despachos/crear', [DespachosController::class, 'create'])->middleware('permiso:inventario.despachos.crear')->name('despachos.create');
        Route::post('despachos', [DespachosController::class, 'store'])->middleware('permiso:inventario.despachos.crear')->name('despachos.store');
        Route::get('despachos/{despacho}', [DespachosController::class, 'show'])->middleware('permiso:inventario.despachos.ver')->name('despachos.show');
        Route::get('despachos/{despacho}/firma', [DespachosController::class, 'firma'])->middleware('permiso:inventario.despachos.ver')->name('despachos.firma');
        Route::post('despachos/{despacho}/aprobar', [DespachosController::class, 'aprobar'])->middleware('permiso:inventario.despachos.aprobar')->name('despachos.aprobar');
        Route::post('despachos/{despacho}/despachar', [DespachosController::class, 'despachar'])->middleware('permiso:inventario.despachos.editar')->name('despachos.despachar');
        Route::post('despachos/{despacho}/entregar', [DespachosController::class, 'entregar'])->middleware('permiso:inventario.despachos.editar')->name('despachos.entregar');
        Route::post('despachos/{despacho}/fallar', [DespachosController::class, 'fallar'])->middleware('permiso:inventario.despachos.editar')->name('despachos.fallar');
        Route::post('despachos/{despacho}/reprogramar', [DespachosController::class, 'reprogramar'])->middleware('permiso:inventario.despachos.editar')->name('despachos.reprogramar');
        Route::post('despachos/{despacho}/anular', [DespachosController::class, 'anular'])->middleware('permiso:inventario.despachos.eliminar')->name('despachos.anular');

        Route::get('devoluciones', [DevolucionesController::class, 'index'])->middleware('permiso:inventario.devolucion.ver|caja.devolucion.ver')->name('devoluciones.index');
        Route::get('devoluciones/crear', [DevolucionesController::class, 'create'])->middleware('permiso:inventario.devolucion.crear|caja.devolucion.crear')->name('devoluciones.create');
        Route::post('devoluciones', [DevolucionesController::class, 'store'])->middleware('permiso:inventario.devolucion.crear|caja.devolucion.crear')->name('devoluciones.store');
        Route::get('devoluciones/{devolucion}', [DevolucionesController::class, 'show'])->middleware('permiso:inventario.devolucion.ver|caja.devolucion.ver')->name('devoluciones.show');
        Route::post('devoluciones/{devolucion}/aprobar', [DevolucionesController::class, 'aprobar'])->middleware('permiso:inventario.devolucion.aprobar|caja.devolucion.aprobar')->name('devoluciones.aprobar');
        Route::post('devoluciones/{devolucion}/rechazar', [DevolucionesController::class, 'rechazar'])->middleware('permiso:inventario.devolucion.aprobar|caja.devolucion.aprobar')->name('devoluciones.rechazar');
    });

    // Compras
    Route::prefix('compras')->name('compras.')->group(function () {
        Route::get('ordenes', [OrdenesCompraController::class, 'index'])->middleware('permiso:compras.orden-de-compra.ver')->name('ordenes.index');
        Route::get('ordenes/crear', [OrdenesCompraController::class, 'create'])->middleware('permiso:compras.orden-de-compra.crear')->name('ordenes.create');
        Route::post('ordenes', [OrdenesCompraController::class, 'store'])->middleware('permiso:compras.orden-de-compra.crear')->name('ordenes.store');
        Route::get('ordenes/{orden}', [OrdenesCompraController::class, 'show'])->middleware('permiso:compras.orden-de-compra.ver')->name('ordenes.show');
        Route::get('ordenes/{orden}/editar', [OrdenesCompraController::class, 'edit'])->middleware('permiso:compras.orden-de-compra.editar')->name('ordenes.edit');
        Route::put('ordenes/{orden}', [OrdenesCompraController::class, 'update'])->middleware('permiso:compras.orden-de-compra.editar')->name('ordenes.update');
        Route::delete('ordenes/{orden}', [OrdenesCompraController::class, 'destroy'])->middleware('permiso:compras.orden-de-compra.eliminar')->name('ordenes.destroy');
        Route::post('ordenes/{orden}/enviar', [OrdenesCompraController::class, 'enviar'])->middleware('permiso:compras.orden-de-compra.editar')->name('ordenes.enviar');
        Route::post('ordenes/{orden}/aprobar', [OrdenesCompraController::class, 'aprobar'])->middleware('permiso:compras.orden-de-compra.aprobar')->name('ordenes.aprobar');
        Route::post('ordenes/{orden}/rechazar', [OrdenesCompraController::class, 'rechazar'])->middleware('permiso:compras.orden-de-compra.aprobar')->name('ordenes.rechazar');
        Route::post('ordenes/{orden}/anular', [OrdenesCompraController::class, 'anular'])->middleware('permiso:compras.orden-de-compra.eliminar')->name('ordenes.anular');
        Route::post('ordenes/{orden}/cerrar', [OrdenesCompraController::class, 'cerrar'])->middleware('permiso:compras.orden-de-compra.editar')->name('ordenes.cerrar');

        Route::get('facturas', [FacturasProveedorController::class, 'index'])->middleware('permiso:compras.factura-de-proveedores.ver')->name('facturas.index');
        Route::get('facturas/crear', [FacturasProveedorController::class, 'create'])->middleware('permiso:compras.factura-de-proveedores.crear')->name('facturas.create');
        Route::post('facturas', [FacturasProveedorController::class, 'store'])->middleware('permiso:compras.factura-de-proveedores.crear')->name('facturas.store');
        Route::get('facturas/{factura}', [FacturasProveedorController::class, 'show'])->middleware('permiso:compras.factura-de-proveedores.ver|finanzas.cuentas-por-pagar.ver')->name('facturas.show');
        Route::post('facturas/{factura}/aprobar', [FacturasProveedorController::class, 'aprobar'])->middleware('permiso:compras.factura-de-proveedores.aprobar')->name('facturas.aprobar');
        Route::post('facturas/{factura}/rechazar', [FacturasProveedorController::class, 'rechazar'])->middleware('permiso:compras.factura-de-proveedores.aprobar')->name('facturas.rechazar');
    });

    // Finanzas
    Route::get('finanzas/cuentas-por-pagar', [CuentasPorPagarController::class, 'index'])->middleware('permiso:finanzas.cuentas-por-pagar.ver')->name('finanzas.cuentas-por-pagar');
    Route::post('finanzas/cuentas-por-pagar/{factura}/pagos', [CuentasPorPagarController::class, 'pagar'])->middleware('permiso:finanzas.cuentas-por-pagar.crear')->name('finanzas.pagos.store');
    Route::post('finanzas/pagos-proveedor/{pago}/aprobar', [CuentasPorPagarController::class, 'aprobarPago'])->middleware('permiso:finanzas.cuentas-por-pagar.aprobar')->name('finanzas.pagos.aprobar');
    Route::post('finanzas/pagos-proveedor/{pago}/rechazar', [CuentasPorPagarController::class, 'rechazarPago'])->middleware('permiso:finanzas.cuentas-por-pagar.aprobar')->name('finanzas.pagos.rechazar');
    Route::get('finanzas/libro-de-compras', [LibroComprasController::class, 'index'])->middleware('permiso:finanzas.libro-de-compras.ver')->name('finanzas.libro-compras');
    Route::get('finanzas/libro-de-ventas', [LibroVentasController::class, 'index'])->middleware('permiso:finanzas.libro-de-ventas.ver')->name('finanzas.libro-ventas');
    Route::get('finanzas/cuentas-por-cobrar', [CuentasPorCobrarController::class, 'index'])->middleware('permiso:finanzas.cuentas-por-cobrar.ver')->name('finanzas.cuentas-por-cobrar');

    // RRHH
    Route::prefix('rrhh')->name('rrhh.')->group(function () {
        $p = fn (string $sub, string $accion) => "permiso:rrhh.{$sub}.{$accion}";
        Route::get('personal', [RRHH\PersonalController::class, 'index'])->middleware($p('ficha-de-personal', 'ver'))->name('personal.index');
        Route::get('personal/crear', [RRHH\PersonalController::class, 'create'])->middleware($p('ficha-de-personal', 'crear'))->name('personal.create');
        Route::post('personal', [RRHH\PersonalController::class, 'store'])->middleware($p('ficha-de-personal', 'crear'))->name('personal.store');
        Route::get('personal/{empleado}', [RRHH\PersonalController::class, 'show'])->middleware($p('ficha-de-personal', 'ver'))->name('personal.show');
        Route::get('personal/{empleado}/editar', [RRHH\PersonalController::class, 'edit'])->middleware($p('ficha-de-personal', 'editar'))->name('personal.edit');
        Route::put('personal/{empleado}', [RRHH\PersonalController::class, 'update'])->middleware($p('ficha-de-personal', 'editar'))->name('personal.update');
        Route::post('personal/{empleado}/documentos', [RRHH\PersonalController::class, 'adjuntar'])->middleware($p('ficha-de-personal', 'editar'))->name('personal.adjuntar');
        Route::get('documentos/{documento}', [RRHH\PersonalController::class, 'documento'])->middleware($p('ficha-de-personal', 'ver'))->name('personal.documento');

        Route::get('contratos', [RRHH\ContratosController::class, 'index'])->middleware($p('gestion-de-contratos', 'ver'))->name('contratos.index');
        Route::get('contratos/crear', [RRHH\ContratosController::class, 'create'])->middleware($p('gestion-de-contratos', 'crear'))->name('contratos.create');
        Route::post('contratos', [RRHH\ContratosController::class, 'store'])->middleware($p('gestion-de-contratos', 'crear'))->name('contratos.store');
        Route::get('contratos/plantillas', [RRHH\ContratosController::class, 'plantillas'])->middleware($p('gestion-de-contratos', 'ver'))->name('contratos.plantillas');
        Route::post('contratos/plantillas', [RRHH\ContratosController::class, 'guardarPlantilla'])->middleware($p('gestion-de-contratos', 'editar'))->name('contratos.plantillas.store');
        Route::put('contratos/plantillas/{plantilla}', [RRHH\ContratosController::class, 'guardarPlantilla'])->middleware($p('gestion-de-contratos', 'editar'))->name('contratos.plantillas.update');
        Route::get('contratos/{contrato}', [RRHH\ContratosController::class, 'show'])->middleware($p('gestion-de-contratos', 'ver'))->name('contratos.show');
        Route::get('contratos/{contrato}/editar', [RRHH\ContratosController::class, 'edit'])->middleware($p('gestion-de-contratos', 'editar'))->name('contratos.edit');
        Route::put('contratos/{contrato}', [RRHH\ContratosController::class, 'update'])->middleware($p('gestion-de-contratos', 'editar'))->name('contratos.update');
        Route::post('contratos/{contrato}/enviar', [RRHH\ContratosController::class, 'enviar'])->middleware($p('gestion-de-contratos', 'editar'))->name('contratos.enviar');
        Route::post('contratos/{contrato}/sincronizar', [RRHH\ContratosController::class, 'sincronizar'])->middleware($p('gestion-de-contratos', 'editar'))->name('contratos.sincronizar');

        Route::get('turnos', [RRHH\TurnosController::class, 'index'])->middleware($p('gestion-de-turnos', 'ver'))->name('turnos');
        Route::post('turnos', [RRHH\TurnosController::class, 'store'])->middleware($p('gestion-de-turnos', 'crear'))->name('turnos.store');
        Route::put('turnos/{turno}', [RRHH\TurnosController::class, 'update'])->middleware($p('gestion-de-turnos', 'editar'))->name('turnos.update');
        Route::post('turnos/{turno}/asignar', [RRHH\TurnosController::class, 'asignar'])->middleware($p('gestion-de-turnos', 'editar'))->name('turnos.asignar');

        Route::get('asistencia', [RRHH\AsistenciaController::class, 'index'])->middleware($p('control-de-asistencia', 'ver'))->name('asistencia');
        Route::post('asistencia/marcas', [RRHH\AsistenciaController::class, 'marcar'])->middleware($p('control-de-asistencia', 'crear'))->name('asistencia.marcar');
        Route::post('asistencia/importar', [RRHH\AsistenciaController::class, 'importar'])->middleware($p('control-de-asistencia', 'crear'))->name('asistencia.importar');

        Route::get('nominas', [RRHH\NominasController::class, 'index'])->middleware($p('nomina-y-liquidaciones', 'ver'))->name('nominas.index');
        Route::post('nominas', [RRHH\NominasController::class, 'generar'])->middleware($p('nomina-y-liquidaciones', 'crear'))->name('nominas.generar');
        Route::get('nominas/{nomina}', [RRHH\NominasController::class, 'show'])->middleware($p('nomina-y-liquidaciones', 'ver'))->name('nominas.show');
        Route::post('nominas/{nomina}/aprobar', [RRHH\NominasController::class, 'aprobar'])->middleware($p('nomina-y-liquidaciones', 'aprobar'))->name('nominas.aprobar');
        Route::post('nominas/{nomina}/pagar', [RRHH\NominasController::class, 'pagar'])->middleware($p('nomina-y-liquidaciones', 'editar'))->name('nominas.pagar');
        Route::post('liquidaciones/{liquidacion}/bonos', [RRHH\NominasController::class, 'bonos'])->middleware($p('nomina-y-liquidaciones', 'editar'))->name('liquidaciones.bonos');
        Route::get('liquidaciones/{liquidacion}/pdf', [RRHH\NominasController::class, 'pdf'])->middleware($p('nomina-y-liquidaciones', 'ver'))->name('liquidaciones.pdf');
        Route::get('parametros', fn () => to_route('configuracion.parametros.show', 'nomina'))->middleware($p('parametros-de-nomina', 'ver'))->name('parametros');

        Route::get('ausencias', [RRHH\AusenciasController::class, 'index'])->middleware($p('vacaciones-y-ausencias', 'ver'))->name('ausencias');
        Route::post('ausencias', [RRHH\AusenciasController::class, 'store'])->middleware($p('vacaciones-y-ausencias', 'crear'))->name('ausencias.store');
        Route::get('ausencias/saldo/{empleado}', [RRHH\AusenciasController::class, 'saldo'])->middleware($p('vacaciones-y-ausencias', 'ver'))->name('ausencias.saldo');
        Route::post('ausencias/{ausencia}/aprobar', [RRHH\AusenciasController::class, 'aprobar'])->middleware($p('vacaciones-y-ausencias', 'aprobar'))->name('ausencias.aprobar');
        Route::post('ausencias/{ausencia}/rechazar', [RRHH\AusenciasController::class, 'rechazar'])->middleware($p('vacaciones-y-ausencias', 'aprobar'))->name('ausencias.rechazar');
        Route::post('ausencias/{ausencia}/anular', [RRHH\AusenciasController::class, 'anular'])->middleware($p('vacaciones-y-ausencias', 'eliminar'))->name('ausencias.anular');

        Route::get('cumplimiento', [RRHH\CumplimientoController::class, 'index'])->middleware($p('cumplimiento-normativo', 'ver'))->name('cumplimiento');

        Route::get('finiquitos', [RRHH\FiniquitosController::class, 'index'])->middleware($p('desvinculaciones-y-finiquitos', 'ver'))->name('finiquitos');
        Route::post('finiquitos', [RRHH\FiniquitosController::class, 'store'])->middleware($p('desvinculaciones-y-finiquitos', 'crear'))->name('finiquitos.store');
        Route::post('finiquitos/{finiquito}/aprobar', [RRHH\FiniquitosController::class, 'aprobar'])->middleware($p('desvinculaciones-y-finiquitos', 'aprobar'))->name('finiquitos.aprobar');
        Route::post('finiquitos/{finiquito}/firmar', [RRHH\FiniquitosController::class, 'firmar'])->middleware($p('desvinculaciones-y-finiquitos', 'editar'))->name('finiquitos.firmar');
        Route::post('finiquitos/{finiquito}/anular', [RRHH\FiniquitosController::class, 'anular'])->middleware($p('desvinculaciones-y-finiquitos', 'eliminar'))->name('finiquitos.anular');

        Route::get('indicadores', [RRHH\IndicadoresController::class, 'index'])->middleware($p('indicadores-y-reportes', 'ver'))->name('indicadores');

        Route::get('anticipos', [RRHH\AnticiposController::class, 'index'])->middleware($p('registrar-anticipo', 'ver'))->name('anticipos');
        Route::post('anticipos', [RRHH\AnticiposController::class, 'store'])->middleware($p('registrar-anticipo', 'crear'))->name('anticipos.store');
        Route::post('anticipos/{anticipo}/anular', [RRHH\AnticiposController::class, 'anular'])->middleware($p('registrar-anticipo', 'eliminar'))->name('anticipos.anular');

        Route::get('comisiones', [RRHH\ComisionesController::class, 'index'])->middleware($p('comisiones-de-venta', 'ver'))->name('comisiones');
        Route::post('comisiones/aprobar', [RRHH\ComisionesController::class, 'aprobar'])->middleware($p('comisiones-de-venta', 'aprobar'))->name('comisiones.aprobar');
        Route::post('comisiones/reglas', [RRHH\ComisionesController::class, 'guardarRegla'])->middleware($p('comisiones-de-venta', 'aprobar'))->name('comisiones.reglas');
        Route::delete('comisiones/reglas/{regla}', [RRHH\ComisionesController::class, 'eliminarRegla'])->middleware($p('comisiones-de-venta', 'aprobar'))->name('comisiones.reglas.destroy');

        Route::get('prestamos', [RRHH\PrestamosController::class, 'index'])->middleware($p('gestion-de-prestamos', 'ver'))->name('prestamos');
        Route::post('prestamos', [RRHH\PrestamosController::class, 'store'])->middleware($p('gestion-de-prestamos', 'crear'))->name('prestamos.store');
        Route::post('prestamos/{prestamo}/aprobar', [RRHH\PrestamosController::class, 'aprobar'])->middleware($p('gestion-de-prestamos', 'aprobar'))->name('prestamos.aprobar');
        Route::post('prestamos/{prestamo}/rechazar', [RRHH\PrestamosController::class, 'rechazar'])->middleware($p('gestion-de-prestamos', 'aprobar'))->name('prestamos.rechazar');
    });

    // VentasCRM → contact center
    Route::prefix('ventas')->name('ventas.')->group(function () {
        $p = fn (string $sub, string $accion) => "permiso:ventascrm.{$sub}.{$accion}";
        Route::get('agentes', [\App\Http\Controllers\ContactCenter\AgentesController::class, 'index'])->middleware($p('agente', 'ver'))->name('agentes');
        Route::post('agentes', [\App\Http\Controllers\ContactCenter\AgentesController::class, 'store'])->middleware($p('agente', 'crear'))->name('agentes.store');
        Route::put('agentes/{agente}', [\App\Http\Controllers\ContactCenter\AgentesController::class, 'update'])->middleware($p('agente', 'editar'))->name('agentes.update');

        Route::get('consola', [\App\Http\Controllers\ContactCenter\ConsolaController::class, 'show'])->middleware($p('agente', 'ver'))->name('consola');
        Route::post('consola/estado', [\App\Http\Controllers\ContactCenter\ConsolaController::class, 'estado'])->middleware($p('agente', 'ver'))->name('consola.estado');
        Route::post('consola/llamar/{lead}', [\App\Http\Controllers\ContactCenter\ConsolaController::class, 'llamar'])->middleware([$p('agente', 'ver'), 'throttle:30,1'])->name('consola.llamar');
        Route::post('llamadas/{llamada}/colgar', [\App\Http\Controllers\ContactCenter\ConsolaController::class, 'colgar'])->middleware($p('agente', 'ver'))->name('llamadas.colgar');
        Route::post('llamadas/{llamada}/simular', [\App\Http\Controllers\ContactCenter\ConsolaController::class, 'simular'])->middleware($p('agente', 'ver'))->name('llamadas.simular');
        Route::post('llamadas/{llamada}/tipificar', [\App\Http\Controllers\ContactCenter\ConsolaController::class, 'tipificar'])->middleware($p('agente', 'ver'))->name('llamadas.tipificar');

        Route::get('campanas', [\App\Http\Controllers\ContactCenter\CampanasController::class, 'index'])->middleware($p('campana', 'ver'))->name('campanas.index');
        Route::get('campanas/crear', [\App\Http\Controllers\ContactCenter\CampanasController::class, 'create'])->middleware($p('campana', 'crear'))->name('campanas.create');
        Route::post('campanas', [\App\Http\Controllers\ContactCenter\CampanasController::class, 'store'])->middleware($p('campana', 'crear'))->name('campanas.store');
        Route::get('campanas/{campana}', [\App\Http\Controllers\ContactCenter\CampanasController::class, 'show'])->middleware($p('campana', 'ver'))->name('campanas.show');
        Route::get('campanas/{campana}/editar', [\App\Http\Controllers\ContactCenter\CampanasController::class, 'edit'])->middleware($p('campana', 'editar'))->name('campanas.edit');
        Route::put('campanas/{campana}', [\App\Http\Controllers\ContactCenter\CampanasController::class, 'update'])->middleware($p('campana', 'editar'))->name('campanas.update');
        Route::post('campanas/{campana}/estado', [\App\Http\Controllers\ContactCenter\CampanasController::class, 'estado'])->middleware($p('campana', 'editar'))->name('campanas.estado');
        Route::post('campanas/{campana}/importar', [\App\Http\Controllers\ContactCenter\CampanasController::class, 'importar'])->middleware($p('campana', 'editar'))->name('campanas.importar');

        Route::get('asignacion', [\App\Http\Controllers\ContactCenter\AsignacionController::class, 'index'])->middleware($p('asignacion', 'ver'))->name('asignacion');
        Route::post('asignacion/reasignar', [\App\Http\Controllers\ContactCenter\AsignacionController::class, 'reasignar'])->middleware($p('asignacion', 'editar'))->name('asignacion.reasignar');
        Route::post('asignacion/{campana}', [\App\Http\Controllers\ContactCenter\AsignacionController::class, 'asignar'])->middleware($p('asignacion', 'editar'))->name('asignacion.asignar');

        Route::get('guiones', [\App\Http\Controllers\ContactCenter\GuionesController::class, 'index'])->middleware($p('script', 'ver'))->name('guiones');
        Route::post('guiones', [\App\Http\Controllers\ContactCenter\GuionesController::class, 'store'])->middleware($p('script', 'crear'))->name('guiones.store');
        Route::put('guiones/{guion}', [\App\Http\Controllers\ContactCenter\GuionesController::class, 'update'])->middleware($p('script', 'editar'))->name('guiones.update');

        Route::get('tiempo-real', [\App\Http\Controllers\ContactCenter\OperacionController::class, 'tiempoReal'])->middleware($p('tiempo-real', 'ver'))->name('tiempo-real');
        Route::get('panel', [\App\Http\Controllers\ContactCenter\OperacionController::class, 'panel'])->middleware($p('panel-de-control', 'ver'))->name('panel');
        Route::post('panel/colas', [\App\Http\Controllers\ContactCenter\OperacionController::class, 'guardarCola'])->middleware($p('panel-de-control', 'editar'))->name('panel.colas.store');
        Route::put('panel/colas/{cola}', [\App\Http\Controllers\ContactCenter\OperacionController::class, 'guardarCola'])->middleware($p('panel-de-control', 'editar'))->name('panel.colas.update');
        Route::put('panel/campanas/{campana}/horario', [\App\Http\Controllers\ContactCenter\OperacionController::class, 'horario'])->middleware($p('panel-de-control', 'editar'))->name('panel.horario');

        Route::get('auditoria', [\App\Http\Controllers\ContactCenter\CalidadController::class, 'auditoria'])->middleware($p('auditoria', 'ver'))->name('auditoria');
        Route::get('auditoria/evaluaciones/{auditoria}', [\App\Http\Controllers\ContactCenter\CalidadController::class, 'verAuditoria'])->middleware($p('auditoria', 'ver'))->name('auditoria.ver');
        Route::post('auditoria/criterios', [\App\Http\Controllers\ContactCenter\CalidadController::class, 'guardarCriterio'])->middleware($p('auditoria', 'editar'))->name('auditoria.criterios.store');
        Route::put('auditoria/criterios/{criterio}', [\App\Http\Controllers\ContactCenter\CalidadController::class, 'guardarCriterio'])->middleware($p('auditoria', 'editar'))->name('auditoria.criterios.update');
        Route::post('auditoria/{llamada}', [\App\Http\Controllers\ContactCenter\CalidadController::class, 'evaluar'])->middleware($p('auditoria', 'crear'))->name('auditoria.evaluar');

        $rc = \App\Http\Controllers\Terreno\RecorridosController::class;
        Route::get('recorrido', [$rc, 'index'])->middleware($p('lista-de-recorrido', 'ver'))->name('recorrido');
        Route::post('recorrido', [$rc, 'store'])->middleware($p('lista-de-recorrido', 'crear'))->name('recorrido.store');
        Route::post('recorrido/{recorrido}/paradas', [$rc, 'agregar'])->middleware($p('lista-de-recorrido', 'crear'))->name('recorrido.agregar');
        Route::post('recorrido/{recorrido}/optimizar', [$rc, 'optimizar'])->middleware($p('lista-de-recorrido', 'crear'))->name('recorrido.optimizar');
        Route::post('recorrido/{recorrido}/cerrar', [$rc, 'cerrar'])->middleware($p('lista-de-recorrido', 'crear'))->name('recorrido.cerrar');
        Route::post('recorrido/paradas/{parada}/marcar', [$rc, 'marcar'])->middleware($p('lista-de-recorrido', 'ver'))->name('recorrido.marcar');
        Route::delete('recorrido/paradas/{parada}', [$rc, 'quitar'])->middleware($p('lista-de-recorrido', 'crear'))->name('recorrido.quitar');

        $wa = \App\Http\Controllers\Mensajeria\WhatsappController::class;
        Route::get('whatsapp', [$wa, 'index'])->middleware($p('whatsapp', 'ver'))->name('whatsapp');
        Route::post('whatsapp/iniciar', [$wa, 'iniciar'])->middleware($p('whatsapp', 'crear'))->name('whatsapp.iniciar');
        Route::post('whatsapp/{conversacion}/texto', [$wa, 'texto'])->middleware([$p('whatsapp', 'crear'), 'throttle:60,1'])->name('whatsapp.texto');
        Route::post('whatsapp/{conversacion}/plantilla', [$wa, 'plantilla'])->middleware([$p('whatsapp', 'crear'), 'throttle:30,1'])->name('whatsapp.plantilla');
        Route::post('whatsapp/{conversacion}/estado', [$wa, 'estado'])->middleware($p('whatsapp', 'crear'))->name('whatsapp.estado');
        Route::post('whatsapp/{conversacion}/ia/{accion}', [$wa, 'ia'])->middleware([$p('whatsapp', 'crear'), 'throttle:20,1'])->whereIn('accion', ['sugerir', 'resumir'])->name('whatsapp.ia');

        $pl = \App\Http\Controllers\Mensajeria\PlantillasController::class;
        Route::get('whatsapp-plantillas', [$pl, 'index'])->middleware($p('whatsapp', 'ver'))->name('whatsapp.plantillas');
        Route::post('whatsapp-plantillas', [$pl, 'store'])->middleware($p('whatsapp', 'editar'))->name('whatsapp.plantillas.store');
        Route::post('whatsapp-plantillas/actualizar', [$pl, 'actualizar'])->middleware([$p('whatsapp', 'editar'), 'throttle:10,1'])->name('whatsapp.plantillas.actualizar');
        Route::put('whatsapp-plantillas/{plantilla}', [$pl, 'update'])->middleware($p('whatsapp', 'editar'))->name('whatsapp.plantillas.update');
        Route::post('whatsapp-plantillas/{plantilla}/revision', [$pl, 'revision'])->middleware($p('whatsapp', 'editar'))->name('whatsapp.plantillas.revision');
        Route::delete('whatsapp-plantillas/{plantilla}', [$pl, 'destroy'])->middleware($p('whatsapp', 'editar'))->name('whatsapp.plantillas.destroy');

        $li = \App\Http\Controllers\Mensajeria\LinkedinController::class;
        Route::get('linkedin', [$li, 'index'])->middleware($p('linkedin', 'ver'))->name('linkedin');
        Route::post('linkedin/sincronizar', [$li, 'sincronizar'])->middleware([$p('linkedin', 'ver'), 'throttle:6,1'])->name('linkedin.sincronizar');
        Route::post('linkedin/{conversacion}/texto', [$li, 'texto'])->middleware([$p('linkedin', 'crear'), 'throttle:60,1'])->name('linkedin.texto');
        Route::post('linkedin/{conversacion}/lead', [$li, 'lead'])->middleware($p('linkedin', 'crear'))->name('linkedin.lead');
        Route::post('linkedin/{conversacion}/estado', [$li, 'estado'])->middleware($p('linkedin', 'crear'))->name('linkedin.estado');

        $ma = \App\Http\Controllers\Marketing\MetaAdsController::class;
        Route::get('meta-ads', [$ma, 'index'])->middleware($p('meta-ads', 'ver'))->name('meta-ads');
        Route::post('meta-ads/sincronizar', [$ma, 'sincronizar'])->middleware([$p('meta-ads', 'editar'), 'throttle:6,1'])->name('meta-ads.sincronizar');
        Route::put('meta-ads/{campana}/destino', [$ma, 'destino'])->middleware($p('meta-ads', 'editar'))->name('meta-ads.destino');

        Route::get('grabaciones', [\App\Http\Controllers\ContactCenter\CalidadController::class, 'grabaciones'])->middleware($p('grabaciones', 'ver'))->name('grabaciones');
        Route::post('grabaciones/{llamada}', [\App\Http\Controllers\ContactCenter\CalidadController::class, 'solicitarGrabacion'])->middleware([$p('grabaciones', 'escuchar'), 'throttle:20,1'])->name('grabaciones.solicitar');
        Route::get('grabaciones/{llamada}/audio', [\App\Http\Controllers\ContactCenter\CalidadController::class, 'audio'])->middleware($p('grabaciones', 'escuchar'))->name('grabaciones.audio');
    });

    // Configuración → listas de precios, descuentos, metas y documentación de la API
    Route::prefix('configuracion')->name('configuracion.')->group(function () {
        $lp = \App\Http\Controllers\Configuracion\ListasPreciosController::class;
        Route::get('listas-precios', [$lp, 'index'])->middleware('permiso:configuracion.listas-de-precios.ver')->name('listas-precios.index');
        Route::get('listas-precios/simular', [$lp, 'simular'])->middleware('permiso:configuracion.listas-de-precios.ver')->name('listas-precios.simular');
        Route::post('listas-precios', [$lp, 'store'])->middleware('permiso:configuracion.listas-de-precios.crear')->name('listas-precios.store');
        Route::put('listas-precios/{lista}', [$lp, 'update'])->middleware('permiso:configuracion.listas-de-precios.editar')->name('listas-precios.update');
        Route::delete('listas-precios/{lista}', [$lp, 'destroy'])->middleware('permiso:configuracion.listas-de-precios.eliminar')->name('listas-precios.destroy');
        Route::post('listas-precios/{lista}/items', [$lp, 'guardarItem'])->middleware('permiso:configuracion.listas-de-precios.editar')->name('listas-precios.items.store');
        Route::delete('listas-precios/{lista}/items/{item}', [$lp, 'quitarItem'])->middleware('permiso:configuracion.listas-de-precios.editar')->name('listas-precios.items.destroy');

        $dc = \App\Http\Controllers\Configuracion\DescuentosController::class;
        Route::get('descuentos', [$dc, 'index'])->middleware('permiso:configuracion.descuentos.ver')->name('descuentos.index');
        Route::post('descuentos/reglas', [$dc, 'guardarRegla'])->middleware('permiso:configuracion.descuentos.crear')->name('descuentos.reglas.store');
        Route::put('descuentos/reglas/{regla}', [$dc, 'guardarRegla'])->middleware('permiso:configuracion.descuentos.editar')->name('descuentos.reglas.update');
        Route::delete('descuentos/reglas/{regla}', [$dc, 'eliminarRegla'])->middleware('permiso:configuracion.descuentos.eliminar')->name('descuentos.reglas.destroy');
        Route::post('descuentos/niveles', [$dc, 'guardarNivel'])->middleware('permiso:configuracion.descuentos.editar')->name('descuentos.niveles.store');
        Route::delete('descuentos/niveles/{nivel}', [$dc, 'eliminarNivel'])->middleware('permiso:configuracion.descuentos.eliminar')->name('descuentos.niveles.destroy');

        $mc = \App\Http\Controllers\Configuracion\MetasController::class;
        Route::get('metas', [$mc, 'index'])->middleware('permiso:configuracion.meta-admin.ver')->name('metas.index');
        Route::post('metas', [$mc, 'store'])->middleware('permiso:configuracion.meta-admin.crear')->name('metas.store');
        Route::post('metas/copiar', [$mc, 'copiar'])->middleware('permiso:configuracion.meta-admin.crear')->name('metas.copiar');
        Route::put('metas/{meta}', [$mc, 'update'])->middleware('permiso:configuracion.meta-admin.editar')->name('metas.update');
        Route::delete('metas/{meta}', [$mc, 'destroy'])->middleware('permiso:configuracion.meta-admin.eliminar')->name('metas.destroy');

        $da = \App\Http\Controllers\Configuracion\DocumentacionApiController::class;
        Route::get('documentacion-api', [$da, 'index'])->middleware('permiso:configuracion.documentacion-informe-ventas.ver')->name('documentacion-api');
        Route::get('documentacion-api/openapi.json', [$da, 'json'])->middleware('permiso:configuracion.documentacion-informe-ventas.ver')->name('documentacion-api.json');
    });

    // Configuración → telefonía
    Route::prefix('configuracion/integraciones')->name('configuracion.integraciones.')->group(function () {
        $ic = \App\Http\Controllers\Configuracion\IntegracionesController::class;
        Route::get('/', [$ic, 'index'])->middleware('permiso:configuracion.integraciones.ver')->name('index');
        Route::put('{tipo}', [$ic, 'update'])->middleware('permiso:configuracion.integraciones.editar')->whereIn('tipo', array_keys(\App\Modules\Integraciones\Models\Integracion::TIPOS))->name('update');
        Route::post('{tipo}/probar', [$ic, 'probar'])->middleware(['permiso:configuracion.integraciones.editar', 'throttle:10,1'])->whereIn('tipo', array_keys(\App\Modules\Integraciones\Models\Integracion::TIPOS))->name('probar');
    });

    Route::prefix('configuracion/telefonia')->name('configuracion.telefonia.')->group(function () {
        $t = \App\Http\Controllers\ContactCenter\TelefoniaController::class;
        Route::get('proveedores', [$t, 'proveedores'])->middleware('permiso:configuracion.proveedor-ip.ver')->name('proveedores');
        Route::post('proveedores', [$t, 'guardarProveedor'])->middleware('permiso:configuracion.proveedor-ip.crear')->name('proveedores.store');
        Route::put('proveedores/{proveedor}', [$t, 'guardarProveedor'])->middleware('permiso:configuracion.proveedor-ip.editar')->name('proveedores.update');
        Route::post('proveedores/{proveedor}/probar', [$t, 'probarProveedor'])->middleware(['permiso:configuracion.proveedor-ip.editar', 'throttle:10,1'])->name('proveedores.probar');
        Route::get('numeros', [$t, 'numeros'])->middleware('permiso:configuracion.numero-entrante.ver')->name('numeros');
        Route::post('numeros', [$t, 'guardarNumero'])->middleware('permiso:configuracion.numero-entrante.crear')->name('numeros.store');
        Route::put('numeros/{numero}', [$t, 'guardarNumero'])->middleware('permiso:configuracion.numero-entrante.editar')->name('numeros.update');
        Route::get('ivr', [$t, 'ivrs'])->middleware('permiso:configuracion.ivr.ver')->name('ivr');
        Route::post('ivr', [$t, 'guardarIvr'])->middleware('permiso:configuracion.ivr.crear')->name('ivr.store');
        Route::put('ivr/{ivr}', [$t, 'guardarIvr'])->middleware('permiso:configuracion.ivr.editar')->name('ivr.update');
        Route::get('lista-negra', [$t, 'listaNegra'])->middleware('permiso:configuracion.lista-negra.ver')->name('lista-negra');
        Route::post('lista-negra', [$t, 'agregarListaNegra'])->middleware('permiso:configuracion.lista-negra.crear')->name('lista-negra.store');
        Route::delete('lista-negra/{numero}', [$t, 'quitarListaNegra'])->middleware('permiso:configuracion.lista-negra.eliminar')->name('lista-negra.destroy');
    });

    // Finanzas: tesorería, contabilidad y honorarios
    Route::prefix('finanzas')->name('finanzas.')->group(function () {
        Route::get('flujos-de-caja', [FlujoCajaController::class, 'index'])->middleware('permiso:finanzas.flujos-de-caja.ver')->name('flujo-caja');

        Route::get('honorarios', [HonorariosController::class, 'index'])->middleware('permiso:finanzas.libro-de-honorarios.ver')->name('honorarios');
        Route::post('honorarios', [HonorariosController::class, 'store'])->middleware('permiso:finanzas.libro-de-honorarios.crear')->name('honorarios.store');
        Route::post('honorarios/{honorario}/pagar', [HonorariosController::class, 'pagar'])->middleware('permiso:finanzas.libro-de-honorarios.editar')->name('honorarios.pagar');
        Route::post('honorarios/{honorario}/anular', [HonorariosController::class, 'anular'])->middleware('permiso:finanzas.libro-de-honorarios.eliminar')->name('honorarios.anular');

        Route::get('centros-de-costo', [CentrosCostoController::class, 'index'])->middleware('permiso:finanzas.centro-de-costos.ver')->name('centros');
        Route::post('centros-de-costo', [CentrosCostoController::class, 'store'])->middleware('permiso:finanzas.centro-de-costos.crear')->name('centros.store');
        Route::put('centros-de-costo/{centro}', [CentrosCostoController::class, 'update'])->middleware('permiso:finanzas.centro-de-costos.editar')->name('centros.update');

        Route::get('asientos', [AsientosController::class, 'index'])->middleware('permiso:finanzas.asientos-contables.ver')->name('asientos.index');
        Route::get('asientos/crear', [AsientosController::class, 'create'])->middleware('permiso:finanzas.asientos-contables.crear')->name('asientos.create');
        Route::post('asientos', [AsientosController::class, 'store'])->middleware('permiso:finanzas.asientos-contables.crear')->name('asientos.store');
        Route::get('asientos/{asiento}', [AsientosController::class, 'show'])->middleware('permiso:finanzas.asientos-contables.ver')->name('asientos.show');
        Route::post('asientos/{asiento}/revertir', [AsientosController::class, 'revertir'])->middleware('permiso:finanzas.asientos-contables.eliminar')->name('asientos.revertir');
        Route::get('plan-de-cuentas', [AsientosController::class, 'plan'])->middleware('permiso:finanzas.asientos-contables.ver')->name('plan');
        Route::post('plan-de-cuentas', [AsientosController::class, 'guardarCuenta'])->middleware('permiso:finanzas.asientos-contables.editar')->name('plan.store');
        Route::put('plan-de-cuentas/{cuenta}', [AsientosController::class, 'guardarCuenta'])->middleware('permiso:finanzas.asientos-contables.editar')->name('plan.update');

        Route::get('balances', [BalancesController::class, 'index'])->middleware('permiso:finanzas.balances-y-reportes.ver')->name('balances');

        Route::get('conciliacion', [ConciliacionController::class, 'index'])->middleware('permiso:finanzas.conciliacion-bancaria.ver')->name('conciliacion');
        Route::post('conciliacion/cuentas', [ConciliacionController::class, 'guardarCuenta'])->middleware('permiso:finanzas.conciliacion-bancaria.crear')->name('conciliacion.cuentas');
        Route::post('conciliacion/{cuenta}/importar', [ConciliacionController::class, 'importar'])->middleware('permiso:finanzas.conciliacion-bancaria.crear')->name('conciliacion.importar');
        Route::post('conciliacion/{cuenta}/automatico', [ConciliacionController::class, 'automatico'])->middleware('permiso:finanzas.conciliacion-bancaria.editar')->name('conciliacion.automatico');
        Route::get('conciliacion/movimientos/{movimiento}/candidatos', [ConciliacionController::class, 'candidatos'])->middleware('permiso:finanzas.conciliacion-bancaria.editar')->name('conciliacion.candidatos');
        Route::post('conciliacion/movimientos/{movimiento}/conciliar', [ConciliacionController::class, 'conciliar'])->middleware('permiso:finanzas.conciliacion-bancaria.editar')->name('conciliacion.conciliar');
        Route::post('conciliacion/movimientos/{movimiento}/contabilizar', [ConciliacionController::class, 'contabilizar'])->middleware('permiso:finanzas.conciliacion-bancaria.editar')->name('conciliacion.contabilizar');
        Route::post('conciliacion/movimientos/{movimiento}/ignorar', [ConciliacionController::class, 'ignorar'])->middleware('permiso:finanzas.conciliacion-bancaria.editar')->name('conciliacion.ignorar');
        Route::post('conciliacion/movimientos/{movimiento}/deshacer', [ConciliacionController::class, 'deshacer'])->middleware('permiso:finanzas.conciliacion-bancaria.editar')->name('conciliacion.deshacer');
    });
});
