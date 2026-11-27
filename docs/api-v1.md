# API v1

Base: `/api/v1`. Solo tokens de API (`Authorization: Bearer <token>`), creados en
Configuración → Tokens de API. La sesión del navegador no sirve. Cada token pertenece a
una empresa, tiene vencimiento, scopes y opcionalmente IP permitidas. Cada llamada queda
registrada en `api_registros`.

Montos en pesos chilenos enteros. Fechas `AAAA-MM-DD`. Errores en JSON con `message`
(y `errors` en validaciones, código 422).

## Paginación

Toda colección acepta `page` y `per_page` (por defecto 50, máximo 200) y responde:

```json
{ "data": [ ... ], "meta": { "total": 1234, "current_page": 1, "last_page": 25, "per_page": 50 } }
```

## Endpoints

| Método | Ruta | Scope | Descripción |
|---|---|---|---|
| GET | `/ping` | — | Comprueba el token. |
| GET | `/empresa` | `empresa:read` | Datos del emisor. |
| GET | `/informe-ventas/resumen?desde=&hasta=` | `informe-ventas:read` | Totales del período por tipo de documento. Las NC restan. Período máximo: 1 año. |
| GET | `/informe-ventas/dtes?desde=&hasta=` | `informe-ventas:read` | Documentos emitidos. Filtros: `tipo_dte`, `cliente_id`, `local_id`, `estado`. Paginado. |
| GET | `/dtes/{id}` | `dtes:read` | Un documento con líneas y referencias. |
| POST | `/dtes` | `dtes:write` | Crea un documento (33, 34, 39, 41, 52) y, con `"emitir": true`, lo emite. El usuario dueño del token debe tener además los permisos de crear y emitir. |
| GET | `/clientes?buscar=` | `clientes:read` | Clientes. Paginado. |

### Ejemplo: crear y emitir una boleta

```http
POST /api/v1/dtes
Authorization: Bearer 12|xxxxxxxx
Content-Type: application/json

{ "tipo_dte": 39, "emitir": true, "lineas": [ { "producto_id": 15, "cantidad": 2 } ] }
```

```json
{ "data": { "id": 812, "estado": "emitido", "tipo_dte": 39, "folio": 1407, "total": 11876, "motivos_aprobacion": [] } }
```

Si el documento requiere aprobación (descuento sobre el máximo, precio bajo lista, cupo
excedido), queda `por_aprobar` con sus `motivos_aprobacion` y no se emite.

El precio lo decide el sistema según la lista del cliente; un `precio_unitario` enviado
se registra como precio manual junto al de lista.

La especificación OpenAPI 3.1 se genera desde las rutas (`App\Support\Api\OpenApi`) y se ve en
Configuración → Documentación Informe Ventas, con descarga de `openapi-v1.json`. Una ruta nueva
sin descripción aparece como "Sin documentar" y hace fallar la prueba de la API.

Pendiente: alertas por uso anómalo.
