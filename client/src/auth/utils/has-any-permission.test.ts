import { describe, expect, it } from 'vitest';

import { hasAnyPermission } from './has-any-permission';
import type { User } from '../types/user';

const lector: User = {
    id: 1,
    name: 'Lector',
    username: 'lector',
    role: 'consulta',
    permissions: [{ modulo: 'facturacion', accion: 'listar_notas_credito' }],
    isSuperAdmin: false,
    mustChangePassword: false,
    permissionVersion: 1,
};

describe('hasAnyPermission', () => {
    it('concede si el usuario tiene alguna de las claves', () => {
        expect(hasAnyPermission(lector, ['facturacion.listar_facturas', 'facturacion.listar_notas_credito'])).toBe(true);
    });

    it('niega si no tiene ninguna, aunque tenga otra acción del mismo módulo', () => {
        expect(hasAnyPermission(lector, ['facturacion.listar_facturas', 'facturacion.listar_notas_debito'])).toBe(false);
        expect(hasAnyPermission(null, ['facturacion.listar_notas_credito'])).toBe(false);
    });
});
