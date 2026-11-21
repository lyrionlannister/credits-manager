import type { FC } from 'react';
import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router';
import { Download, FileText, Upload } from 'lucide-react';

import { useAuth } from '../../auth/hooks/use-auth';
import { usePermission } from '../../auth/hooks/use-permission';
import { hasAnyPermission } from '../../auth/utils/has-any-permission';
import { useDownloadFile } from '../../shared/hooks/use-download-file';
import { useResource } from '../../shared/hooks/use-resource';
import { useSendRequest } from '../../shared/hooks/use-send-request';
import type { ApiVoucherType } from '../../accounting/types/api-voucher-type';
import type { PaginatedResponse } from '../../shared/types/paginated-response';
import { Alert } from '@ui/alert/components/alert';
import { Button } from '@ui/button/components/button';
import { Checkbox } from '@ui/forms/components/checkbox';
import { Dropzone } from '@ui/dropzone/components/dropzone';
import { Label } from '@ui/forms/components/label';
import { Select } from '@ui/forms/components/select';
import { Spinner } from '@ui/spinner/components/spinner';
import { CsvExampleCard } from './csv-example-card';
import { ImportSteps } from './import-steps';
import { IMPORT_EXAMPLE_BY_TYPE } from '../constants/import-example-by-type';
import {
    IMPORT_TYPE_OPTIONS,
    IMPORT_TYPE_PERMISSIONS,
    IMPORT_TYPES,
    IMPORT_TYPES_WITH_ACCOUNTING,
    IMPORT_TYPES_WITH_EMPLOYER_CHECK,
    type ImportType,
} from '../constants/import-types';
import type { ApiImport } from '../types/api-import';

const TIPOS_CON_TIPO_COMPROBANTE: ImportType[] = ['saldos_iniciales'];

const STEPS = ['Preparar', 'Subir', 'Validar', 'Decidir', 'Escribir', 'Resultado'];

function esTipoImportable(valor: string): valor is ImportType {
    return (IMPORT_TYPES as readonly string[]).includes(valor);
}

export const NewImportForm: FC = () => {
    const navigate = useNavigate();
    const [searchParams] = useSearchParams();
    const { sendRequest } = useSendRequest();
    const { download: descargarGuia, isDownloading } = useDownloadFile();
    const puedeCrearEmpleadores = usePermission('empleadores', 'crear');
    const puedeContabilizar = usePermission('contabilidad', 'crear_manual');
    const { user } = useAuth();
    const opcionesTipo = IMPORT_TYPE_OPTIONS.filter((opcion) => hasAnyPermission(user, [IMPORT_TYPE_PERMISSIONS[opcion.value]]));

    const tipoInicial = searchParams.get('tipo') ?? '';
    const [tipo, setTipo] = useState<ImportType | ''>(esTipoImportable(tipoInicial) ? tipoInicial : '');
    const [archivo, setArchivo] = useState<File | null>(null);
    const [crearEmpleadores, setCrearEmpleadores] = useState(false);
    const [generarAsiento, setGenerarAsiento] = useState(false);
    const [tipoComprobanteId, setTipoComprobanteId] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const pideTipoComprobante = tipo !== '' && generarAsiento && TIPOS_CON_TIPO_COMPROBANTE.includes(tipo);
    const { data: tiposComprobanteData } = useResource<PaginatedResponse<ApiVoucherType>>(
        pideTipoComprobante ? '/contabilidad/tipos-comprobante?per_page=100' : null,
    );
    const opcionesTipoComprobante = (tiposComprobanteData?.data ?? [])
        .filter((tipoComprobante) => tipoComprobante.activo)
        .map((tipoComprobante) => ({ value: String(tipoComprobante.id), label: `${tipoComprobante.codigo} — ${tipoComprobante.nombre}` }));

    const handleDescargarGuia = async () => {
        if (tipo !== '') {
            await descargarGuia(`/importaciones/guias/${tipo}`, `guia-${tipo}.csv`);
        }
    };

    const handleTipoChange = (valor: string) => {
        setTipo(esTipoImportable(valor) ? valor : '');
        setCrearEmpleadores(false);
        setGenerarAsiento(false);
        setTipoComprobanteId('');
    };

    const handleSubir = async () => {
        if (tipo === '' || archivo === null) {
            setError('Elige la hoja y el archivo CSV.');
            return;
        }

        if (pideTipoComprobante && tipoComprobanteId === '') {
            setError('Elige el tipo de comprobante para la apertura.');
            return;
        }

        setError(null);
        setIsSubmitting(true);

        try {
            const formData = new FormData();
            formData.set('tipo', tipo);
            formData.set('archivo', archivo);
            formData.set('crear_empleadores', crearEmpleadores ? '1' : '0');
            formData.set('generar_asiento', generarAsiento ? '1' : '0');
            if (pideTipoComprobante) {
                formData.set('tipo_comprobante_id', tipoComprobanteId);
            }

            const respuesta = await sendRequest<{ importacion: ApiImport }>('/importaciones', { method: 'POST', body: formData });
            navigate(`/importaciones/${respuesta.importacion.id}`, { replace: true });
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : 'Ocurrió un error inesperado.');
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <div className="flex flex-col gap-6">
            <ImportSteps steps={STEPS} currentStep={tipo === '' ? 0 : 1} />

            {error && <Alert variant="danger" title="No se pudo subir el archivo" message={error} onDismiss={() => setError(null)} />}

            <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                <h2 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">1. Preparar</h2>
                <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
                    Carga las hojas en este orden: clientes, créditos, plan de cuotas, pagos y saldos. Cada una depende de las anteriores. Guarda cada hoja como CSV,
                    borra la fila de ejemplo (la amarilla) y deja los datos reales desde la fila siguiente al encabezado.
                </p>

                <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div>
                        <Label htmlFor="tipo-importacion">Hoja a importar</Label>
                        <Select
                            id="tipo-importacion"
                            options={opcionesTipo}
                            placeholder="Elige una hoja"
                            defaultValue={tipo}
                            onChange={handleTipoChange}
                        />
                    </div>

                    <div className="flex items-end">
                        <Button
                            variant="outline"
                            startIcon={<Download className="size-4" />}
                            disabled={tipo === '' || isDownloading}
                            onClick={() => void handleDescargarGuia()}
                        >
                            Descargar guía de la hoja
                        </Button>
                    </div>
                </div>
            </section>

            {tipo !== '' && (
                <CsvExampleCard
                    icon={<FileText className="size-4" />}
                    title="Ejemplo de estructura"
                    subtitle={IMPORT_EXAMPLE_BY_TYPE[tipo].note ?? 'Estructura esperada del archivo CSV para esta hoja.'}
                    columns={IMPORT_EXAMPLE_BY_TYPE[tipo].columns}
                    example={IMPORT_EXAMPLE_BY_TYPE[tipo].example}
                />
            )}

            <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3 md:p-6">
                <h2 className="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">2. Subir</h2>

                <div className="flex flex-col gap-6">
                    <div>
                        <Label htmlFor="archivo-importacion">Archivo CSV</Label>
                        <Dropzone
                            id="archivo-importacion"
                            onFileAccepted={setArchivo}
                            hint={archivo ? archivo.name : 'Arrastra o selecciona un archivo .csv (separador ; o , y codificación UTF-8 o Windows-1252)'}
                            accept={{ 'text/csv': ['.csv'] }}
                        />
                    </div>

                    <div className="flex flex-col gap-4">
                        {tipo !== '' && puedeCrearEmpleadores && IMPORT_TYPES_WITH_EMPLOYER_CHECK.includes(tipo) && (
                            <Checkbox
                                id="crear-empleadores"
                                label="Crear empleadores no encontrados"
                                checked={crearEmpleadores}
                                onChange={setCrearEmpleadores}
                            />
                        )}

                        {tipo !== '' && puedeContabilizar && IMPORT_TYPES_WITH_ACCOUNTING.includes(tipo) && (
                            <Checkbox id="generar-asiento" label="Generar asientos contables" checked={generarAsiento} onChange={setGenerarAsiento} />
                        )}

                        {pideTipoComprobante && (
                            <div>
                                <Label htmlFor="tipo-comprobante-apertura">Tipo de comprobante para la apertura</Label>
                                <Select
                                    id="tipo-comprobante-apertura"
                                    options={opcionesTipoComprobante}
                                    placeholder="Elige el tipo de comprobante"
                                    defaultValue={tipoComprobanteId}
                                    onChange={setTipoComprobanteId}
                                />
                            </div>
                        )}
                    </div>

                    <div className="flex justify-end">
                        <Button
                            startIcon={isSubmitting ? <Spinner size="sm" color="white" /> : <Upload className="size-4" />}
                            disabled={isSubmitting || tipo === '' || archivo === null}
                            onClick={() => void handleSubir()}
                        >
                            {isSubmitting ? 'Subiendo…' : 'Validar archivo'}
                        </Button>
                    </div>
                </div>
            </section>
        </div>
    );
};
