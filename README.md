# ERP + CRM Obramax 2.0

Sistema de gestión (ERP + CRM + Contact Center) para Obramax SpA, construido según la pestaña
"Prompt de Desarrollo" del análisis forense de obramax.neighbour.cl.

Stack: Laravel 11 · PHP 8.3 · PostgreSQL 16 · Redis · Inertia + Vue 3 + TypeScript · Tailwind.
La gráfica (colores, tipografías Archivo y Barlow, logo) sigue el HTML de referencia de diseño.

El desarrollo avanza por fases, cada una en su propio pull request:

| Fase | Contenido |
|---|---|
| 1 | Base: login seguro, 2FA, sesiones, multiempresa con RLS, RBAC, trazabilidad inmutable, 28 mantenedores con pisos de seguridad, tokens de API |
| 2 | Facturación CLP / SII (DTE con proveedor simulado), clientes, productos, cotización, caja |
| 3 | Resto de módulos (compras, inventario, RRHH, finanzas, contact center) |
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

En producción, programa el scheduler (`php artisan schedule:run` cada minuto): desactiva usuarios
inactivos según el mantenedor de Usuarios.

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
