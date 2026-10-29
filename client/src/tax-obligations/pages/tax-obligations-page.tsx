import type { DatesSetArg, EventClickArg, EventContentArg } from '@fullcalendar/core';
import esLocale from '@fullcalendar/core/locales/es';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin, { type DateClickArg } from '@fullcalendar/interaction';
import FullCalendar from '@fullcalendar/react';
import timeGridPlugin from '@fullcalendar/timegrid';
import type { FC } from 'react';
import { useState } from 'react';

import { usePermission } from '../../auth/hooks/use-permission';
import { useResource } from '../../shared/hooks/use-resource';
import { useSendRequest } from '../../shared/hooks/use-send-request';
import { Button } from '@ui/button/components/button';
import { Alert } from '@ui/alert/components/alert';
import { PageBreadcrumb } from '@ui/breadcrumb/components/page-breadcrumb';
import { ConfirmModal } from '@ui/modal/components/confirm-modal';
import { PageSkeleton } from '@ui/skeleton/components/page-skeleton';
import { Plus, Settings2 } from 'lucide-react';
import { TaxObligationEventModal } from '../components/tax-obligation-event-modal';
import { TaxObligationTypesModal } from '../components/tax-obligation-types-modal';
import { TAX_OBLIGATION_LEGEND_ITEMS } from '../constants/tax-obligation-legend-items';
import { buildTaxObligationEvents } from '../utils/build-tax-obligation-events';
import type { ApiTaxObligation, ApiTaxObligationColor } from '../types/api-tax-obligation';
import type { ApiTaxObligationResponsible } from '../types/api-tax-obligation-responsible';
import type { ApiTaxObligationType } from '../types/api-tax-obligation-type';
import type { TaxObligationEventProps } from '../types/api-tax-obligation-event';

function mesActualEnCurso(): string {
    const hoy = new Date();
    return `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}`;
}

export const TaxObligationsPage: FC = () => {
    const puedeConfigurar = usePermission('obligaciones_tributarias', 'configurar');
    const puedeCrear = usePermission('obligaciones_tributarias', 'crear');
    const puedeEditar = usePermission('obligaciones_tributarias', 'editar');
    const puedeCambiarEstado = usePermission('obligaciones_tributarias', 'cambiar_estado');
    const puedeEliminar = usePermission('obligaciones_tributarias', 'eliminar');
    const { sendRequest, isLoading: isDeleting } = useSendRequest();

    const [mes, setMes] = useState(mesActualEnCurso);
    const { data: respuesta, error, refetch } = useResource<{ data: ApiTaxObligation[] }>(`/obligaciones-tributarias?mes=${mes}`);
    const { data: tiposData, refetch: refetchTipos } = useResource<{ data: ApiTaxObligationType[] }>('/obligaciones-tributarias/tipos?solo_activos=true');
    const { data: responsablesData } = useResource<{ data: ApiTaxObligationResponsible[] }>('/obligaciones-tributarias/responsables-asignables');

    const tipos = tiposData?.data ?? [];
    const responsables = responsablesData?.data ?? [];

    /** Mismo motivo que en `calendar/pages/calendar-page.tsx`: no desmontar `<FullCalendar>` entre meses. */
    const [obligacionesVisibles, setObligacionesVisibles] = useState<ApiTaxObligation[] | null>(null);
    const [obligacionEnEdicion, setObligacionEnEdicion] = useState<ApiTaxObligation | null>(null);
    const [fechaNuevaObligacion, setFechaNuevaObligacion] = useState<string | null>(null);
    const [modalEventoAbierto, setModalEventoAbierto] = useState(false);
    const [modalTiposAbierto, setModalTiposAbierto] = useState(false);
    const [obligacionPorEliminar, setObligacionPorEliminar] = useState<ApiTaxObligation | null>(null);
    const [errorEliminar, setErrorEliminar] = useState<string | null>(null);

    if (respuesta && respuesta.data !== obligacionesVisibles) {
        setObligacionesVisibles(respuesta.data);
    }

    const handleDatesSet = (info: DatesSetArg) => {
        const inicio = info.view.currentStart;
        const nuevoMes = `${inicio.getFullYear()}-${String(inicio.getMonth() + 1).padStart(2, '0')}`;
        setMes((actual) => (actual === nuevoMes ? actual : nuevoMes));
    };

    const abrirNuevaObligacion = (fecha: string | null) => {
        setObligacionEnEdicion(null);
        setFechaNuevaObligacion(fecha);
        setModalEventoAbierto(true);
    };

    const handleDateClick = (info: DateClickArg) => {
        if (puedeCrear) {
            abrirNuevaObligacion(info.dateStr);
        }
    };

    const handleEventClick = (info: EventClickArg) => {
        const { obligacion } = info.event.extendedProps as TaxObligationEventProps;
        setObligacionEnEdicion(obligacion);
        setFechaNuevaObligacion(null);
        setModalEventoAbierto(true);
    };

    const handleSaved = () => {
        setModalEventoAbierto(false);
        refetch();
    };

    const handleDelete = (obligacion: ApiTaxObligation) => {
        setModalEventoAbierto(false);
        setObligacionPorEliminar(obligacion);
    };

    const confirmarEliminar = async () => {
        if (obligacionPorEliminar === null) {
            return;
        }

        setErrorEliminar(null);

        try {
            await sendRequest(`/obligaciones-tributarias/${obligacionPorEliminar.id}`, { method: 'DELETE' });
            refetch();
        } catch (caught) {
            setErrorEliminar(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setObligacionPorEliminar(null);
        }
    };

    const renderEventContent = (eventInfo: EventContentArg) => {
        const { color } = eventInfo.event.extendedProps as { color: ApiTaxObligationColor };

        return (
            <div className={`event-fc-color fc-event-main flex fc-bg-${color}`}>
                <div className="fc-daygrid-event-dot" />
                <div className="fc-event-title">{eventInfo.event.title}</div>
            </div>
        );
    };

    if (error) {
        return (
            <div className="rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400">
                {error}
            </div>
        );
    }

    if (!obligacionesVisibles) {
        return <PageSkeleton />;
    }

    const events = buildTaxObligationEvents(obligacionesVisibles);

    return (
        <div className="flex flex-col pb-6">
            <PageBreadcrumb pageTitle="Calendario Tributario" />

            <div className="mb-4 flex flex-wrap items-center justify-between gap-4">
                <div className="flex flex-wrap items-center gap-4">
                    {TAX_OBLIGATION_LEGEND_ITEMS.map((item) => (
                        <div key={item.color} className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                            <span className={`h-2.5 w-2.5 rounded-full ${item.dotClass}`} />
                            {item.label} ({obligacionesVisibles.filter((obligacion) => obligacion.color === item.color).length})
                        </div>
                    ))}
                </div>

                <div className="flex items-center gap-3">
                    {puedeConfigurar && (
                        <Button variant="outline" collapseBelow="md" startIcon={<Settings2 className="size-4" />} onClick={() => setModalTiposAbierto(true)}>
                            Tipos de obligación
                        </Button>
                    )}
                    {puedeCrear && (
                        <Button collapseBelow="md" startIcon={<Plus className="size-4" />} onClick={() => abrirNuevaObligacion(null)}>
                            Nueva obligación
                        </Button>
                    )}
                </div>
            </div>

            {errorEliminar && (
                <div className="mb-4">
                    <Alert variant="danger" title="No se pudo eliminar la obligación" message={errorEliminar} onDismiss={() => setErrorEliminar(null)} />
                </div>
            )}

            <div className="custom-calendar overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
                <FullCalendar
                    plugins={[dayGridPlugin, timeGridPlugin, interactionPlugin]}
                    initialView="dayGridMonth"
                    height={650}
                    expandRows
                    dayMaxEventRows
                    locale={esLocale}
                    headerToolbar={{
                        left: 'prev,next',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek,timeGridDay',
                    }}
                    events={events}
                    dateClick={handleDateClick}
                    eventClick={handleEventClick}
                    eventContent={renderEventContent}
                    datesSet={handleDatesSet}
                />
            </div>

            <TaxObligationEventModal
                isOpen={modalEventoAbierto}
                obligacion={obligacionEnEdicion}
                fechaInicial={fechaNuevaObligacion}
                tipos={tipos}
                responsables={responsables}
                canEdit={puedeEditar}
                canChangeStatus={puedeCambiarEstado}
                canDelete={puedeEliminar}
                onClose={() => setModalEventoAbierto(false)}
                onSaved={handleSaved}
                onDelete={handleDelete}
            />

            <ConfirmModal
                isOpen={obligacionPorEliminar !== null}
                onClose={() => setObligacionPorEliminar(null)}
                onConfirm={() => void confirmarEliminar()}
                variant="danger"
                title="Eliminar obligación tributaria"
                description={`La obligación ${obligacionPorEliminar?.tipo_obligacion.nombre ?? ''} del período ${obligacionPorEliminar?.periodo ?? ''} se eliminará del calendario.`}
                confirmLabel="Eliminar"
                isConfirming={isDeleting}
            />

            <TaxObligationTypesModal isOpen={modalTiposAbierto} onClose={() => setModalTiposAbierto(false)} onChanged={refetchTipos} />
        </div>
    );
};
