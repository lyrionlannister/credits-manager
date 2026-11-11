import type { ApiCreditDetail } from '../types/api-credit-detail';

export type CreditSummaryCardsProps = {
    valorPrestamo: string;
    valorTotal: string;
    saldo: string;
    cartera: 'En mora' | 'Al día' | 'Refinanciado';
    /** Sin crédito desembolsado (p. ej. la previsualización) no hay mora que mostrar. */
    mora?: ApiCreditDetail['mora'];
};
