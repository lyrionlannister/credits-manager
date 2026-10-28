import type { FC } from 'react';
import { useState } from 'react';
import { useNavigate } from 'react-router';
import { FileText, Pencil, Trash2 } from 'lucide-react';

import { useLocationSuccessMessage } from '../../shared/hooks/use-location-success-message';
import { useResource } from '../../shared/hooks/use-resource';
import { useSendRequest } from '../../shared/hooks/use-send-request';
import { formatDateEs } from '../../shared/utils/format-date-es';
import { formatMoney } from '../../shared/utils/format-money';
import { usePermission } from '../../auth/hooks/use-permission';
import { ActionButton } from '@ui/action-button/components/action-button';
import { Button } from '@ui/button/components/button';
import { ConfirmModal } from '@ui/modal/components/confirm-modal';
import { ModuleListPage } from '@ui/module/components/module-list-page';
import { DataTable } from '@ui/table/components/data-table';
import type { UvtValuesListLocationState } from '../types/uvt-values-list-location-state';
import type { ApiUvtValue } from '../types/api-uvt-value';

export const UvtValuesPage: FC = () => {
    const navigate = useNavigate();
    const { sendRequest, isLoading } = useSendRequest();

    const canCreate = usePermission('tributario', 'crear_valores_uvt');
    const canEdit = usePermission('tributario', 'editar_valores_uvt');
    const canDelete = usePermission('tributario', 'eliminar_valores_uvt');
    const [successMessage, setSuccessMessage] = useLocationSuccessMessage<UvtValuesListLocationState>();
    const { data, isLoading: isLoadingValores, refetch } = useResource<{ valores_uvt: ApiUvtValue[] }>('/gastos/valores-uvt');
    const valoresUvt = data?.valores_uvt ?? [];

    const [porEliminar, setPorEliminar] = useState<ApiUvtValue | null>(null);
    const [error, setError] = useState<string | null>(null);

    const confirmarEliminar = async () => {
        if (porEliminar === null) {
            return;
        }

        setError(null);

        try {
            await sendRequest(`/gastos/valores-uvt/${porEliminar.id}`, { method: 'DELETE' });
            setPorEliminar(null);
            void refetch();
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        }
    };

    return (
        <ModuleListPage
            pageTitle="Valores UVT"
            successMessage={successMessage}
            onDismissSuccess={() => setSuccessMessage(null)}
            errorMessage={error}
            onDismissError={() => setError(null)}
            countLabel={`${valoresUvt.length} ${valoresUvt.length === 1 ? 'valor registrado' : 'valores registrados'}`}
            toolbar={
                canCreate && (
                    <Button collapseBelow="md" startIcon={<FileText className="size-4" />} onClick={() => navigate('/gastos/valores-uvt/crear')}>
                        Guardar valor UVT
                    </Button>
                )
            }
            modals={
                <ConfirmModal
                    isOpen={porEliminar !== null}
                    onClose={() => setPorEliminar(null)}
                    onConfirm={() => void confirmarEliminar()}
                    variant="danger"
                    title="Eliminar valor UVT"
                    description={`El valor UVT vigente desde ${porEliminar ? formatDateEs(porEliminar.fecha_desde) : ''} se eliminará. Esta acción no se puede deshacer.`}
                    confirmLabel="Eliminar"
                    isConfirming={isLoading}
                />
            }
        >
            <DataTable<ApiUvtValue>
                columns={[
                    { key: 'fecha_desde', label: 'Vigente desde', render: (valorUvt) => formatDateEs(valorUvt.fecha_desde) },
                    { key: 'fecha_hasta', label: 'Vigente hasta', render: (valorUvt) => (valorUvt.fecha_hasta ? formatDateEs(valorUvt.fecha_hasta) : 'Vigente') },
                    { key: 'valor', label: 'Valor UVT', render: (valorUvt) => formatMoney(valorUvt.valor) },
                    ...(canEdit || canDelete
                        ? [
                              {
                                  key: 'acciones',
                                  label: 'Acciones',
                                  render: (valorUvt: ApiUvtValue) => (
                                      <div className="flex items-center gap-3">
                                          {canEdit && (
                                              <ActionButton
                                                  icon={<Pencil className="size-5" />}
                                                  label="Editar"
                                                  onClick={() => navigate(`/gastos/valores-uvt/${valorUvt.id}/editar`)}
                                              />
                                          )}
                                          {canDelete && (
                                              <ActionButton
                                                  icon={<Trash2 className="size-5" />}
                                                  label="Eliminar"
                                                  variant="danger"
                                                  onClick={() => setPorEliminar(valorUvt)}
                                              />
                                          )}
                                      </div>
                                  ),
                              },
                          ]
                        : []),
                ]}
                rows={valoresUvt}
                getRowKey={(row) => row.id}
                loading={isLoadingValores}
                emptyMessage="Sin valores de UVT registrados."
                maxHeightClassName="max-h-[65vh]"
            />
        </ModuleListPage>
    );
};
