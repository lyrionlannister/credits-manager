/** Acceso de soporte just-in-time, tal como lo devuelve `GET /accesos-soporte`. */
export type ApiAccesoSoporte = {
    id: number;
    superadmin_id: number;
    empresa_id: number;
    empresa: { id: number; nombre: string } | null;
    superadmin: { id: number; usuario: string } | null;
    motivo: string;
    estado: 'solicitado' | 'aprobado' | 'activo' | 'expirado' | 'revocado' | 'rechazado';
    duracion_minutos: number;
    solicitado_en: string;
    aprobado_en: string | null;
    activado_en: string | null;
    expira_en: string | null;
    revocado_en: string | null;
};
