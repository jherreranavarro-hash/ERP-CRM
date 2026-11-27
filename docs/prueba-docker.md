# Probar el ERP en tu computador (Docker)

Entorno de **prueba** con datos de demostración. Las integraciones externas (SII, central telefónica, WhatsApp, Meta, LinkedIn, IA y mapas) están simuladas: nada sale de tu computador y los correos no se envían, quedan en el registro.

## 1. Instalar Docker (una vez)

Descarga e instala **Docker Desktop** desde https://www.docker.com/products/docker-desktop/ (Windows o Mac) y ábrelo. Espera a que diga que está corriendo ("Engine running").

## 2. Bajar el sistema

Opción A, sin git: en GitHub abre el repositorio, elige la rama `fase-7-docker-prueba`, pulsa **Code → Download ZIP** y descomprímelo.

Opción B, con git:

```
git clone -b fase-7-docker-prueba https://github.com/jherreranavarro-hash/ERP-CRM.git
```

## 3. Levantarlo

Abre una terminal (en Windows, PowerShell) dentro de la carpeta del sistema y ejecuta:

```
docker compose up -d --build
```

La primera vez tarda unos minutos porque descarga y arma todo. Las siguientes veces arranca en segundos. Cuando termine, abre **http://localhost:8000**.

## 4. Ingresar

| Usuario | Perfil |
|---|---|
| `admin@obramax.cl` | SuperAdmin, ve todo y tiene dos empresas (Obramax y ObraCenter Santander) |
| `caja@obramax.cl` | Cajera de Casa Matriz |

Contraseña de ambos: `ObramaxDemo2026`.

Estos perfiles exigen verificación en dos pasos. En el primer ingreso el sistema te lleva a activarla: escanea el código QR con Google Authenticator o Microsoft Authenticator en tu teléfono y escribe el código de 6 dígitos. Guarda los códigos de recuperación que te muestra.

Para probar con más personas, invítalas desde **Configuración → Usuarios**. Como el correo no sale, el enlace de la invitación se ve con `docker compose logs app`.

## 5. Uso diario

| Para | Comando |
|---|---|
| Detenerlo (conserva los datos) | `docker compose stop` |
| Volver a levantarlo | `docker compose up -d` |
| Ver qué está pasando | `docker compose logs -f app` |
| Actualizar a una versión nueva del código | bajar el código nuevo y `docker compose up -d --build` |
| Borrar todo y partir de cero | `docker compose down -v` y luego `docker compose up -d --build` |

## Qué corre adentro

`app` (la aplicación web), `db` (PostgreSQL 16 con seguridad por empresa), `redis` (sesiones, caché y colas), `cola` (tareas en segundo plano: correos, DTE, reportes), `programador` (tareas programadas) y `reverb` (pantallas en tiempo real del contact center y WhatsApp).

El sistema solo queda accesible desde tu propio computador (`127.0.0.1`). Las contraseñas de la base y los secretos internos son de prueba o se generan solos al primer arranque, por eso este entorno **no** sirve para producción: para eso está `docs/despliegue.md`.

## Si algo falla

- **"port is already allocated"**: otro programa usa el puerto 8000. Cierra ese programa o cambia `127.0.0.1:8000` por `127.0.0.1:8001` en `docker-compose.yml` (y entra por http://localhost:8001; en ese caso agrega `REVERB_PUBLIC_PORT: 8001` y `APP_URL: http://localhost:8001` en el servicio `app`).
- **La página no carga después de levantarlo**: espera un minuto y revisa `docker compose ps`. Todos los servicios deben decir `healthy` o `running`.
- **Olvidé la verificación en dos pasos**: usa uno de los códigos de recuperación, o borra todo y parte de cero.
