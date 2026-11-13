import type { FC, SubmitEvent } from 'react';
import { useState } from 'react';

import { useAuth } from '../../auth/hooks/use-auth';
import { isCompanyAdmin } from '../../auth/utils/is-company-admin';
import { useResource } from '../../shared/hooks/use-resource';
import { useSendRequest } from '../../shared/hooks/use-send-request';
import type { PaginatedResponse } from '../../shared/types/paginated-response';
import type { ApiCompany } from '../../company/types/api-company';
import { Button } from '@ui/button/components/button';
import { Input } from '@ui/forms/components/input';
import { Label } from '@ui/forms/components/label';
import { Select } from '@ui/forms/components/select';
import { Spinner } from '@ui/spinner/components/spinner';
import { ModuleListPage } from '@ui/module/components/module-list-page';
import { DataTable } from '@ui/table/components/data-table';
import { ShieldUser } from 'lucide-react';
import type { ApiAccesoSoporte } from '../types/api-acceso-soporte';

const ESTADO_ETIQUETA: Record<ApiAccesoSoporte['estado'], string> = {
    solicitado: 'Solicitado',
    aprobado: 'Aprobado',
    activo: 'Activo',
    expirado: 'Expirado',
    revocado: 'Revocado',
    rechazado: 'Rechazado',
};

/**
 * Acceso de soporte just-in-time (Fase 4): el superadmin pide acceso de solo lectura a una empresa,
 * un administrador de esa empresa lo aprueba y el superadmin lo activa por un tiempo corto.
 * Sin acceso activo, el superadmin no ve datos de negocio de ninguna empresa.
 */
export const AccesosSoportePage: FC = () => {
    const { user } = useAuth();
    const { sendRequest } = useSendRequest();
    const { data, error, isLoading, refetch } = useResource<{ accesos: ApiAccesoSoporte[] }>('/accesos-soporte');
    const { data: empresasData } = useResource<PaginatedResponse<ApiCompany>>(
        user?.isSuperAdmin ? '/empresas?per_page=100' : null,
    );

    const [empresaId, setEmpresaId] = useState('');
    const [motivo, setMotivo] = useState('');
    const [duracion, setDuracion] = useState('30');
    const [motivoRechazo, setMotivoRechazo] = useState('');
    const [accesoARechazar, setAccesoARechazar] = useState<number | null>(null);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const accesos = data?.accesos ?? [];
    const empresas = empresasData?.data ?? [];
    const esAdminEmpresa = isCompanyAdmin(user);

    const ejecutar = async (accesoId: number, accion: 'aprobar' | 'activar' | 'revocar' | 'rechazar', body?: Record<string, unknown>) => {
        setErrorMessage(null);

        try {
            await sendRequest(`/accesos-soporte/${accesoId}/${accion}`, { method: 'POST', body });
            refetch();
        } catch (caught) {
            setErrorMessage(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        }
    };

    const solicitar = async (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        setErrorMessage(null);

        if (!empresaId || !motivo.trim()) {
            setErrorMessage('Elige la empresa e indica el motivo del acceso.');
            return;
        }

        setIsSubmitting(true);

        try {
            await sendRequest('/accesos-soporte', {
                method: 'POST',
                body: { empresa_id: Number(empresaId), motivo: motivo.trim(), duracion_minutos: Number(duracion) },
            });
            setMotivo('');
            setEmpresaId('');
            refetch();
        } catch (caught) {
            setErrorMessage(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <ModuleListPage
            pageTitle="Acceso de soporte"
            errorMessage={errorMessage ?? error}
            onDismissError={() => setErrorMessage(null)}
            countLabel={`${accesos.length} ${accesos.length === 1 ? 'acceso' : 'accesos'}`}
        >
            <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
                El superadministrador no ve datos de ninguna empresa por defecto. Para revisar un caso, solicita acceso de
                solo lectura a una empresa; un administrador de esa empresa lo aprueba y el acceso vence solo (máximo 2 horas).
            </p>

            {user?.isSuperAdmin && (
                <form onSubmit={solicitar} className="mb-8 space-y-4 rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-white/3">
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div>
                            <Label htmlFor="empresa-acceso">Empresa</Label>
                            <Select
                                id="empresa-acceso"
                                options={empresas.map((empresa) => ({ value: String(empresa.id), label: empresa.nombre_empresa }))}
                                placeholder="Selecciona una empresa…"
                                onChange={setEmpresaId}
                            />
                        </div>
                        <div>
                            <Label htmlFor="motivo-acceso">Motivo</Label>
                            <Input id="motivo-acceso" name="motivo-acceso" pill value={motivo} onChange={(event) => setMotivo(event.target.value)} placeholder="Por qué necesitas entrar…" />
                        </div>
                        <div>
                            <Label htmlFor="duracion-acceso">Duración (minutos)</Label>
                            <Select
                                id="duracion-acceso"
                                options={[
                                    { value: '15', label: '15 minutos' },
                                    { value: '30', label: '30 minutos' },
                                    { value: '60', label: '1 hora' },
                                    { value: '120', label: '2 horas' },
                                ]}
                                defaultValue="30"
                                onChange={setDuracion}
                            />
                        </div>
                    </div>
                    <div className="flex justify-end">
                        <Button type="submit" disabled={isSubmitting} startIcon={isSubmitting ? <Spinner size="sm" color="white" /> : <ShieldUser className="size-4" />}>
                            {isSubmitting ? 'Solicitando…' : 'Solicitar acceso'}
                        </Button>
                    </div>
                </form>
            )}

            <DataTable
                columns={[
                    { key: 'empresa', label: 'Empresa', render: (acceso) => acceso.empresa?.nombre ?? `Empresa #${acceso.empresa_id}` },
                    { key: 'superadmin', label: 'Solicitado por', render: (acceso) => acceso.superadmin?.usuario ?? `#${acceso.superadmin_id}` },
                    { key: 'motivo', label: 'Motivo', render: (acceso) => acceso.motivo },
                    { key: 'estado', label: 'Estado', render: (acceso) => ESTADO_ETIQUETA[acceso.estado] },
                    {
                        key: 'vigencia',
                        label: 'Vigencia',
                        render: (acceso) => (acceso.estado === 'activo' && acceso.expira_en ? `Vence ${new Date(acceso.expira_en).toLocaleString()}` : `${acceso.duracion_minutos} min`),
                    },
                    {
                        key: 'acciones',
                        label: 'Acciones',
                        render: (acceso) => (
                            <div className="flex flex-wrap gap-2">
                                {esAdminEmpresa && acceso.estado === 'solicitado' && (
                                    <>
                                        <Button type="button" size="sm" onClick={() => void ejecutar(acceso.id, 'aprobar')}>
                                            Aprobar
                                        </Button>
                                        <Button type="button" size="sm" variant="outline" onClick={() => setAccesoARechazar(acceso.id)}>
                                            Rechazar
                                        </Button>
                                    </>
                                )}
                                {user?.isSuperAdmin && acceso.estado === 'aprobado' && (
                                    <Button type="button" size="sm" onClick={() => void ejecutar(acceso.id, 'activar')}>
                                        Activar
                                    </Button>
                                )}
                                {(user?.isSuperAdmin || esAdminEmpresa) && ['solicitado', 'aprobado', 'activo'].includes(acceso.estado) && (
                                    <Button type="button" size="sm" variant="outline" onClick={() => void ejecutar(acceso.id, 'revocar')}>
                                        Revocar
                                    </Button>
                                )}
                            </div>
                        ),
                    },
                ]}
                rows={accesos}
                getRowKey={(acceso) => acceso.id}
                loading={isLoading}
                emptyMessage="No hay accesos de soporte."
            />

            {accesoARechazar !== null && (
                <form
                    className="mt-4 space-y-3 rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-800 dark:bg-white/3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (!motivoRechazo.trim()) {
                            setErrorMessage('Indica el motivo del rechazo.');
                            return;
                        }
                        void ejecutar(accesoARechazar, 'rechazar', { motivo: motivoRechazo.trim() }).then(() => {
                            setAccesoARechazar(null);
                            setMotivoRechazo('');
                        });
                    }}
                >
                    <Label htmlFor="motivo-rechazo">Motivo del rechazo</Label>
                    <Input id="motivo-rechazo" name="motivo-rechazo" pill value={motivoRechazo} onChange={(event) => setMotivoRechazo(event.target.value)} />
                    <div className="flex gap-2">
                        <Button type="submit">Rechazar acceso</Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setAccesoARechazar(null);
                                setMotivoRechazo('');
                            }}
                        >
                            Cancelar
                        </Button>
                    </div>
                </form>
            )}
        </ModuleListPage>
    );
};
