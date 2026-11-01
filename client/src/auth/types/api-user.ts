import type { ApiRoleWithPermisos } from './api-role';
import type { ApiPermisoExcepcion } from './api-permiso';

/** Raw shape returned by the Laravel API (mirrors the Usuario Eloquent model). */
export type ApiUser = {
    id: number;
    nombre: string;
    usuario: string;
    rol: ApiRoleWithPermisos | null;
    permisos_extra: ApiPermisoExcepcion[];
    activo: boolean;
    es_superadmin: boolean;
    debe_cambiar_clave: boolean;
};
