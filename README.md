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
| 3 | Resto de módulos (compras, RRHH, finanzas, contact center) |
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
