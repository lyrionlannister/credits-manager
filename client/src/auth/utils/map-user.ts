import type { ApiUser } from '../types/api-user';
import type { ModulePermission, User } from '../types/user';

/** Merges the role's baseline permissions with the user's own exceptions — an exception always wins. */
function buildEffectivePermissions(apiUser: ApiUser): ModulePermission[] {
    const efectivos = new Map<string, ModulePermission>(
        (apiUser.rol?.permisos ?? []).map((permiso) => [`${permiso.modulo}.${permiso.accion}`, { modulo: permiso.modulo, accion: permiso.accion }]),
    );

    for (const excepcion of apiUser.permisos_extra) {
        const key = `${excepcion.modulo}.${excepcion.accion}`;

        if (excepcion.pivot.concedido) {
            efectivos.set(key, { modulo: excepcion.modulo, accion: excepcion.accion });
        } else {
            efectivos.delete(key);
        }
    }

    return Array.from(efectivos.values());
}

export function mapUser(apiUser: ApiUser): User {
    return {
        id: apiUser.id,
        name: apiUser.nombre,
        username: apiUser.usuario,
        role: apiUser.es_superadmin ? 'Super Admin' : (apiUser.rol?.nombre ?? ''),
        permissions: buildEffectivePermissions(apiUser),
        isSuperAdmin: apiUser.es_superadmin,
        mustChangePassword: apiUser.debe_cambiar_clave,
    };
}
