/** Raw shape returned by the rows of `GET /seguridad/admins-empresa`. */
export type ApiCompanyAdmin = {
    id: number;
    nombre: string;
    usuario: string;
    activo: boolean;
    nombre_empresa: string;
};
