import type { FC } from 'react';
import { useState } from 'react';
import { useNavigate } from 'react-router';
import { Pencil, Settings, Trash2 } from 'lucide-react';

import { useResource } from '../../shared/hooks/use-resource';
import { useSendRequest } from '../../shared/hooks/use-send-request';
import { usePermission } from '../../auth/hooks/use-permission';
import { ActionButton } from '@ui/action-button/components/action-button';
import { Alert } from '@ui/alert/components/alert';
import { Button } from '@ui/button/components/button';
import { PageBreadcrumb } from '@ui/breadcrumb/components/page-breadcrumb';
import { Label } from '@ui/forms/components/label';
import { Select } from '@ui/forms/components/select';
import { ConfirmModal } from '@ui/modal/components/confirm-modal';
import { SkeletonStatus } from '@ui/skeleton/components/skeleton-status';
import type { ApiAdvisor } from '../../clients/types/api-advisor';
import { GoalAdvisorsTable } from '../components/goal-advisors-table';
import { GoalAlertsList } from '../components/goal-alerts-list';
import { GoalClosingsTable } from '../components/goal-closings-table';
import { GoalCreateForm } from '../components/goal-create-form';
import { EditGoalModal } from '../components/edit-goal-modal';
import { GoalDistributionForm } from '../components/goal-distribution-form';
import { GoalProgressCard } from '../components/goal-progress-card';
import { GoalProgressCardSkeleton } from '../components/goal-progress-card-skeleton';
import { GoalRecalcForm } from '../components/goal-recalc-form';
import { GOAL_TYPE_LABELS } from '../constants/goal-status';
import type { ApiGoalAlert } from '../types/api-goal-alert';
import type { ApiGoalClosing } from '../types/api-goal-closing';
import type { ApiGoal, ApiGoalType, ApiGoalsPeriod } from '../types/api-goals-period';
import { opcionesDePeriodo, periodoActual } from '../utils/periodo-meta';

const TIPOS: ApiGoalType[] = ['colocacion', 'recaudo'];

export const GoalsPage: FC = () => {
    const navigate = useNavigate();
    const { sendRequest, isLoading: isDeleting } = useSendRequest();

    const [periodo, setPeriodo] = useState(periodoActual());
    const [markingId, setMarkingId] = useState<number | null>(null);
    const [mensaje, setMensaje] = useState<string | null>(null);
    const [metaEnEdicion, setMetaEnEdicion] = useState<ApiGoal | null>(null);
    const [metaPorEliminar, setMetaPorEliminar] = useState<ApiGoal | null>(null);

    const puedeConfigurar = usePermission('metas', 'configurar');
    const puedeRecalcular = usePermission('metas', 'recalcular');
    const puedeEliminar = usePermission('metas', 'eliminar');

    const { data: resumen, error, refetch } = useResource<ApiGoalsPeriod>(`/metas?periodo=${periodo}`);
    const { data: alertas, refetch: recargarAlertas } = useResource<{ alertas: ApiGoalAlert[] }>('/metas/alertas');

    const tieneCerradas = resumen?.metas.some((meta) => meta.cerrado) ?? false;
    const { data: cierres, refetch: recargarCierres } = useResource<{ cierres: ApiGoalClosing[] }>(
        tieneCerradas ? `/metas/cierres?periodo=${periodo}` : null,
    );
    const { data: asignables } = useResource<{ asesores: ApiAdvisor[] }>(puedeConfigurar ? '/clientes/asesores-asignables' : null);

    const tiposCreados = resumen?.metas.map((meta) => meta.tipo) ?? [];
    const tiposDisponibles = TIPOS.filter((tipo) => !tiposCreados.includes(tipo));

    const recargarTodo = () => {
        refetch();
        recargarCierres();
        recargarAlertas();
        setMensaje(null);
    };

    const marcarVista = async (alertaId: number) => {
        setMarkingId(alertaId);

        try {
            await sendRequest(`/metas/alertas/${alertaId}/vista`, { method: 'POST' });
            recargarAlertas();
        } catch (caught) {
            setMensaje(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setMarkingId(null);
        }
    };

    const confirmarEliminar = async () => {
        if (metaPorEliminar === null) {
            return;
        }

        try {
            await sendRequest(`/metas/${metaPorEliminar.id}`, { method: 'DELETE' });
            recargarTodo();
        } catch (caught) {
            setMensaje(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setMetaPorEliminar(null);
        }
    };

    if (error) {
        return (
            <div className="rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400">
                {error}
            </div>
        );
    }

    return (
        <div className="flex min-h-full flex-col pb-6">
            <PageBreadcrumb pageTitle="Metas" />

            <div className="mb-6 flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                <div className="w-full md:max-w-xs">
                    <Label htmlFor="metas-periodo">Período</Label>
                    <Select id="metas-periodo" options={opcionesDePeriodo()} defaultValue={periodo} onChange={setPeriodo} />
                </div>

                {puedeConfigurar && (
                    <Button size="sm" variant="outline" startIcon={<Settings className="size-4" />} onClick={() => navigate('/metas/configuracion')}>
                        Parámetros de metas
                    </Button>
                )}
            </div>

            {mensaje && <Alert variant="danger" title="No se pudo completar la acción" message={mensaje} onDismiss={() => setMensaje(null)} />}

            {!resumen ? (
                <SkeletonStatus className="grid grid-cols-1 gap-4 md:grid-cols-2 md:gap-6">
                    <GoalProgressCardSkeleton />
                    <GoalProgressCardSkeleton />
                </SkeletonStatus>
            ) : (
                <div className="space-y-6">
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 md:gap-6">
                        {TIPOS.map((tipo) => {
                            const meta = resumen.metas.find((candidata) => candidata.tipo === tipo);

                            if (!meta) {
                                return (
                                    <div key={tipo} className="rounded-2xl border border-dashed border-gray-300 p-5 text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400 md:p-6">
                                        Sin meta de {GOAL_TYPE_LABELS[tipo].toLowerCase()} para {periodo}.
                                    </div>
                                );
                            }

                            return <GoalProgressCard key={tipo} tipo={tipo} meta={meta} />;
                        })}
                    </div>

                    {puedeConfigurar && tiposDisponibles.length > 0 && (
                        <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                            <h2 className="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">Crear meta del período</h2>
                            <GoalCreateForm periodo={periodo} tiposDisponibles={tiposDisponibles} onCreated={recargarTodo} />
                        </section>
                    )}

                    {resumen.metas.map((meta) => (
                        <section key={meta.id} className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                            <div className="mb-4 flex items-center justify-between gap-3">
                                <h2 className="text-lg font-semibold text-gray-800 dark:text-white/90">Asesores · {GOAL_TYPE_LABELS[meta.tipo]}</h2>
                                {!meta.cerrado && (puedeConfigurar || puedeEliminar) && (
                                    <div className="flex shrink-0 items-center gap-3">
                                        {puedeConfigurar && (
                                            <ActionButton icon={<Pencil className="size-5" />} label="Editar meta" onClick={() => setMetaEnEdicion(meta)} />
                                        )}
                                        {puedeEliminar && (
                                            <ActionButton icon={<Trash2 className="size-5" />} label="Eliminar meta" variant="danger" onClick={() => setMetaPorEliminar(meta)} />
                                        )}
                                    </div>
                                )}
                            </div>

                            {resumen.ver_detalle ? (
                                <div className="space-y-6">
                                    <GoalAdvisorsTable asesores={meta.asesores} />

                                    {puedeConfigurar && !meta.cerrado && (
                                        <div className="border-t border-gray-100 pt-6 dark:border-gray-800">
                                            <h3 className="mb-4 text-sm font-semibold text-gray-800 dark:text-white/90">Distribuir la meta</h3>
                                            <GoalDistributionForm meta={meta} asesores={asignables?.asesores ?? []} onSaved={recargarTodo} />
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <p className="text-sm text-gray-500 dark:text-gray-400">Solo ves tu propia parte de esta meta.</p>
                            )}
                        </section>
                    ))}

                    <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                        <h2 className="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">Alertas</h2>
                        <GoalAlertsList
                            alertas={alertas?.alertas ?? []}
                            emptyMessage="Sin alertas de metas."
                            onMarkSeen={marcarVista}
                            markingId={markingId}
                        />
                    </section>

                    {tieneCerradas && (
                        <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                            <h2 className="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">Histórico del período</h2>

                            <div className="overflow-hidden">
                                <GoalClosingsTable cierres={cierres?.cierres ?? []} />
                            </div>

                            {puedeRecalcular && (
                                <div className="mt-6 space-y-6 border-t border-gray-100 pt-6 dark:border-gray-800">
                                    {resumen.metas
                                        .filter((meta) => meta.cerrado)
                                        .map((meta) => (
                                            <div key={meta.id}>
                                                <h3 className="mb-3 text-sm font-semibold text-gray-800 dark:text-white/90">
                                                    Recalcular {GOAL_TYPE_LABELS[meta.tipo].toLowerCase()} de {periodo}
                                                </h3>
                                                <GoalRecalcForm periodo={periodo} tipo={meta.tipo} onRecalculated={recargarTodo} />
                                            </div>
                                        ))}
                                </div>
                            )}
                        </section>
                    )}
                </div>
            )}

            {metaEnEdicion && (
                <EditGoalModal
                    meta={metaEnEdicion}
                    periodo={periodo}
                    onClose={() => setMetaEnEdicion(null)}
                    onSaved={() => {
                        setMetaEnEdicion(null);
                        recargarTodo();
                    }}
                />
            )}

            <ConfirmModal
                isOpen={metaPorEliminar !== null}
                onClose={() => setMetaPorEliminar(null)}
                onConfirm={() => void confirmarEliminar()}
                variant="danger"
                title="Eliminar meta"
                description={`La meta de ${metaPorEliminar ? GOAL_TYPE_LABELS[metaPorEliminar.tipo].toLowerCase() : ''} del período ${periodo} se eliminará junto con su distribución por asesor.`}
                confirmLabel="Eliminar"
                isConfirming={isDeleting}
            />
        </div>
    );
};
