import type { ApiEmployer } from '../../employers/types/api-employer';
import type { ApiCalendarInstallment } from './api-calendar-installment';

/** Raw shape returned by `GET /calendario?mes=YYYY-MM&empleador_id=N`. */
export type ApiCalendarAgenda = {
    empleadores: ApiEmployer[];
    cuotas: ApiCalendarInstallment[];
};
