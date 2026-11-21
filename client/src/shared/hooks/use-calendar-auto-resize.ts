import type FullCalendar from '@fullcalendar/react';
import { useCallback, useRef } from 'react';

/**
 * FullCalendar solo recalcula su ancho con el resize de la ventana; colapsar el sidebar
 * cambia el ancho del contenedor sin tocar la ventana, así que se observa el contenedor.
 */
export function useCalendarAutoResize() {
    const calendarRef = useRef<FullCalendar>(null);

    const containerRef = useCallback((contenedor: HTMLDivElement | null) => {
        if (!contenedor) {
            return;
        }

        let frame = 0;
        const observer = new ResizeObserver(() => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => calendarRef.current?.getApi().updateSize());
        });
        observer.observe(contenedor);

        return () => {
            cancelAnimationFrame(frame);
            observer.disconnect();
        };
    }, []);

    return { calendarRef, containerRef };
}
