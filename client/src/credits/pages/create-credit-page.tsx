import type { FC } from 'react';

import { PageBreadcrumb } from '@ui/breadcrumb/components/page-breadcrumb';
import { CreateCreditForm } from '../components/create-credit-form';

export const CreateCreditPage: FC = () => {
    return (
        <div className="flex min-h-full flex-col">
            <PageBreadcrumb pageTitle="Nuevo crédito" />

            <CreateCreditForm />
        </div>
    );
};
