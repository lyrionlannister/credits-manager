import type { RouteObject } from 'react-router';
import { Navigate, createBrowserRouter } from 'react-router';

import { CREATE_NOTE_PERMISSIONS } from './invoicing/constants/create-note-permissions';
import { INVOICE_DETAIL_PERMISSIONS } from './invoicing/constants/invoice-detail-permissions';
import { IMPORT_PERMISSIONS } from './imports/constants/import-types';
import { LoginPage } from './auth/pages/login-page';
import { RequireAdmin } from './auth/components/require-admin';
import { RequireAuditAccess } from './auth/components/require-audit-access';
import { RequireAuth } from './auth/components/require-auth';
import { RequireAnyPermission } from './auth/components/require-any-permission';
import { RequireModulePermission } from './auth/components/require-module-permission';
import { RequirePasswordChange } from './auth/components/require-password-change';
import { RequireSuperAdmin } from './auth/components/require-super-admin';
import { RequireCompany } from './company/components/require-company';
import { AppLayout } from './layout/components/app-layout';
import { ErrorPage } from './layout/pages/error-page';
import { lazyPage } from './shared/utils/lazy-page';

const AccountingDashboardPage = lazyPage(() => import('./accounting/pages/accounting-dashboard-page'), 'AccountingDashboardPage');
const AccountsPayablePage = lazyPage(() => import('./accounting/pages/accounts-payable-page'), 'AccountsPayablePage');
const AccountsReceivablePage = lazyPage(() => import('./accounting/pages/accounts-receivable-page'), 'AccountsReceivablePage');
const CreateAdvancePage = lazyPage(() => import('./advances/pages/create-advance-page'), 'CreateAdvancePage');
const CrossAdvancePage = lazyPage(() => import('./advances/pages/cross-advance-page'), 'CrossAdvancePage');
const ChartOfAccountsPage = lazyPage(() => import('./accounting/pages/chart-of-accounts-page'), 'ChartOfAccountsPage');
const CostCentersPage = lazyPage(() => import('./accounting/pages/cost-centers-page'), 'CostCentersPage');
const CostCenterDetailPage = lazyPage(() => import('./accounting/pages/cost-center-detail-page'), 'CostCenterDetailPage');
const CreateAccountPage = lazyPage(() => import('./accounting/pages/create-account-page'), 'CreateAccountPage');
const EditAccountPage = lazyPage(() => import('./accounting/pages/edit-account-page'), 'EditAccountPage');
const CreateCostCenterPage = lazyPage(() => import('./accounting/pages/create-cost-center-page'), 'CreateCostCenterPage');
const EditCostCenterPage = lazyPage(() => import('./accounting/pages/edit-cost-center-page'), 'EditCostCenterPage');
const CreateConceptAccrualPage = lazyPage(() => import('./accounting/pages/create-concept-accrual-page'), 'CreateConceptAccrualPage');
const CreateVoucherPage = lazyPage(() => import('./accounting/pages/create-voucher-page'), 'CreateVoucherPage');
const CreateVoucherTypePage = lazyPage(() => import('./accounting/pages/create-voucher-type-page'), 'CreateVoucherTypePage');
const EditVoucherTypePage = lazyPage(() => import('./accounting/pages/edit-voucher-type-page'), 'EditVoucherTypePage');
const VoucherTypeDetailPage = lazyPage(() => import('./accounting/pages/voucher-type-detail-page'), 'VoucherTypeDetailPage');
const FinancialStatementsPage = lazyPage(() => import('./accounting/pages/financial-statements-page'), 'FinancialStatementsPage');
const ImportsPage = lazyPage(() => import('./imports/pages/imports-page'), 'ImportsPage');
const NewImportPage = lazyPage(() => import('./imports/pages/new-import-page'), 'NewImportPage');
const ImportDetailPage = lazyPage(() => import('./imports/pages/import-detail-page'), 'ImportDetailPage');
const JournalPage = lazyPage(() => import('./accounting/pages/journal-page'), 'JournalPage');
const LedgerPage = lazyPage(() => import('./accounting/pages/ledger-page'), 'LedgerPage');
const ReceiptsListPage = lazyPage(() => import('./receipts/pages/receipts-list-page'), 'ReceiptsListPage');
const BankAccountsPage = lazyPage(() => import('./reconciliation/pages/bank-accounts-page'), 'BankAccountsPage');
const BankStatementsPage = lazyPage(() => import('./reconciliation/pages/bank-statements-page'), 'BankStatementsPage');
const CreateBankAccountPage = lazyPage(() => import('./reconciliation/pages/create-bank-account-page'), 'CreateBankAccountPage');
const ReconciliationMatchingPage = lazyPage(() => import('./reconciliation/pages/reconciliation-matching-page'), 'ReconciliationMatchingPage');
const PeriodsPage = lazyPage(() => import('./accounting/pages/periods-page'), 'PeriodsPage');
const PostingRulesPage = lazyPage(() => import('./accounting/pages/posting-rules-page'), 'PostingRulesPage');
const TrialBalancePage = lazyPage(() => import('./accounting/pages/trial-balance-page'), 'TrialBalancePage');
const AuxiliariesPage = lazyPage(() => import('./accounting/pages/auxiliaries-page'), 'AuxiliariesPage');
const OpeningReconciliationPage = lazyPage(() => import('./accounting/pages/opening-reconciliation-page'), 'OpeningReconciliationPage');
const ClosingControlsPage = lazyPage(() => import('./accounting/pages/closing-controls-page'), 'ClosingControlsPage');
const VoucherDetailPage = lazyPage(() => import('./accounting/pages/voucher-detail-page'), 'VoucherDetailPage');
const VoucherTypesPage = lazyPage(() => import('./accounting/pages/voucher-types-page'), 'VoucherTypesPage');
const BusinessDocumentTypesPage = lazyPage(() => import('./accounting/pages/business-document-types-page'), 'BusinessDocumentTypesPage');
const VouchersListPage = lazyPage(() => import('./accounting/pages/vouchers-list-page'), 'VouchersListPage');
const AuditLogPage = lazyPage(() => import('./audit/pages/audit-log-page'), 'AuditLogPage');
const AuditRecoveryPage = lazyPage(() => import('./audit/pages/audit-recovery-page'), 'AuditRecoveryPage');
const BackupsPage = lazyPage(() => import('./backups/pages/backups-page'), 'BackupsPage');
const SystemHealthPage = lazyPage(() => import('./monitoring/pages/system-health-page'), 'SystemHealthPage');
const ScheduledTasksPage = lazyPage(() => import('./monitoring/pages/scheduled-tasks-page'), 'ScheduledTasksPage');
const ActiveSessionsPage = lazyPage(() => import('./security/pages/active-sessions-page'), 'ActiveSessionsPage');
const AccessHistoryPage = lazyPage(() => import('./security/pages/access-history-page'), 'AccessHistoryPage');
const CompanyAdminsPage = lazyPage(() => import('./security/pages/company-admins-page'), 'CompanyAdminsPage');
const CalendarPage = lazyPage(() => import('./calendar/pages/calendar-page'), 'CalendarPage');
const TaxObligationsPage = lazyPage(() => import('./tax-obligations/pages/tax-obligations-page'), 'TaxObligationsPage');
const CreateEconomicActivityPage = lazyPage(() => import('./expenses/pages/create-economic-activity-page'), 'CreateEconomicActivityPage');
const CreateExpensePage = lazyPage(() => import('./expenses/pages/create-expense-page'), 'CreateExpensePage');
const CreateExpenseTypePage = lazyPage(() => import('./expenses/pages/create-expense-type-page'), 'CreateExpenseTypePage');
const EditExpenseTypePage = lazyPage(() => import('./expenses/pages/edit-expense-type-page'), 'EditExpenseTypePage');
const ExpenseTypeDetailPage = lazyPage(() => import('./expenses/pages/expense-type-detail-page'), 'ExpenseTypeDetailPage');
const CreateMunicipalityPage = lazyPage(() => import('./geography/pages/create-municipality-page'), 'CreateMunicipalityPage');
const CreateParametrizationPage = lazyPage(() => import('./expenses/pages/create-parametrization-page'), 'CreateParametrizationPage');
const EditParametrizationPage = lazyPage(() => import('./expenses/pages/edit-parametrization-page'), 'EditParametrizationPage');
const CreateReteicaTariffPage = lazyPage(() => import('./expenses/pages/create-reteica-tariff-page'), 'CreateReteicaTariffPage');
const CreateUvtValuePage = lazyPage(() => import('./expenses/pages/create-uvt-value-page'), 'CreateUvtValuePage');
const EditUvtValuePage = lazyPage(() => import('./expenses/pages/edit-uvt-value-page'), 'EditUvtValuePage');
const ExpenseCostCenterReportPage = lazyPage(() => import('./expenses/pages/expense-cost-center-report-page'), 'ExpenseCostCenterReportPage');
const EconomicActivitiesPage = lazyPage(() => import('./expenses/pages/economic-activities-page'), 'EconomicActivitiesPage');
const EditEconomicActivityPage = lazyPage(() => import('./expenses/pages/edit-economic-activity-page'), 'EditEconomicActivityPage');
const ExpenseDetailPage = lazyPage(() => import('./expenses/pages/expense-detail-page'), 'ExpenseDetailPage');
const ExpenseTypesPage = lazyPage(() => import('./expenses/pages/expense-types-page'), 'ExpenseTypesPage');
const ExpensesListPage = lazyPage(() => import('./expenses/pages/expenses-list-page'), 'ExpensesListPage');
const ConceptTaxSettingsPage = lazyPage(() => import('./invoicing/pages/concept-tax-settings-page'), 'ConceptTaxSettingsPage');
const CreateInvoicePage = lazyPage(() => import('./invoicing/pages/create-invoice-page'), 'CreateInvoicePage');
const InvoicePaidInterestPage = lazyPage(() => import('./invoicing/pages/invoice-paid-interest-page'), 'InvoicePaidInterestPage');
const CreateNotePage = lazyPage(() => import('./invoicing/pages/create-note-page'), 'CreateNotePage');
const CreateMultiplePaymentPage = lazyPage(() => import('./invoicing/pages/create-multiple-payment-page'), 'CreateMultiplePaymentPage');
const InvoiceDetailPage = lazyPage(() => import('./invoicing/pages/invoice-detail-page'), 'InvoiceDetailPage');
const InvoiceSequencesPage = lazyPage(() => import('./invoicing/pages/invoice-sequences-page'), 'InvoiceSequencesPage');
const FacturasListPage = lazyPage(() => import('./invoicing/pages/facturas-list-page'), 'FacturasListPage');
const NotasCreditoListPage = lazyPage(() => import('./invoicing/pages/notas-credito-list-page'), 'NotasCreditoListPage');
const NotasDebitoListPage = lazyPage(() => import('./invoicing/pages/notas-debito-list-page'), 'NotasDebitoListPage');
const MunicipalitiesAdminPage = lazyPage(() => import('./geography/pages/municipalities-admin-page'), 'MunicipalitiesAdminPage');
const ParametrizationsPage = lazyPage(() => import('./expenses/pages/parametrizations-page'), 'ParametrizationsPage');
const ReteicaTariffsPage = lazyPage(() => import('./expenses/pages/reteica-tariffs-page'), 'ReteicaTariffsPage');
const UvtValuesPage = lazyPage(() => import('./expenses/pages/uvt-values-page'), 'UvtValuesPage');
const CreateRolePage = lazyPage(() => import('./auth/pages/create-role-page'), 'CreateRolePage');
const CreateUserPage = lazyPage(() => import('./auth/pages/create-user-page'), 'CreateUserPage');
const EditRolePage = lazyPage(() => import('./auth/pages/edit-role-page'), 'EditRolePage');
const EditUserPage = lazyPage(() => import('./auth/pages/edit-user-page'), 'EditUserPage');
const RolesListPage = lazyPage(() => import('./auth/pages/roles-list-page'), 'RolesListPage');
const UsersListPage = lazyPage(() => import('./auth/pages/users-list-page'), 'UsersListPage');
const ClientDetailPage = lazyPage(() => import('./clients/pages/client-detail-page'), 'ClientDetailPage');
const ClientsListPage = lazyPage(() => import('./clients/pages/clients-list-page'), 'ClientsListPage');
const CreateClientPage = lazyPage(() => import('./clients/pages/create-client-page'), 'CreateClientPage');
const EditClientPage = lazyPage(() => import('./clients/pages/edit-client-page'), 'EditClientPage');
const CreateEmployerPage = lazyPage(() => import('./employers/pages/create-employer-page'), 'CreateEmployerPage');
const EditEmployerPage = lazyPage(() => import('./employers/pages/edit-employer-page'), 'EditEmployerPage');
const EmployersListPage = lazyPage(() => import('./employers/pages/employers-list-page'), 'EmployersListPage');
const AccountStatementPage = lazyPage(() => import('./credits/pages/account-statement-page'), 'AccountStatementPage');
const EditCreditPage = lazyPage(() => import('./credits/pages/edit-credit-page'), 'EditCreditPage');
const CreateCreditPage = lazyPage(() => import('./credits/pages/create-credit-page'), 'CreateCreditPage');
const CreditDetailPage = lazyPage(() => import('./credits/pages/credit-detail-page'), 'CreditDetailPage');
const CreditsListPage = lazyPage(() => import('./credits/pages/credits-list-page'), 'CreditsListPage');
const CompaniesListPage = lazyPage(() => import('./company/pages/companies-list-page'), 'CompaniesListPage');
const CompanyDashboardPage = lazyPage(() => import('./company/pages/company-dashboard-page'), 'CompanyDashboardPage');
const CreateCompanyPage = lazyPage(() => import('./company/pages/create-company-page'), 'CreateCompanyPage');
const EditCompanyPage = lazyPage(() => import('./company/pages/edit-company-page'), 'EditCompanyPage');
const EmailSettingsPage = lazyPage(() => import('./email/pages/email-settings-page'), 'EmailSettingsPage');
const EmailTemplatesPage = lazyPage(() => import('./email/pages/email-templates-page'), 'EmailTemplatesPage');
const CreateEmailTemplatePage = lazyPage(() => import('./email/pages/create-email-template-page'), 'CreateEmailTemplatePage');
const EditEmailTemplatePage = lazyPage(() => import('./email/pages/edit-email-template-page'), 'EditEmailTemplatePage');
const HomePage = lazyPage(() => import('./layout/pages/home-page'), 'HomePage');
const NotificationsPage = lazyPage(() => import('./notifications/pages/notifications-page'), 'NotificationsPage');
const CreditPaymentsPage = lazyPage(() => import('./payments/pages/credit-payments-page'), 'CreditPaymentsPage');
const PaymentsListPage = lazyPage(() => import('./payments/pages/payments-list-page'), 'PaymentsListPage');
const RegisterPaymentPage = lazyPage(() => import('./payments/pages/register-payment-page'), 'RegisterPaymentPage');
const SearchCreditsPage = lazyPage(() => import('./payments/pages/search-credits-page'), 'SearchCreditsPage');
const SettleCreditPage = lazyPage(() => import('./payments/pages/settle-credit-page'), 'SettleCreditPage');
const EmployerReportPage = lazyPage(() => import('./portfolio/pages/employer-report-page'), 'EmployerReportPage');
const PortfolioPage = lazyPage(() => import('./portfolio/pages/portfolio-page'), 'PortfolioPage');
const GoalsPage = lazyPage(() => import('./goals/pages/goals-page'), 'GoalsPage');
const GoalSettingsPage = lazyPage(() => import('./goals/pages/goal-settings-page'), 'GoalSettingsPage');
const CreateThirdPartyPage = lazyPage(() => import('./third-parties/pages/create-third-party-page'), 'CreateThirdPartyPage');
const EditThirdPartyPage = lazyPage(() => import('./third-parties/pages/edit-third-party-page'), 'EditThirdPartyPage');
const ThirdPartiesListPage = lazyPage(() => import('./third-parties/pages/third-parties-list-page'), 'ThirdPartiesListPage');

const routes: RouteObject[] = [
    {
        errorElement: <ErrorPage />,
        children: [
            { path: '/login', element: <LoginPage /> },
            {
                element: (
                    <RequireAuth>
                        <RequirePasswordChange>
                            <RequireCompany>
                                <AppLayout />
                            </RequireCompany>
                        </RequirePasswordChange>
                    </RequireAuth>
                ),
                children: [
                    { index: true, element: <HomePage /> },
                    { path: 'notificaciones', element: <NotificationsPage /> },
                    {
                        path: 'metas',
                        element: (
                            <RequireModulePermission modulo="metas" accion="listar">
                                <GoalsPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'metas/configuracion',
                        element: (
                            <RequireModulePermission modulo="metas" accion="configurar">
                                <GoalSettingsPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'calendario',
                        element: (
                            <RequireModulePermission modulo="calendario" accion="listar">
                                <CalendarPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'obligaciones-tributarias',
                        element: (
                            <RequireModulePermission modulo="obligaciones_tributarias" accion="listar">
                                <TaxObligationsPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'clientes',
                        element: (
                            <RequireModulePermission modulo="clientes" accion="listar">
                                <ClientsListPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'clientes/crear',
                        element: (
                            <RequireModulePermission modulo="clientes" accion="crear">
                                <CreateClientPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'clientes/:id/editar',
                        element: (
                            <RequireModulePermission modulo="clientes" accion="editar">
                                <EditClientPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'clientes/:id',
                        element: (
                            <RequireModulePermission modulo="clientes" accion="ver">
                                <ClientDetailPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'empleadores',
                        element: (
                            <RequireModulePermission modulo="empleadores" accion="listar">
                                <EmployersListPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'empleadores/crear',
                        element: (
                            <RequireModulePermission modulo="empleadores" accion="crear">
                                <CreateEmployerPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'empleadores/:id/editar',
                        element: (
                            <RequireModulePermission modulo="empleadores" accion="editar">
                                <EditEmployerPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'creditos',
                        element: (
                            <RequireModulePermission modulo="creditos" accion="listar">
                                <CreditsListPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'creditos/crear',
                        element: (
                            <RequireModulePermission modulo="creditos" accion="crear">
                                <CreateCreditPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'creditos/:id',
                        element: (
                            <RequireModulePermission modulo="creditos" accion="listar">
                                <CreditDetailPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'creditos/:id/editar',
                        element: (
                            <RequireModulePermission modulo="creditos" accion="editar">
                                <EditCreditPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'creditos/:id/estado-cuenta',
                        element: (
                            <RequireModulePermission modulo="creditos" accion="listar">
                                <AccountStatementPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'pagos',
                        element: (
                            <RequireModulePermission modulo="pagos" accion="listar">
                                <PaymentsListPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'recibos',
                        element: (
                            <RequireModulePermission modulo="recibos" accion="listar">
                                <ReceiptsListPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'pagos/buscar',
                        element: (
                            <RequireModulePermission modulo="pagos" accion="crear">
                                <SearchCreditsPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'pagos/creditos/:id',
                        element: (
                            <RequireModulePermission modulo="pagos" accion="listar">
                                <CreditPaymentsPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'pagos/creditos/:id/nuevo',
                        element: (
                            <RequireModulePermission modulo="pagos" accion="crear">
                                <RegisterPaymentPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'pagos/creditos/:id/liquidar',
                        element: (
                            <RequireModulePermission modulo="pagos" accion="crear">
                                <SettleCreditPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'cartera',
                        element: (
                            <RequireModulePermission modulo="cartera" accion="listar">
                                <PortfolioPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'cartera/reporte-empleador',
                        element: (
                            <RequireModulePermission modulo="cartera" accion="listar">
                                <EmployerReportPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'empresa',
                        element: (
                            <RequireAdmin>
                                <EditCompanyPage />
                            </RequireAdmin>
                        ),
                    },
                    {
                        path: 'correo',
                        element: (
                            <RequireAdmin>
                                <EmailSettingsPage />
                            </RequireAdmin>
                        ),
                    },
                    {
                        path: 'plantillas-correo',
                        element: (
                            <RequireAdmin>
                                <EmailTemplatesPage />
                            </RequireAdmin>
                        ),
                    },
                    {
                        path: 'plantillas-correo/:tipo/crear',
                        element: (
                            <RequireAdmin>
                                <CreateEmailTemplatePage />
                            </RequireAdmin>
                        ),
                    },
                    {
                        path: 'plantillas-correo/:tipo/:id/editar',
                        element: (
                            <RequireAdmin>
                                <EditEmailTemplatePage />
                            </RequireAdmin>
                        ),
                    },
                    {
                        path: 'empresas',
                        element: (
                            <RequireSuperAdmin>
                                <CompaniesListPage />
                            </RequireSuperAdmin>
                        ),
                    },
                    {
                        path: 'empresas/crear',
                        element: (
                            <RequireSuperAdmin>
                                <CreateCompanyPage />
                            </RequireSuperAdmin>
                        ),
                    },
                    {
                        path: 'empresas/:id/dashboard',
                        element: (
                            <RequireSuperAdmin>
                                <CompanyDashboardPage />
                            </RequireSuperAdmin>
                        ),
                    },
                    {
                        path: 'geografia/municipios',
                        element: (
                            <RequireSuperAdmin>
                                <MunicipalitiesAdminPage />
                            </RequireSuperAdmin>
                        ),
                    },
                    {
                        path: 'geografia/municipios/crear',
                        element: (
                            <RequireSuperAdmin>
                                <CreateMunicipalityPage />
                            </RequireSuperAdmin>
                        ),
                    },
                    {
                        path: 'auditoria',
                        element: (
                            <RequireAuditAccess>
                                <AuditLogPage />
                            </RequireAuditAccess>
                        ),
                    },
                    {
                        path: 'recuperacion-auditoria',
                        element: (
                            <RequireSuperAdmin>
                                <AuditRecoveryPage />
                            </RequireSuperAdmin>
                        ),
                    },
                    {
                        path: 'respaldos',
                        element: <BackupsPage />,
                    },
                    {
                        path: 'monitoreo',
                        element: (
                            <RequireSuperAdmin>
                                <SystemHealthPage />
                            </RequireSuperAdmin>
                        ),
                    },
                    {
                        path: 'tareas-programadas',
                        element: (
                            <RequireSuperAdmin>
                                <ScheduledTasksPage />
                            </RequireSuperAdmin>
                        ),
                    },
                    {
                        path: 'seguridad/sesiones',
                        element: (
                            <RequireSuperAdmin>
                                <ActiveSessionsPage />
                            </RequireSuperAdmin>
                        ),
                    },
                    {
                        path: 'seguridad/accesos',
                        element: (
                            <RequireSuperAdmin>
                                <AccessHistoryPage />
                            </RequireSuperAdmin>
                        ),
                    },
                    {
                        path: 'seguridad/admins-empresa',
                        element: (
                            <RequireSuperAdmin>
                                <CompanyAdminsPage />
                            </RequireSuperAdmin>
                        ),
                    },
                    {
                        path: 'roles',
                        element: (
                            <RequireAdmin>
                                <RolesListPage />
                            </RequireAdmin>
                        ),
                    },
                    {
                        path: 'roles/crear',
                        element: (
                            <RequireAdmin>
                                <CreateRolePage />
                            </RequireAdmin>
                        ),
                    },
                    {
                        path: 'roles/:id/editar',
                        element: (
                            <RequireAdmin>
                                <EditRolePage />
                            </RequireAdmin>
                        ),
                    },
                    {
                        path: 'usuarios',
                        element: (
                            <RequireModulePermission modulo="usuarios" accion="listar">
                                <UsersListPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'usuarios/crear',
                        element: (
                            <RequireModulePermission modulo="usuarios" accion="crear">
                                <CreateUserPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'usuarios/:id/editar',
                        element: (
                            <RequireModulePermission modulo="usuarios" accion="editar">
                                <EditUserPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'terceros',
                        element: (
                            <RequireModulePermission modulo="terceros" accion="listar">
                                <ThirdPartiesListPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'terceros/crear',
                        element: (
                            <RequireModulePermission modulo="terceros" accion="crear">
                                <CreateThirdPartyPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'terceros/:id/editar',
                        element: (
                            <RequireModulePermission modulo="terceros" accion="editar">
                                <EditThirdPartyPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/plan-cuentas',
                        element: (
                            <RequireModulePermission modulo="configuracion_contable" accion="listar_plan_cuentas">
                                <ChartOfAccountsPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/plan-cuentas/crear',
                        element: (
                            <RequireModulePermission modulo="configuracion_contable" accion="crear_plan_cuentas">
                                <CreateAccountPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/plan-cuentas/:id/editar',
                        element: (
                            <RequireModulePermission modulo="configuracion_contable" accion="editar_plan_cuentas">
                                <EditAccountPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/periodos',
                        element: (
                            <RequireModulePermission modulo="configuracion_contable" accion="listar_periodos">
                                <PeriodsPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/tipos-comprobante',
                        element: (
                            <RequireModulePermission modulo="configuracion_contable" accion="listar_tipos_comprobante">
                                <VoucherTypesPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/tipos-comprobante/crear',
                        element: (
                            <RequireModulePermission modulo="configuracion_contable" accion="crear_tipos_comprobante">
                                <CreateVoucherTypePage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/tipos-comprobante/:id',
                        element: (
                            <RequireModulePermission modulo="configuracion_contable" accion="listar_tipos_comprobante">
                                <VoucherTypeDetailPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/tipos-comprobante/:id/editar',
                        element: (
                            <RequireModulePermission modulo="configuracion_contable" accion="editar_tipos_comprobante">
                                <EditVoucherTypePage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/tipos-documento-negocio',
                        element: (
                            <RequireModulePermission modulo="configuracion_contable" accion="listar_tipos_documento_negocio">
                                <BusinessDocumentTypesPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/centros-costo',
                        element: (
                            <RequireModulePermission modulo="configuracion_contable" accion="listar_centros_costo">
                                <CostCentersPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/centros-costo/crear',
                        element: (
                            <RequireModulePermission modulo="configuracion_contable" accion="crear_centros_costo">
                                <CreateCostCenterPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/centros-costo/:id',
                        element: (
                            <RequireModulePermission modulo="configuracion_contable" accion="listar_centros_costo">
                                <CostCenterDetailPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/centros-costo/:id/editar',
                        element: (
                            <RequireModulePermission modulo="configuracion_contable" accion="editar_centros_costo">
                                <EditCostCenterPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/reglas',
                        element: (
                            <RequireModulePermission modulo="configuracion_contable" accion="configurar">
                                <PostingRulesPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/comprobantes',
                        element: (
                            <RequireModulePermission modulo="contabilidad" accion="listar_comprobantes">
                                <VouchersListPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/comprobantes/crear',
                        element: (
                            <RequireModulePermission modulo="contabilidad" accion="crear_manual">
                                <CreateVoucherPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/comprobantes/causar-concepto',
                        element: (
                            <RequireModulePermission modulo="contabilidad" accion="crear_manual">
                                <CreateConceptAccrualPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/comprobantes/:id',
                        element: (
                            <RequireModulePermission modulo="contabilidad" accion="listar_comprobantes">
                                <VoucherDetailPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/dashboard',
                        element: (
                            <RequireModulePermission modulo="informes_contables" accion="ver_dashboard_contable">
                                <AccountingDashboardPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/libro-diario',
                        element: (
                            <RequireModulePermission modulo="informes_contables" accion="listar_libro_diario">
                                <JournalPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/libro-mayor',
                        element: (
                            <RequireModulePermission modulo="informes_contables" accion="listar_libro_mayor">
                                <LedgerPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/caja-bancos',
                        element: (
                            <RequireModulePermission modulo="informes_contables" accion="listar_caja_bancos">
                                <LedgerPage modo="cajaBancos" />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/cuentas-por-pagar',
                        element: (
                            <RequireModulePermission modulo="contabilidad" accion="listar_cuentas_por_pagar">
                                <AccountsPayablePage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/cuentas-por-cobrar',
                        element: (
                            <RequireModulePermission modulo="contabilidad" accion="listar_cuentas_por_cobrar">
                                <AccountsReceivablePage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/anticipos/crear',
                        element: (
                            <RequireModulePermission modulo="anticipos" accion="crear">
                                <CreateAdvancePage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/anticipos/cruzar',
                        element: (
                            <RequireModulePermission modulo="anticipos" accion="crear">
                                <CrossAdvancePage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/balance-prueba',
                        element: (
                            <RequireModulePermission modulo="informes_contables" accion="listar_balance_prueba">
                                <TrialBalancePage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/estados-financieros',
                        element: (
                            <RequireModulePermission modulo="informes_contables" accion="listar_estados_financieros">
                                <FinancialStatementsPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/auxiliares',
                        element: (
                            <RequireModulePermission modulo="informes_contables" accion="listar_auxiliares">
                                <AuxiliariesPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/conciliacion-apertura',
                        element: (
                            <RequireModulePermission modulo="informes_contables" accion="listar_conciliacion_apertura">
                                <OpeningReconciliationPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'contabilidad/controles-cierre',
                        element: (
                            <RequireModulePermission modulo="informes_contables" accion="listar_controles_cierre">
                                <ClosingControlsPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'importaciones',
                        element: (
                            <RequireAnyPermission permisos={IMPORT_PERMISSIONS}>
                                <ImportsPage />
                            </RequireAnyPermission>
                        ),
                    },
                    {
                        path: 'importaciones/nueva',
                        element: (
                            <RequireAnyPermission permisos={IMPORT_PERMISSIONS}>
                                <NewImportPage />
                            </RequireAnyPermission>
                        ),
                    },
                    {
                        path: 'importaciones/:id',
                        element: (
                            <RequireAnyPermission permisos={IMPORT_PERMISSIONS}>
                                <ImportDetailPage />
                            </RequireAnyPermission>
                        ),
                    },
                    {
                        path: 'facturacion',
                        element: (
                            <RequireModulePermission modulo="facturacion" accion="listar_facturas">
                                <FacturasListPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'facturacion/notas-credito',
                        element: (
                            <RequireModulePermission modulo="facturacion" accion="listar_notas_credito">
                                <NotasCreditoListPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'facturacion/notas-debito',
                        element: (
                            <RequireModulePermission modulo="facturacion" accion="listar_notas_debito">
                                <NotasDebitoListPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'facturacion/consecutivos',
                        element: (
                            <RequireModulePermission modulo="facturacion" accion="configurar">
                                <InvoiceSequencesPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'facturacion/parametrizacion-conceptos',
                        element: (
                            <RequireModulePermission modulo="facturacion" accion="configurar">
                                <ConceptTaxSettingsPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'facturacion/crear',
                        element: (
                            <RequireModulePermission modulo="facturacion" accion="crear">
                                <CreateInvoicePage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'facturacion/intereses-pagados/crear',
                        element: (
                            <RequireModulePermission modulo="facturacion" accion="crear">
                                <InvoicePaidInterestPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'facturacion/notas/crear',
                        element: (
                            <RequireAnyPermission permisos={CREATE_NOTE_PERMISSIONS}>
                                <CreateNotePage />
                            </RequireAnyPermission>
                        ),
                    },
                    {
                        path: 'facturacion/cobros/crear',
                        element: (
                            <RequireModulePermission modulo="facturacion" accion="registrar_cobro">
                                <CreateMultiplePaymentPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'facturacion/:id',
                        element: (
                            <RequireAnyPermission permisos={INVOICE_DETAIL_PERMISSIONS}>
                                <InvoiceDetailPage />
                            </RequireAnyPermission>
                        ),
                    },
                    {
                        path: 'gastos',
                        element: (
                            <RequireModulePermission modulo="gastos" accion="listar_gastos">
                                <ExpensesListPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'gastos/crear',
                        element: (
                            <RequireModulePermission modulo="gastos" accion="crear">
                                <CreateExpensePage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'gastos/:id',
                        element: (
                            <RequireModulePermission modulo="gastos" accion="listar_gastos">
                                <ExpenseDetailPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'gastos/por-centro-costo',
                        element: (
                            <RequireModulePermission modulo="gastos" accion="listar_gastos_centro_costo">
                                <ExpenseCostCenterReportPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'gastos/tipos-gasto',
                        element: (
                            <RequireModulePermission modulo="gastos" accion="listar_tipos_gasto">
                                <ExpenseTypesPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'gastos/tipos-gasto/crear',
                        element: (
                            <RequireModulePermission modulo="gastos" accion="crear_tipos_gasto">
                                <CreateExpenseTypePage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'gastos/tipos-gasto/:id',
                        element: (
                            <RequireModulePermission modulo="gastos" accion="listar_tipos_gasto">
                                <ExpenseTypeDetailPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'gastos/tipos-gasto/:id/editar',
                        element: (
                            <RequireModulePermission modulo="gastos" accion="editar_tipos_gasto">
                                <EditExpenseTypePage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'gastos/actividades-economicas',
                        element: (
                            <RequireModulePermission modulo="tributario" accion="listar_actividades_economicas">
                                <EconomicActivitiesPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'gastos/actividades-economicas/crear',
                        element: (
                            <RequireModulePermission modulo="tributario" accion="crear_actividades_economicas">
                                <CreateEconomicActivityPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'gastos/actividades-economicas/:id/editar',
                        element: (
                            <RequireModulePermission modulo="tributario" accion="editar_actividades_economicas">
                                <EditEconomicActivityPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'gastos/valores-uvt',
                        element: (
                            <RequireModulePermission modulo="tributario" accion="listar_valores_uvt">
                                <UvtValuesPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'gastos/valores-uvt/crear',
                        element: (
                            <RequireModulePermission modulo="tributario" accion="crear_valores_uvt">
                                <CreateUvtValuePage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'gastos/valores-uvt/:id/editar',
                        element: (
                            <RequireModulePermission modulo="tributario" accion="editar_valores_uvt">
                                <EditUvtValuePage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'gastos/parametrizaciones',
                        element: (
                            <RequireModulePermission modulo="gastos" accion="listar_parametrizaciones">
                                <ParametrizationsPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'gastos/parametrizaciones/crear',
                        element: (
                            <RequireModulePermission modulo="gastos" accion="crear_parametrizaciones">
                                <CreateParametrizationPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'gastos/parametrizaciones/:id/editar',
                        element: (
                            <RequireModulePermission modulo="gastos" accion="crear_parametrizaciones">
                                <EditParametrizationPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'conciliacion/cuentas-bancarias',
                        element: (
                            <RequireModulePermission modulo="conciliacion" accion="listar">
                                <BankAccountsPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'conciliacion/cuentas-bancarias/crear',
                        element: (
                            <RequireModulePermission modulo="conciliacion" accion="configurar">
                                <CreateBankAccountPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'conciliacion/cuentas-bancarias/:id/extractos',
                        element: (
                            <RequireModulePermission modulo="conciliacion" accion="listar">
                                <BankStatementsPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'conciliacion/extractos/:id/conciliar',
                        element: (
                            <RequireModulePermission modulo="conciliacion" accion="listar">
                                <ReconciliationMatchingPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'gastos/tarifas-reteica',
                        element: (
                            <RequireModulePermission modulo="tributario" accion="listar_tarifas_reteica">
                                <ReteicaTariffsPage />
                            </RequireModulePermission>
                        ),
                    },
                    {
                        path: 'gastos/tarifas-reteica/crear',
                        element: (
                            <RequireModulePermission modulo="tributario" accion="crear_tarifas_reteica">
                                <CreateReteicaTariffPage />
                            </RequireModulePermission>
                        ),
                    },
                ],
            },
            { path: '*', element: <Navigate to="/" replace /> },
        ],
    },
];

export const router = createBrowserRouter(routes);
