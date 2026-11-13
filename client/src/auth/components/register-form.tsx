import type { FC, SubmitEvent } from 'react';
import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router';

import { useAuth } from '../hooks/use-auth';
import { useCompany } from '../../company/hooks/use-company';
import { useResource } from '../../shared/hooks/use-resource';
import { useSendRequest } from '../../shared/hooks/use-send-request';
import { Button } from '@ui/button/components/button';
import { Input } from '@ui/forms/components/input';
import { Label } from '@ui/forms/components/label';
import { Select } from '@ui/forms/components/select';
import { ToggleSwitchInput } from '@ui/forms/components/toggle-switch-input';
import { Spinner } from '@ui/spinner/components/spinner';
import { KeyRound, RotateCcw, User, Save } from 'lucide-react';
import { PermissionMatrix } from './permission-matrix';
import { PERMISSION_ORIGIN_BADGES } from '../constants/permission-origin-badges';
import type { RegisterFormProps } from '../props/register-form-props';
import type { ApiPermisoCatalogItem, ApiPermisoPanelItem } from '../types/api-permiso';
import type { ApiRoleWithPermisos } from '../types/api-role';
import type { PermissionOrigin } from '../types/permission-origin';
import type { PaginatedResponse } from '../../shared/types/paginated-response';
import { hasPermission } from '../utils/has-permission';
import { isCompanyAdmin } from '../utils/is-company-admin';

const capitalize = (value: string) => value.charAt(0).toUpperCase() + value.slice(1);

function buildBaseline(rol: ApiRoleWithPermisos | undefined, permisos: ApiPermisoCatalogItem[]): Record<number, boolean> {
    const idsDelRol = new Set(rol?.permisos.map((permiso) => permiso.id) ?? []);
    return Object.fromEntries(permisos.map((permiso) => [permiso.id, idsDelRol.has(permiso.id)]));
}

export const RegisterForm: FC<RegisterFormProps> = ({ userId, initialData }) => {
    const navigate = useNavigate();
    const { sendRequest } = useSendRequest();
    const { user: currentUser, refreshUser } = useAuth();
    const { company } = useCompany();
    const { data: rolesData } = useResource<PaginatedResponse<ApiRoleWithPermisos>>('/roles?per_page=100');
    const { data: permisosData } = useResource<{ permisos: ApiPermisoPanelItem[] }>('/permisos');
    const roles = rolesData?.data ?? [];
    const permisos = permisosData?.permisos ?? [];

    const isEditMode = userId !== undefined;

    const [name, setName] = useState(initialData?.nombre ?? '');
    const [username, setUsername] = useState(initialData?.usuario ?? '');
    const [password, setPassword] = useState('');
    const [confirmPassword, setConfirmPassword] = useState('');
    const [roleId, setRoleId] = useState<number | null>(initialData?.rol?.id ?? null);
    const [overrides, setOverrides] = useState<Record<number, boolean>>({});
    const [active, setActive] = useState(initialData?.activo ?? true);
    // Fase 2: las excepciones son temporales y visibles — motivo obligatorio y vencimiento opcional (máx. 90 días).
    const [motivo, setMotivo] = useState(initialData?.permisos_extra[0]?.pivot.motivo ?? '');
    const [venceEn, setVenceEn] = useState(initialData?.permisos_extra[0]?.pivot.vence_en ?? '');
    const [isGlobal, setIsGlobal] = useState(initialData?.es_asesor_global ?? false);
    const [error, setError] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const hasInitializedOverrides = useRef(false);
    const isAdvisorRole = roles.find((rol) => rol.id === roleId)?.nombre === 'asesor';
    const isAdvisorGlobal = isAdvisorRole && isGlobal;

    useEffect(() => {
        if (hasInitializedOverrides.current || roles.length === 0 || permisos.length === 0) {
            return;
        }

        hasInitializedOverrides.current = true;

        const rolSeleccionado = roles.find((rol) => rol.id === roleId);
        const base = buildBaseline(rolSeleccionado, permisos);

        if (initialData) {
            for (const excepcion of initialData.permisos_extra) {
                const permiso = permisos.find((item) => item.modulo === excepcion.modulo && item.accion === excepcion.accion);
                if (permiso) {
                    base[permiso.id] = excepcion.pivot.concedido;
                }
            }
        }

        setOverrides(base);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [roles, permisos]);

    const handleRoleChange = (value: string) => {
        const nuevoRolId = Number(value);
        setRoleId(nuevoRolId);
        setOverrides(buildBaseline(roles.find((rol) => rol.id === nuevoRolId), permisos));
    };

    const togglePermisos = (permisoIds: number[], isChecked: boolean) => {
        setOverrides((current) => ({ ...current, ...Object.fromEntries(permisoIds.map((permisoId) => [permisoId, isChecked])) }));
    };

    // Espejo de la regla del backend: quien no es admin no concede, por rol nuevo o excepción, lo que él no tiene.
    const canGrant = (permiso: ApiPermisoCatalogItem) => hasPermission(currentUser, permiso.modulo, permiso.accion);
    const isAssignableRole = (rol: ApiRoleWithPermisos) =>
        isCompanyAdmin(currentUser) || rol.id === initialData?.rol?.id || (rol.nombre !== 'admin' && rol.permisos.every(canGrant));
    const currentBaseline = buildBaseline(roles.find((rol) => rol.id === roleId), permisos);

    // Lo que el rol ya da o quita se puede volver a marcar o desmarcar; agregar algo nuevo exige poder concederlo.
    const canChange = (permiso: ApiPermisoCatalogItem) => canGrant(permiso) || Boolean(overrides[permiso.id]) || Boolean(currentBaseline[permiso.id]);
    const originOf = (permiso: ApiPermisoCatalogItem): PermissionOrigin | null => {
        const marcado = overrides[permiso.id] ?? false;
        const delRol = currentBaseline[permiso.id] ?? false;

        if (marcado) {
            return delRol ? 'rol' : 'extra';
        }

        return delRol ? 'quitado' : null;
    };
    const conteoOrigen = permisos.reduce<Record<PermissionOrigin, number>>(
        (conteo, permiso) => {
            const origen = originOf(permiso);
            return origen ? { ...conteo, [origen]: conteo[origen] + 1 } : conteo;
        },
        { rol: 0, extra: 0, quitado: 0 },
    );
    const tieneExcepciones = conteoOrigen.extra + conteoOrigen.quitado > 0;

    const roleOptions = roles.filter(isAssignableRole).map((rol) => ({ value: String(rol.id), label: capitalize(rol.nombre) }));

    const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError(null);

        if (!isEditMode && password !== confirmPassword) {
            setError('Las contraseñas no coinciden.');
            return;
        }

        if (!roleId) {
            setError('Selecciona un rol.');
            return;
        }

        const baselineParaValidar = buildBaseline(roles.find((rol) => rol.id === roleId), permisos);
        const hayExcepciones = permisos.some(
            (permiso) => (overrides[permiso.id] ?? false) !== (baselineParaValidar[permiso.id] ?? false),
        );

        if (hayExcepciones && !motivo.trim()) {
            setError('Indica el motivo de las excepciones de permisos de este usuario.');
            return;
        }

        if (venceEn) {
            const limite = new Date();
            limite.setDate(limite.getDate() + 90);
            if (new Date(`${venceEn}T00:00:00`) > limite) {
                setError('El vencimiento de las excepciones no puede superar los 90 días.');
                return;
            }
        }

        setIsSubmitting(true);

        try {
            const baseline = buildBaseline(
                roles.find((rol) => rol.id === roleId),
                permisos,
            );

            const permisosExtra = permisos
                .filter((permiso) => (overrides[permiso.id] ?? false) !== (baseline[permiso.id] ?? false))
                .map((permiso) => ({
                    permiso_id: permiso.id,
                    concedido: overrides[permiso.id] ?? false,
                    motivo: motivo.trim(),
                    vence_en: venceEn || null,
                }));

            if (isEditMode) {
                await sendRequest(`/usuarios/${userId}`, {
                    method: 'PATCH',
                    body: { nombre: name, usuario: username, rol_id: roleId, activo: active, es_asesor_global: isAdvisorGlobal, permisos_extra: permisosExtra },
                });
            } else {
                await sendRequest('/usuarios', {
                    method: 'POST',
                    body: {
                        nombre: name,
                        usuario: username,
                        clave: password,
                        rol_id: roleId,
                        activo: active,
                        es_asesor_global: isAdvisorGlobal,
                        permisos_extra: permisosExtra,
                    },
                });
            }

            // Fase 3: la sesión propia se vuelve a pedir tras guardar (por si el usuario editado es uno mismo).
            await refreshUser();

            navigate('/usuarios', {
                replace: true,
                state: { message: isEditMode ? 'Usuario actualizado correctamente.' : 'Usuario creado correctamente.' },
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
                        {`Los permisos y excepciones de este usuario aplican solo en ${company.name}. Si el usuario trabaja en otra empresa, tiene otra cuenta con sus propios permisos.`}
                    </p>
                )}

                {error && (
                    <div className="rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400">
                        {error}
                    </div>
                )}

                <div className="flex flex-col gap-8 xl:grid xl:grid-cols-2 xl:items-start xl:gap-x-12">
                    <section>
                        <h3 className="mb-4 text-xs font-semibold tracking-wide text-gray-400 uppercase dark:text-gray-500">
                            Datos de la cuenta
                        </h3>
                        <div className="space-y-6">
                            <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <Label htmlFor="name">Nombre</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        autoComplete="name"
                                        pill
                                        startIcon={<User className="size-4" />}
                                        value={name}
                                        onChange={(event) => setName(event.target.value)}
                                    />
                                </div>

                                <div>
                                    <Label htmlFor="username">Usuario</Label>
                                    <Input
                                        id="username"
                                        name="username"
                                        autoComplete="off"
                                        pill
                                        startIcon={<User className="size-4" />}
                                        value={username}
                                        onChange={(event) => setUsername(event.target.value)}
                                    />
                                </div>
                            </div>

                            {!isEditMode && (
                                <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                                    <div>
                                        <Label htmlFor="password">Contraseña</Label>
                                        <Input
                                            id="password"
                                            name="password"
                                            type="password"
                                            autoComplete="new-password"
                                            pill
                                            startIcon={<KeyRound className="size-4" />}
                                            minLength={8}
                                            hint="Mínimo 8 caracteres."
                                            value={password}
                                            onChange={(event) => setPassword(event.target.value)}
                                        />
                                    </div>

                                    <div>
                                        <Label htmlFor="confirm-password">Confirmar contraseña</Label>
                                        <Input
                                            id="confirm-password"
                                            name="confirm-password"
                                            type="password"
                                            autoComplete="new-password"
                                            pill
                                            startIcon={<KeyRound className="size-4" />}
                                            minLength={8}
                                            value={confirmPassword}
                                            onChange={(event) => setConfirmPassword(event.target.value)}
                                        />
                                    </div>
                                </div>
                            )}

                            <div>
                                <Label htmlFor="activo">Estado</Label>
                                <div className="flex h-11 items-center">
                                    <ToggleSwitchInput
                                        id="activo"
                                        label={active ? 'Activo' : 'Inactivo'}
                                        checked={active}
                                        onChange={setActive}
                                    />
                                </div>
                            </div>

                            <div className="md:w-1/2 md:pr-3">
                                <Label htmlFor="role">Rol</Label>
                                <Select
                                    id="role"
                                    options={roleOptions}
                                    defaultValue={roleId !== null ? String(roleId) : undefined}
                                    placeholder="Selecciona un rol…"
                                    onChange={handleRoleChange}
                                />
                                <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    Los permisos base del usuario vienen de este rol.
                                </p>
                            </div>

                            {isAdvisorRole && (
                                <div>
                                    <Label htmlFor="es-global">Alcance</Label>
                                    <div className="flex h-11 items-center">
                                        <ToggleSwitchInput
                                            id="es-global"
                                            label={isGlobal ? 'Ve todos los clientes' : 'Solo sus clientes asignados'}
                                            checked={isGlobal}
                                            onChange={setIsGlobal}
                                        />
                                    </div>
                                    <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                        Con el alcance global el asesor ve los clientes y créditos de toda la empresa; si no, solo los suyos.
                                    </p>
                                </div>
                            )}
                        </div>
                    </section>

                    <section className="border-t border-gray-100 pt-8 xl:border-t-0 xl:border-l xl:pt-0 xl:pl-12 dark:border-gray-800">
                        <PermissionMatrix
                            permisos={permisos}
                            isLoading={!permisosData || !rolesData}
                            isChecked={(permiso) => overrides[permiso.id] ?? false}
                            canChange={canChange}
                            onChange={togglePermisos}
                            getOrigin={originOf}
                            summary={
                                <div className="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-white/3">
                                    <p className="text-sm text-gray-500 dark:text-gray-400">
                                        {roleId
                                            ? 'Los permisos marcados con «Rol» vienen del rol elegido. Puedes darle permisos extra o quitarle alguno puntual a este usuario sin cambiar su rol.'
                                            : 'Elige un rol: sus permisos se marcan aquí y luego puedes ajustar excepciones para este usuario.'}
                                    </p>
                                    <div className="mt-3 flex flex-wrap items-center gap-2">
                                        {(['rol', 'extra', 'quitado'] as const).map((origen) => (
                                            <span key={origen} className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${PERMISSION_ORIGIN_BADGES[origen].className}`}>
                                                {`${PERMISSION_ORIGIN_BADGES[origen].label}: ${conteoOrigen[origen]}`}
                                            </span>
                                        ))}
                                        {tieneExcepciones && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                className="sm:ml-auto"
                                                startIcon={<RotateCcw className="size-4" />}
                                                onClick={() => setOverrides(currentBaseline)}
                                            >
                                                Restablecer al rol
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            }
                        />
                        {tieneExcepciones && (
                            <div className="mt-6 space-y-4 rounded-2xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/50 dark:bg-amber-500/10">
                                <p className="text-sm font-medium text-amber-800 dark:text-amber-300">
                                    Excepciones temporales: vencen a los 30 días si no indicas fecha (máximo 90 días).
                                </p>
                                <div>
                                    <Label htmlFor="motivo-excepciones">Motivo de las excepciones *</Label>
                                    <Input
                                        id="motivo-excepciones"
                                        name="motivo-excepciones"
                                        pill
                                        value={motivo}
                                        onChange={(event) => setMotivo(event.target.value)}
                                        placeholder="Por qué este usuario necesita estos permisos…"
                                    />
                                </div>
                                <div className="md:w-1/2">
                                    <Label htmlFor="vence-excepciones">Vencimiento (opcional)</Label>
                                    <Input
                                        id="vence-excepciones"
                                        name="vence-excepciones"
                                        type="date"
                                        pill
                                        value={venceEn}
                                        onChange={(event) => setVenceEn(event.target.value)}
                                    />
                                </div>
                                {initialData && initialData.permisos_extra.length > 0 && (
                                    <ul className="space-y-1 text-xs text-gray-600 dark:text-gray-400">
                                        {initialData.permisos_extra.map((excepcion) => (
                                            <li key={`${excepcion.modulo}.${excepcion.accion}`}>
                                                {`${excepcion.modulo}.${excepcion.accion} — ${excepcion.pivot.concedido ? 'concedido' : 'revocado'}`}
                                                {excepcion.pivot.motivo ? ` · ${excepcion.pivot.motivo}` : ''}
                                                {excepcion.pivot.vence_en ? ` · vence ${excepcion.pivot.vence_en}` : ' · sin vencimiento'}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}
                    </section>
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
                              ? 'Actualizar usuario'
                              : 'Crear usuario'}
                    </Button>
                </div>
            </div>
        </form>
    );
};
