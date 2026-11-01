import { formatMoney } from '../../shared/utils/format-money';
import type { ApiCalendarInstallment } from '../types/api-calendar-installment';
import type { CalendarEvent } from '../types/calendar-event';

export function buildCalendarEvents(cuotas: ApiCalendarInstallment[]): CalendarEvent[] {
    return cuotas.map((cuota) => ({
        id: `cuota-${cuota.id}`,
        title: `${cuota.cliente_nombre} · Cuota #${cuota.numero_cuota} · ${formatMoney(cuota.saldo)}`,
        start: cuota.fecha_vencimiento,
        allDay: true,
        extendedProps: {
            color: cuota.color,
            creditoId: cuota.credito_id,
            clienteId: cuota.cliente.id,
            clienteNombre: cuota.cliente.nombre,
            tipoDocumento: cuota.cliente.tipo_documento,
            numeroDocumento: cuota.cliente.numero_documento,
            celular: cuota.cliente.celular,
            creditosConEntidad: cuota.creditos_con_entidad,
            numeroCuota: cuota.numero_cuota,
            saldo: cuota.saldo,
            valorTotal: cuota.valor_total,
            valorPagado: cuota.valor_pagado,
            valorCapital: cuota.valor_capital,
            valorInteres: cuota.valor_interes,
            valorOtrosGastos: cuota.valor_otros_gastos,
            fechaVencimiento: cuota.fecha_vencimiento,
            estado: cuota.estado,
        },
    }));
}
