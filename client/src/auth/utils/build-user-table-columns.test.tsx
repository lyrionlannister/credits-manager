import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { buildUserTableColumns } from './build-user-table-columns';
import type { ApiUserListItem } from '../types/api-user-list-item';

function fila(rol: string): ApiUserListItem {
    return { id: 1, nombre: 'Usuario', usuario: 'usuario', rol: { id: 1, nombre: rol, descripcion: null }, activo: true, creado_en: null };
}

function renderAcciones(
    rol: string,
    canManageAdmins: boolean,
    permisos: { canEdit?: boolean; canDelete?: boolean; canChangePassword?: boolean; currentUserId?: number } = {},
): void {
    const columnas = buildUserTableColumns({
        canEdit: permisos.canEdit ?? true,
        canDelete: permisos.canDelete ?? true,
        canChangePassword: permisos.canChangePassword ?? false,
        canManageAdmins,
        currentUserId: permisos.currentUserId,
        onEdit: vi.fn(),
        onDelete: vi.fn(),
        onChangePassword: vi.fn(),
    });
    const acciones = columnas.find((columna) => columna.key === 'acciones');

    render(<>{acciones?.render?.(fila(rol))}</>);
}

describe('buildUserTableColumns', () => {
    it('quien no es admin no ve editar ni eliminar sobre un administrador', () => {
        renderAcciones('admin', false);

        expect(screen.queryByLabelText('Editar')).toBeNull();
        expect(screen.queryByLabelText('Eliminar')).toBeNull();
    });

    it('quien no es admin sí ve las acciones que tiene sobre los demás usuarios', () => {
        renderAcciones('cartera', false);

        expect(screen.getByLabelText('Editar')).toBeTruthy();
        expect(screen.getByLabelText('Eliminar')).toBeTruthy();
    });

    it('sin el permiso de la acción el botón no aparece, aunque sea admin', () => {
        renderAcciones('cartera', true, { canDelete: false });

        expect(screen.getByLabelText('Editar')).toBeTruthy();
        expect(screen.queryByLabelText('Eliminar')).toBeNull();
    });

    it('no ofrece cambiar la propia contraseña por esta fila', () => {
        renderAcciones('cartera', true, { canChangePassword: true, currentUserId: 1 });

        expect(screen.queryByLabelText('Cambiar contraseña')).toBeNull();
    });

    it('sí ofrece cambiar la contraseña de otro usuario', () => {
        renderAcciones('cartera', true, { canChangePassword: true, currentUserId: 2 });

        expect(screen.getByLabelText('Cambiar contraseña')).toBeTruthy();
    });
});
