import type { DataTableColumn } from '@ui/table/props/data-table-props';
import { KeyRound, Pencil, Trash2 } from 'lucide-react';
import { ActionButton } from '@ui/action-button/components/action-button';
import type { ApiUserListItem } from '../types/api-user-list-item';
import type { BuildUserTableColumnsOptions } from '../types/build-user-table-columns-options';

export function buildUserTableColumns({
    canEdit,
    canDelete,
    canChangePassword,
    canManageAdmins,
    currentUserId,
    onEdit,
    onDelete,
    onChangePassword,
}: BuildUserTableColumnsOptions): DataTableColumn<ApiUserListItem>[] {
    const columns: DataTableColumn<ApiUserListItem>[] = [
        { key: 'nombre', label: 'Nombre', sortable: true },
        { key: 'usuario', label: 'Usuario', sortable: true },
        {
            key: 'rol',
            label: 'Rol',
            sortable: true,
            render: (row) => (
                <span
                    className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
                        row.rol?.nombre === 'admin'
                            ? 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400'
                            : 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400'
                    }`}
                >
                    {row.rol ? row.rol.nombre.charAt(0).toUpperCase() + row.rol.nombre.slice(1) : '—'}
                </span>
            ),
        },
        {
            key: 'activo',
            label: 'Estado',
            sortable: true,
            render: (row) => (
                <span
                    className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
                        row.activo
                            ? 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400'
                            : 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400'
                    }`}
                >
                    {row.activo ? 'Activo' : 'Inactivo'}
                </span>
            ),
        },
        {
            key: 'creado_en',
            label: 'Creado',
            sortable: true,
            render: (row) => (row.creado_en ? new Date(row.creado_en).toLocaleDateString('es-CO') : '—'),
        },
    ];

    if (canEdit || canDelete || canChangePassword) {
        columns.push({
            key: 'acciones',
            label: 'Acción',
            render: (row) => {
                const isManageable = canManageAdmins || row.rol?.nombre !== 'admin';

                return (
                    <div className="flex w-full items-center gap-2">
                        {canDelete && isManageable && (
                            <ActionButton icon={<Trash2 className="size-5" />} label="Eliminar" variant="danger" onClick={() => onDelete(row)} />
                        )}
                        {canEdit && isManageable && <ActionButton icon={<Pencil className="size-5" />} label="Editar" onClick={() => onEdit(row)} />}
                        {canChangePassword && row.id !== currentUserId && (
                            <ActionButton
                                icon={<KeyRound className="size-5" />}
                                label="Cambiar contraseña"
                                variant="warning"
                                onClick={() => onChangePassword(row)}
                            />
                        )}
                    </div>
                );
            },
        });
    }

    return columns;
}
