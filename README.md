# ERP + CRM Obramax 2.0

Sistema de gestión (ERP + CRM + Contact Center) para Obramax SpA, construido según la pestaña
"Prompt de Desarrollo" del análisis forense de obramax.neighbour.cl.

Stack: Laravel 12 · PHP 8.3 · PostgreSQL 16 · Redis · Inertia + Vue 3 + TypeScript · Tailwind.
La gráfica (colores, tipografías Archivo y Barlow, logo) sigue el HTML de referencia de diseño.

El desarrollo avanza por fases, cada una en su propio pull request:

| Fase | Contenido |
|---|---|
| 1 | Base: login seguro, 2FA, sesiones, multiempresa con RLS, RBAC, trazabilidad inmutable, 28 mantenedores con pisos de seguridad, tokens de API |
| 2 | Facturación CLP / SII: clientes, productos, folios, boletas, facturas, guías, NC/ND con aprobaciones y proveedor DTE simulado; libro de ventas, cuentas por cobrar y API v1 de ventas |
| 2b | Cotizaciones (aprobación, envío, vigencia y conversión a factura o boleta) y turnos de caja con cuadratura por medio de pago |
| 3a | Inventario: kardex con costo promedio ponderado (en la base de datos), stock por local, recepción de mercadería, mermas con aprobación; órdenes de venta que reservan stock; la emisión descuenta stock y la NC lo repone |
| 3b | Actualización de precios con historial (trigger) y aprobación; despachos con máquina de estados en la base de datos, firma de recepción y transportista simulado; devoluciones que emiten la NC y reingresan stock |
| 3c | Compras: proveedores, órdenes de compra con comparación de cotizaciones y aprobación por monto, recepción contra OC, facturas de proveedor con 3-way match (OC × recepción × factura), cuentas por pagar con antigüedad, calendario y pagos con segunda aprobación, libro de compras |
| 3d | Finanzas: plan de cuentas y libro diario con partida doble validada en la base de datos (asientos inmutables, se revierten), asientos automáticos desde ventas, cobros, compras, pagos y honorarios; balance de comprobación, estado de resultados, balance general y libro mayor; centros de costo; conciliación bancaria con importación de cartolas CSV y calce automático; libro de honorarios con retención; flujo de caja proyectado a 30/60/90 días |
| 3e | RRHH: ficha de personal con documentos cifrados, contratos desde plantillas con firma electrónica (simulada), turnos, asistencia con importación de reloj control y tope de horas extra, vacaciones con saldo por antigüedad y feriado progresivo, nómina con AFP, salud, cesantía e impuesto único (liquidación en PDF y asiento contable), préstamos y anticipos descontados por nómina, comisiones por categoría, finiquitos con indemnizaciones, cumplimiento normativo e indicadores; todo lo que mueve dinero se aprueba con cuatro ojos |
| 3f | Dashboards: Dashboard CRM (ventas netas con variación, resultado contable del período, margen bruto real por costo de salida, cotizaciones, conversión, cobertura de cartera, mix de margen por categoría y mapa de actividad por ejecutivo), dashboard comercial por ejecutivo (ventas, ticket, conversión, cotizaciones abiertas, comisiones) y cuentas corrientes por cliente con cartola; agregados cacheados en Redis con invalidación por evento |
| 4a | Contact center: agentes (extensión, habilidades, zonas, capacidad, cola), consola del agente con estados, discado, tipificación y guion paso a paso; campañas con metas, horario de discado, carga CSV y lista negra; asignación rotativa, por zona o por carga; tiempo real por WebSocket (Laravel Reverb) con números enmascarados; colas, IVR, números entrantes y proveedores IP con secreto cifrado; auditoría de calidad con checklist y criterios críticos; grabaciones con permiso nominativo, motivo, registro inmutable y enlace de un solo uso. La central va detrás de una interfaz (`Pbx`) con una implementación simulada y un webhook firmado con HMAC |
| 4b | Lista de recorrido para ejecutivos de terreno (paradas desde clientes y leads, geocodificación, orden por vecino más cercano + 2-opt, kilómetros y duración estimados, marcar visita con resultado que mueve el lead, cierre del día); bandeja de WhatsApp (ventana de 24 h, plantillas aprobadas con variables, lista negra, envío en cola con reintentos, estados que nunca retroceden, tiempo real); webhook de Meta firmado con HMAC; asistente de IA que seudonimiza datos personales antes de enviar y registra el uso sin contenido; ficha de integraciones con secreto cifrado que nunca se devuelve. Mapas, WhatsApp e IA van detrás de interfaces (`Mapas`, `Whatsapp`, `AsistenteIa`) con implementaciones simuladas |
| 4c | Listas de precios configurables (mesón base, mayorista y listas propias con % bajo mesón o precio propio, vigencia, comparador por producto); reglas de descuento automáticas por categoría, lista y cantidad; escalones de aprobación por rol (quien solicita no aprueba, sobre el último escalón no se acepta); metas comerciales por mes para empresa, local o vendedor con avance, proyección y copia del mes anterior; documentación OpenAPI 3.1 de la API v1 generada desde las rutas, con descarga del JSON |
| 4d | Meta Ads de solo lectura (métricas, CTR, costo por prospecto) con prospectos de formularios importados a una campaña del contact center, idempotentes y respetando la lista negra; plantillas de WhatsApp con borrador, revisión de Meta, motivo de rechazo y bloqueo de edición/eliminación según estado y uso; bandeja de LinkedIn sincronizada por cola, respuestas en cola con reintentos y conversión en prospecto. Tarea programada `integraciones:sincronizar` (Meta Ads cada hora, LinkedIn cada 5 min, plantillas cada 15 min). Meta Ads y LinkedIn van detrás de interfaces con implementaciones simuladas |
| 3 | Resto de módulos (RRHH, contabilidad y tesorería, contact center) |
| 4 | Integraciones reales (SII, PBX, Meta) detrás de interfaces |

## Instalación local

Requisitos: PHP 8.3 con `pdo_pgsql` y `redis`, Composer, Node 22, PostgreSQL 16 y Redis.

```bash
# 1. Roles y bases de datos (una vez, como superusuario de PostgreSQL)
psql -U postgres -v owner_pass="'clave-owner'" -v app_pass="'clave-app'" < database/setup/roles.sql

# 2. Dependencias y configuración
composer install
npm ci
cp .env.example .env          # completar DB_PASSWORD, DB_OWNER_PASSWORD y CONFIG_EXPORT_KEY
php artisan key:generate

# 3. Esquema y datos de demostración (siempre con el rol dueño)
php artisan migrate --database=pgsql_owner --seed

# 4. Levantar
npm run build                 # o npm run dev mientras desarrollas
php artisan serve
```

El seeder imprime la contraseña de los usuarios demo (`admin@obramax.cl` y `caja@obramax.cl`),
o usa la que definas en `DEMO_PASSWORD`. Ambos perfiles exigen activar la verificación en dos pasos
al primer ingreso.

En producción, programa el scheduler (`php artisan schedule:run` cada minuto) y un worker de colas
(`php artisan queue:work`): el envío al proveedor DTE va por cola y se reintenta cada 15 minutos
si el proveedor no responde. El scheduler también desactiva usuarios inactivos.

El tablero de tiempo real del contact center usa Laravel Reverb. Para verlo en vivo, define
`BROADCAST_CONNECTION=reverb` y las variables `REVERB_*` en `.env`, y levanta `php artisan reverb:start`.
Sin Reverb, la pantalla muestra la foto al abrirla. La central telefónica es simulada (`PbxSimulada`)
hasta configurar un proveedor IP; los eventos reales llegan a `POST /api/pbx/eventos` firmados con
`PBX_WEBHOOK_SECRET`.

La facturación se activa por empresa en Parámetros → Módulos y funcionalidades. El proveedor DTE
es simulado (`ProveedorDteSimulado`): los documentos no tienen validez tributaria hasta enlazar
un proveedor certificado. La API está descrita en [docs/api-v1.md](docs/api-v1.md).

## Seguridad, en corto

- **Dos roles de base de datos.** `erp_owner` es dueño del esquema y solo corre migraciones.
  La app se conecta como `erp_app`, que no es dueño, así que Row-Level Security siempre se le aplica.
- **Multiempresa en tres capas:** la empresa activa la resuelve el servidor desde la membresía del
  usuario, PostgreSQL filtra con RLS (`app.empresa_id`), y los modelos agregan un scope global.
- **Trazabilidad de solo inserción:** sin UPDATE, DELETE ni TRUNCATE para nadie, con trigger de respaldo.
- **Parámetros sin desplegar código:** cada mantenedor tiene un piso fijo que no se puede bajar;
  los sensibles exigen aprobación de una segunda persona (también validado por la base de datos).
- **API solo con tokens:** scope, vencimiento, IP permitidas y empresa propia; nunca la sesión del navegador.

## Tests

```bash
php artisan test
```

Los tests migran `erp_test` con el rol dueño y corren cada caso como `erp_app` dentro de una
transacción, de modo que las políticas de RLS se prueban de verdad.
