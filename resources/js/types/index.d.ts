export interface Usuario {
    id: number;
    nombre: string;
    email: string;
    iniciales: string;
    es_superadmin: boolean;
    tiene_2fa: boolean;
    perfil: string | null;
}

export interface ItemMenu {
    clave: string;
    nombre: string;
    grupo: string | null;
    fase: number;
    disponible: boolean;
    href: string;
}

export interface ModuloMenu {
    clave: string;
    nombre: string;
    icono: string;
    items: ItemMenu[];
}

export interface Paginado<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
    app: { nombre: string };
    auth: { user: Usuario | null; permisos: string[] };
    empresa: { actual: { id: number; nombre: string; rut: string }; disponibles: { id: number; nombre: string }[] } | null;
    menu: ModuloMenu[];
    whatsapp: number | null;
    flash: { exito?: string | null; error?: string | null; aviso?: string | null; status?: string | null };
    errors: Record<string, string>;
};
