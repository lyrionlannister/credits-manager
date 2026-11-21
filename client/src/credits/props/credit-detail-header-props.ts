import type { ReactNode } from 'react';

import type { ApiCredit } from '../types/api-credit';
import type { ApiCreditDetail } from '../types/api-credit-detail';

export type CreditDetailHeaderProps = {
    credito: ApiCredit;
    cartera: ApiCreditDetail['cartera'];
    /** Botones de acción; la barra los alinea a la derecha desde `lg` y los envuelve en móvil. */
    children: ReactNode;
};
