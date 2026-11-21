import type { FC } from 'react';

import { formatDateEs } from '../../shared/utils/format-date-es';
import type { CreditConditionsCardProps } from '../props/credit-conditions-card-props';

export const CreditConditionsCard: FC<CreditConditionsCardProps> = ({
    cliente,
    creditoOrigenId,
    tasaInteres,
    tasaEfectivaAnual,
    plazo,
    tipoPago,
    fechaDesembolso,
    cuotas,
}) => {
    const filas = [
        { etiqueta: 'Documento', valor: `${cliente.tercero.tipo_documento.toUpperCase()} ${cliente.tercero.numero_documento}` },
        { etiqueta: 'Tipo de crédito', valor: creditoOrigenId ? `Refinanciación de #${creditoOrigenId}` : 'Nuevo' },
        {
            etiqueta: 'Tasa de interés',
            valor: `${parseFloat(tasaInteres).toFixed(2)}% mensual${tasaEfectivaAnual ? ` · ${parseFloat(tasaEfectivaAnual).toFixed(2)}% EA` : ''}`,
        },
        { etiqueta: 'Plazo', valor: `${plazo} cuotas (${tipoPago})` },
        { etiqueta: 'Desembolso', valor: formatDateEs(fechaDesembolso) },
        { etiqueta: 'Primer vencimiento', valor: cuotas[0] ? formatDateEs(cuotas[0].fecha_vencimiento) : '—' },
        { etiqueta: 'Último vencimiento', valor: cuotas.length > 0 ? formatDateEs(cuotas[cuotas.length - 1].fecha_vencimiento) : '—' },
    ];

    return (
        <div className="rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/3">
            <h3 className="text-lg font-semibold text-gray-800 dark:text-white/90">Condiciones</h3>
            <p className="mt-1 truncate text-sm text-gray-500 dark:text-gray-400">{cliente.tercero.nombre}</p>

            <dl className="mt-4 flex flex-col divide-y divide-gray-100 dark:divide-gray-800">
                {filas.map((fila) => (
                    <div key={fila.etiqueta} className="flex items-start justify-between gap-4 py-2.5 text-sm">
                        <dt className="shrink-0 text-gray-500 dark:text-gray-400">{fila.etiqueta}</dt>
                        <dd className="text-right font-medium text-gray-800 dark:text-white/90">{fila.valor}</dd>
                    </div>
                ))}
            </dl>
        </div>
    );
};
