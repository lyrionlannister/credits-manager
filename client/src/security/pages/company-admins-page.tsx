import type { FC } from 'react';
import { useState } from 'react';

import { ModuleListPage } from '@ui/module/components/module-list-page';
import { DataTable } from '@ui/table/components/data-table';
import { useResource } from '../../shared/hooks/use-resource';
import { useSendRequest } from '../../shared/hooks/use-send-request';
import { TemporaryPasswordModal } from '../components/temporary-password-modal';
import type { ApiCompanyAdmin } from '../types/api-company-admin';
import { buildCompanyAdminsTableColumns } from '../utils/build-company-admins-table-columns';

export const CompanyAdminsPage: FC = () => {
    const { data, error, isLoading } = useResource<{ admins: ApiCompanyAdmin[] }>('/seguridad/admins-empresa');
    const { sendRequest } = useSendRequest();

    const [adminToReveal, setAdminToReveal] = useState<ApiCompanyAdmin | null>(null);
    const [temporaryPassword, setTemporaryPassword] = useState<string | null>(null);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const admins = data?.admins ?? [];

    const resetPassword = async (admin: ApiCompanyAdmin) => {
        setErrorMessage(null);

        try {
            const respuesta = await sendRequest<{ clave_temporal: string }>(`/seguridad/admins-empresa/${admin.id}/clave`, { method: 'PATCH' });
            setAdminToReveal(admin);
            setTemporaryPassword(respuesta.clave_temporal);
        } catch (caught) {
            setErrorMessage(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        }
    };

    return (
        <ModuleListPage
            pageTitle="Administradores de empresa"
            errorMessage={errorMessage ?? error}
            onDismissError={() => setErrorMessage(null)}
            countLabel={`${admins.length} ${admins.length === 1 ? 'administrador' : 'administradores'}`}
            modals={
                <TemporaryPasswordModal
                    adminName={adminToReveal?.nombre ?? null}
                    password={temporaryPassword}
                    onClose={() => {
                        setTemporaryPassword(null);
                        setAdminToReveal(null);
                    }}
                />
            }
        >
            <DataTable
                columns={buildCompanyAdminsTableColumns((admin) => void resetPassword(admin))}
                rows={admins}
                getRowKey={(admin) => admin.id}
                loading={isLoading}
                emptyMessage="No hay administradores de empresa."
            />
        </ModuleListPage>
    );
};
