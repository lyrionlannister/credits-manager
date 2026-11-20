import { useCallback, useState } from 'react';

import { API_PREFIX, API_ROOT } from '../constants/api';
import { SESSION_EXPIRED_EVENT } from '../constants/auth-events';
import { readCookie } from '../utils/read-cookie';
import { readFilenameFromDisposition } from '../utils/read-filename-from-disposition';
import { saveBlob } from '../utils/save-blob';

const GENERIC_ERROR = 'Ocurrió un error inesperado.';

/**
 * Descarga un archivo binario de la API (PDF, Excel…) con la sesión del usuario y lo guarda con el nombre que manda el servidor.
 * `download` devuelve el mensaje de error, o `null` si salió bien.
 */
export function useDownloadFile() {
    const [isDownloading, setIsDownloading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const download = useCallback(async (path: string, fallbackFilename: string, body?: unknown): Promise<string | null> => {
        setIsDownloading(true);
        setError(null);

        try {
            const headers = new Headers({ Accept: 'application/pdf, application/octet-stream, application/json' });

            if (body !== undefined) {
                await fetch(`${API_ROOT}/sanctum/csrf-cookie`, { credentials: 'include' });
                headers.set('Content-Type', 'application/json');
                headers.set('X-XSRF-TOKEN', readCookie('XSRF-TOKEN') ?? '');
            }

            const response = await fetch(`${API_ROOT}${API_PREFIX}${path}`, {
                method: body === undefined ? 'GET' : 'POST',
                credentials: 'include',
                headers,
                body: body === undefined ? undefined : JSON.stringify(body),
            });

            if (response.status === 401) {
                window.dispatchEvent(new Event(SESSION_EXPIRED_EVENT));
                return null;
            }

            if (!response.ok) {
                const body = await response.json().catch(() => null);
                throw new Error(body?.message ?? 'No se pudo descargar el archivo.');
            }

            const blob = await response.blob();

            saveBlob(readFilenameFromDisposition(response.headers.get('Content-Disposition')) ?? fallbackFilename, blob);

            return null;
        } catch (caught) {
            const message = caught instanceof Error && !(caught instanceof TypeError) ? caught.message : GENERIC_ERROR;
            setError(message);

            return message;
        } finally {
            setIsDownloading(false);
        }
    }, []);

    return { download, isDownloading, error };
}
