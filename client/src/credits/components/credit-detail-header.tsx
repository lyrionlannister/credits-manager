import type { FC } from 'react';

import { CARTERA_STATUS_BADGE_CLASSES } from '../constants/cartera-status-badge-classes';
import { CREDIT_STATUS_BADGE_CLASSES } from '../constants/credit-status-badge-classes';
import { CREDIT_STATUS_LABEL } from '../constants/credit-status-label';
import type { CreditDetailHeaderProps } from '../props/credit-detail-header-props';

/**
 * Barra del detalle: identidad y estado del crédito con sus acciones. Fija desde `md`; en móvil las acciones
 * ocupan dos filas y fijarla taparía buena parte de la pantalla.
 */
export const CreditDetailHeader: FC<CreditDetailHeaderProps> = ({ credito, cartera, children }) => (
    <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-theme-sm md:sticky md:top-0 md:z-20 md:px-6 print:static print:shadow-none dark:border-gray-800 dark:bg-gray-900">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <h3 className="font-semibold text-gray-800 dark:text-white/90">Crédito #{credito.id}</h3>
                    <span className={`rounded-full px-2.5 py-0.5 text-theme-xs font-medium ${CREDIT_STATUS_BADGE_CLASSES[credito.estado]}`}>
                        {CREDIT_STATUS_LABEL[credito.estado]}
                    </span>
                    {credito.estado === 'desembolsado' || cartera === 'Refinanciado' ? (
                        <span className={`rounded-full px-2.5 py-0.5 text-theme-xs font-medium ${CARTERA_STATUS_BADGE_CLASSES[cartera]}`}>{cartera}</span>
                    ) : null}
                </div>
                {credito.cliente && (
                    <p className="mt-1 truncate text-theme-sm text-gray-500 dark:text-gray-400">
                        {credito.cliente.tercero.nombre} · {credito.cliente.tercero.tipo_documento.toUpperCase()} {credito.cliente.tercero.numero_documento}
                    </p>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-2 lg:shrink-0 lg:justify-end print:hidden">{children}</div>
        </div>
    </div>
);
