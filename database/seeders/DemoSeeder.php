<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Core\Models\Empresa;
use App\Modules\Core\Models\Local;
use App\Modules\Core\Models\Rol;
use App\Modules\Core\Models\UsuarioEmpresa;
use App\Modules\Comercial\Models\Categoria;
use App\Modules\Comercial\Models\Cliente;
use App\Modules\Comercial\Models\Producto;
use App\Modules\Core\Tenancy\Tenancy;
use App\Modules\Facturacion\Models\FolioRango;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Datos de demostración para desarrollo. Los RUT son ficticios (válidos en
 * módulo 11) hasta que se carguen los datos reales del emisor.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $obramax = Empresa::updateOrCreate(['rut' => '76123456-0'], [
            'razon_social' => 'Obramax SpA', 'nombre_fantasia' => 'Obramax', 'giro' => 'Venta al por mayor de materiales de construcción',
            'direccion' => 'Av. Ejemplo 1234', 'comuna' => 'Santiago', 'ciudad' => 'Santiago', 'activo' => true,
        ]);
        $santander = Empresa::updateOrCreate(['rut' => '77654321-7'], [
            'razon_social' => 'Obra Center Santander SpA', 'nombre_fantasia' => 'ObraCenter Santander', 'giro' => 'Ferretería',
            'comuna' => 'Santiago', 'ciudad' => 'Santiago', 'activo' => true,
        ]);

        $tenancy = app(Tenancy::class);
        $tenancy->como($obramax, function () {
            Local::firstOrCreate(['codigo' => 'MATRIZ'], ['nombre' => 'Casa Matriz', 'direccion' => 'Av. Ejemplo 1234', 'comuna' => 'Santiago']);
            Local::firstOrCreate(['codigo' => 'BOD-01'], ['nombre' => 'Bodega Central', 'comuna' => 'Quilicura', 'es_bodega' => true]);
        });
        $tenancy->como($santander, fn () => Local::firstOrCreate(['codigo' => 'STGO'], ['nombre' => 'Local Santiago', 'comuna' => 'Santiago']));

        $password = env('DEMO_PASSWORD') ?: Str::password(16);
        $admin = User::firstOrNew(['email' => 'admin@obramax.cl']);
        $nuevo = ! $admin->exists;
        $admin->fill(['name' => 'Administración Obramax', 'activo' => true]);
        if ($nuevo) {
            $admin->password = $password;
            $admin->password_changed_at = now();
        }
        $admin->es_superadmin = true;
        $admin->save();

        $rolAdmin = Rol::where('nombre', 'Administrador del sistema')->first();
        foreach ([$obramax, $santander] as $e) {
            $tenancy->como($e, fn () => UsuarioEmpresa::updateOrCreate(['user_id' => $admin->id, 'empresa_id' => $e->id], ['rol_id' => $rolAdmin->id, 'activo' => true]));
        }

        $cajera = User::firstOrNew(['email' => 'caja@obramax.cl']);
        if (! $cajera->exists) {
            $cajera->fill(['name' => 'María Cajera', 'activo' => true, 'password' => $password, 'password_changed_at' => now()])->save();
        }
        $tenancy->como($obramax, fn () => UsuarioEmpresa::updateOrCreate(['user_id' => $cajera->id, 'empresa_id' => $obramax->id], [
            'rol_id' => Rol::where('nombre', 'Cajera')->value('id'), 'activo' => true,
            'local_id' => Local::where('codigo', 'MATRIZ')->value('id'),
        ]));

        $this->facturacion($obramax, $admin);

        if ($nuevo && $this->command) {
            $this->command->warn("Usuarios demo: admin@obramax.cl y caja@obramax.cl · contraseña: {$password}");
        }
    }

    /** Facturación activa en Obramax con folios que siguen a los últimos vistos en Fenix (25-nov-2026). */
    private function facturacion(Empresa $empresa, User $admin): void
    {
        app(Tenancy::class)->como($empresa, function () use ($admin) {
            $p = app(\App\Modules\Core\Parametros\Parametros::class);
            if (! $p->valor('modulos.facturacion')) {
                $p->proponer('modulos.facturacion', true, 'empresa', 'Datos de demostración', $admin);
            }

            foreach ([33 => [1071, 2070], 39 => [1406, 3405], 61 => [254, 753], 56 => [1, 100], 52 => [1, 500], 34 => [1, 100]] as $tipo => [$desde, $hasta]) {
                FolioRango::firstOrCreate(['tipo_dte' => $tipo, 'desde' => $desde], ['hasta' => $hasta, 'siguiente' => $desde, 'caf_referencia' => 'DEMO (sin validez)']);
            }

            $cat = ['Obra gruesa' => Categoria::firstOrCreate(['nombre' => 'Obra gruesa']), 'Terminaciones' => Categoria::firstOrCreate(['nombre' => 'Terminaciones']), 'Ferretería' => Categoria::firstOrCreate(['nombre' => 'Ferretería'])];
            foreach ([
                ['CEM-25', 'Cemento especial 25 kg', 'Obra gruesa', 'SAC', '4990', '4500'],
                ['FIE-8', 'Fierro estriado 8 mm x 6 m', 'Obra gruesa', 'UN', '3290', '2990'],
                ['YES-15', 'Plancha yeso-cartón 15 mm', 'Terminaciones', 'UN', '8490', '7790'],
                ['PIN-1G', 'Pintura látex blanco 1 galón', 'Terminaciones', 'UN', '12990', '11490'],
                ['TOR-100', 'Tornillo volcanita 6x1 (caja 100)', 'Ferretería', 'CJ', '2490', '2190'],
            ] as [$sku, $nombre, $c, $u, $neto, $may]) {
                Producto::firstOrCreate(['sku' => $sku], ['nombre' => $nombre, 'categoria_id' => $cat[$c]->id, 'unidad' => $u, 'precio_neto' => $neto, 'precio_mayorista' => $may]);
            }

            Cliente::firstOrCreate(['rut' => '76543210-3'], ['razon_social' => 'Constructora Andes SpA', 'giro' => 'Construcción de edificios', 'direccion' => 'Av. Los Leones 100', 'comuna' => 'Providencia', 'ciudad' => 'Santiago', 'lista_precio' => 'mayorista', 'tiene_credito' => true, 'cupo_credito' => 5000000, 'plazo_dias' => 30]);
            $matriz = \App\Modules\Core\Models\Local::where('codigo', 'MATRIZ')->value('id');
            foreach (['Caja 1', 'Caja 2'] as $nombre) {
                \App\Modules\Caja\Models\Caja::firstOrCreate(['local_id' => $matriz, 'nombre' => $nombre]);
            }

            // Stock inicial en la casa matriz: una recepción confirmada (entra por el kardex).
            if ($matriz && ! \App\Modules\Inventario\Models\Recepcion::exists()) {
                $costos = ['CEM-25' => [400, 3600], 'FIE-8' => [600, 2400], 'YES-15' => [150, 6300], 'PIN-1G' => [60, 8900], 'TOR-100' => [200, 1500]];
                $inv = app(\App\Modules\Inventario\Recepciones::class);
                $rec = $inv->guardar([
                    'local_id' => $matriz, 'proveedor_nombre' => 'Distribuidora Ferretera del Sur', 'proveedor_rut' => '96.512.340-7', 'documento_proveedor' => 'Guía 4512',
                    'observaciones' => 'Stock inicial de demostración',
                    'lineas' => Producto::whereIn('sku', array_keys($costos))->get()->map(fn ($p) => ['producto_id' => $p->id, 'cantidad' => $costos[$p->sku][0], 'costo_unitario' => $costos[$p->sku][1]])->all(),
                ], $admin);
                $inv->confirmar($rec, $admin);
            }

            // Compras: proveedores y una orden de compra aprobada lista para recibir.
            $sur = \App\Modules\Compras\Models\Proveedor::firstOrCreate(['rut' => '96512340-7'], ['razon_social' => 'Distribuidora Ferretera del Sur', 'giro' => 'Distribución de materiales', 'comuna' => 'San Bernardo', 'contacto' => 'Patricia Muñoz', 'email' => 'ventas@ferresur.example', 'plazo_pago_dias' => 30]);
            \App\Modules\Compras\Models\Proveedor::firstOrCreate(['rut' => '76086428-5'], ['razon_social' => 'Aceros y Fierros Andinos Ltda.', 'giro' => 'Comercialización de acero', 'comuna' => 'Quilicura', 'plazo_pago_dias' => 45]);
            if ($matriz && ! \App\Modules\Compras\Models\OrdenCompra::exists()) {
                $oc = app(\App\Modules\Compras\OrdenesCompra::class);
                $orden = $oc->guardar(['proveedor_id' => $sur->id, 'local_id' => $matriz, 'fecha_entrega' => now()->addDays(3)->toDateString(), 'observaciones' => 'Reposición semanal',
                    'lineas' => Producto::whereIn('sku', ['CEM-25', 'YES-15'])->get()->map(fn ($p) => ['producto_id' => $p->id, 'cantidad' => $p->sku === 'CEM-25' ? 60 : 20, 'costo_unitario' => $p->sku === 'CEM-25' ? 3600 : 6300])->all(),
                ], $admin);
                $oc->enviar($orden, $admin);
            }

            // Finanzas: plan de cuentas, un centro de costo por local, la cuenta corriente y el asiento de apertura.
            $conta = app(\App\Modules\Finanzas\Contabilidad::class);
            $conta->asegurarPlan();
            foreach (Local::orderBy('codigo')->get() as $l) {
                \App\Modules\Finanzas\Models\CentroCosto::firstOrCreate(['codigo' => $l->codigo], ['nombre' => $l->nombre, 'local_id' => $l->id]);
            }
            \App\Modules\Finanzas\Models\CuentaBancaria::firstOrCreate(['banco' => 'Banco de Chile', 'numero' => '00-123-45678-09'], ['nombre' => 'Cuenta corriente principal', 'cuenta_contable_id' => $conta->cuenta('banco')->id]);
            if (! \App\Modules\Finanzas\Models\Asiento::where('tipo', 'manual')->exists()) {
                $conta->registrar(now()->startOfYear()->toDateString(), 'Asiento de apertura', [
                    ['cuenta_id' => $conta->cuenta('banco')->id, 'debe' => 25000000, 'haber' => 0],
                    ['cuenta_id' => $conta->cuenta('caja')->id, 'debe' => 500000, 'haber' => 0],
                    ['cuenta_id' => $conta->cuenta('capital')->id, 'debe' => 0, 'haber' => 25500000],
                ], $admin);
            }

            $this->rrhh($admin);
            $this->contactCenter($admin, User::where('email', 'caja@obramax.cl')->first());
            $this->terrenoYMensajeria($admin);

            Cliente::firstOrCreate(['rut' => '11111111-1'], ['razon_social' => 'Juan Pérez Soto', 'giro' => 'Particular', 'direccion' => 'Pasaje Uno 123', 'comuna' => 'Maipú', 'ciudad' => 'Santiago', 'lista_precio' => 'meson']);
        });
    }

    /** RRHH: turnos, plantilla de contrato y cuatro personas con contrato vigente firmado. */
    private function rrhh(User $admin): void
    {
        $turno = \App\Modules\RRHH\Models\Turno::firstOrCreate(['nombre' => 'Jornada completa'], ['hora_inicio' => '09:00', 'hora_fin' => '18:00', 'colacion_min' => 60, 'dias' => [1, 2, 3, 4, 5], 'activo' => true]);
        \App\Modules\RRHH\Models\Turno::firstOrCreate(['nombre' => 'Sábado mañana'], ['hora_inicio' => '09:00', 'hora_fin' => '14:00', 'colacion_min' => 0, 'dias' => [6], 'activo' => true]);
        \App\Modules\RRHH\Models\PlantillaContrato::firstOrCreate(['nombre' => 'Contrato de trabajo general'], ['activa' => true, 'cuerpo' => "CONTRATO DE TRABAJO\n\nEn Santiago, a {{fecha}}, entre {{empresa}}, RUT {{rut_empresa}}, en adelante el empleador, y don(ña) {{nombre}}, RUT {{rut}}, domiciliado(a) en {{domicilio}}, en adelante el trabajador, se conviene el siguiente contrato de trabajo:\n\nPRIMERO: El trabajador se desempeñará como {{cargo}} en {{local}}.\n\nSEGUNDO: La jornada ordinaria será de {{jornada}}, distribuida de lunes a viernes.\n\nTERCERO: El empleador pagará un sueldo base mensual de {{sueldo}} ({{sueldo_palabras}}), más asignación de colación de {{colacion}} y de movilización de {{movilizacion}}.\n\nCUARTO: El contrato es {{tipo}}, con inicio el {{fecha_inicio}} y término {{fecha_termino}}.\n\nPara constancia firman las partes."]);
        if (\App\Modules\RRHH\Models\Empleado::exists()) {
            return;
        }
        $local = Local::orderBy('codigo')->first();
        $centro = \App\Modules\Finanzas\Models\CentroCosto::where('local_id', $local?->id)->value('id');
        $personal = app(\App\Modules\RRHH\Personal::class);
        $contratos = app(\App\Modules\RRHH\Contratos::class);
        foreach ([
            ['16.543.210-K', 'Carolina', 'Muñoz Vera', 'Jefa de local', 7, 'habitat', 'fonasa', 1450000, 'indefinido', 0],
            ['17.890.123-0', 'Diego', 'Fuentes Lagos', 'Vendedor', 3, 'modelo', 'fonasa', 620000, 'indefinido', 1.5],
            ['18.234.567-9', 'Valentina', 'Soto Pizarro', 'Cajera', 2, 'provida', 'fonasa', 560000, 'indefinido', 0],
            ['19.456.789-8', 'Matías', 'Reyes Contreras', 'Bodeguero', 0, 'uno', 'fonasa', 540000, 'plazo_fijo', 0],
        ] as [$rut, $nombres, $apellidos, $cargo, $anios, $afp, $salud, $sueldo, $tipo, $comision]) {
            $ingreso = now()->subYears($anios)->subMonths(2)->startOfMonth()->toDateString();
            $e = $personal->guardar(['rut' => $rut, 'nombres' => $nombres, 'apellidos' => $apellidos, 'cargo' => $cargo, 'fecha_ingreso' => $ingreso, 'afp' => $afp, 'salud' => $salud,
                'local_id' => $local?->id, 'centro_costo_id' => $centro, 'comuna' => 'Santiago', 'direccion' => 'Av. Providencia 1234']);
            $e->forceFill(['turno_id' => $turno->id])->save();
            $c = $contratos->guardar($e, ['tipo' => $tipo, 'cargo' => $cargo, 'jornada_horas' => 42, 'sueldo_base' => $sueldo, 'gratificacion' => true, 'colacion' => 45000, 'movilizacion' => 35000,
                'comision_pct' => $comision, 'fecha_inicio' => $ingreso, 'fecha_termino' => $tipo === 'plazo_fijo' ? now()->addDays(20)->toDateString() : null, 'texto' => "Contrato de trabajo de {$nombres} {$apellidos}."], $admin);
            $contratos->sincronizarFirma($contratos->enviarAFirma($c, $admin), $admin);
        }
    }

    /** Contact center: cola, menú de voz, guion, checklist de calidad, dos agentes y una campaña activa con prospectos asignados. */
    private function contactCenter(User $admin, ?User $otra): void
    {
        if (\App\Modules\ContactCenter\Models\Campana::exists()) {
            return;
        }
        $cola = \App\Modules\ContactCenter\Models\Cola::create(['nombre' => 'Ventas', 'estrategia' => 'menos_reciente', 'espera_max_seg' => 120, 'nivel_servicio_seg' => 20, 'activo' => true]);
        $ivr = \App\Modules\ContactCenter\Models\Ivr::create(['nombre' => 'Principal', 'bienvenida' => 'Bienvenido a Obramax. Para ventas marque 1. Para despachos marque 2.', 'activo' => true,
            'opciones' => [['tecla' => '1', 'etiqueta' => 'Ventas', 'accion' => 'cola', 'destino' => $cola->id], ['tecla' => '2', 'etiqueta' => 'Despachos', 'accion' => 'cola', 'destino' => $cola->id]],
            'horario' => ['dias' => [1, 2, 3, 4, 5, 6], 'desde' => '08:30', 'hasta' => '19:00'], 'fuera_de_horario' => 'Nuestro horario es de lunes a sábado de 8:30 a 19:00.']);
        \App\Modules\ContactCenter\Models\NumeroEntrante::create(['numero' => '+56222345678', 'descripcion' => 'Línea de ventas', 'destino_tipo' => 'ivr', 'destino_id' => $ivr->id, 'activo' => true]);
        foreach ([['Saluda y se identifica', 10, false], ['Confirma los datos del cliente', 20, true], ['Ofrece el producto adecuado', 30, false], ['Registra el resultado', 20, false], ['Cierra con cortesía', 20, false]] as $i => [$n, $p, $c]) {
            \App\Modules\ContactCenter\Models\CriterioCalidad::create(['nombre' => $n, 'peso' => $p, 'critico' => $c, 'activo' => true, 'orden' => $i]);
        }
        $guion = \App\Modules\ContactCenter\Models\Guion::create(['nombre' => 'Constructoras: obra gruesa', 'version' => 1, 'activo' => true, 'actualizado_por' => $admin->id, 'pasos' => [
            ['titulo' => 'Saludo', 'texto' => "Buenos días, le habla {agente} de Obramax. ¿Hablo con el encargado de compras?", 'respuestas' => [['texto' => 'Sí', 'siguiente' => 1], ['texto' => 'No está', 'siguiente' => 3]]],
            ['titulo' => 'Necesidad', 'texto' => '¿Tienen obras en curso que necesiten cemento, fierro o áridos en las próximas semanas?', 'respuestas' => [['texto' => 'Sí', 'siguiente' => 2], ['texto' => 'No por ahora', 'siguiente' => 3]]],
            ['titulo' => 'Oferta', 'texto' => 'Tenemos despacho a obra en 48 horas y precios por volumen. ¿Le preparo una cotización?', 'respuestas' => []],
            ['titulo' => 'Cierre', 'texto' => 'Muchas gracias por su tiempo. ¿Cuándo le acomoda que volvamos a llamar?', 'respuestas' => []],
        ]]);
        $agentes = [\App\Modules\ContactCenter\Models\Agente::create(['user_id' => $admin->id, 'extension' => '201', 'habilidades' => ['obra gruesa', 'empresas'], 'zonas' => [], 'capacidad' => 50, 'cola_id' => $cola->id, 'estado' => 'desconectado'])];
        if ($otra) {
            $agentes[] = \App\Modules\ContactCenter\Models\Agente::create(['user_id' => $otra->id, 'extension' => '202', 'habilidades' => ['particulares'], 'zonas' => ['Maipú', 'Pudahuel'], 'capacidad' => 30, 'cola_id' => $cola->id, 'estado' => 'desconectado']);
        }
        $campana = \App\Modules\ContactCenter\Models\Campana::create(['nombre' => 'Constructoras zona poniente', 'tipo' => 'saliente', 'estado' => 'activa', 'fecha_inicio' => now()->startOfMonth()->toDateString(),
            'descripcion' => 'Prospección de constructoras medianas para obra gruesa.', 'meta_contactos' => 40, 'meta_cotizaciones' => 10, 'meta_ventas' => 15000000,
            'horario' => ['dias' => [1, 2, 3, 4, 5, 6, 7], 'desde' => '00:00', 'hasta' => '23:59'], 'max_intentos' => 3, 'asignacion' => 'zona', 'guion_id' => $guion->id, 'cola_id' => $cola->id, 'creado_por' => $admin->id]);
        $campana->agentes()->sync(collect($agentes)->mapWithKeys(fn ($a) => [$a->id => ['empresa_id' => $campana->empresa_id]])->all());
        $csv = "nombre;telefono;rut;email;comuna;direccion\n";
        foreach ([['Constructora Los Andes', 'Maipú'], ['Inmobiliaria Poniente', 'Pudahuel'], ['Edificaciones Sur', 'Cerrillos'], ['Obras Civiles Ríos', 'Maipú'], ['Constructora Cordillera', 'Lo Prado'], ['Ingeniería Pacífico', 'Estación Central'], ['Construcciones Valle', 'Pudahuel'], ['Grupo Austral', 'Quinta Normal']] as $i => [$n, $c]) {
            $csv .= "{$n};+5699".str_pad((string) (1234500 + $i * 7), 7, '0', STR_PAD_LEFT).";;;{$c};\n";
        }
        $asignador = app(\App\Modules\ContactCenter\Asignador::class);
        $asignador->importar($campana, $csv, $admin);
        $asignador->asignar($campana, $admin);

        // Dos llamadas ya terminadas, con grabación simulada, para revisar auditoría y grabaciones.
        $leads = \App\Modules\ContactCenter\Models\Lead::where('campana_id', $campana->id)->orderBy('id')->limit(2)->get();
        foreach ($leads as $i => $lead) {
            $agente = $agentes[min($i + 1, count($agentes) - 1)];
            $inicio = now()->subDay()->setTime(10 + $i, 15);
            \App\Modules\ContactCenter\Models\Llamada::create(['campana_id' => $campana->id, 'lead_id' => $lead->id, 'agente_id' => $agente->id, 'direccion' => 'saliente', 'numero' => $lead->telefono,
                'estado' => 'terminada', 'pbx_id' => 'demo-'.$lead->id, 'inicio' => $inicio, 'contestada_at' => $inicio->copy()->addSeconds(12), 'fin' => $inicio->copy()->addSeconds(200),
                'espera_seg' => 12, 'duracion_seg' => 188, 'resultado' => $i ? 'interesado' : 'volver_a_llamar', 'notas' => 'Llamada de demostración.', 'grabacion_ref' => 'demo-'.$lead->id.'.wav']);
        }
    }

    /** Fase 4b: integraciones simuladas activas, plantillas de WhatsApp, una conversación y el recorrido de hoy del administrador. */
    private function terrenoYMensajeria(User $admin): void
    {
        if (\App\Modules\Integraciones\Models\Integracion::exists()) {
            return;
        }
        foreach (['whatsapp' => 'DEMO-PHONE-ID', 'ia' => null, 'mapas' => null, 'correo' => null] as $tipo => $id) {
            \App\Modules\Integraciones\Models\Integracion::create(['tipo' => $tipo, 'activa' => true, 'ambiente' => 'certificacion', 'identificador' => $id, 'reintentos' => 5, 'timeout_seg' => 15]);
        }
        $seguimiento = \App\Modules\Mensajeria\Models\Plantilla::create(['nombre' => 'seguimiento_cotizacion', 'cuerpo' => 'Hola {{1}}, le escribimos de Obramax por su cotización {{2}}. ¿Le podemos ayudar con algo más?']);
        \App\Modules\Mensajeria\Models\Plantilla::create(['nombre' => 'confirmacion_visita', 'cuerpo' => 'Hola {{1}}, le confirmamos la visita de nuestro ejecutivo para el {{2}}. Responda este mensaje si necesita cambiarla.']);

        // Direcciones para los prospectos de la campaña demo, así pueden entrar al recorrido.
        $calles = ['Av. Pajaritos 2450', 'Av. Teniente Cruz 880', 'Av. Lo Errázuriz 1200', 'Camino a Melipilla 9050', 'Av. San Pablo 6700', 'Av. Ecuador 4100', 'Av. La Estrella 550', 'Av. Carrascal 4300'];
        foreach (\App\Modules\ContactCenter\Models\Lead::whereNull('direccion')->orderBy('id')->get() as $i => $l) {
            $l->forceFill(['direccion' => $calles[$i % count($calles)]])->save();
        }

        $bandeja = app(\App\Modules\Mensajeria\Bandeja::class);
        $lead = \App\Modules\ContactCenter\Models\Lead::orderBy('id')->first();
        if ($lead) {
            $c = $bandeja->iniciar($lead->telefono, $lead->nombre, null, $lead->id, $admin);
            $c->mensajes()->create(['direccion' => 'saliente', 'tipo' => 'plantilla', 'plantilla_id' => $seguimiento->id, 'cuerpo' => $seguimiento->completar([$lead->nombre, 'N° 1024']),
                'wa_id' => 'wamid.demo.1', 'estado' => 'leido', 'user_id' => $admin->id, 'created_at' => now()->subHours(3)]);
            $bandeja->recibir($c->telefono, $lead->nombre, 'wamid.demo.2', 'Hola, sí. ¿Me puede confirmar el precio de 120 sacos de cemento con despacho a Maipú?', now()->subMinutes(20)->toImmutable());
        }
        $bandeja->recibir('56955501234', 'Ferretería El Maestro', 'wamid.demo.3', 'Buenas tardes, ¿tienen stock de fierro de 12 mm?', now()->subMinutes(5)->toImmutable());

        $leads = \App\Modules\ContactCenter\Models\Lead::whereHas('agente', fn ($q) => $q->where('user_id', $admin->id))->whereIn('estado', \App\Modules\ContactCenter\Models\Lead::ABIERTOS)->limit(4)->pluck('id');
        $paradas = $leads->map(fn ($id) => ['lead_id' => $id])->all();
        if ($c = \App\Modules\Comercial\Models\Cliente::whereNotNull('direccion')->first()) {
            $paradas[] = ['cliente_id' => $c->id];
        }
        $matriz = \App\Modules\Core\Models\Local::whereNotNull('direccion')->orderBy('es_bodega')->orderBy('codigo')->first();
        if ($paradas) {
            app(\App\Modules\Terreno\Recorridos::class)->crear($admin, today()->toDateString(), $matriz?->direccion, $matriz?->comuna, $paradas, $admin);
        }
    }
}
