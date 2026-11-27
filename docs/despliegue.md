# Despliegue en producción

Guía para instalar el ERP + CRM en un servidor propio, conectar las credenciales reales y
dejar la operación (colas, respaldos, alertas y retención) funcionando. Todo lo que aquí
aparece como `erp.ejemplo.cl` o `/var/www/erp` es un ejemplo: reemplázalo por lo tuyo.

> Ningún secreto va en el repositorio, en tickets ni en correos. Se guardan en el gestor de
> secretos de la empresa (1Password, Bitwarden, AWS Secrets Manager, Vault…) y se copian al
> servidor al instalar. La plantilla [`.env.production.example`](../.env.production.example)
> lista cada variable sin valores.

## 1. Arquitectura mínima

| Pieza | Recomendado | Notas |
|---|---|---|
| Servidor de aplicación | Ubuntu 24.04, 4 vCPU, 8 GB RAM | nginx + PHP 8.3-FPM, Node 22 solo para compilar |
| Base de datos | PostgreSQL 16 (administrado o propio) | Discos y respaldos separados del servidor de app |
| Caché, sesiones y colas | Redis 7 con contraseña | Solo escucha en red privada |
| Tiempo real | Laravel Reverb (proceso en el mismo servidor) | nginx lo publica en `wss://…/app` |
| Respaldo externo | Bucket S3 compatible con versionado | Copia de los `pg_dump` y del archivo de retención |

Con más carga: separa la base de datos, sube los workers de cola a un segundo servidor
(mismo código y `.env`) y pon dos servidores de app detrás de un balanceador. El planificador
usa `onOneServer()`, así que corre una sola vez aunque haya varios servidores.

## 2. Preparar el servidor

```bash
# Paquetes base
sudo apt install -y nginx php8.3-fpm php8.3-{pgsql,redis,intl,bcmath,mbstring,xml,zip,curl,gd} \
     postgresql-client-16 redis-tools supervisor git unzip
# Composer y Node 22 según sus instaladores oficiales.

# Usuario del sistema dueño de la app (sin shell de login para terceros)
sudo adduser --system --group --home /var/www/erp erp
sudo mkdir -p /var/log/erp /etc/erp /var/backups/erp && sudo chown erp:erp /var/log/erp /var/backups/erp
sudo chmod 700 /etc/erp /var/backups/erp
```

### PostgreSQL

Correr una vez como superusuario. Crea `erp_owner` (dueño del esquema, solo migra) y `erp_app`
(la aplicación: sin superusuario ni `BYPASSRLS`, así Row-Level Security siempre aplica).

```bash
psql -h <host-db> -U postgres -v owner_pass="'<desde el gestor>'" -v app_pass="'<desde el gestor>'" \
     -f database/setup/roles.sql
# Para la prueba de restauración en el mismo servidor de base:
psql -h <host-db> -U postgres -c "ALTER ROLE erp_owner CREATEDB"
```

Si la base es administrada (RDS, Cloud SQL, Azure), crea los roles con la consola del proveedor
con los mismos atributos y ejecuta el resto del script con el usuario administrador.

## 3. Instalar la aplicación

```bash
sudo -u erp git clone https://github.com/jherreranavarro-hash/ERP-CRM.git /var/www/erp
cd /var/www/erp
sudo -u erp cp .env.production.example .env && sudo chmod 600 .env
# Completar .env con los valores del gestor de secretos (ver sección 5).
sudo -u erp composer install --no-dev --optimize-autoloader
sudo -u erp php artisan key:generate          # solo la primera vez; guarda APP_KEY en el gestor
sudo -u erp npm ci && sudo -u erp npm run build
sudo -u erp php artisan migrate --database=pgsql_owner --force
sudo -u erp php artisan db:seed --database=pgsql_owner --class=PerfilesSeeder --force
sudo -u erp php artisan db:seed --database=pgsql_owner --class=PermisosSeeder --force
sudo -u erp php artisan db:seed --database=pgsql_owner --class=RolesSeeder --force
sudo -u erp php artisan storage:link
```

No corras el `DemoSeeder` en producción: crea empresas, usuarios y documentos de ejemplo.

### Primera empresa y primer SuperAdmin

```bash
sudo -u erp php artisan sistema:instalar
```

Pide RUT, razón social, nombre de fantasía, nombre y correo del administrador; crea la empresa,
el usuario con su membresía y la marca SuperAdmin, y muestra **una sola vez** una contraseña
temporal ya vencida: en el primer ingreso el sistema obliga a cambiarla y a activar 2FA. Solo
corre si aún no hay empresas.

Desde ahí, las demás empresas y locales se crean en **Configuración**, los usuarios entran por
invitación, y todo SuperAdmin adicional se propone en **Configuración → Usuarios** y lo aprueba
**otro** SuperAdmin en **Configuración → Perfiles** (cuatro ojos). Revocar rige al tiro, pero el
sistema nunca se queda sin al menos un SuperAdmin activo con 2FA. Si alguna vez se pierde el
acceso de todos los administradores, `php artisan usuarios:superadmin <correo>` otorga la marca
desde el servidor (queda en la auditoría).

## 4. Servicios

Los archivos de ejemplo están en [`deploy/`](../deploy):

| Archivo | Destino | Qué hace |
|---|---|---|
| `nginx.conf` | `/etc/nginx/sites-available/erp` | HTTPS, PHP-FPM y proxy de Reverb en `/app` |
| `supervisor.conf` | `/etc/supervisor/conf.d/erp.conf` | Worker dedicado a DTE, workers generales con prioridad `dte > caja > correo > default > reportes`, y Reverb |
| `crontab` | `crontab -u erp deploy/crontab` | Planificador cada minuto, respaldo diario y prueba de restauración mensual |
| `respaldo.sh` | cron | `pg_dump` comprimido, verificación, cifrado y copia opcional a S3, retención local, registro en el panel |
| `probar-restauracion.sh` | cron | Restaura el último respaldo en una base temporal, verifica tablas clave y registra el resultado |
| `desplegar.sh` | manual o pipeline | Modo mantenimiento, dependencias, build, migraciones con el rol dueño, permisos, cachés, reinicio de colas y verificación final |

```bash
sudo ln -s /etc/nginx/sites-available/erp /etc/nginx/sites-enabled/erp && sudo nginx -t && sudo systemctl reload nginx
sudo supervisorctl reread && sudo supervisorctl update
sudo crontab -u erp /var/www/erp/deploy/crontab
```

El planificador de Laravel (`schedule:run`) se encarga de:

| Tarea | Frecuencia |
|---|---|
| `dte:reintentar` (reenvía al SII lo que quedó con error) | cada 15 min |
| `integraciones:sincronizar` (Meta Ads, LinkedIn, plantillas de WhatsApp) | 1 h / 5 min / 15 min |
| `operacion:vigilar` (API anómala, folios, integraciones caídas, respaldos atrasados) | cada hora |
| `datos:aplicar-retencion` (política de retención por empresa) | diario 02:30 |
| `usuarios:desactivar-inactivos` | diario 03:00 |

### Respaldos

`/etc/erp/respaldo.env` (permisos 600, dueño `erp`):

```bash
APP_DIR=/var/www/erp
RESPALDO_DIR=/var/backups/erp
PGHOST=<host-db>
PGUSER=erp_owner
PGDATABASE=erp
# La clave de erp_owner va en /var/www/erp/.pgpass (600), no aquí.
RESPALDO_REMOTO=s3://<bucket>/erp            # opcional, requiere aws cli
RESPALDO_GPG_DESTINATARIO=<id-de-la-llave>    # opcional, cifra antes de subir
```

Cada ejecución queda en **Configuración → Operación y continuidad** con fecha, tamaño y
duración. Si el respaldo o la prueba de restauración fallan, o si se atrasan respecto de lo que
dice el mantenedor "Respaldos y continuidad", se emite una alerta de seguridad por correo.

## 5. Secretos y credenciales reales

Hay dos lugares, y nunca un tercero:

1. **`.env` del servidor** (valores desde el gestor de secretos): lo que la app necesita para
   arrancar y lo que validan los webhooks.
2. **Configuración → Integraciones** (en la app): tokens y claves de cada proveedor. Se guardan
   cifrados con `APP_KEY`, nunca se vuelven a mostrar (solo se reemplazan), nunca se envían al
   navegador y se avisa cuando cumplen el plazo de rotación.

| Variable / ficha | Dónde | Quién la entrega |
|---|---|---|
| `APP_KEY` | `.env` | Se genera una vez. Respáldala en el gestor: sin ella no se leen los secretos cifrados |
| `DB_PASSWORD`, `DB_OWNER_PASSWORD` | `.env` | Las define quien crea los roles |
| `REDIS_PASSWORD` | `.env` | Administrador de Redis |
| `MAIL_*` | `.env` | Proveedor de correo transaccional (SMTP) |
| `CONFIG_EXPORT_KEY` | `.env` | Generar al azar, **distinta** por ambiente |
| `REVERB_APP_ID/KEY/SECRET` | `.env` | `php artisan reverb:install` |
| `PBX_WEBHOOK_SECRET` | `.env` | Lo comparten la app y el servicio puente de la central |
| `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN` | `.env` | App de Meta (secreto de la app) y un texto al azar que también se pone en Meta |
| Token de WhatsApp Business, `phone_number_id` | Integraciones → WhatsApp | Meta Business Manager (usuario de sistema) |
| Token de Meta Ads, ID de cuenta publicitaria | Integraciones → Meta Ads | Meta Business Manager, permiso `ads_read` y `leads_retrieval` |
| Token de LinkedIn, ID de organización | Integraciones → LinkedIn | Programa de partners de LinkedIn |
| Credenciales del proveedor DTE, RUT emisor | Integraciones → DTE | Proveedor de facturación electrónica acreditado ante el SII |
| Usuario de servicio AMI/ARI de la central | Configuración → Telefonía → Proveedores IP | Administrador de la central |
| Clave del proveedor de IA | Integraciones → IA | Proveedor elegido (con acuerdo de retención de datos) |
| Clave del proveedor de mapas | Integraciones → Mapas | Proveedor elegido |
| `AWS_*`, `ARCHIVO_BUCKET` | `.env` | Solo si el archivo de retención va a S3 |

### Integraciones reales

Cada integración está detrás de una interfaz con una implementación simulada que tiene el mismo
contrato (las pruebas corren contra ella). Para conectar un proveedor real:

1. Implementa la interfaz en una clase nueva, por ejemplo
   `App\Modules\Integraciones\Whatsapp\WhatsappCloud implements Whatsapp`, leyendo URL, token y
   reintentos desde la ficha `Integracion` (nunca desde el `.env`).
2. Pon el nombre de la clase en la variable correspondiente del `.env`
   (`INTEGRACION_WHATSAPP=App\Modules\Integraciones\Whatsapp\WhatsappCloud`) y despliega.
3. Completa la ficha en **Configuración → Integraciones**, en ambiente **certificación** primero,
   y usa **Probar**. Cuando responda, cambia a **producción**.

| Interfaz | Variable | Webhook a registrar en el proveedor |
|---|---|---|
| `Facturacion\Sii\ProveedorDTE` | `INTEGRACION_DTE` | — (la app consulta el estado y reintenta) |
| `ContactCenter\Telefonia\Pbx` | `INTEGRACION_PBX` | `POST https://erp.ejemplo.cl/api/pbx/eventos` firmado con HMAC |
| `Integraciones\Whatsapp\Whatsapp` | `INTEGRACION_WHATSAPP` | `https://erp.ejemplo.cl/api/whatsapp/webhook` |
| `Integraciones\MetaAds\MetaAds` | `INTEGRACION_META_ADS` | — (sincronización cada hora) |
| `Integraciones\Linkedin\Linkedin` | `INTEGRACION_LINKEDIN` | — (sincronización cada 5 min) |
| `Integraciones\Ia\AsistenteIa` | `INTEGRACION_IA` | — |
| `Integraciones\Mapas\Mapas` | `INTEGRACION_MAPAS` | — |
| `RRHH\Integraciones\FirmaElectronica` | `INTEGRACION_FIRMA` | según proveedor |

`php artisan sistema:verificar-produccion` muestra qué integraciones siguen simuladas.

## 6. Verificación antes de abrir

```bash
sudo -u erp php artisan sistema:verificar-produccion
```

Revisa: `APP_ENV=production`, debug apagado (además, la app lo fuerza a `false` en producción),
`APP_KEY`, `https`, cookies seguras y cifradas, cola y caché en Redis, correo real, Reverb,
frontend compilado, que la app **no** use el rol dueño ni uno con `BYPASSRLS`, que toda tabla
con `empresa_id` tenga RLS, que exista un SuperAdmin con 2FA, que haya respaldos registrados y
qué integraciones siguen simuladas. Termina con código 1 si hay errores, para cortar un pipeline.

Lista manual complementaria:

- [ ] `https://erp.ejemplo.cl` responde con certificado válido y redirige desde `http`.
- [ ] Cabeceras: `curl -sI https://erp.ejemplo.cl/login` muestra `Strict-Transport-Security`,
      `Content-Security-Policy` y `X-Frame-Options: DENY`.
- [ ] Ingreso con 2FA del primer SuperAdmin; segundo SuperAdmin aprobado por el primero.
- [ ] Parámetros revisados en **Configuración → Parámetros del Sistema**, en especial
      "Notificaciones y alertas" (destinatarios reales de seguridad y operación).
- [ ] Folios (CAF) cargados por tipo de documento en ambiente de certificación del SII.
- [ ] Un respaldo y una prueba de restauración en verde en **Operación y continuidad**.
- [ ] `supervisorctl status` con los tres programas en `RUNNING`.
- [ ] El panel de alertas recibe una alerta de prueba (por ejemplo, desactivando una integración
      activa y corriendo `php artisan operacion:vigilar`).
- [ ] La API pública queda cerrada a terceros hasta pasar una revisión de seguridad externa
      (criterio de aceptación 4): no emitas tokens a terceros antes de eso.

## 7. Operación diaria

- **Desplegar una versión:** `sudo -u erp deploy/desplegar.sh <tag>`. Las migraciones corren con
  el rol dueño; nunca des privilegios de dueño a `erp_app`.
- **Logs:** `storage/logs/erp-AAAA-MM-DD.log`, una línea JSON por evento (lista para Loki, ELK o
  CloudWatch). Los logs de API están en la tabla `api_registros`, separados.
- **Alertas:** llegan por correo a los destinatarios del mantenedor y quedan en
  **Configuración → Operación y continuidad** hasta marcarlas como atendidas. La misma alerta no
  se repite dentro de su ventana (24 h por defecto).
- **Retención de datos:** corre cada madrugada según el mantenedor "Retención de datos". La
  acción al vencer puede ser archivar (JSONL en el disco `archivo` y borrar), anonimizar o
  eliminar. Los prospectos siempre se anonimizan. La **retención legal** de una empresa suspende
  todos sus borrados. La auditoría y los documentos tributarios no se borran desde la app (mínimo
  6 años); si alguna vez corresponde purgarlos, lo hace el DBA con el rol dueño.
- **Rotación de secretos:** reemplaza el valor en el gestor y en la ficha de la integración; la
  app avisa cuando un secreto cumple el plazo configurado.
- **Restaurar de verdad:** `pg_restore --clean --no-owner -d erp <archivo>` con la app en modo
  mantenimiento (`php artisan down`), luego `php artisan migrate --database=pgsql_owner --force`
  si el respaldo es de una versión anterior, y `php artisan up`.
