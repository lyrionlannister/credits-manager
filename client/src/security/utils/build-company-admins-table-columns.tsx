import type { DataTableColumn } from '@ui/table/props/data-table-props';
import { KeyRound } from 'lucide-react';
import { ActionButton } from '@ui/action-button/components/action-button';
import type { ApiCompanyAdmin } from '../types/api-company-admin';

export function buildCompanyAdminsTableColumns(onResetPassword: (admin: ApiCompanyAdmin) => void): DataTableColumn<ApiCompanyAdmin>[] {
    return [
        {
            key: 'empresa',
            label: 'Empresa',
            render: (admin) => <span className="font-medium text-gray-800 dark:text-white/90">{admin.nombre_empresa}</span>,
        },
        {
            key: 'admin',
            label: 'Administrador',
            render: (admin) => (
                <div className="min-w-40">
                    <p className="text-sm font-medium text-gray-800 dark:text-white/90">{admin.nombre}</p>
                    <p className="text-xs text-gray-500 dark:text-gray-400">{admin.usuario}</p>
                </div>
            ),
        },
        {
            key: 'activo',
            label: 'Estado',
            render: (admin) => (
                <span
                    className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
                        admin.activo
                            ? 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400'
                            : 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400'
                    }`}
                >
                    {admin.activo ? 'Activo' : 'Inactivo'}
                </span>
            ),
        },
        {
            key: 'acciones',
            label: 'Acción',
            render: (admin) => (
                <ActionButton icon={<KeyRound className="size-5" />} label="Recuperar contraseña" onClick={() => onResetPassword(admin)} />
            ),
        },
    ];
}
