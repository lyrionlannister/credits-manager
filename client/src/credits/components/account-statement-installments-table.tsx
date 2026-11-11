import type { FC } from 'react';

import { formatDateEs } from '../../shared/utils/format-date-es';
import { formatMoney } from '../../shared/utils/format-money';
import type { AccountStatementInstallmentsTableProps } from '../props/account-statement-installments-table-props';

export const AccountStatementInstallmentsTable: FC<AccountStatementInstallmentsTableProps> = ({
    cuotas,
    saldoCapitalTeoricoPorCuota,
    saldo,
}) => (
    <>
        <h3 className="mb-2 text-sm font-semibold text-gray-800 dark:text-white/90">Plan de cuotas</h3>
        <div className="custom-scrollbar overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b border-gray-200 text-left text-xs text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        <th className="py-2 pr-4 font-medium whitespace-nowrap">#</th>
                        <th className="py-2 pr-4 font-medium whitespace-nowrap">Vencimiento</th>
                        <th className="py-2 pr-4 font-medium whitespace-nowrap">Fecha de pago</th>
                        <th className="py-2 pr-4 font-medium whitespace-nowrap">Capital</th>
                        <th className="py-2 pr-4 font-medium whitespace-nowrap">Intereses</th>
                        <th className="py-2 pr-4 font-medium whitespace-nowrap">Total de la cuota</th>
                        <th className="py-2 pr-4 font-medium whitespace-nowrap">Saldo capital</th>
                        <th className="py-2 pr-4 font-medium whitespace-nowrap">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    {cuotas.map((cuota) => (
                        <tr key={cuota.id} className="border-b border-gray-100 text-gray-700 dark:border-gray-800 dark:text-gray-300">
                            <td className="py-2 pr-4 whitespace-nowrap">{cuota.numero_cuota}</td>
                            <td className="py-2 pr-4 whitespace-nowrap">{formatDateEs(cuota.fecha_vencimiento)}</td>
                            <td className="py-2 pr-4 whitespace-nowrap">{cuota.fecha_pago ? formatDateEs(cuota.fecha_pago) : '—'}</td>
                            <td className="py-2 pr-4 whitespace-nowrap">{formatMoney(cuota.valor_capital)}</td>
                            <td className="py-2 pr-4 whitespace-nowrap">{formatMoney(cuota.valor_interes)}</td>
                            <td className="py-2 pr-4 whitespace-nowrap">{formatMoney(cuota.valor_total_cuota)}</td>
                            <td className="py-2 pr-4 whitespace-nowrap">{formatMoney(saldoCapitalTeoricoPorCuota[cuota.numero_cuota] ?? 0)}</td>
                            <td className="py-2 pr-4 whitespace-nowrap capitalize">{cuota.estado}</td>
                        </tr>
                    ))}
                    <tr className="border-t-2 border-gray-800 font-semibold text-gray-800 dark:border-white/90 dark:text-white/90">
                        <td className="py-2 pr-4" colSpan={6}>
                            Saldo pendiente a la fecha
                        </td>
                        <td className="py-2 pr-4" colSpan={2}>
                            {formatMoney(saldo)}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </>
);
