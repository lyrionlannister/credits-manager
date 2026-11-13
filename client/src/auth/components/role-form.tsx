import type { FC, SubmitEvent } from 'react';
import { useState } from 'react';
import { useNavigate } from 'react-router';

import { useAuth } from '../hooks/use-auth';
import { useCompany } from '../../company/hooks/use-company';

import { useResource } from '../../shared/hooks/use-resource';
import { useSendRequest } from '../../shared/hooks/use-send-request';
import { Button } from '@ui/button/components/button';
import { Input } from '@ui/forms/components/input';
import { Label } from '@ui/forms/components/label';
import { Spinner } from '@ui/spinner/components/spinner';
import { Save, Tag } from 'lucide-react';
import { PermissionMatrix } from './permission-matrix';
import type { RoleFormProps } from '../props/role-form-props';
import type { ApiPermisoPanelItem } from '../types/api-permiso';

export const RoleForm: FC<RoleFormProps> = ({ roleId, initialData }) => {
    const navigate = useNavigate();
    const { sendRequest } = useSendRequest();
    const { refreshUser } = useAuth();
    const { company } = useCompany();
    const { data: permisosData } = useResource<{ permisos: ApiPermisoPanelItem[] }>('/permisos');
    const permisos = permisosData?.permisos ?? [];

    const isEditMode = roleId !== undefined;
    const isRolAdmin = initialData?.nombre === 'admin';

    const [nombre, setNombre] = useState(initialData?.nombre ?? '');
    const [descripcion, setDescripcion] = useState(initialData?.descripcion ?? '');
    const [seleccionados, setSeleccionados] = useState<Set<number>>(
        () => new Set(initialData?.permisos.map((permiso) => permiso.id) ?? []),
    );
    const [error, setError] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const togglePermisos = (permisoIds: number[], isChecked: boolean) => {
        setSeleccionados((current) => {
            const siguiente = new Set(current);
            for (const permisoId of permisoIds) {
                if (isChecked) {
                    siguiente.add(permisoId);
                } else {
                    siguiente.delete(permisoId);
                }
            }
            return siguiente;
        });
    };

    const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError(null);
        setIsSubmitting(true);

        try {
            const body = { nombre, descripcion: descripcion || null, permisos: Array.from(seleccionados) };

            if (isEditMode) {
                await sendRequest(`/roles/${roleId}`, { method: 'PATCH', body });
            } else {
                await sendRequest('/roles', { method: 'POST', body });
            }

            // Fase 3: la sesión propia se vuelve a pedir tras cambiar permisos de un rol
            // (por si el rol editado es el propio).
            await refreshUser();

            navigate('/roles', {
                replace: true,
                state: { message: isEditMode ? 'Rol actualizado correctamente.' : 'Rol creado correctamente.' },
            });
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <form onSubmit={handleSubmit}>
            <div className="space-y-8">
                {company && (
                    <p className="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:border-gray-800 dark:bg-white/3 dark:text-gray-400">
                        {`Este rol pertenece a ${company.name}: sus permisos no se heredan en otras empresas.`}
                    </p>
                )}

                {error && (
                    <div className="rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400">
                        {error}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                        <Label htmlFor="nombre">Nombre</Label>
                        <Input
                            id="nombre"
                            name="nombre"
                            pill
                            disabled={isRolAdmin}
                            startIcon={<Tag className="size-4" />}
                            value={nombre}
                            onChange={(event) => setNombre(event.target.value)}
                        />
                        {isRolAdmin && (
                            <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">El rol de administrador no se puede renombrar.</p>
                        )}
                    </div>

                    <div>
                        <Label htmlFor="descripcion">Descripción</Label>
                        <Input
                            id="descripcion"
                            name="descripcion"
                            pill
                            value={descripcion}
                            onChange={(event) => setDescripcion(event.target.value)}
                        />
                    </div>
                </div>

                <div className="border-t border-gray-100 pt-8 dark:border-gray-800">
                    <PermissionMatrix
                        permisos={permisos}
                        isLoading={!permisosData}
                        isChecked={(permiso) => seleccionados.has(permiso.id)}
                        canChange={() => true}
                        onChange={togglePermisos}
                    />
                </div>

                <div className="flex justify-end border-t border-gray-100 pt-6 dark:border-gray-800">
                    <Button
                        type="submit"
                        disabled={isSubmitting}
                        startIcon={isSubmitting ? <Spinner size="sm" color="white" /> : undefined}
                        endIcon={isSubmitting ? undefined : <Save className="size-4" />}
                    >
                        {isSubmitting
                            ? isEditMode
                                ? 'Actualizando…'
                                : 'Creando…'
                            : isEditMode
                              ? 'Actualizar rol'
                              : 'Crear rol'}
                    </Button>
                </div>
            </div>
        </form>
    );
};
