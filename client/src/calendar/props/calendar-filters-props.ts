import type { ApiEmployer } from '../../employers/types/api-employer';
import type { CalendarRange } from '../types/calendar-range';

export type CalendarFiltersProps = {
    rango: CalendarRange;
    onChangeRango: (rango: CalendarRange) => void;
    empleadores: ApiEmployer[];
    empleadorId: number | null;
    onChangeEmpleadorId: (empleadorId: number | null) => void;
};
