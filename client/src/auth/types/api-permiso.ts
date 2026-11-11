/** Raw shape of a permission row (`id`, `modulo`, `accion`), as nested in `GET /roles` and user responses. */
export type ApiPermisoCatalogItem = {
    id: number;
    modulo: string;
    accion: string;
};

/** Raw shape of a catalog entry returned by `GET /permisos`: the permission plus its readable labels and menu group. */
export type ApiPermisoPanelItem = ApiPermisoCatalogItem & {
    grupo: string;
    grupo_etiqueta: string;
    modulo_etiqueta: string;
    accion_etiqueta: string;
};

/** A catalog permission with the pivot data from a user's `permisos_extra` exception. */
export type ApiPermisoExcepcion = ApiPermisoCatalogItem & {
    pivot: {
        concedido: boolean;
        empresa_id: number;
        motivo: string | null;
        responsable_id: number | null;
        vence_en: string | null;
    };
};
