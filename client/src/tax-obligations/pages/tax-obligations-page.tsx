import esLocale from '@fullcalendar/react/locales/es';
import dayGridPlugin from '@fullcalendar/react/daygrid';
import interactionPlugin from '@fullcalendar/react/interaction';
import FullCalendar from '@fullcalendar/react';
import timeGridPlugin from '@fullcalendar/react/timegrid';
import type { DateClickInfo, DatesSetInfo, EventClickInfo, EventDisplayInfo } from '@fullcalendar/react';
import type { FC } from 'react';
import { useState } from 'react';

import { usePermission } from '../../auth/hooks/use-permission';
import { useResource } from '../../shared/hooks/use-resource';
import { Button } from '@ui/button/components/button';
import { PageBreadcrumb } from '@ui/breadcrumb/components/page-breadcrumb';
import { PageSkeleton } from '@ui/skeleton/components/page-skeleton';
import { Settings2 } from 'lucide-react';
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

    if (respuesta && respuesta.data !== obligacionesVisibles) {
        setObligacionesVisibles(respuesta.data);
    }

    const handleDatesSet = (info: DatesSetInfo) => {
        const inicio = info.view.currentStart;
        const nuevoMes = `${inicio.getFullYear()}-${String(inicio.getMonth() + 1).padStart(2, '0')}`;
        setMes((actual) => (actual === nuevoMes ? actual : nuevoMes));
    };

    const handleDateClick = (info: DateClickInfo) => {
        setObligacionEnEdicion(null);
        setFechaNuevaObligacion(info.dateStr);
        setModalEventoAbierto(true);
    };

    const handleEventClick = (info: EventClickInfo) => {
        const { obligacion } = info.event.extendedProps as TaxObligationEventProps;
        setObligacionEnEdicion(obligacion);
        setFechaNuevaObligacion(null);
        setModalEventoAbierto(true);
    };

    const handleSaved = () => {
        setModalEventoAbierto(false);
        refetch();
    };

    const renderEventContent = (eventInfo: EventDisplayInfo) => {
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

                {puedeConfigurar && (
                    <Button variant="outline" collapseBelow="md" startIcon={<Settings2 className="size-4" />} onClick={() => setModalTiposAbierto(true)}>
                        Tipos de obligación
                    </Button>
                )}
            </div>

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
                onClose={() => setModalEventoAbierto(false)}
                onSaved={handleSaved}
            />

            <TaxObligationTypesModal isOpen={modalTiposAbierto} onClose={() => setModalTiposAbierto(false)} onChanged={refetchTipos} />
        </div>
    );
};
