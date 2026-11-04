import type { FC, SubmitEvent } from 'react';
import { useState } from 'react';
import { useNavigate } from 'react-router';

import { useSendRequest } from '../../shared/hooks/use-send-request';
import { formatDateEs } from '../../shared/utils/format-date-es';
import { formatMoney } from '../../shared/utils/format-money';
import { Button } from '@ui/button/components/button';
import { DatePicker } from '@ui/forms/components/date-picker';
import { Input } from '@ui/forms/components/input';
import { Label } from '@ui/forms/components/label';
import { Select } from '@ui/forms/components/select';
import { Spinner } from '@ui/spinner/components/spinner';
import { DataTable } from '@ui/table/components/data-table';
import { Save } from 'lucide-react';
import { INSTALLMENT_STATUS_CLASSES, INSTALLMENT_STATUS_LABEL } from '../../credits/constants/installment-status-styles';
import { PaymentTargetCard } from './payment-target-card';
import { PAYMENT_METHOD_OPTIONS } from '../constants/payment-method-options';
import type { RegisterPaymentFormProps } from '../props/register-payment-form-props';
import type { ApiPayment } from '../types/api-payment';
import type { PaymentMethod } from '../types/payment-method';

export const RegisterPaymentForm: FC<RegisterPaymentFormProps> = ({ detalle }) => {
    const navigate = useNavigate();
    const { sendRequest } = useSendRequest();

    const [valorPago, setValorPago] = useState('');
    const [formaPago, setFormaPago] = useState<PaymentMethod>('efectivo');
    const [fechaPago, setFechaPago] = useState('');
    const [observacion, setObservacion] = useState('');

    const [error, setError] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const { credito, saldo } = detalle;
    const cuotasPendientes = (credito.cuotas ?? []).filter((cuota) => cuota.estado === 'pendiente' || cuota.estado === 'vencida');
    const cuotaObjetivo = cuotasPendientes[0] ?? null;

    const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError(null);

        if (Number(valorPago) <= 0) {
            setError('Ingresa un valor de pago mayor a cero.');
            return;
        }

        setIsSubmitting(true);

        try {
            await sendRequest<{ pago: ApiPayment }>(`/creditos/${credito.id}/pagos`, {
                method: 'POST',
                body: { valor_pago: valorPago, forma_pago: formaPago, fecha_pago: fechaPago || null, observacion: observacion || null },
            });

            navigate(`/pagos/creditos/${credito.id}`, { replace: true, state: { message: 'Pago registrado correctamente.' } });
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <>
            <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
                Saldo pendiente del crédito: <strong className="text-gray-800 dark:text-white/90">{formatMoney(saldo)}</strong>
            </p>

            {cuotasPendientes.length === 0 ? (
                <div className="rounded-lg border border-warning-500 bg-warning-50 px-4 py-3 text-sm text-warning-600 dark:border-warning-800 dark:bg-warning-500/10 dark:text-orange-400">
                    Este crédito no tiene cuotas pendientes.
                </div>
            ) : (
                <>
                    {cuotaObjetivo && <PaymentTargetCard cuota={cuotaObjetivo} />}

                    <form onSubmit={handleSubmit}>
                        <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            {error && (
                                <div className="rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 sm:col-span-2 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400">
                                    {error}
                                </div>
                            )}

                            <div>
                                <Label htmlFor="valor-pago">Valor a pagar (permite abonos parciales)</Label>
                                <Input
                                    id="valor-pago"
                                    name="valor-pago"
                                    type="number"
                                    step={0.01}
                                    min="0.01"
                                    required
                                    value={valorPago}
                                    onChange={(event) => setValorPago(event.target.value)}
                                />
                            </div>

                            <div>
                                <Label htmlFor="forma-pago">Forma de pago</Label>
                                <Select
                                    id="forma-pago"
                                    options={PAYMENT_METHOD_OPTIONS}
                                    defaultValue={formaPago}
                                    onChange={(value) => setFormaPago(value as PaymentMethod)}
                                />
                            </div>

                            <div>
                                <Label htmlFor="fecha-pago">Fecha de pago (opcional)</Label>
                                <DatePicker
                                    id="fecha-pago"
                                    name="fecha-pago"
                                    value={fechaPago}
                                    onChange={setFechaPago}
                                    maxDate={new Date().toISOString().slice(0, 10)}
                                />
                            </div>

                            <div className="sm:col-span-2">
                                <Label htmlFor="observacion">Observación (opcional)</Label>
                                <Input
                                    id="observacion"
                                    name="observacion"
                                    value={observacion}
                                    onChange={(event) => setObservacion(event.target.value)}
                                />
                            </div>

                            <p className="text-sm text-gray-500 sm:col-span-2 dark:text-gray-400">
                                El pago se aplicará automáticamente a la cuota vencida/pendiente más antigua primero.
                            </p>

                            <div className="flex gap-3 border-t border-gray-100 pt-6 sm:col-span-2 dark:border-gray-800">
                                <Button
                                    type="submit"
                                    disabled={isSubmitting}
                                    startIcon={isSubmitting ? <Spinner size="sm" color="white" /> : undefined}
                                    endIcon={isSubmitting ? undefined : <Save className="size-4" />}
                                >
                                    {isSubmitting ? 'Pagando…' : 'Pagar cuota'}
                                </Button>
                                <Button type="button" variant="outline" onClick={() => navigate(`/pagos/creditos/${credito.id}`)}>
                                    Cancelar
                                </Button>
                            </div>
                        </div>
                    </form>

                    <h2 className="mt-8 mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">Próximas cuotas</h2>
                    <DataTable
                        columns={[
                            { key: 'numero_cuota', label: '#' },
                            { key: 'fecha_vencimiento', label: 'Vencimiento', render: (row) => formatDateEs(row.fecha_vencimiento) },
                            { key: 'valor_capital', label: 'Capital', render: (row) => formatMoney(row.valor_capital) },
                            { key: 'valor_interes', label: 'Intereses', render: (row) => formatMoney(row.valor_interes) },
                            { key: 'valor_otros_gastos', label: 'Otros gastos', render: (row) => formatMoney(row.valor_otros_gastos) },
                            { key: 'valor_total_cuota', label: 'Total de la cuota', render: (row) => formatMoney(row.valor_total_cuota) },
                            {
                                key: 'saldo',
                                label: 'Saldo',
                                render: (row) => formatMoney((parseFloat(row.valor_total_cuota) - parseFloat(row.valor_pagado)).toFixed(2)),
                            },
                            {
                                key: 'estado',
                                label: 'Estado',
                                render: (row) => (
                                    <div className="flex items-center gap-2">
                                        <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${INSTALLMENT_STATUS_CLASSES[row.estado]}`}>
                                            {INSTALLMENT_STATUS_LABEL[row.estado]}
                                        </span>
                                        {row.id === cuotaObjetivo?.id && (
                                            <span className="rounded-full bg-brand-50 px-2.5 py-0.5 text-xs font-medium text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
                                                Pagando ahora
                                            </span>
                                        )}
                                    </div>
                                ),
                            },
                        ]}
                        rows={cuotasPendientes}
                        getRowKey={(row) => row.id}
                        maxHeightClassName="max-h-80"
                    />
                </>
            )}
        </>
    );
};
