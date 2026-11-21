import type { FC } from 'react';
import { isRouteErrorResponse, useNavigate, useRouteError } from 'react-router';

import { RefreshCw } from 'lucide-react';
import { Button } from '@ui/button/components/button';
import { isStaleChunkError } from '../utils/is-stale-chunk-error';

export const ErrorPage: FC = () => {
    const error = useRouteError();
    const navigate = useNavigate();

    // Tras un despliegue el navegador pide chunks que ya no existen: basta recargar, el detalle técnico no le sirve al usuario.
    if (isStaleChunkError(error)) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4 dark:bg-gray-900">
                <div className="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 text-center shadow-theme-sm dark:border-gray-800 dark:bg-gray-900">
                    <div className="mx-auto flex size-12 items-center justify-center rounded-xl bg-brand-50 text-brand-500 dark:bg-brand-500/15 dark:text-brand-400">
                        <RefreshCw className="size-6" />
                    </div>
                    <h1 className="mt-4 text-title-sm font-semibold text-gray-800 dark:text-white/90">Hay una nueva versión</h1>
                    <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        La aplicación se actualizó. Recarga la página para continuar (F5, o Ctrl + F5 si el problema persiste).
                    </p>
                    <div className="mt-6 flex justify-center">
                        <Button onClick={() => window.location.reload()} startIcon={<RefreshCw className="size-4" />}>
                            Recargar página
                        </Button>
                    </div>
                </div>
            </div>
        );
    }

    const mensaje = isRouteErrorResponse(error)
        ? error.statusText || 'No se pudo completar la solicitud.'
        : error instanceof Error
          ? error.message
          : 'Ocurrió un error inesperado.';

    return (
        <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4 dark:bg-gray-900">
            <div className="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 text-center shadow-theme-sm dark:border-gray-800 dark:bg-gray-900">
                <h1 className="text-title-sm font-semibold text-gray-800 dark:text-white/90">Algo salió mal</h1>
                <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">{mensaje}</p>
                <div className="mt-6 flex justify-center gap-3">
                    <Button variant="outline" onClick={() => navigate(-1)}>
                        Volver
                    </Button>
                    <Button onClick={() => navigate('/', { replace: true })}>Ir al inicio</Button>
                </div>
            </div>
        </div>
    );
};
