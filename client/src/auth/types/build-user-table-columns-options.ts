import type { ApiUserListItem } from './api-user-list-item';

export type BuildUserTableColumnsOptions = {
    canEdit: boolean;
    canDelete: boolean;
    canChangePassword: boolean;
    /** Solo el admin de empresa edita o elimina a otro admin (el backend lo rechaza para los demás). */
    canManageAdmins: boolean;
    /** El backend rechaza restablecer la propia contraseña por este endpoint (usa "Cambiar contraseña" del perfil). */
    currentUserId: number | undefined;
    onEdit: (row: ApiUserListItem) => void;
    onDelete: (row: ApiUserListItem) => void;
    onChangePassword: (row: ApiUserListItem) => void;
};
