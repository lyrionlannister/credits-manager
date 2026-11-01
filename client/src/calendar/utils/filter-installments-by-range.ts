import type { ApiCalendarInstallment } from '../types/api-calendar-installment';
import type { CalendarRange } from '../types/calendar-range';

function toIsoDate(fecha: Date): string {
    return `${fecha.getFullYear()}-${String(fecha.getMonth() + 1).padStart(2, '0')}-${String(fecha.getDate()).padStart(2, '0')}`;
}

/** Lunes a domingo de la semana que contiene `fecha`. */
function semanaDe(fecha: Date): { desde: string; hasta: string } {
    const diasDesdeLunes = (fecha.getDay() + 6) % 7;
    const lunes = new Date(fecha.getFullYear(), fecha.getMonth(), fecha.getDate() - diasDesdeLunes);
    const domingo = new Date(lunes.getFullYear(), lunes.getMonth(), lunes.getDate() + 6);

    return { desde: toIsoDate(lunes), hasta: toIsoDate(domingo) };
}

/** Filtra sin tocar el mes que el calendario tiene abierto: "hoy"/"esta semana" son siempre relativos a la fecha real. */
export function filterInstallmentsByRange(cuotas: ApiCalendarInstallment[], rango: CalendarRange): ApiCalendarInstallment[] {
    if (rango === 'mes') {
        return cuotas;
    }

    const hoy = toIsoDate(new Date());

    if (rango === 'hoy') {
        return cuotas.filter((cuota) => cuota.fecha_vencimiento === hoy);
    }

    const { desde, hasta } = semanaDe(new Date());

    return cuotas.filter((cuota) => cuota.fecha_vencimiento >= desde && cuota.fecha_vencimiento <= hasta);
}
