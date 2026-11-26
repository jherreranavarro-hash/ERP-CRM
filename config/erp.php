<?php

/*
|--------------------------------------------------------------------------
| Catálogo de módulos (8) y submódulos (71)
|--------------------------------------------------------------------------
|
| Fuente única para el menú lateral, la matriz de permisos (RBAC) y el
| estado de avance. Cada submódulo genera permisos "modulo.submodulo.accion".
| 'ruta' es el nombre de ruta cuando el submódulo ya está construido; si es
| null, el menú lleva a una pantalla que indica en qué fase se entrega.
|
*/

$crud = ['ver', 'crear', 'editar', 'eliminar', 'exportar'];

return [

    'acciones' => [
        'ver' => 'Ver',
        'crear' => 'Crear',
        'editar' => 'Editar',
        'eliminar' => 'Eliminar',
        'exportar' => 'Exportar',
        'aprobar' => 'Aprobar',
        'emitir' => 'Emitir',
        'anular' => 'Anular',
        'proponer' => 'Proponer',
        'escuchar' => 'Escuchar',
    ],

    // Permisos que ni SuperAdmin tiene de forma implícita: deben asignarse
    // explícitamente a un rol y cada uso queda en la trazabilidad.
    'permisos_restringidos' => [
        'ventascrm.grabaciones.escuchar',
    ],

    'modulos' => [

        'dashboard' => [
            'nombre' => 'Dashboard',
            'icono' => 'panel',
            'submodulos' => [
                'crm' => ['nombre' => 'Dashboard CRM', 'acciones' => ['ver'], 'fase' => 3, 'ruta' => 'dashboard'],
            ],
        ],

        'finanzas' => [
            'nombre' => 'Finanzas',
            'icono' => 'finanzas',
            'submodulos' => [
                'cuentas-por-cobrar' => ['nombre' => 'Cuentas por Cobrar', 'grupo' => 'Tesorería y Banco', 'acciones' => $crud, 'fase' => 2, 'ruta' => 'finanzas.cuentas-por-cobrar'],
                'cuentas-por-pagar' => ['nombre' => 'Cuentas por Pagar', 'grupo' => 'Tesorería y Banco', 'acciones' => [...$crud, 'aprobar'], 'fase' => 3],
                'flujos-de-caja' => ['nombre' => 'Flujos de Caja', 'grupo' => 'Tesorería y Banco', 'acciones' => ['ver', 'exportar'], 'fase' => 3],
                'conciliacion-bancaria' => ['nombre' => 'Conciliación Bancaria', 'grupo' => 'Tesorería y Banco', 'acciones' => $crud, 'fase' => 3],
                'libro-de-ventas' => ['nombre' => 'Libro de Ventas', 'grupo' => 'Libros Tributarios', 'acciones' => ['ver', 'exportar'], 'fase' => 2, 'ruta' => 'finanzas.libro-ventas'],
                'libro-de-compras' => ['nombre' => 'Libro de Compras', 'grupo' => 'Libros Tributarios', 'acciones' => ['ver', 'exportar'], 'fase' => 3],
                'libro-de-honorarios' => ['nombre' => 'Libro de Honorarios', 'grupo' => 'Libros Tributarios', 'acciones' => ['ver', 'exportar'], 'fase' => 3],
                'asientos-contables' => ['nombre' => 'Asientos Contables', 'grupo' => 'Contabilidad', 'acciones' => $crud, 'fase' => 3],
                'balances-y-reportes' => ['nombre' => 'Balances y Reportes', 'grupo' => 'Contabilidad', 'acciones' => ['ver', 'exportar'], 'fase' => 3],
                'centro-de-costos' => ['nombre' => 'Centro de Costos', 'grupo' => 'Contabilidad', 'acciones' => $crud, 'fase' => 3],
            ],
        ],

        'rrhh' => [
            'nombre' => 'RRHH',
            'icono' => 'personas',
            'submodulos' => [
                'ficha-de-personal' => ['nombre' => 'Ficha de Personal', 'acciones' => $crud, 'fase' => 3],
                'gestion-de-contratos' => ['nombre' => 'Gestión de Contratos', 'acciones' => $crud, 'fase' => 3],
                'gestion-de-turnos' => ['nombre' => 'Gestión de Turnos', 'acciones' => $crud, 'fase' => 3],
                'control-de-asistencia' => ['nombre' => 'Control de Asistencia', 'acciones' => $crud, 'fase' => 3],
                'nomina-y-liquidaciones' => ['nombre' => 'Nómina y Liquidaciones', 'acciones' => [...$crud, 'aprobar'], 'fase' => 3],
                'parametros-de-nomina' => ['nombre' => 'Parámetros de Nómina', 'acciones' => ['ver', 'editar'], 'fase' => 3],
                'vacaciones-y-ausencias' => ['nombre' => 'Vacaciones y Ausencias', 'acciones' => [...$crud, 'aprobar'], 'fase' => 3],
                'cumplimiento-normativo' => ['nombre' => 'Cumplimiento Normativo', 'acciones' => $crud, 'fase' => 3],
                'desvinculaciones-y-finiquitos' => ['nombre' => 'Desvinculaciones y Finiquitos', 'acciones' => [...$crud, 'aprobar'], 'fase' => 3],
                'indicadores-y-reportes' => ['nombre' => 'Indicadores y Reportes', 'acciones' => ['ver', 'exportar'], 'fase' => 3],
                'gestion-de-prestamos' => ['nombre' => 'Gestión de Préstamos', 'acciones' => [...$crud, 'aprobar'], 'fase' => 3],
                'registrar-anticipo' => ['nombre' => 'Registrar Anticipo (Quincena)', 'acciones' => $crud, 'fase' => 3],
                'comisiones-de-venta' => ['nombre' => 'Comisiones de Venta', 'acciones' => ['ver', 'exportar', 'aprobar'], 'fase' => 3],
            ],
        ],

        'compras' => [
            'nombre' => 'Compras',
            'icono' => 'compras',
            'submodulos' => [
                'orden-de-compra' => ['nombre' => 'Orden de Compra', 'acciones' => [...$crud, 'aprobar'], 'fase' => 3],
                'factura-de-proveedores' => ['nombre' => 'Factura de Proveedores', 'acciones' => $crud, 'fase' => 3],
            ],
        ],

        'inventario' => [
            'nombre' => 'Inventario',
            'icono' => 'inventario',
            'submodulos' => [
                'stock' => ['nombre' => 'Stock', 'acciones' => ['ver', 'exportar', 'editar'], 'fase' => 3, 'ruta' => 'inventario.stock.index'],
                'actualizacion-de-precios' => ['nombre' => 'Actualización de Precios', 'acciones' => [...$crud, 'aprobar'], 'fase' => 3, 'ruta' => 'inventario.precios.index'],
                'recepcion-de-mercaderia' => ['nombre' => 'Recepción de Mercadería', 'acciones' => [...$crud, 'confirmar'], 'fase' => 3, 'ruta' => 'inventario.recepciones.index'],
                'despachos' => ['nombre' => 'Despachos', 'acciones' => [...$crud, 'aprobar'], 'fase' => 3, 'ruta' => 'inventario.despachos.index'],
                'devolucion' => ['nombre' => 'Devolución', 'acciones' => [...$crud, 'aprobar'], 'fase' => 3, 'ruta' => 'inventario.devoluciones.index'],
                'mermas' => ['nombre' => 'Mermas', 'acciones' => [...$crud, 'aprobar'], 'fase' => 3, 'ruta' => 'inventario.mermas.index'],
                'orden-de-venta' => ['nombre' => 'Orden de Venta', 'acciones' => ['ver', 'exportar'], 'fase' => 3, 'ruta' => 'ventas.ordenes.index'],
            ],
        ],

        'ventascrm' => [
            'nombre' => 'VentasCRM',
            'icono' => 'ventas',
            'submodulos' => [
                'dashboard' => ['nombre' => 'Dashboard', 'acciones' => ['ver'], 'fase' => 3],
                'agente' => ['nombre' => 'Agente', 'acciones' => $crud, 'fase' => 4],
                'tiempo-real' => ['nombre' => 'Tiempo Real', 'acciones' => ['ver'], 'fase' => 4],
                'panel-de-control' => ['nombre' => 'Panel de Control', 'acciones' => ['ver', 'editar'], 'fase' => 4],
                'grabaciones' => ['nombre' => 'Grabaciones', 'acciones' => ['ver', 'escuchar', 'exportar'], 'fase' => 4],
                'auditoria' => ['nombre' => 'Auditoría', 'acciones' => $crud, 'fase' => 4],
                'cotizacion' => ['nombre' => 'Cotización', 'acciones' => [...$crud, 'aprobar'], 'fase' => 2, 'ruta' => 'ventas.cotizaciones.index'],
                'orden-de-venta' => ['nombre' => 'Orden de Venta', 'acciones' => [...$crud, 'aprobar'], 'fase' => 3, 'ruta' => 'ventas.ordenes.index'],
                'campana' => ['nombre' => 'Campaña', 'acciones' => $crud, 'fase' => 4],
                'asignacion' => ['nombre' => 'Asignación', 'acciones' => $crud, 'fase' => 4],
                'lista-de-recorrido' => ['nombre' => 'Lista de Recorrido', 'acciones' => $crud, 'fase' => 4],
                'script' => ['nombre' => 'Script', 'acciones' => $crud, 'fase' => 4],
                'cuentas-corrientes' => ['nombre' => 'Cuentas Corrientes por Cliente', 'acciones' => ['ver', 'exportar'], 'fase' => 2],
            ],
        ],

        'caja' => [
            'nombre' => 'Caja',
            'icono' => 'caja',
            'submodulos' => [
                'caja' => ['nombre' => 'Caja', 'acciones' => ['ver', 'crear', 'editar'], 'fase' => 2, 'ruta' => 'caja.turno'],
                'cierres-de-caja' => ['nombre' => 'Cierres de Caja', 'acciones' => ['ver', 'crear', 'exportar', 'aprobar'], 'fase' => 2, 'ruta' => 'caja.cierres.index'],
                'facturas-de-ventas' => ['nombre' => 'Facturas de Ventas', 'acciones' => [...$crud, 'emitir', 'anular', 'aprobar'], 'fase' => 2, 'ruta' => 'caja.documentos.index'],
                'clientes' => ['nombre' => 'Clientes', 'acciones' => ['ver'], 'fase' => 2, 'ruta' => 'configuracion.clientes.index'],
                'stock' => ['nombre' => 'Stock', 'acciones' => ['ver'], 'fase' => 3, 'ruta' => 'inventario.stock.index'],
                'devolucion' => ['nombre' => 'Devolución', 'acciones' => ['ver', 'crear', 'aprobar'], 'fase' => 2, 'ruta' => 'inventario.devoluciones.index'],
            ],
        ],

        'configuracion' => [
            'nombre' => 'Configuración',
            'icono' => 'configuracion',
            'submodulos' => [
                'negocio' => ['nombre' => 'Negocio', 'acciones' => $crud, 'fase' => 1, 'ruta' => 'configuracion.empresas.index'],
                'local' => ['nombre' => 'Local', 'acciones' => $crud, 'fase' => 1, 'ruta' => 'configuracion.locales.index'],
                'usuarios' => ['nombre' => 'Usuarios', 'acciones' => $crud, 'fase' => 1, 'ruta' => 'configuracion.usuarios.index'],
                'perfiles' => ['nombre' => 'Perfiles', 'acciones' => $crud, 'fase' => 1, 'ruta' => 'configuracion.roles.index'],
                'producto' => ['nombre' => 'Producto', 'acciones' => $crud, 'fase' => 2, 'ruta' => 'configuracion.productos.index'],
                'categoria' => ['nombre' => 'Categoría', 'acciones' => $crud, 'fase' => 2, 'ruta' => 'configuracion.productos.index'],
                'descuentos' => ['nombre' => 'Descuentos', 'acciones' => $crud, 'fase' => 2],
                'proveedores' => ['nombre' => 'Proveedores', 'acciones' => $crud, 'fase' => 3],
                'clientes' => ['nombre' => 'Clientes', 'acciones' => $crud, 'fase' => 2, 'ruta' => 'configuracion.clientes.index'],
                'proveedor-ip' => ['nombre' => 'ProveedorIP', 'acciones' => $crud, 'fase' => 4],
                'numero-entrante' => ['nombre' => 'NumeroEntrante', 'acciones' => $crud, 'fase' => 4],
                'lista-negra' => ['nombre' => 'ListaNegra', 'acciones' => $crud, 'fase' => 4],
                'ivr' => ['nombre' => 'Ivr', 'acciones' => $crud, 'fase' => 4],
                'meta-admin' => ['nombre' => 'MetaAdmin', 'acciones' => $crud, 'fase' => 3],
                'tracking-de-actividades' => ['nombre' => 'Tracking de Actividades', 'acciones' => ['ver', 'exportar'], 'fase' => 1, 'ruta' => 'configuracion.auditoria.index'],
                'parametros-de-despacho' => ['nombre' => 'Parámetros de Despacho', 'acciones' => ['ver', 'editar'], 'fase' => 3, 'ruta' => 'configuracion.despacho'],
                'documentacion-informe-ventas' => ['nombre' => 'Documentación Informe Ventas', 'acciones' => ['ver'], 'fase' => 2],
                'listas-de-precios' => ['nombre' => 'Listas de Precios', 'acciones' => $crud, 'fase' => 2],
                'cuentas-corrientes' => ['nombre' => 'Cuentas Corrientes por Cliente', 'acciones' => $crud, 'fase' => 2],
            ],
        ],
    ],

    // Entradas del menú de Configuración que no son submódulos del sistema
    // original, sino exigencias del prompt (mantenedores y tokens de API).
    'extras' => [
        'configuracion' => [
            'parametros' => ['nombre' => 'Parámetros del Sistema', 'acciones' => ['ver', 'proponer', 'aprobar', 'exportar'], 'fase' => 1, 'ruta' => 'configuracion.parametros.index'],
            'tokens-api' => ['nombre' => 'Tokens de API', 'acciones' => ['ver', 'crear', 'eliminar'], 'fase' => 1, 'ruta' => 'configuracion.tokens.index'],
            'folios' => ['nombre' => 'Folios (CAF)', 'acciones' => ['ver', 'crear'], 'fase' => 2, 'ruta' => 'configuracion.folios.index'],
        ],
    ],
];
