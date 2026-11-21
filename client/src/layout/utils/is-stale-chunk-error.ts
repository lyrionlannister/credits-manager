/** Mensajes de Chrome/Edge, Firefox, Safari y del preload de Vite cuando el chunk de una página ya no existe tras un despliegue. */
const PATRONES_CHUNK_VIEJO = [
    'failed to fetch dynamically imported module',
    'error loading dynamically imported module',
    'importing a module script failed',
    'unable to preload css',
];

export function isStaleChunkError(error: unknown): boolean {
    if (!(error instanceof Error)) {
        return false;
    }

    const mensaje = error.message.toLowerCase();

    return PATRONES_CHUNK_VIEJO.some((patron) => mensaje.includes(patron));
}
