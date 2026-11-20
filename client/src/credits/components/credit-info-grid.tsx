import type { FC } from 'react';

import { formatDateEs } from '../../shared/utils/format-date-es';
import { CREDIT_STATUS_LABEL } from '../constants/credit-status-label';
import type { CreditInfoGridProps } from '../props/credit-info-grid-props';

export const CreditInfoGrid: FC<CreditInfoGridProps> = ({ credito }) => (
    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div>
            <p className="text-sm text-gray-500 dark:text-gray-400">Cliente</p>
            <p className="font-medium text-gray-800 dark:text-white/90">{credito.cliente?.tercero.nombre ?? '—'}</p>
        </div>
        <div>
            <p className="text-sm text-gray-500 dark:text-gray-400">Documento</p>
            <p className="font-medium text-gray-800 dark:text-white/90">
                {credito.cliente ? `${credito.cliente.tercero.tipo_documento.toUpperCase()} ${credito.cliente.tercero.numero_documento}` : '—'}
            </p>
        </div>
        <div>
            <p className="text-sm text-gray-500 dark:text-gray-400">Tasa de interés</p>
            <p className="font-medium text-gray-800 dark:text-white/90">
                {parseFloat(credito.tasa_interes).toFixed(2)}% mensual
                {credito.tasa_efectiva_anual && (
                    <span className="font-normal text-gray-500 dark:text-gray-400"> · {parseFloat(credito.tasa_efectiva_anual).toFixed(2)}% EA</span>
                )}
            </p>
        </div>
        <div>
            <p className="text-sm text-gray-500 dark:text-gray-400">Plazo</p>
            <p className="font-medium text-gray-800 dark:text-white/90">
                {credito.plazo} cuotas ({credito.tipo_pago})
            </p>
        </div>
        <div>
            <p className="text-sm text-gray-500 dark:text-gray-400">Fecha de desembolso</p>
            <p className="font-medium text-gray-800 dark:text-white/90">{formatDateEs(credito.fecha_desembolso)}</p>
        </div>
        <div>
            <p className="text-sm text-gray-500 dark:text-gray-400">Estado del crédito</p>
            <p className="font-medium text-gray-800 dark:text-white/90">{CREDIT_STATUS_LABEL[credito.estado]}</p>
        </div>
        <div>
            <p className="text-sm text-gray-500 dark:text-gray-400">Tipo de crédito</p>
            <p className="font-medium text-gray-800 dark:text-white/90">
                {credito.credito_origen ? `Refinanciación de crédito #${credito.credito_origen.id}` : 'Nuevo'}
            </p>
        </div>
    </div>
);
