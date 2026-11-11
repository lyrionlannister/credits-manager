export type Role = string;

export type PermissionAction = string;

export type ModulePermission = {
    modulo: string;
    accion: PermissionAction;
};

export type User = {
    id: number;
    name: string;
    username: string;
    role: Role;
    permissions: ModulePermission[];
    isSuperAdmin: boolean;
    mustChangePassword: boolean;
    permissionVersion: number;
};
