import type { DatesSetArg, EventClickArg, EventContentArg } from '@fullcalendar/core';
import esLocale from '@fullcalendar/core/locales/es';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import FullCalendar from '@fullcalendar/react';
import timeGridPlugin from '@fullcalendar/timegrid';
import type { FC } from 'react';
import { useState } from 'react';

import { useCalendarAutoResize } from '../../shared/hooks/use-calendar-auto-resize';
import { useResource } from '../../shared/hooks/use-resource';
import { PageBreadcrumb } from '@ui/breadcrumb/components/page-breadcrumb';
import { PageSkeleton } from '@ui/skeleton/components/page-skeleton';
import { CalendarFilters } from '../components/calendar-filters';
import { CalendarInstallmentModal } from '../components/calendar-installment-modal';
import { LEGEND_ITEMS } from '../constants/legend-items';
import { buildCalendarEvents } from '../utils/build-calendar-events';
import { filterInstallmentsByRange } from '../utils/filter-installments-by-range';
import type { ApiCalendarAgenda } from '../types/api-calendar-agenda';
import type { CalendarRange } from '../types/calendar-range';
import type { CalendarInstallmentColor } from '../types/api-calendar-installment';
import type { CalendarEventProps } from '../types/calendar-event';

function mesActualEnCurso(): string {
    const hoy = new Date();
    return `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}`;
}

export const CalendarPage: FC = () => {
    const [mes, setMes] = useState(mesActualEnCurso);
    const [rango, setRango] = useState<CalendarRange>('mes');
    const [empleadorId, setEmpleadorId] = useState<number | null>(null);
    const { calendarRef, containerRef } = useCalendarAutoResize();

    const parametros = new URLSearchParams({ mes });
    if (empleadorId) {
        parametros.set('empleador_id', String(empleadorId));
    }

    const { data: agenda, error } = useResource<ApiCalendarAgenda>(`/calendario?${parametros.toString()}`);

    /**
     * `useResource` pone `data` en `null` mientras carga el nuevo mes (para que otras
     * páginas puedan mostrar un spinner de recarga). Si `<FullCalendar>` se desmontara en
     * ese intervalo, al volver a montar perdería la navegación y volvería siempre al mes
     * de hoy — por eso el calendario se queda con la última agenda cargada (`agendaVisible`)
     * y sigue montado de forma continua; solo el spinner de la primera carga usa `agenda` directo.
     */
    const [agendaVisible, setAgendaVisible] = useState<ApiCalendarAgenda | null>(null);
    const [detalle, setDetalle] = useState<CalendarEventProps | null>(null);

    // Se ajusta durante el render (no en un efecto): React re-renderiza de inmediato sin pintar la agenda vieja.
    if (agenda && agenda !== agendaVisible) {
        setAgendaVisible(agenda);
    }

    const handleDatesSet = (info: DatesSetArg) => {
        const inicio = info.view.currentStart;
        const nuevoMes = `${inicio.getFullYear()}-${String(inicio.getMonth() + 1).padStart(2, '0')}`;
        setMes((actual) => (actual === nuevoMes ? actual : nuevoMes));
    };

    const handleEventClick = (info: EventClickArg) => {
        setDetalle(info.event.extendedProps as CalendarEventProps);
    };

    const renderEventContent = (eventInfo: EventContentArg) => {
        const { color } = eventInfo.event.extendedProps as { color: CalendarInstallmentColor };

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

    if (!agendaVisible) {
        return <PageSkeleton />;
    }

    const cuotasFiltradas = filterInstallmentsByRange(agendaVisible.cuotas, rango);
    const events = buildCalendarEvents(cuotasFiltradas);

    return (
        <div className="flex flex-col pb-6">
            <PageBreadcrumb pageTitle="Calendario" />

            <CalendarFilters
                rango={rango}
                onChangeRango={setRango}
                empleadores={agendaVisible.empleadores}
                empleadorId={empleadorId}
                onChangeEmpleadorId={setEmpleadorId}
            />

            <div className="mb-4 flex flex-wrap items-center gap-4">
                {LEGEND_ITEMS.map((item) => (
                    <div key={item.color} className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <span className={`h-2.5 w-2.5 rounded-full ${item.dotClass}`} />
                        {item.label} ({cuotasFiltradas.filter((cuota) => cuota.color === item.color).length})
                    </div>
                ))}
            </div>

            {/* Altura fija (no depende de un ancestro con altura acotada, `AppLayout` ya no
                provee eso a propósito) — con `expandRows` + `dayMaxEventRows` esto le alcanza
                a un mes de 6 filas sin necesitar scroll de página completa en pantallas típicas. */}
            <div ref={containerRef} className="custom-calendar overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/3">
                <FullCalendar
                    ref={calendarRef}
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
                    eventClick={handleEventClick}
                    eventContent={renderEventContent}
                    datesSet={handleDatesSet}
                />
            </div>

            <CalendarInstallmentModal detalle={detalle} onClose={() => setDetalle(null)} />
        </div>
    );
};
