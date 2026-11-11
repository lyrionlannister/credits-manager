import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ReportExportButton } from './report-export-button';

const mocks = vi.hoisted(() => ({
    permisos: new Set<string>(),
}));

/** Usuario sin rol admin: cada permiso de la lista es una fila de `permissions`. */
vi.mock('../../auth/hooks/use-auth', () => ({
    useAuth: () => ({
        user: {
            id: 1,
            name: 'Contador',
            username: 'contador',
            role: 'contable',
            isSuperAdmin: false,
            permissions: [...mocks.permisos].map((clave) => {
                const [modulo, accion] = clave.split('.');

                return { modulo, accion };
            }),
        },
    }),
}));

/** Muestra los formatos que recibe y si trae PDF, para afirmar qué opciones habilita cada permiso. */
vi.mock('../../shared/components/export-button', () => ({
    ExportButton: ({ formats, pdfEndpoint }: { formats?: string[]; pdfEndpoint?: string }) => (
        <p>{`formatos: ${(formats ?? []).join(',') || 'ninguno'}; pdf: ${pdfEndpoint ? 'sí' : 'no'}`}</p>
    ),
}));

function renderBoton(exportPermissions: string[]) {
    render(
        <ReportExportButton
            endpoint="/contabilidad/libro-mayor/exportar"
            exportPermissions={exportPermissions}
            pdfEndpoint="/contabilidad/libro-mayor/pdf"
            filters={{}}
        />,
    );
}

describe('ReportExportButton', () => {
    beforeEach(() => {
        mocks.permisos = new Set();
    });

    it('con el permiso de exportar de ese informe ofrece Excel y CSV', () => {
        mocks.permisos = new Set(['informes_contables.exportar_libro_mayor']);
        renderBoton(['informes_contables.exportar_libro_mayor']);

        expect(screen.getByText('formatos: xlsx,csv; pdf: no')).toBeTruthy();
    });

    it('basta uno de los permisos aceptados (caja y bancos también con el del mayor)', () => {
        mocks.permisos = new Set(['informes_contables.exportar_caja_bancos']);
        renderBoton(['informes_contables.exportar_libro_mayor', 'informes_contables.exportar_caja_bancos']);

        expect(screen.getByText('formatos: xlsx,csv; pdf: no')).toBeTruthy();
    });

    it('el permiso de exportar de otro informe no habilita este', () => {
        mocks.permisos = new Set(['informes_contables.exportar_libro_diario', 'informes_contables.imprimir']);
        renderBoton(['informes_contables.exportar_libro_mayor']);

        expect(screen.getByText('formatos: ninguno; pdf: sí')).toBeTruthy();
    });

    it('sin exportar ni imprimir indica qué permiso falta', () => {
        mocks.permisos = new Set(['informes_contables.exportar_libro_diario']);
        renderBoton(['informes_contables.exportar_libro_mayor']);

        expect(screen.queryByText(/formatos/)).toBeNull();
        expect(
            screen.getByText(/Para exportar este informe necesitas el permiso informes_contables\.exportar_libro_mayor/),
        ).toBeTruthy();
    });
});
