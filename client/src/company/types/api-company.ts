/** Raw shape returned by `GET /empresas` (rows); `GET /empresa`, `GET /empresas/{id}`, `POST /empresas` y `PATCH /empresas/{id}` la envuelven en `{ empresa: ApiCompany }`. */
export type ApiCompany = {
    id: number;
    nombre_empresa: string;
    nit: string | null;
    digito_verificacion: string | null;
    direccion: string | null;
    actividad_economica_id: number | null;
    actividad_economica: { id: number; codigo: string; nombre: string } | null;
    municipio_id: number | null;
    /** En el listado del superadmin solo trae `id` y `nombre`; el resto llega en el detalle y en la empresa propia. */
    municipio: { id: number; nombre: string; estado?: { id: number; pais_id: number; nombre: string } } | null;
    telefono: string | null;
    correo: string | null;
    logo_path: string | null;
    logo_dark_path: string | null;
    creado_en: string | null;
};
