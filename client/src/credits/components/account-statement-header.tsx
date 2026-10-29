import type { FC } from 'react';

import { API_ROOT } from '../../shared/constants/api';
import { formatDateEs } from '../../shared/utils/format-date-es';
import { formatMoney } from '../../shared/utils/format-money';
import { useTheme } from '@ui/theme/hooks/use-theme';
import type { AccountStatementHeaderProps } from '../props/account-statement-header-props';

export const AccountStatementHeader: FC<AccountStatementHeaderProps> = ({ company, credito, cuotasPagadas, fechaCorte }) => {
    const { theme } = useTheme();
    const logoPath = (theme === 'dark' ? company?.logoDarkPath : null) ?? company?.logoPath;

    return (
        <>
            <div className="mb-6 flex items-start justify-between border-b border-gray-200 pb-4 dark:border-gray-800">
                <div>
                    {logoPath && <img src={`${API_ROOT}/${logoPath}`} alt={company?.name} className="mb-2 max-h-11 max-w-50" />}
                    <h1 className="text-lg font-semibold text-gray-800 dark:text-white/90">{company?.name ?? 'Estado de Cuenta'}</h1>
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                        {company?.taxId && `NIT ${company.taxId} · `}
                        {company?.address}
                        {company?.phone && ` · Tel: ${company.phone}`}
                    </p>
                    <p className="mt-2 font-medium text-gray-800 dark:text-white/90">{credito.cliente?.tercero.nombre}</p>
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                        {credito.cliente && `${credito.cliente.tercero.tipo_documento.toUpperCase()} ${credito.cliente.tercero.numero_documento}`}
                        {credito.cliente?.tercero.celular && ` · Tel: ${credito.cliente.tercero.celular}`}
                    </p>
                </div>
                <div className="text-right text-xs text-gray-500 dark:text-gray-400">
                    <p>Fecha de corte:</p>
                    <p className="font-medium text-gray-800 dark:text-white/90">{formatDateEs(fechaCorte)}</p>
                    <p className="mt-1">Crédito #{credito.id}</p>
                </div>
            </div>

            <div className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div className="rounded-lg border border-gray-100 bg-gray-50 p-3 dark:border-gray-800 dark:bg-white/3">
                    <p className="text-[10px] font-bold tracking-wide text-gray-500 uppercase dark:text-gray-400">Monto prestado</p>
                    <p className="mt-1 font-semibold text-gray-800 dark:text-white/90">{formatMoney(credito.valor_prestamo)}</p>
                </div>
                <div className="rounded-lg border border-gray-100 bg-gray-50 p-3 dark:border-gray-800 dark:bg-white/3">
                    <p className="text-[10px] font-bold tracking-wide text-gray-500 uppercase dark:text-gray-400">Cuota fija</p>
                    <p className="mt-1 font-semibold text-gray-800 dark:text-white/90">{formatMoney(credito.valor_cuota_total)}</p>
                </div>
                <div className="rounded-lg border border-gray-100 bg-gray-50 p-3 dark:border-gray-800 dark:bg-white/3">
                    <p className="text-[10px] font-bold tracking-wide text-gray-500 uppercase dark:text-gray-400">Periodicidad</p>
                    <p className="mt-1 font-semibold text-gray-800 capitalize dark:text-white/90">{credito.tipo_pago}</p>
                </div>
                <div className="rounded-lg border border-gray-100 bg-gray-50 p-3 dark:border-gray-800 dark:bg-white/3">
                    <p className="text-[10px] font-bold tracking-wide text-gray-500 uppercase dark:text-gray-400">Cuotas pagadas / pactadas</p>
                    <p className="mt-1 font-semibold text-gray-800 dark:text-white/90">
                        {cuotasPagadas} / {credito.plazo}
                    </p>
                </div>
            </div>
        </>
    );
};
