<?php

namespace App\Modules\Core\Parametros;

/**
 * Definición en código (semilla versionada) de los 28 mantenedores de
 * configuración y sus parámetros: tipo, valor inicial, rango y piso fijo.
 *
 * Ningún valor de negocio o de seguridad se lee de constantes: se lee con
 * Parametros::valor('clave'), que resuelve local → empresa → global → inicial.
 *
 * Claves de cada parámetro:
 *  - etiqueta, tipo: entero | decimal | booleano | texto | lista | opcion | multiple | porcentaje | escalones
 *  - inicial: valor por defecto
 *  - min / max: rango permitido (el piso de seguridad se expresa aquí)
 *  - opciones: para opcion / multiple
 *  - obligatorias: opciones de un "multiple" que no se pueden quitar (piso)
 *  - siempre_true: booleano que no se puede apagar (en 'produccion' o 'siempre')
 *  - estricto: 'mayor' | 'menor'. Dirección más estricta; un valor de empresa o
 *    local no puede ser más laxo que el global.
 *  - alcances: dónde se puede fijar (global, empresa, local)
 *  - unidad, ayuda
 */
class Catalogo
{
    public const GRUPOS = [
        'seguridad' => 'Seguridad y acceso',
        'auditoria' => 'Auditoría, datos y privacidad',
        'integraciones' => 'Integraciones y API',
        'negocio' => 'Negocio y facturación',
        'operacion' => 'Operación y plataforma',
    ];

    private const PERFILES = ['superadmin', 'finanzas', 'rrhh', 'caja', 'ventas', 'contact_center', 'bodega', 'compras', 'gerencia', 'auditor', 'legal'];

    private const G = ['global'];
    private const GE = ['global', 'empresa'];
    private const GEL = ['global', 'empresa', 'local'];

    /** @return array<string, array> */
    public static function mantenedores(): array
    {
        return [
            // ───────────────────────── Seguridad y acceso ─────────────────────────
            'login' => [
                'nombre' => 'Política de inicio de sesión',
                'grupo' => 'seguridad', 'sensible' => true, 'edita' => 'SuperAdmin',
                'piso' => 'Máx. 10 intentos por ventana; bloqueo y captcha no desactivables.',
                'parametros' => [
                    'login.intentos_maximos' => ['etiqueta' => 'Intentos fallidos por cuenta e IP', 'tipo' => 'entero', 'inicial' => 5, 'min' => 1, 'max' => 10, 'estricto' => 'menor', 'alcances' => self::GE],
                    'login.ventana_minutos' => ['etiqueta' => 'Ventana de conteo', 'tipo' => 'entero', 'inicial' => 1, 'min' => 1, 'max' => 60, 'unidad' => 'min', 'estricto' => 'mayor', 'alcances' => self::G],
                    'login.escalones_bloqueo' => ['etiqueta' => 'Escalones de bloqueo', 'tipo' => 'escalones', 'inicial' => [1, 5, 30], 'min' => 1, 'max' => 1440, 'unidad' => 'min', 'alcances' => self::G, 'ayuda' => 'Cada bloqueo sucesivo dura el siguiente escalón.'],
                    'login.captcha_desde_intento' => ['etiqueta' => 'Captcha desde el intento N', 'tipo' => 'entero', 'inicial' => 3, 'min' => 1, 'max' => 5, 'estricto' => 'menor', 'alcances' => self::G],
                    'login.ips_admin' => ['etiqueta' => 'IP permitidas para perfiles administrativos', 'tipo' => 'lista', 'inicial' => [], 'alcances' => self::GE, 'ayuda' => 'Vacío = sin restricción por IP.'],
                ],
            ],
            '2fa' => [
                'nombre' => 'Política de 2FA',
                'grupo' => 'seguridad', 'sensible' => true, 'edita' => 'SuperAdmin',
                'piso' => 'SuperAdmin, Finanzas, RRHH y Caja no se pueden quitar; SMS no permitido para administradores.',
                'parametros' => [
                    '2fa.perfiles_obligatorios' => ['etiqueta' => 'Perfiles con 2FA obligatorio', 'tipo' => 'multiple', 'opciones' => self::PERFILES, 'inicial' => ['superadmin', 'finanzas', 'rrhh', 'caja'], 'obligatorias' => ['superadmin', 'finanzas', 'rrhh', 'caja'], 'alcances' => self::GE],
                    '2fa.metodos' => ['etiqueta' => 'Métodos permitidos', 'tipo' => 'multiple', 'opciones' => ['totp', 'webauthn'], 'inicial' => ['totp', 'webauthn'], 'obligatorias' => ['totp'], 'alcances' => self::G],
                    '2fa.recordar_dispositivo_dias' => ['etiqueta' => 'Días para recordar dispositivo', 'tipo' => 'entero', 'inicial' => 30, 'min' => 0, 'max' => 30, 'unidad' => 'días', 'estricto' => 'menor', 'alcances' => self::G],
                    '2fa.enrolamiento_forzado' => ['etiqueta' => 'Forzar enrolamiento en el primer login', 'tipo' => 'booleano', 'inicial' => true, 'siempre_true' => 'siempre', 'alcances' => self::G],
                ],
            ],
            'contrasenas' => [
                'nombre' => 'Política de contraseñas',
                'grupo' => 'seguridad', 'sensible' => true, 'edita' => 'SuperAdmin',
                'piso' => 'Mínimo 12 caracteres; verificación de filtradas siempre activa en producción.',
                'parametros' => [
                    'contrasenas.largo_minimo' => ['etiqueta' => 'Largo mínimo', 'tipo' => 'entero', 'inicial' => 12, 'min' => 12, 'max' => 64, 'unidad' => 'caracteres', 'estricto' => 'mayor', 'alcances' => self::GE],
                    'contrasenas.verificar_filtradas' => ['etiqueta' => 'Verificar contra contraseñas filtradas', 'tipo' => 'booleano', 'inicial' => true, 'siempre_true' => 'produccion', 'alcances' => self::G],
                    'contrasenas.caducidad_admin_dias' => ['etiqueta' => 'Caducidad para cuentas administrativas', 'tipo' => 'entero', 'inicial' => 90, 'min' => 30, 'max' => 365, 'unidad' => 'días', 'estricto' => 'menor', 'alcances' => self::GE],
                    'contrasenas.historial' => ['etiqueta' => 'Contraseñas anteriores que no se pueden repetir', 'tipo' => 'entero', 'inicial' => 5, 'min' => 3, 'max' => 24, 'estricto' => 'mayor', 'alcances' => self::G],
                    'contrasenas.palabras_prohibidas' => ['etiqueta' => 'Palabras prohibidas', 'tipo' => 'lista', 'inicial' => ['obramax', 'obmax', 'obracenter', 'password', 'contraseña'], 'alcances' => self::GE],
                ],
            ],
            'sesiones' => [
                'nombre' => 'Sesiones',
                'grupo' => 'seguridad', 'sensible' => false, 'edita' => 'SuperAdmin',
                'piso' => 'Inactividad máxima 60 min.',
                'parametros' => [
                    'sesiones.inactividad_minutos' => ['etiqueta' => 'Cierre por inactividad', 'tipo' => 'entero', 'inicial' => 30, 'min' => 5, 'max' => 60, 'unidad' => 'min', 'estricto' => 'menor', 'alcances' => self::GEL],
                    'sesiones.inactividad_perfiles_criticos' => ['etiqueta' => 'Inactividad en Caja y Finanzas', 'tipo' => 'entero', 'inicial' => 15, 'min' => 5, 'max' => 60, 'unidad' => 'min', 'estricto' => 'menor', 'alcances' => self::GEL],
                    'sesiones.duracion_maxima_horas' => ['etiqueta' => 'Duración máxima de la sesión', 'tipo' => 'entero', 'inicial' => 12, 'min' => 1, 'max' => 24, 'unidad' => 'h', 'estricto' => 'menor', 'alcances' => self::GE],
                    'sesiones.simultaneas' => ['etiqueta' => 'Sesiones simultáneas por usuario', 'tipo' => 'entero', 'inicial' => 2, 'min' => 1, 'max' => 10, 'estricto' => 'menor', 'alcances' => self::GE],
                ],
            ],
            'usuarios' => [
                'nombre' => 'Usuarios e invitaciones',
                'grupo' => 'seguridad', 'sensible' => false, 'edita' => 'SuperAdmin', 'entidad' => 'configuracion.usuarios.index',
                'piso' => 'Autorregistro público siempre deshabilitado.',
                'parametros' => [
                    'usuarios.invitacion_horas' => ['etiqueta' => 'Vigencia de la invitación', 'tipo' => 'entero', 'inicial' => 72, 'min' => 1, 'max' => 168, 'unidad' => 'h', 'estricto' => 'menor', 'alcances' => self::G],
                    'usuarios.dominios_permitidos' => ['etiqueta' => 'Dominios de correo permitidos', 'tipo' => 'lista', 'inicial' => ['obramax.cl', 'obracenter.cl'], 'alcances' => self::GE, 'ayuda' => 'Vacío = cualquier dominio.'],
                    'usuarios.desactivar_inactivos_dias' => ['etiqueta' => 'Desactivación automática por inactividad', 'tipo' => 'entero', 'inicial' => 90, 'min' => 15, 'max' => 365, 'unidad' => 'días', 'estricto' => 'menor', 'alcances' => self::G],
                ],
            ],
            'rbac' => [
                'nombre' => 'Roles y permisos (RBAC)',
                'grupo' => 'seguridad', 'sensible' => true, 'edita' => 'SuperAdmin', 'entidad' => 'configuracion.roles.index',
                'piso' => 'Siempre queda al menos 1 SuperAdmin activo con 2FA.',
                'parametros' => [
                    'rbac.incompatibilidades' => ['etiqueta' => 'Incompatibilidades (quien hace X no aprueba X)', 'tipo' => 'lista', 'inicial' => ['descuento', 'precio_manual', 'pago_proveedor', 'diferencia_caja'], 'alcances' => self::GE],
                ],
            ],
            'cabeceras' => [
                'nombre' => 'Cabeceras y política de contenido',
                'grupo' => 'seguridad', 'sensible' => true, 'edita' => 'SuperAdmin',
                'piso' => 'X-Frame-Options DENY, HSTS y ocultar versiones no desactivables.',
                'parametros' => [
                    'cabeceras.csp_scripts' => ['etiqueta' => 'Dominios permitidos para scripts', 'tipo' => 'lista', 'inicial' => [], 'alcances' => self::G],
                    'cabeceras.csp_conexiones' => ['etiqueta' => 'Dominios permitidos para conexiones', 'tipo' => 'lista', 'inicial' => [], 'alcances' => self::G],
                    'cabeceras.csp_imagenes' => ['etiqueta' => 'Dominios permitidos para imágenes', 'tipo' => 'lista', 'inicial' => [], 'alcances' => self::G],
                    'cabeceras.csp_frames' => ['etiqueta' => 'Dominios permitidos en frames', 'tipo' => 'lista', 'inicial' => [], 'alcances' => self::G],
                    'cabeceras.hsts_max_age' => ['etiqueta' => 'HSTS max-age', 'tipo' => 'entero', 'inicial' => 31536000, 'min' => 31536000, 'max' => 63072000, 'unidad' => 's', 'estricto' => 'mayor', 'alcances' => self::G],
                    'cabeceras.csp_solo_reportar' => ['etiqueta' => 'CSP en modo "solo reportar"', 'tipo' => 'booleano', 'inicial' => false, 'alcances' => self::G, 'ayuda' => 'Para probar cambios sin bloquear.'],
                ],
            ],

            // ─────────────────── Auditoría, datos y privacidad ───────────────────
            'auditoria' => [
                'nombre' => 'Auditoría y trazabilidad',
                'grupo' => 'auditoria', 'sensible' => false, 'edita' => 'Auditor (lectura), SuperAdmin', 'entidad' => 'configuracion.auditoria.index',
                'piso' => 'Mínimo auditado no desactivable (login, permisos, configuración, documentos tributarios, caja, pagos); tabla solo inserción.',
                'parametros' => [
                    'auditoria.entidades_extra' => ['etiqueta' => 'Entidades auditadas además del mínimo', 'tipo' => 'lista', 'inicial' => [], 'alcances' => self::G],
                    'auditoria.replica_destino' => ['etiqueta' => 'Destino de réplica externa (bucket con versionado)', 'tipo' => 'texto', 'inicial' => '', 'alcances' => self::G],
                    'auditoria.replica_minutos' => ['etiqueta' => 'Frecuencia de réplica', 'tipo' => 'entero', 'inicial' => 5, 'min' => 1, 'max' => 60, 'unidad' => 'min', 'estricto' => 'menor', 'alcances' => self::G],
                    'auditoria.alertas_criticas' => ['etiqueta' => 'Alertar acciones críticas', 'tipo' => 'booleano', 'inicial' => true, 'alcances' => self::G],
                ],
            ],
            'retencion' => [
                'nombre' => 'Retención de datos',
                'grupo' => 'auditoria', 'sensible' => true, 'edita' => 'SuperAdmin + Legal',
                'piso' => 'Documentos tributarios y auditoría: mínimo 6 años (plazo de fiscalización SII).',
                'parametros' => [
                    'retencion.grabaciones_dias' => ['etiqueta' => 'Grabaciones de llamadas', 'tipo' => 'entero', 'inicial' => 365, 'min' => 30, 'max' => 3650, 'unidad' => 'días', 'alcances' => self::GE],
                    'retencion.mensajeria_dias' => ['etiqueta' => 'Conversaciones WhatsApp y LinkedIn', 'tipo' => 'entero', 'inicial' => 730, 'min' => 30, 'max' => 3650, 'unidad' => 'días', 'alcances' => self::GE],
                    'retencion.logs_app_dias' => ['etiqueta' => 'Logs de aplicación', 'tipo' => 'entero', 'inicial' => 90, 'min' => 30, 'max' => 3650, 'unidad' => 'días', 'alcances' => self::G],
                    'retencion.logs_api_dias' => ['etiqueta' => 'Logs de API', 'tipo' => 'entero', 'inicial' => 365, 'min' => 90, 'max' => 3650, 'unidad' => 'días', 'alcances' => self::G],
                    'retencion.auditoria_anios' => ['etiqueta' => 'Auditoría', 'tipo' => 'entero', 'inicial' => 6, 'min' => 6, 'max' => 20, 'unidad' => 'años', 'estricto' => 'mayor', 'alcances' => self::G],
                    'retencion.tributarios_anios' => ['etiqueta' => 'Documentos tributarios y libros', 'tipo' => 'entero', 'inicial' => 6, 'min' => 6, 'max' => 20, 'unidad' => 'años', 'estricto' => 'mayor', 'alcances' => self::G],
                    'retencion.prospectos_dias' => ['etiqueta' => 'Prospectos sin actividad', 'tipo' => 'entero', 'inicial' => 730, 'min' => 30, 'max' => 3650, 'unidad' => 'días', 'alcances' => self::GE],
                    'retencion.accion_al_vencer' => ['etiqueta' => 'Acción al vencer', 'tipo' => 'opcion', 'opciones' => ['archivar', 'anonimizar', 'eliminar'], 'inicial' => 'anonimizar', 'alcances' => self::GE],
                    'retencion.retencion_legal' => ['etiqueta' => 'Retención legal activa (suspende borrados)', 'tipo' => 'booleano', 'inicial' => false, 'alcances' => self::GE],
                ],
            ],
            'privacidad' => [
                'nombre' => 'Privacidad y datos personales',
                'grupo' => 'auditoria', 'sensible' => true, 'edita' => 'SuperAdmin',
                'piso' => 'Ningún perfil sin permiso explícito ve credenciales de terceros.',
                'parametros' => [
                    'privacidad.campos_personales' => ['etiqueta' => 'Campos personales', 'tipo' => 'multiple', 'opciones' => ['rut', 'telefono', 'email', 'direccion'], 'inicial' => ['rut', 'telefono', 'email', 'direccion'], 'obligatorias' => ['rut', 'telefono', 'email'], 'alcances' => self::G],
                    'privacidad.enmascarar_rut_perfiles' => ['etiqueta' => 'Perfiles que ven el RUT enmascarado', 'tipo' => 'multiple', 'opciones' => self::PERFILES, 'inicial' => ['caja', 'contact_center'], 'alcances' => self::GE],
                ],
            ],
            'ia' => [
                'nombre' => 'Asistente de IA',
                'grupo' => 'auditoria', 'sensible' => true, 'edita' => 'SuperAdmin',
                'piso' => 'RUT, teléfono y correo siempre seudonimizados; no se envían grabaciones sin anonimizar.',
                'parametros' => [
                    'ia.activo' => ['etiqueta' => 'Asistente activo', 'tipo' => 'booleano', 'inicial' => false, 'alcances' => self::GE],
                    'ia.modelo' => ['etiqueta' => 'Proveedor y modelo', 'tipo' => 'texto', 'inicial' => '', 'alcances' => self::G],
                    'ia.perfiles' => ['etiqueta' => 'Perfiles que lo usan', 'tipo' => 'multiple', 'opciones' => self::PERFILES, 'inicial' => ['ventas', 'contact_center'], 'alcances' => self::GE],
                    'ia.seudonimizar' => ['etiqueta' => 'Campos a seudonimizar', 'tipo' => 'multiple', 'opciones' => ['rut', 'telefono', 'email', 'direccion', 'nombre'], 'inicial' => ['rut', 'telefono', 'email', 'direccion'], 'obligatorias' => ['rut', 'telefono', 'email'], 'alcances' => self::G],
                    'ia.limite_diario' => ['etiqueta' => 'Límite de uso por usuario y día', 'tipo' => 'entero', 'inicial' => 50, 'min' => 0, 'max' => 1000, 'alcances' => self::GE],
                    'ia.retencion_proveedor_dias' => ['etiqueta' => 'Retención acordada con el proveedor', 'tipo' => 'entero', 'inicial' => 0, 'min' => 0, 'max' => 30, 'unidad' => 'días', 'estricto' => 'menor', 'alcances' => self::G],
                ],
            ],

            // ───────────────────────── Integraciones y API ─────────────────────────
            'integraciones' => [
                'nombre' => 'Integraciones externas',
                'grupo' => 'integraciones', 'sensible' => true, 'edita' => 'SuperAdmin',
                'piso' => 'El secreto nunca se muestra ni se exporta, solo se reemplaza; webhooks siempre con firma verificada.',
                'parametros' => [
                    'integraciones.activas' => ['etiqueta' => 'Integraciones activas', 'tipo' => 'multiple', 'opciones' => ['pbx', 'dte', 'whatsapp', 'meta_ads', 'linkedin', 'correo', 'ia', 'mapas'], 'inicial' => ['correo'], 'alcances' => self::GE],
                    'integraciones.ambiente' => ['etiqueta' => 'Ambiente', 'tipo' => 'opcion', 'opciones' => ['certificacion', 'produccion'], 'inicial' => 'certificacion', 'alcances' => self::GE],
                    'integraciones.rotacion_dias' => ['etiqueta' => 'Aviso de rotación de secretos', 'tipo' => 'entero', 'inicial' => 90, 'min' => 7, 'max' => 365, 'unidad' => 'días', 'estricto' => 'menor', 'alcances' => self::G],
                    'integraciones.reintentos' => ['etiqueta' => 'Reintentos (espera exponencial)', 'tipo' => 'entero', 'inicial' => 5, 'min' => 0, 'max' => 20, 'alcances' => self::G],
                    'integraciones.timeout_segundos' => ['etiqueta' => 'Tiempo de espera', 'tipo' => 'entero', 'inicial' => 15, 'min' => 1, 'max' => 120, 'unidad' => 's', 'alcances' => self::G],
                ],
            ],
            'telefonia' => [
                'nombre' => 'Telefonía',
                'grupo' => 'integraciones', 'sensible' => false, 'edita' => 'Admin Contact Center',
                'piso' => 'Credenciales de discador fuera del modelo Usuario.',
                'parametros' => [
                    'telefonia.horario_discado_desde' => ['etiqueta' => 'Discado desde', 'tipo' => 'texto', 'inicial' => '09:00', 'alcances' => self::GEL],
                    'telefonia.horario_discado_hasta' => ['etiqueta' => 'Discado hasta', 'tipo' => 'texto', 'inicial' => '20:00', 'alcances' => self::GEL],
                ],
            ],
            'tokens' => [
                'nombre' => 'Tokens de API',
                'grupo' => 'integraciones', 'sensible' => false, 'edita' => 'SuperAdmin', 'entidad' => 'configuracion.tokens.index',
                'piso' => 'Ningún token sin vencimiento ni sin scope.',
                'parametros' => [
                    'tokens.vencimiento_dias' => ['etiqueta' => 'Vencimiento máximo', 'tipo' => 'entero', 'inicial' => 90, 'min' => 1, 'max' => 365, 'unidad' => 'días', 'estricto' => 'menor', 'alcances' => self::G],
                ],
            ],
            'limites_api' => [
                'nombre' => 'Límites de API',
                'grupo' => 'integraciones', 'sensible' => false, 'edita' => 'SuperAdmin',
                'piso' => 'Máx. 200 registros por página.',
                'parametros' => [
                    'api.por_pagina' => ['etiqueta' => 'Registros por página por defecto', 'tipo' => 'entero', 'inicial' => 50, 'min' => 1, 'max' => 200, 'alcances' => self::G],
                    'api.por_pagina_max' => ['etiqueta' => 'Registros por página máximo', 'tipo' => 'entero', 'inicial' => 200, 'min' => 1, 'max' => 200, 'estricto' => 'menor', 'alcances' => self::G],
                    'api.solicitudes_por_minuto' => ['etiqueta' => 'Solicitudes por minuto por token', 'tipo' => 'entero', 'inicial' => 60, 'min' => 1, 'max' => 600, 'estricto' => 'menor', 'alcances' => self::G],
                    'api.factor_anomalia' => ['etiqueta' => 'Alerta por uso anómalo (veces el promedio)', 'tipo' => 'entero', 'inicial' => 5, 'min' => 2, 'max' => 50, 'alcances' => self::G],
                ],
            ],

            // ───────────────────────── Negocio y facturación ─────────────────────────
            'empresas' => [
                'nombre' => 'Empresas (tenants)',
                'grupo' => 'negocio', 'sensible' => false, 'edita' => 'SuperAdmin', 'entidad' => 'configuracion.empresas.index',
                'piso' => 'Toda tabla de negocio con empresa_id y RLS.',
                'parametros' => [],
            ],
            'aprobaciones' => [
                'nombre' => 'Aprobaciones y umbrales',
                'grupo' => 'negocio', 'sensible' => true, 'edita' => 'Gerencia',
                'piso' => 'Quien solicita no aprueba su propia excepción.',
                'parametros' => [
                    'aprobaciones.descuento_max_pct' => ['etiqueta' => 'Descuento de línea o global sin aprobación', 'tipo' => 'porcentaje', 'inicial' => 10, 'min' => 0, 'max' => 100, 'unidad' => '%', 'alcances' => self::GEL],
                    'aprobaciones.precio_bajo_lista' => ['etiqueta' => 'Precio manual bajo lista requiere aprobación', 'tipo' => 'booleano', 'inicial' => true, 'alcances' => self::GE],
                    'aprobaciones.despacho_gratis' => ['etiqueta' => 'Despacho gratis requiere aprobación', 'tipo' => 'booleano', 'inicial' => true, 'alcances' => self::GE],
                    'aprobaciones.cupo_excedido' => ['etiqueta' => 'Cupo de crédito excedido requiere aprobación', 'tipo' => 'booleano', 'inicial' => true, 'alcances' => self::GE],
                    'aprobaciones.retroactivo_dias' => ['etiqueta' => 'Fecha retroactiva sin aprobación', 'tipo' => 'entero', 'inicial' => 7, 'min' => 0, 'max' => 30, 'unidad' => 'días', 'alcances' => self::GE],
                    'aprobaciones.pago_proveedor_monto' => ['etiqueta' => 'Pago a proveedor sin segunda aprobación hasta', 'tipo' => 'entero', 'inicial' => 5000000, 'min' => 0, 'max' => 1000000000, 'unidad' => '$', 'alcances' => self::GE],
                ],
            ],
            'tributarios' => [
                'nombre' => 'Parámetros tributarios',
                'grupo' => 'negocio', 'sensible' => true, 'edita' => 'Finanzas',
                'piso' => 'Redondeo mitad hacia arriba en pesos enteros, inmutabilidad del emitido y folio solo al emitir no son configurables.',
                'parametros' => [
                    'tributario.tasa_iva' => ['etiqueta' => 'Tasa de IVA', 'tipo' => 'porcentaje', 'inicial' => 19, 'min' => 0, 'max' => 100, 'unidad' => '%', 'alcances' => self::G],
                    'tributario.retencion_honorarios_pct' => ['etiqueta' => 'Retención de boletas de honorarios', 'tipo' => 'porcentaje', 'inicial' => 15.25, 'min' => 0, 'max' => 30, 'unidad' => '%', 'alcances' => self::G],
                    'tributario.tipos_dte' => ['etiqueta' => 'Tipos DTE habilitados', 'tipo' => 'multiple', 'opciones' => ['33', '34', '39', '41', '52', '56', '61'], 'inicial' => ['33', '34', '39', '41', '52', '56', '61'], 'alcances' => self::GE],
                    'tributario.rut_generico' => ['etiqueta' => 'RUT genérico bloqueado en factura', 'tipo' => 'texto', 'inicial' => '66666666-6', 'alcances' => self::G],
                    'tributario.precio_boleta' => ['etiqueta' => 'Precio de boleta', 'tipo' => 'opcion', 'opciones' => ['derivado', 'manual'], 'inicial' => 'derivado', 'alcances' => self::GE],
                    'tributario.max_lineas' => ['etiqueta' => 'Máximo de líneas por documento', 'tipo' => 'entero', 'inicial' => 60, 'min' => 1, 'max' => 60, 'alcances' => self::G],
                    'tributario.largo_descripcion' => ['etiqueta' => 'Largo máximo de descripción', 'tipo' => 'entero', 'inicial' => 80, 'min' => 1, 'max' => 80, 'alcances' => self::G],
                ],
            ],
            'folios' => [
                'nombre' => 'Folios y CAF',
                'grupo' => 'negocio', 'sensible' => true, 'edita' => 'Finanzas',
                'piso' => 'Sin rangos solapados; folio por transacción con bloqueo.',
                'parametros' => [
                    'folios.aviso_restantes' => ['etiqueta' => 'Avisar cuando queden', 'tipo' => 'entero', 'inicial' => 100, 'min' => 1, 'max' => 10000, 'unidad' => 'folios', 'alcances' => self::GE],
                    'folios.aviso_porcentaje' => ['etiqueta' => 'Avisar bajo este % del rango', 'tipo' => 'porcentaje', 'inicial' => 10, 'min' => 1, 'max' => 50, 'unidad' => '%', 'alcances' => self::GE],
                    'folios.custodio' => ['etiqueta' => 'Custodio del CAF', 'tipo' => 'opcion', 'opciones' => ['proveedor_dte', 'empresa'], 'inicial' => 'proveedor_dte', 'alcances' => self::GE],
                ],
            ],
            'listas_precios' => [
                'nombre' => 'Listas de precios',
                'grupo' => 'negocio', 'sensible' => false, 'edita' => 'Comercial',
                'piso' => 'Precio decidido en el backend.',
                'parametros' => [
                    'precios.regla_mayorista' => ['etiqueta' => 'Regla de la lista mayorista', 'tipo' => 'opcion', 'opciones' => ['porcentaje_sobre_meson', 'precio_propio'], 'inicial' => 'precio_propio', 'alcances' => self::GE],
                    'precios.descuento_mayorista_pct' => ['etiqueta' => '% bajo mesón (si aplica)', 'tipo' => 'porcentaje', 'inicial' => 0, 'min' => 0, 'max' => 90, 'unidad' => '%', 'alcances' => self::GE],
                ],
            ],
            'credito' => [
                'nombre' => 'Crédito y cuentas corrientes',
                'grupo' => 'negocio', 'sensible' => false, 'edita' => 'Finanzas',
                'piso' => 'Venta a crédito solo con cuenta corriente.',
                'parametros' => [
                    'credito.cupo_defecto' => ['etiqueta' => 'Cupo por defecto', 'tipo' => 'entero', 'inicial' => 0, 'min' => 0, 'max' => 1000000000, 'unidad' => '$', 'alcances' => self::GE],
                    'credito.plazos_dias' => ['etiqueta' => 'Plazos de pago', 'tipo' => 'escalones', 'inicial' => [0, 7, 15, 30, 45, 60, 90], 'min' => 0, 'max' => 365, 'unidad' => 'días', 'alcances' => self::GE],
                    'credito.bloqueo_mora_dias' => ['etiqueta' => 'Bloqueo por mora', 'tipo' => 'entero', 'inicial' => 30, 'min' => 1, 'max' => 365, 'unidad' => 'días', 'alcances' => self::GE],
                    'credito.interes_mensual_pct' => ['etiqueta' => 'Interés mensual por mora', 'tipo' => 'porcentaje', 'inicial' => 0, 'min' => 0, 'max' => 10, 'unidad' => '%', 'alcances' => self::GE],
                ],
            ],
            'caja' => [
                'nombre' => 'Caja y medios de pago',
                'grupo' => 'negocio', 'sensible' => false, 'edita' => 'Finanzas',
                'piso' => 'Cierre con diferencia siempre auditado.',
                'parametros' => [
                    'caja.medios' => ['etiqueta' => 'Medios de pago habilitados', 'tipo' => 'multiple', 'opciones' => ['efectivo', 'debito', 'credito', 'transferencia', 'mixto'], 'inicial' => ['efectivo', 'debito', 'credito', 'transferencia', 'mixto'], 'alcances' => self::GEL],
                    'caja.tolerancia' => ['etiqueta' => 'Tolerancia de diferencia en cuadratura', 'tipo' => 'entero', 'inicial' => 0, 'min' => 0, 'max' => 10000, 'unidad' => '$', 'estricto' => 'menor', 'alcances' => self::GEL],
                    'caja.efectivo_maximo' => ['etiqueta' => 'Monto máximo en efectivo por venta', 'tipo' => 'entero', 'inicial' => 5000000, 'min' => 0, 'max' => 100000000, 'unidad' => '$', 'alcances' => self::GEL],
                    'caja.cierre_por_turno' => ['etiqueta' => 'Apertura y cierre por turno', 'tipo' => 'booleano', 'inicial' => true, 'alcances' => self::GEL],
                ],
            ],
            'cotizaciones' => [
                'nombre' => 'Cotizaciones',
                'grupo' => 'negocio', 'sensible' => false, 'edita' => 'Gerencia Comercial',
                'piso' => 'Una cotización vencida no se convierte sin renovarla.',
                'parametros' => [
                    'cotizaciones.vigencia_dias' => ['etiqueta' => 'Vigencia por defecto', 'tipo' => 'entero', 'inicial' => 15, 'min' => 1, 'max' => 90, 'unidad' => 'días', 'alcances' => self::GE],
                ],
            ],
            'inventario' => [
                'nombre' => 'Inventario',
                'grupo' => 'negocio', 'sensible' => false, 'edita' => 'Bodega y Finanzas',
                'piso' => 'El kardex es solo inserción; toda merma queda con motivo y responsable.',
                'parametros' => [
                    'inventario.permitir_negativo' => ['etiqueta' => 'Permitir vender sin stock', 'tipo' => 'booleano', 'inicial' => false, 'alcances' => self::GEL],
                    'inventario.merma_aprobacion_monto' => ['etiqueta' => 'Merma sin aprobación hasta', 'tipo' => 'entero', 'inicial' => 100000, 'min' => 0, 'max' => 100000000, 'unidad' => '$', 'estricto' => 'menor', 'alcances' => self::GE],
                    'inventario.reserva_dias' => ['etiqueta' => 'Días de reserva de una orden de venta', 'tipo' => 'entero', 'inicial' => 7, 'min' => 1, 'max' => 60, 'unidad' => 'días', 'alcances' => self::GE],
                    'inventario.precio_variacion_pct' => ['etiqueta' => 'Variación de precio sin aprobación', 'tipo' => 'porcentaje', 'inicial' => 15, 'min' => 0, 'max' => 100, 'unidad' => '%', 'estricto' => 'menor', 'alcances' => self::GE],
                    'inventario.devolucion_aprobacion_monto' => ['etiqueta' => 'Devolución sin aprobación hasta', 'tipo' => 'entero', 'inicial' => 0, 'min' => 0, 'max' => 100000000, 'unidad' => '$', 'estricto' => 'menor', 'alcances' => self::GE],
                ],
            ],
            'contabilidad' => [
                'nombre' => 'Contabilidad y tesorería',
                'grupo' => 'negocio', 'sensible' => true, 'edita' => 'Finanzas',
                'piso' => 'Todo asiento cuadra (debe = haber) y no se edita ni se borra: se corrige con un reverso. Los balances salen de los asientos, nunca se digitan.',
                'parametros' => [
                    'contabilidad.cierre_hasta' => ['etiqueta' => 'Período cerrado hasta (AAAA-MM-DD)', 'tipo' => 'texto', 'inicial' => '', 'alcances' => self::GE],
                    'conciliacion.dias_tolerancia' => ['etiqueta' => 'Días de diferencia tolerados al conciliar', 'tipo' => 'entero', 'inicial' => 3, 'min' => 0, 'max' => 15, 'unidad' => 'días', 'alcances' => self::GE],
                ],
            ],
            'nomina' => [
                'nombre' => 'Parámetros de nómina',
                'grupo' => 'negocio', 'sensible' => true, 'edita' => 'RRHH + Finanzas',
                'piso' => 'Los indicadores (UF, UTM, sueldo mínimo, topes y tasas) se actualizan aquí, nunca en el código; cada nómina guarda los valores con que se calculó. La tabla del impuesto único (tramos en UTM, art. 43 LIR) es legal y está en el motor.',
                'parametros' => [
                    'nomina.uf' => ['etiqueta' => 'Valor UF del mes', 'tipo' => 'decimal', 'inicial' => 39900.00, 'min' => 1, 'max' => 1000000, 'unidad' => '$', 'alcances' => self::G, 'ayuda' => 'Último día del mes de la remuneración.'],
                    'nomina.utm' => ['etiqueta' => 'Valor UTM del mes', 'tipo' => 'entero', 'inicial' => 69500, 'min' => 1, 'max' => 1000000, 'unidad' => '$', 'alcances' => self::G],
                    'nomina.imm' => ['etiqueta' => 'Ingreso mínimo mensual', 'tipo' => 'entero', 'inicial' => 539000, 'min' => 1, 'max' => 10000000, 'unidad' => '$', 'alcances' => self::G],
                    'nomina.tope_afp_uf' => ['etiqueta' => 'Tope imponible AFP y salud', 'tipo' => 'decimal', 'inicial' => 89.9, 'min' => 1, 'max' => 500, 'unidad' => 'UF', 'alcances' => self::G],
                    'nomina.tope_cesantia_uf' => ['etiqueta' => 'Tope imponible seguro de cesantía', 'tipo' => 'decimal', 'inicial' => 135.1, 'min' => 1, 'max' => 500, 'unidad' => 'UF', 'alcances' => self::G],
                    'nomina.tope_indemnizacion_uf' => ['etiqueta' => 'Tope de remuneración para indemnizaciones', 'tipo' => 'decimal', 'inicial' => 90, 'min' => 1, 'max' => 500, 'unidad' => 'UF', 'alcances' => self::G],
                    'nomina.afp_capital' => ['etiqueta' => 'AFP Capital (10% + comisión)', 'tipo' => 'porcentaje', 'inicial' => 11.44, 'min' => 10, 'max' => 15, 'unidad' => '%', 'alcances' => self::G],
                    'nomina.afp_cuprum' => ['etiqueta' => 'AFP Cuprum', 'tipo' => 'porcentaje', 'inicial' => 11.44, 'min' => 10, 'max' => 15, 'unidad' => '%', 'alcances' => self::G],
                    'nomina.afp_habitat' => ['etiqueta' => 'AFP Habitat', 'tipo' => 'porcentaje', 'inicial' => 11.27, 'min' => 10, 'max' => 15, 'unidad' => '%', 'alcances' => self::G],
                    'nomina.afp_modelo' => ['etiqueta' => 'AFP Modelo', 'tipo' => 'porcentaje', 'inicial' => 10.58, 'min' => 10, 'max' => 15, 'unidad' => '%', 'alcances' => self::G],
                    'nomina.afp_planvital' => ['etiqueta' => 'AFP PlanVital', 'tipo' => 'porcentaje', 'inicial' => 11.16, 'min' => 10, 'max' => 15, 'unidad' => '%', 'alcances' => self::G],
                    'nomina.afp_provida' => ['etiqueta' => 'AFP ProVida', 'tipo' => 'porcentaje', 'inicial' => 11.45, 'min' => 10, 'max' => 15, 'unidad' => '%', 'alcances' => self::G],
                    'nomina.afp_uno' => ['etiqueta' => 'AFP Uno', 'tipo' => 'porcentaje', 'inicial' => 10.46, 'min' => 10, 'max' => 15, 'unidad' => '%', 'alcances' => self::G],
                    'nomina.salud_pct' => ['etiqueta' => 'Cotización legal de salud', 'tipo' => 'porcentaje', 'inicial' => 7, 'min' => 7, 'max' => 7, 'unidad' => '%', 'alcances' => self::G],
                    'nomina.cesantia_trabajador_pct' => ['etiqueta' => 'Seguro de cesantía, trabajador (indefinido)', 'tipo' => 'porcentaje', 'inicial' => 0.6, 'min' => 0, 'max' => 5, 'unidad' => '%', 'alcances' => self::G],
                    'nomina.cesantia_empleador_indefinido_pct' => ['etiqueta' => 'Seguro de cesantía, empleador (indefinido)', 'tipo' => 'porcentaje', 'inicial' => 2.4, 'min' => 0, 'max' => 5, 'unidad' => '%', 'alcances' => self::G],
                    'nomina.cesantia_empleador_plazo_pct' => ['etiqueta' => 'Seguro de cesantía, empleador (plazo fijo u obra)', 'tipo' => 'porcentaje', 'inicial' => 3, 'min' => 0, 'max' => 5, 'unidad' => '%', 'alcances' => self::G],
                    'nomina.sis_pct' => ['etiqueta' => 'Seguro de invalidez y sobrevivencia (empleador)', 'tipo' => 'porcentaje', 'inicial' => 1.88, 'min' => 0, 'max' => 5, 'unidad' => '%', 'alcances' => self::G],
                    'nomina.aporte_empleador_pct' => ['etiqueta' => 'Cotización adicional del empleador (reforma de pensiones)', 'tipo' => 'porcentaje', 'inicial' => 1, 'min' => 0, 'max' => 10, 'unidad' => '%', 'alcances' => self::G, 'ayuda' => 'Ley 21.735: sube gradualmente; actualizar según el calendario vigente.'],
                    'nomina.mutual_pct' => ['etiqueta' => 'Seguro de accidentes del trabajo (empleador)', 'tipo' => 'porcentaje', 'inicial' => 0.93, 'min' => 0.9, 'max' => 7, 'unidad' => '%', 'alcances' => self::GE],
                    'nomina.jornada_maxima' => ['etiqueta' => 'Jornada ordinaria máxima semanal', 'tipo' => 'entero', 'inicial' => 42, 'min' => 40, 'max' => 45, 'unidad' => 'horas', 'alcances' => self::G, 'ayuda' => 'Ley 21.561 (40 horas): 42 desde abril de 2026, 40 desde abril de 2028.'],
                    'nomina.recargo_horas_extra_pct' => ['etiqueta' => 'Recargo de horas extraordinarias', 'tipo' => 'porcentaje', 'inicial' => 50, 'min' => 50, 'max' => 200, 'unidad' => '%', 'alcances' => self::GE, 'estricto' => 'mayor'],
                    'nomina.max_horas_extra_dia' => ['etiqueta' => 'Máximo de horas extra por día', 'tipo' => 'entero', 'inicial' => 2, 'min' => 0, 'max' => 2, 'unidad' => 'horas', 'alcances' => self::GE, 'estricto' => 'menor'],
                    'nomina.prestamo_max_cuota_pct' => ['etiqueta' => 'Cuota máxima de préstamo sobre el sueldo', 'tipo' => 'porcentaje', 'inicial' => 15, 'min' => 1, 'max' => 15, 'unidad' => '%', 'alcances' => self::GE, 'estricto' => 'menor', 'ayuda' => 'Art. 58 del Código del Trabajo: los descuentos voluntarios no superan el 15% de la remuneración.'],
                    'rrhh.dias_aviso_contrato' => ['etiqueta' => 'Aviso de vencimiento de contratos', 'tipo' => 'entero', 'inicial' => 30, 'min' => 1, 'max' => 90, 'unidad' => 'días', 'alcances' => self::GE],
                ],
            ],
            'compras' => [
                'nombre' => 'Compras',
                'grupo' => 'negocio', 'sensible' => false, 'edita' => 'Gerencia y Finanzas',
                'piso' => 'Quien crea una orden de compra no la aprueba; una factura que no calza con la OC y la recepción no pasa a pago sin revisión.',
                'parametros' => [
                    'compras.oc_aprobacion_monto' => ['etiqueta' => 'Orden de compra sin aprobación hasta', 'tipo' => 'entero', 'inicial' => 500000, 'min' => 0, 'max' => 1000000000, 'unidad' => '$', 'estricto' => 'menor', 'alcances' => self::GE],
                    'compras.cotizaciones_desde' => ['etiqueta' => 'Comparar cotizaciones desde (neto)', 'tipo' => 'entero', 'inicial' => 1000000, 'min' => 0, 'max' => 1000000000, 'unidad' => '$', 'estricto' => 'menor', 'alcances' => self::GE],
                    'compras.cotizaciones_minimas' => ['etiqueta' => 'Cotizaciones mínimas a comparar', 'tipo' => 'entero', 'inicial' => 2, 'min' => 1, 'max' => 5, 'alcances' => self::GE],
                    'compras.recepcion_tolerancia_pct' => ['etiqueta' => 'Recibir sobre lo pedido hasta', 'tipo' => 'porcentaje', 'inicial' => 0, 'min' => 0, 'max' => 20, 'unidad' => '%', 'estricto' => 'menor', 'alcances' => self::GE],
                    'compras.match_tolerancia_pct' => ['etiqueta' => 'Diferencia de precio factura vs. OC tolerada', 'tipo' => 'porcentaje', 'inicial' => 2, 'min' => 0, 'max' => 20, 'unidad' => '%', 'estricto' => 'menor', 'alcances' => self::GE],
                    'compras.cuadre_tolerancia_monto' => ['etiqueta' => 'Diferencia tolerada entre total declarado y líneas', 'tipo' => 'entero', 'inicial' => 10, 'min' => 0, 'max' => 10000, 'unidad' => '$', 'estricto' => 'menor', 'alcances' => self::GE],
                ],
            ],
            'despacho' => [
                'nombre' => 'Parámetros de despacho',
                'grupo' => 'negocio', 'sensible' => false, 'edita' => 'Logística',
                'piso' => 'Un despacho entregado exige nombre de quien recibe y firma; el despacho gratis bajo el monto mínimo lo aprueba otra persona.',
                'parametros' => [
                    'despacho.tarifa_base' => ['etiqueta' => 'Cargo de despacho sugerido', 'tipo' => 'entero', 'inicial' => 5990, 'min' => 0, 'max' => 1000000, 'unidad' => '$', 'alcances' => self::GEL],
                    'despacho.gratis_desde' => ['etiqueta' => 'Despacho gratis sin aprobación desde', 'tipo' => 'entero', 'inicial' => 150000, 'min' => 0, 'max' => 100000000, 'unidad' => '$', 'alcances' => self::GEL],
                ],
            ],
            'metas' => [
                'nombre' => 'Metas y comisiones',
                'grupo' => 'negocio', 'sensible' => false, 'edita' => 'Gerencia Comercial',
                'piso' => '—',
                'parametros' => [
                    'comisiones.base' => ['etiqueta' => 'Base de cálculo de comisión', 'tipo' => 'opcion', 'opciones' => ['margen', 'monto'], 'inicial' => 'margen', 'alcances' => self::GE],
                    'comisiones.evento' => ['etiqueta' => 'Se devenga al', 'tipo' => 'opcion', 'opciones' => ['emitir', 'pagar'], 'inicial' => 'pagar', 'alcances' => self::GE],
                ],
            ],

            // ───────────────────────── Operación y plataforma ─────────────────────────
            'modulos' => [
                'nombre' => 'Módulos y funcionalidades',
                'grupo' => 'operacion', 'sensible' => false, 'edita' => 'SuperAdmin',
                'piso' => '—',
                'parametros' => [
                    'modulos.activos' => ['etiqueta' => 'Módulos activos', 'tipo' => 'multiple', 'opciones' => ['dashboard', 'finanzas', 'rrhh', 'compras', 'inventario', 'ventascrm', 'caja', 'configuracion'], 'inicial' => ['dashboard', 'finanzas', 'rrhh', 'compras', 'inventario', 'ventascrm', 'caja', 'configuracion'], 'obligatorias' => ['configuracion'], 'alcances' => self::GE],
                    'modulos.facturacion' => ['etiqueta' => 'Facturación electrónica activa', 'tipo' => 'booleano', 'inicial' => false, 'alcances' => self::GE],
                    'modulos.piloto' => ['etiqueta' => 'Funciones en piloto (por perfil)', 'tipo' => 'lista', 'inicial' => [], 'alcances' => self::GE],
                ],
            ],
            'rendimiento' => [
                'nombre' => 'Rendimiento, colas y caché',
                'grupo' => 'operacion', 'sensible' => false, 'edita' => 'SuperAdmin',
                'piso' => '—',
                'parametros' => [
                    'rendimiento.cache_dashboard_min' => ['etiqueta' => 'Vigencia de caché de dashboards', 'tipo' => 'entero', 'inicial' => 5, 'min' => 1, 'max' => 120, 'unidad' => 'min', 'alcances' => self::G],
                    'rendimiento.prioridad_colas' => ['etiqueta' => 'Prioridad de colas', 'tipo' => 'lista', 'inicial' => ['dte', 'caja', 'correo', 'reportes'], 'alcances' => self::G],
                    'rendimiento.jobs_pesados_desde' => ['etiqueta' => 'Jobs pesados desde', 'tipo' => 'texto', 'inicial' => '20:00', 'alcances' => self::G],
                    'rendimiento.jobs_pesados_hasta' => ['etiqueta' => 'Jobs pesados hasta', 'tipo' => 'texto', 'inicial' => '09:00', 'alcances' => self::G],
                    'rendimiento.alerta_latencia_ms' => ['etiqueta' => 'Alerta por latencia', 'tipo' => 'entero', 'inicial' => 2000, 'min' => 100, 'max' => 30000, 'unidad' => 'ms', 'alcances' => self::G],
                ],
            ],
            'respaldos' => [
                'nombre' => 'Respaldos y continuidad',
                'grupo' => 'operacion', 'sensible' => false, 'edita' => 'SuperAdmin',
                'piso' => 'Respaldo diario mínimo.',
                'parametros' => [
                    'respaldos.frecuencia_horas' => ['etiqueta' => 'Frecuencia de respaldo', 'tipo' => 'entero', 'inicial' => 24, 'min' => 1, 'max' => 24, 'unidad' => 'h', 'estricto' => 'menor', 'alcances' => self::G],
                    'respaldos.retencion_dias' => ['etiqueta' => 'Retención de respaldos', 'tipo' => 'entero', 'inicial' => 30, 'min' => 7, 'max' => 3650, 'unidad' => 'días', 'estricto' => 'mayor', 'alcances' => self::G],
                    'respaldos.prueba_restauracion' => ['etiqueta' => 'Prueba de restauración', 'tipo' => 'opcion', 'opciones' => ['semanal', 'mensual', 'trimestral'], 'inicial' => 'mensual', 'alcances' => self::G],
                    'respaldos.disponibilidad_objetivo' => ['etiqueta' => 'Disponibilidad objetivo Caja y Ventas', 'tipo' => 'texto', 'inicial' => '99,9 %', 'alcances' => self::G],
                ],
            ],
            'notificaciones' => [
                'nombre' => 'Notificaciones y alertas',
                'grupo' => 'operacion', 'sensible' => false, 'edita' => 'SuperAdmin',
                'piso' => 'Alertas de seguridad siempre con al menos un destinatario.',
                'parametros' => [
                    'notificaciones.seguridad' => ['etiqueta' => 'Destinatarios de alertas de seguridad', 'tipo' => 'lista', 'inicial' => ['seguridad@obramax.cl'], 'min' => 1, 'alcances' => self::G],
                    'notificaciones.operacion' => ['etiqueta' => 'Destinatarios de alertas operativas (folios, DTE rechazado, caja, integración caída)', 'tipo' => 'lista', 'inicial' => [], 'alcances' => self::GE],
                    'notificaciones.canales' => ['etiqueta' => 'Canales', 'tipo' => 'multiple', 'opciones' => ['correo', 'whatsapp', 'panel'], 'inicial' => ['correo', 'panel'], 'alcances' => self::GE],
                ],
            ],
            'formatos' => [
                'nombre' => 'Formatos regionales',
                'grupo' => 'operacion', 'sensible' => false, 'edita' => 'SuperAdmin',
                'piso' => '—',
                'parametros' => [
                    'formatos.moneda' => ['etiqueta' => 'Moneda', 'tipo' => 'texto', 'inicial' => 'CLP', 'alcances' => self::GE],
                    'formatos.decimales' => ['etiqueta' => 'Decimales de moneda', 'tipo' => 'entero', 'inicial' => 0, 'min' => 0, 'max' => 4, 'alcances' => self::GE],
                    'formatos.fecha' => ['etiqueta' => 'Formato de fecha', 'tipo' => 'opcion', 'opciones' => ['dd-mm-aaaa', 'aaaa-mm-dd'], 'inicial' => 'dd-mm-aaaa', 'alcances' => self::GE],
                    'formatos.zona_horaria' => ['etiqueta' => 'Zona horaria', 'tipo' => 'texto', 'inicial' => 'America/Santiago', 'alcances' => self::GE],
                    'formatos.identificador_tributario' => ['etiqueta' => 'Identificador tributario', 'tipo' => 'opcion', 'opciones' => ['rut_cl'], 'inicial' => 'rut_cl', 'alcances' => self::GE],
                    'formatos.separador_miles' => ['etiqueta' => 'Separador de miles', 'tipo' => 'opcion', 'opciones' => ['.', ',', ' '], 'inicial' => '.', 'alcances' => self::GE],
                ],
            ],
        ];
    }

    /** @return array<string, array> Parámetros indexados por clave, con su mantenedor. */
    public static function parametros(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        foreach (self::mantenedores() as $mClave => $m) {
            foreach ($m['parametros'] as $clave => $def) {
                $cache[$clave] = $def + ['mantenedor' => $mClave, 'sensible' => $m['sensible'], 'alcances' => self::GE];
            }
        }

        return $cache;
    }

    public static function definicion(string $clave): array
    {
        return self::parametros()[$clave]
            ?? throw new \InvalidArgumentException("Parámetro desconocido: {$clave}");
    }
}
