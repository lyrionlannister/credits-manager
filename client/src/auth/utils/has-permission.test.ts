import { describe, expect, it } from 'vitest';

import { canAccessAudit } from './can-access-audit';
import { hasPermission } from './has-permission';
import { isCompanyAdmin } from './is-company-admin';
import type { User } from '../types/user';

function usuario(overrides: Partial<User> = {}): User {
    return {
        id: 1,
        name: 'Usuario de prueba',
        username: 'usuario',
        role: 'cartera',
        permissions: [],
        isSuperAdmin: false,
        mustChangePassword: false,
    permissionVersion: 1,
        ...overrides,
    };
}

describe('hasPermission', () => {
    it('sin sesión no concede nada', () => {
        expect(hasPermission(null, 'clientes', 'listar')).toBe(false);
    });

    it('concede solo el permiso exacto del módulo: el de otro módulo o el genérico no alcanzan', () => {
        const user = usuario({ permissions: [{ modulo: 'clientes', accion: 'crear' }, { modulo: 'facturacion', accion: 'crear' }] });

        expect(hasPermission(user, 'clientes', 'crear')).toBe(true);
        expect(hasPermission(user, 'empleadores', 'crear')).toBe(false);
        expect(hasPermission(user, 'facturacion', 'registrar_cobro')).toBe(false);
        expect(hasPermission(user, 'clientes', 'eliminar')).toBe(false);
    });

    it('leer no implica modificar', () => {
        const user = usuario({ permissions: [{ modulo: 'empleadores', accion: 'listar' }, { modulo: 'empleadores', accion: 'ver' }] });

        expect(hasPermission(user, 'empleadores', 'listar')).toBe(true);
        expect(hasPermission(user, 'empleadores', 'editar')).toBe(false);
        expect(hasPermission(user, 'empleadores', 'eliminar')).toBe(false);
    });

    it('el admin de empresa tiene todo el catálogo, igual que en el backend', () => {
        const admin = usuario({ role: 'admin' });

        expect(isCompanyAdmin(admin)).toBe(true);
        expect(hasPermission(admin, 'clientes', 'reasignar_asesor')).toBe(true);
        expect(hasPermission(admin, 'facturacion', 'reversar_cobro')).toBe(true);
    });

    it('el Super Admin no es admin de empresa: no recibe permisos de módulo por su rol', () => {
        const superAdmin = usuario({ role: 'Super Admin', isSuperAdmin: true });

        expect(isCompanyAdmin(superAdmin)).toBe(false);
        expect(hasPermission(superAdmin, 'clientes', 'listar')).toBe(false);
        expect(canAccessAudit(superAdmin, 'listar')).toBe(true);
    });
});
