import type { FC, SubmitEvent } from 'react';
import { useState } from 'react';

import { Pencil, Tag, Trash2 } from 'lucide-react';
import { usePermission } from '../../auth/hooks/use-permission';
import { useResource } from '../../shared/hooks/use-resource';
import { useSendRequest } from '../../shared/hooks/use-send-request';
import { ActionButton } from '@ui/action-button/components/action-button';
import { Button } from '@ui/button/components/button';
import { Input } from '@ui/forms/components/input';
import { Label } from '@ui/forms/components/label';
import { Select } from '@ui/forms/components/select';
import { ConfirmModal } from '@ui/modal/components/confirm-modal';
import { Modal } from '@ui/modal/components/modal';
import { Spinner } from '@ui/spinner/components/spinner';
import { DataTable } from '@ui/table/components/data-table';
import type { DataTableColumn } from '@ui/table/props/data-table-props';
import { EditTaxObligationTypeModal } from './edit-tax-obligation-type-modal';
import { TAX_OBLIGATION_PERIODICITY_OPTIONS } from '../constants/tax-obligation-periodicity-options';
import type { ApiTaxObligationPeriodicity, ApiTaxObligationType } from '../types/api-tax-obligation-type';
import type { TaxObligationTypesModalProps } from '../props/tax-obligation-types-modal-props';

const PERIODICITY_LABELS: Record<ApiTaxObligationPeriodicity, string> = Object.fromEntries(
    TAX_OBLIGATION_PERIODICITY_OPTIONS.map((option) => [option.value, option.label]),
) as Record<ApiTaxObligationPeriodicity, string>;

export const TaxObligationTypesModal: FC<TaxObligationTypesModalProps> = ({ isOpen, onClose, onChanged }) => {
    const { sendRequest, isLoading: isDeleting } = useSendRequest();
    const puedeEliminar = usePermission('obligaciones_tributarias', 'eliminar_tipos');
    const { data, isLoading, refetch } = useResource<{ data: ApiTaxObligationType[] }>(isOpen ? '/obligaciones-tributarias/tipos' : null);
    const tipos = data?.data ?? [];

    const [nombre, setNombre] = useState('');
    const [periodicidad, setPeriodicidad] = useState<ApiTaxObligationPeriodicity>('mensual');
    const [diasAlertaPrevia, setDiasAlertaPrevia] = useState('5');
    const [error, setError] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [tipoEnEdicion, setTipoEnEdicion] = useState<ApiTaxObligationType | null>(null);
    const [tipoPorEliminar, setTipoPorEliminar] = useState<ApiTaxObligationType | null>(null);

    const confirmarEliminar = async () => {
        if (tipoPorEliminar === null) {
            return;
        }

        setError(null);

        try {
            await sendRequest(`/obligaciones-tributarias/tipos/${tipoPorEliminar.id}`, { method: 'DELETE' });
            refetch();
            onChanged();
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setTipoPorEliminar(null);
        }
    };

    const handleSubmit = async (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError(null);
        setIsSubmitting(true);

        try {
            await sendRequest('/obligaciones-tributarias/tipos', {
                method: 'POST',
                body: { nombre, periodicidad, dias_alerta_previa: Number(diasAlertaPrevia) },
            });

            setNombre('');
            setPeriodicidad('mensual');
            setDiasAlertaPrevia('5');
            refetch();
            onChanged();
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setIsSubmitting(false);
        }
    };

    const columns: DataTableColumn<ApiTaxObligationType>[] = [
        { key: 'nombre', label: 'Nombre' },
        { key: 'periodicidad', label: 'Periodicidad', render: (tipo) => PERIODICITY_LABELS[tipo.periodicidad] },
        { key: 'dias_alerta_previa', label: 'Días de alerta' },
        {
            key: 'activo',
            label: 'Activo',
            render: (tipo) => (
                <span
                    className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
                        tipo.activo
                            ? 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-400'
                            : 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400'
                    }`}
                >
                    {tipo.activo ? 'Activo' : 'Inactivo'}
                </span>
            ),
        },
        {
            key: 'acciones',
            label: 'Acciones',
            render: (tipo) => (
                <div className="flex items-center gap-3">
                    <ActionButton icon={<Pencil className="size-4" />} label="Editar tipo" onClick={() => setTipoEnEdicion(tipo)} />
                    {puedeEliminar && (
                        <ActionButton icon={<Trash2 className="size-4" />} label="Eliminar tipo" variant="danger" onClick={() => setTipoPorEliminar(tipo)} />
                    )}
                </div>
            ),
        },
    ];

    return (
        <Modal isOpen={isOpen} onClose={onClose} size="lg" className="max-h-[calc(100dvh-2rem)] overflow-y-auto">
            <h4 className="mb-6 text-lg font-semibold text-gray-800 dark:text-white/90">Tipos de obligación tributaria</h4>

            <form onSubmit={handleSubmit} className="mb-6 border-b border-gray-100 pb-6 dark:border-gray-800">
                <div className="space-y-4">
                    {error && (
                        <div className="rounded-lg border border-error-500 bg-error-50 px-4 py-3 text-sm text-error-600 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400">
                            {error}
                        </div>
                    )}

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <Label htmlFor="nuevo-tipo-nombre">Nombre</Label>
                            <Input
                                id="nuevo-tipo-nombre"
                                placeholder="Ej. IVA bimestral"
                                required
                                maxLength={120}
                                startIcon={<Tag className="size-4" />}
                                value={nombre}
                                onChange={(event) => setNombre(event.target.value)}
                            />
                        </div>

                        <div>
                            <Label htmlFor="nuevo-tipo-periodicidad">Periodicidad</Label>
                            <Select
                                id="nuevo-tipo-periodicidad"
                                options={TAX_OBLIGATION_PERIODICITY_OPTIONS}
                                defaultValue={periodicidad}
                                onChange={(value) => setPeriodicidad(value as ApiTaxObligationPeriodicity)}
                            />
                        </div>

                        <div>
                            <Label htmlFor="nuevo-tipo-dias-alerta">Días de alerta previa</Label>
                            <Input
                                id="nuevo-tipo-dias-alerta"
                                type="number"
                                min="0"
                                max="365"
                                required
                                value={diasAlertaPrevia}
                                onChange={(event) => setDiasAlertaPrevia(event.target.value)}
                            />
                        </div>
                    </div>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={isSubmitting} startIcon={isSubmitting ? <Spinner size="sm" color="white" /> : undefined}>
                            {isSubmitting ? 'Creando…' : 'Crear tipo'}
                        </Button>
                    </div>
                </div>
            </form>

            <DataTable
                columns={columns}
                rows={tipos}
                getRowKey={(tipo) => tipo.id}
                loading={isLoading}
                emptyMessage="Todavía no hay tipos de obligación configurados."
            />

            {tipoEnEdicion && (
                <EditTaxObligationTypeModal
                    tipo={tipoEnEdicion}
                    onClose={() => setTipoEnEdicion(null)}
                    onSaved={() => {
                        setTipoEnEdicion(null);
                        refetch();
                        onChanged();
                    }}
                />
            )}

            <ConfirmModal
                isOpen={tipoPorEliminar !== null}
                onClose={() => setTipoPorEliminar(null)}
                onConfirm={() => void confirmarEliminar()}
                variant="danger"
                title="Eliminar tipo de obligación"
                description={`El tipo «${tipoPorEliminar?.nombre}» se eliminará. Si ya tiene obligaciones registradas, desactívalo en su lugar.`}
                confirmLabel="Eliminar"
                isConfirming={isDeleting}
            />
        </Modal>
    );
};
