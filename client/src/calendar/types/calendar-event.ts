import type { EventInput } from '@fullcalendar/core';
import type { InstallmentStatus } from '../../credits/types/installment-status';
import type { CalendarInstallmentColor } from './api-calendar-installment';

export type CalendarEventProps = {
    color: CalendarInstallmentColor;
    creditoId: number;
    clienteId: number;
    clienteNombre: string;
    tipoDocumento: string;
    numeroDocumento: string;
    celular: string | null;
    creditosConEntidad: number;
    numeroCuota: number;
    /** Decimal string (ej. "12345.66"), viene de `App\Core\Dinero\Dinero`. */
    saldo: string;
    valorTotal: string;
    valorPagado: string;
    valorCapital: string;
    valorInteres: string;
    valorOtrosGastos: string;
    fechaVencimiento: string;
    estado: InstallmentStatus;
};

export type CalendarEvent = EventInput & {
    extendedProps: CalendarEventProps;
};
