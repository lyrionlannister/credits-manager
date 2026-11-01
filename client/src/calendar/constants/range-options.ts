import type { CalendarRange } from '../types/calendar-range';

export const RANGE_OPTIONS: { value: CalendarRange; label: string }[] = [
    { value: 'mes', label: 'Todo el mes' },
    { value: 'hoy', label: 'Hoy' },
    { value: 'semana', label: 'Esta semana' },
];
