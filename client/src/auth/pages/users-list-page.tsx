import type { FC } from 'react';
import { useState } from 'react';
import { useNavigate } from 'react-router';
import { Search, UserPlus } from 'lucide-react';

import { usePaginatedResource } from '../../shared/hooks/use-paginated-resource';
import { useSendRequest } from '../../shared/hooks/use-send-request';
import { useLocationSuccessMessage } from '../../shared/hooks/use-location-success-message';
import { useAuth } from '../hooks/use-auth';
import { usePermission } from '../hooks/use-permission';
import { CreateButton } from '@ui/button/components/create-button';
import { Input } from '@ui/forms/components/input';
import { ChangePasswordModal } from '../components/change-password-modal';
import { ConfirmModal } from '@ui/modal/components/confirm-modal';
import { ModuleListPage } from '@ui/module/components/module-list-page';
import { DataTable } from '@ui/table/components/data-table';
import { Pagination } from '@ui/table/components/pagination';
import { buildUserTableColumns } from '../utils/build-user-table-columns';
import { isCompanyAdmin } from '../utils/is-company-admin';
import type { ApiUserListItem } from '../types/api-user-list-item';
import type { UsersListLocationState } from '../types/users-list-location-state';

export const UsersListPage: FC = () => {
    const navigate = useNavigate();
    const { sendRequest } = useSendRequest();
    const { user: currentUser } = useAuth();

    const canCreate = usePermission('usuarios', 'crear');
    const canEdit = usePermission('usuarios', 'editar');
    const canDelete = usePermission('usuarios', 'eliminar');
    const canChangePassword = isCompanyAdmin(currentUser);

    const [userToDelete, setUserToDelete] = useState<ApiUserListItem | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);
    const [userToChangePassword, setUserToChangePassword] = useState<ApiUserListItem | null>(null);
    const [successMessage, setSuccessMessage] = useLocationSuccessMessage<UsersListLocationState>();

    const {
        items,
        isLoading,
        page,
        setPage,
        totalPages,
        totalItems,
        search,
        setSearch,
        sortKey,
        sortDirection,
        toggleSort,
        refetch,
    } = usePaginatedResource<ApiUserListItem>('/usuarios');

    const handleEdit = (row: ApiUserListItem) => {
        navigate(`/usuarios/${row.id}/editar`);
    };

    const confirmDelete = async () => {
        if (!userToDelete) {
            return;
        }

        setDeleteError(null);
        setIsDeleting(true);

        try {
            await sendRequest(`/usuarios/${userToDelete.id}`, { method: 'DELETE' });
            setUserToDelete(null);
            void refetch();
        } catch (caught) {
            setDeleteError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setIsDeleting(false);
        }
    };

    const columns = buildUserTableColumns({
        canEdit,
        canDelete,
        canChangePassword,
        canManageAdmins: isCompanyAdmin(currentUser),
        currentUserId: currentUser?.id,
        onEdit: handleEdit,
        onDelete: setUserToDelete,
        onChangePassword: setUserToChangePassword,
    });

    return (
        <ModuleListPage
            pageTitle="Usuarios"
            successMessage={successMessage}
            onDismissSuccess={() => setSuccessMessage(null)}
            errorMessage={deleteError}
            onDismissError={() => setDeleteError(null)}
            countLabel={`${totalItems} ${totalItems === 1 ? 'usuario registrado' : 'usuarios registrados'}`}
            toolbar={
                <>
                    <div className="w-full sm:w-72">
                        <Input
                            id="buscar-usuario"
                            name="buscar-usuario"
                            placeholder="Buscar por nombre o usuario…"
                            startIcon={<Search className="size-4" />}
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </div>

                    {canCreate && <CreateButton label="usuario" icon={<UserPlus className="size-4" />} onClick={() => navigate('/usuarios/crear')} />}
                </>
            }
            pagination={<Pagination page={page} totalPages={totalPages} onPageChange={setPage} />}
            modals={
                <>
                    <ConfirmModal
                        isOpen={userToDelete !== null}
                        onClose={() => setUserToDelete(null)}
                        onConfirm={() => void confirmDelete()}
                        variant="danger"
                        title="Eliminar usuario"
                        description={
                            userToDelete
                                ? `¿Seguro que quieres eliminar a "${userToDelete.nombre}"? Esta acción NO se puede revertir.`
                                : ''
                        }
                        confirmLabel="Eliminar"
                        isConfirming={isDeleting}
                    />

                    {userToChangePassword && (
                        <ChangePasswordModal
                            user={userToChangePassword}
                            onClose={() => setUserToChangePassword(null)}
                            onSuccess={() => {
                                setUserToChangePassword(null);
                                setSuccessMessage('Contraseña actualizada correctamente.');
                            }}
                        />
                    )}
                </>
            }
        >
            <DataTable
                columns={columns}
                rows={items}
                getRowKey={(row) => row.id}
                sortKey={sortKey}
                sortDirection={sortDirection}
                onSortChange={toggleSort}
                loading={isLoading}
                emptyMessage="No hay usuarios que coincidan con la búsqueda."
                maxHeightClassName="max-h-[65vh]"
            />
        </ModuleListPage>
    );
};
