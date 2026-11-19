import type { FC, SubmitEvent } from 'react';
import { useState } from 'react';
import { useNavigate } from 'react-router';

import { useResource } from '../../shared/hooks/use-resource';
import { useSendRequest } from '../../shared/hooks/use-send-request';
import type { PaginatedResponse } from '../../shared/types/paginated-response';
import { ClientSelector } from '../../clients/components/client-selector';
import type { ApiClient } from '../../clients/types/api-client';
import { Button } from '@ui/button/components/button';
import { DatePicker } from '@ui/forms/components/date-picker';
import { Input } from '@ui/forms/components/input';
import { Label } from '@ui/forms/components/label';
import { Select } from '@ui/forms/components/select';
import { Spinner } from '@ui/spinner/components/spinner';
import { Eye } from 'lucide-react';
import { formatMoney } from '../../shared/utils/format-money';
import { CreditPreviewModal } from './credit-preview-modal';
import { RefinancingBaseCard } from './refinancing-base-card';
import { CREDIT_TYPE_OPTIONS } from '../constants/credit-type-options';
import { PAYMENT_TYPE_OPTIONS } from '../constants/payment-type-options';
import type { ApiCredit } from '../types/api-credit';
import type { ApiCreditDetail } from '../types/api-credit-detail';
import type { ApiCreditPreview } from '../types/api-credit-preview';
import type { CreditType } from '../types/credit-type';
import type { PaymentType } from '../types/payment-type';

const HOY = new Date().toISOString().slice(0, 10);

export const CreateCreditForm: FC = () => {
    const navigate = useNavigate();
    const { sendRequest } = useSendRequest();

    const [cliente, setCliente] = useState<ApiClient | null>(null);
    const [tipoCredito, setTipoCredito] = useState<CreditType>('nuevo');
    const [creditoOrigenId, setCreditoOrigenId] = useState<number | null>(null);

    const creditosActivosParams = cliente
        ? new URLSearchParams({ cliente_id: String(cliente.id), estado: 'desembolsado', per_page: '50' }).toString()
        : '';
    const { data: creditosActivosData } = useResource<PaginatedResponse<ApiCredit>>(
        tipoCredito === 'refinanciacion' && cliente ? `/creditos?${creditosActivosParams}` : null,
    );
    const creditosActivos = creditosActivosData?.data ?? [];

    const { data: origenDetalle, isLoading: isLoadingOrigen } = useResource<ApiCreditDetail>(
        tipoCredito === 'refinanciacion' && creditoOrigenId ? `/creditos/${creditoOrigenId}` : null,
    );
    const saldoTomado = creditoOrigenId ? (origenDetalle?.resumen_cuotas.saldo_capital_pendiente ?? null) : null;

    const [valorPrestamo, setValorPrestamo] = useState('');
    const [tasaInteres, setTasaInteres] = useState('');
    const [gastosAdministracion, setGastosAdministracion] = useState('0');
    const [plazo, setPlazo] = useState('');
    const [tipoPago, setTipoPago] = useState<PaymentType>('mensual');
    const [fechaDesembolso, setFechaDesembolso] = useState(HOY);
    const [fechaPrimerVencimiento, setFechaPrimerVencimiento] = useState('');

    const [error, setError] = useState<string | null>(null);
    const [isPreviewing, setIsPreviewing] = useState(false);
    const [isCreating, setIsCreating] = useState(false);
    const [preview, setPreview] = useState<ApiCreditPreview | null>(null);

    const handleClienteSelect = (nuevoCliente: ApiClient | null) => {
        setCliente(nuevoCliente);
        setCreditoOrigenId(null);
    };

    const handleTipoCreditoChange = (value: CreditType) => {
        setTipoCredito(value);
        setCreditoOrigenId(null);
    };

    const buildPayload = (saldoRefinanciadoPrevisualizado?: string | null) => ({
        cliente_id: cliente?.id,
        tipo_credito: tipoCredito,
        credito_origen_id: tipoCredito === 'refinanciacion' ? creditoOrigenId : null,
        // En una refinanciación el servidor calcula el préstamo: saldo de capital del original + valor adicional.
        ...(tipoCredito === 'refinanciacion' ? { valor_adicional: valorPrestamo } : { valor_prestamo: valorPrestamo }),
        // Solo al crear (no al previsualizar): el servidor rechaza si el saldo del original cambió desde que se vio este valor.
        ...(tipoCredito === 'refinanciacion' && saldoRefinanciadoPrevisualizado !== undefined
            ? { saldo_refinanciado_previsualizado: saldoRefinanciadoPrevisualizado }
            : {}),
        tasa_interes: tasaInteres,
        gastos_administracion_pct: gastosAdministracion,
        plazo,
        tipo_pago: tipoPago,
        fecha_desembolso: fechaDesembolso,
        fecha_primer_vencimiento: fechaPrimerVencimiento || null,
    });

    const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError(null);

        if (!cliente) {
            setError('Selecciona un cliente.');
            return;
        }

        if (tipoCredito === 'refinanciacion' && !creditoOrigenId) {
            setError('Selecciona el crédito que se está refinanciando.');
            return;
        }

        setIsPreviewing(true);

        try {
            const resultado = await sendRequest<ApiCreditPreview>('/creditos/previsualizar', {
                method: 'POST',
                body: buildPayload(),
            });

            setPreview(resultado);
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setIsPreviewing(false);
        }
    };

    const handleConfirmarCreacion = async () => {
        setIsCreating(true);

        try {
            const response = await sendRequest<{ credito: ApiCredit }>('/creditos', {
                method: 'POST',
                body: buildPayload(preview?.saldo_refinanciado),
            });

            navigate(`/creditos/${response.credito.id}`, {
                replace: true,
                state: { message: 'Crédito creado y cuotas generadas correctamente.' },
            });
        } catch (caught) {
            setPreview(null);
            setError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setIsCreating(false);
        }
    };

    return (
        <form onSubmit={handleSubmit}>
            <div className="grid grid-cols-1 gap-6 md:grid-cols-3 print:hidden">
                {error && (
                    <div className="rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 md:col-span-3 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400">
                        {error}
                    </div>
                )}

                <div className="md:col-span-3">
                    <Label htmlFor="credito-cliente">Cliente</Label>
                    <ClientSelector id="credito-cliente" selected={cliente} onSelect={handleClienteSelect} />
                </div>

                <div>
                    <Label htmlFor="tipo-credito">Tipo de crédito</Label>
                    <Select
                        id="tipo-credito"
                        options={CREDIT_TYPE_OPTIONS}
                        defaultValue={tipoCredito}
                        onChange={(value) => handleTipoCreditoChange(value as CreditType)}
                    />
                </div>

                {tipoCredito === 'refinanciacion' && (
                    <div className="md:col-span-2">
                        <Label htmlFor="credito-origen">Crédito que refinancia</Label>
                        <Select
                            id="credito-origen"
                            options={creditosActivos.map((c) => ({
                                value: String(c.id),
                                label: `#${c.id} — ${formatMoney(c.valor_prestamo)}`,
                            }))}
                            placeholder={cliente ? 'Selecciona el crédito activo del cliente…' : 'Primero selecciona un cliente'}
                            onChange={(value) => setCreditoOrigenId(value ? Number(value) : null)}
                        />
                        <p className="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
                            El crédito original sigue activo y recibiendo pagos hasta que se desembolse esta refinanciación.
                        </p>
                    </div>
                )}

                {tipoCredito === 'refinanciacion' && (
                    <RefinancingBaseCard saldoTomado={saldoTomado} valorAdicional={valorPrestamo} isLoading={isLoadingOrigen && !origenDetalle} />
                )}

                <div>
                    <Label htmlFor="valor-prestamo">
                        {tipoCredito === 'refinanciacion' ? 'Nuevo valor a prestar (puede ser 0)' : 'Valor del préstamo'}
                    </Label>
                    <Input
                        id="valor-prestamo"
                        name="valor-prestamo"
                        type="number"
                        step={0.01}
                        min={tipoCredito === 'refinanciacion' ? '0' : '1'}
                        required
                        value={valorPrestamo}
                        onChange={(event) => setValorPrestamo(event.target.value)}
                    />
                </div>

                <div>
                    <Label htmlFor="tasa-interes">Tasa de interés (% Mensual)</Label>
                    <Input
                        id="tasa-interes"
                        name="tasa-interes"
                        type="number"
                        step={0.001}
                        min="0"
                        required
                        value={tasaInteres}
                        onChange={(event) => setTasaInteres(event.target.value)}
                    />
                </div>

                <div>
                    <Label htmlFor="gastos-administracion">Gastos de administración (%)</Label>
                    <Input
                        id="gastos-administracion"
                        name="gastos-administracion"
                        type="number"
                        step={0.001}
                        min="0"
                        max="100"
                        value={gastosAdministracion}
                        onChange={(event) => setGastosAdministracion(event.target.value)}
                    />
                </div>

                <div>
                    <Label htmlFor="plazo">Plazo (número de cuotas)</Label>
                    <Input
                        id="plazo"
                        name="plazo"
                        type="number"
                        min="1"
                        required
                        value={plazo}
                        onChange={(event) => setPlazo(event.target.value)}
                    />
                </div>

                <div>
                    <Label htmlFor="tipo-pago">Tipo de pago</Label>
                    <Select
                        id="tipo-pago"
                        options={PAYMENT_TYPE_OPTIONS}
                        defaultValue={tipoPago}
                        onChange={(value) => setTipoPago(value as PaymentType)}
                    />
                </div>

                <div>
                    <Label htmlFor="fecha-desembolso">Fecha de desembolso</Label>
                    <DatePicker
                        id="fecha-desembolso"
                        name="fecha-desembolso"
                        required
                        value={fechaDesembolso}
                        onChange={setFechaDesembolso}
                    />
                </div>

                <div>
                    <Label htmlFor="fecha-primer-vencimiento">Primer vencimiento (opcional)</Label>
                    <DatePicker
                        id="fecha-primer-vencimiento"
                        name="fecha-primer-vencimiento"
                        value={fechaPrimerVencimiento}
                        onChange={setFechaPrimerVencimiento}
                    />
                </div>

                <p className="text-sm text-gray-500 md:col-span-3 dark:text-gray-400">
                    Las cuotas se generan automáticamente con el <strong>sistema francés</strong> (cuota fija, interés
                    compuesto sobre saldo insoluto). Si eliges el primer vencimiento, la primera cuota cobra el interés
                    de los días reales desde el desembolso y las demás siguen cada periodo desde esa fecha. En quincenal las
                    cuotas vencen el 15 y el último día de cada mes.
                </p>

                <div className="flex justify-end border-t border-gray-100 pt-6 md:col-span-3 dark:border-gray-800">
                    <Button
                        type="submit"
                        disabled={isPreviewing}
                        startIcon={isPreviewing ? <Spinner size="sm" color="white" /> : undefined}
                        endIcon={isPreviewing ? undefined : <Eye className="size-4" />}
                    >
                        {isPreviewing ? 'Calculando…' : 'Previsualizar cuotas'}
                    </Button>
                </div>
            </div>

            {preview && cliente && (
                <CreditPreviewModal
                    preview={preview}
                    cliente={cliente}
                    creditoOrigenId={tipoCredito === 'refinanciacion' ? creditoOrigenId : null}
                    tasaInteres={tasaInteres}
                    plazo={Number(plazo)}
                    tipoPago={tipoPago}
                    fechaDesembolso={fechaDesembolso}
                    isCreating={isCreating}
                    onConfirm={handleConfirmarCreacion}
                    onClose={() => setPreview(null)}
                />
            )}
        </form>
    );
};
