import type { FC } from 'react';
import { useState } from 'react';
import { Link, useLocation } from 'react-router';

import { useAuth } from '../../auth/hooks/use-auth';
import { hasPermission } from '../../auth/utils/has-permission';
import { isCompanyAdmin } from '../../auth/utils/is-company-admin';
import { useCompany } from '../../company/hooks/use-company';
import { API_ROOT } from '../../shared/constants/api';
import { ChevronDown } from 'lucide-react';
import { useSidebar } from '@ui/sidebar/hooks/use-sidebar';
import { useTheme } from '@ui/theme/hooks/use-theme';
import { NAV_ITEMS, SUPERADMIN_NAV_ITEMS } from '../constants/nav-items';
import type { NavItem, NavSubItem } from '../types/nav-item';
import { isNavPathActive } from '../utils/is-nav-path-active';

export const AppSidebar: FC = () => {
    const { isExpanded, isMobileOpen, isHovered, setIsHovered, closeMobileSidebar } = useSidebar();
    const { user } = useAuth();
    const { company } = useCompany();
    const { theme } = useTheme();
    const location = useLocation();
    const logoPath = (theme === 'dark' ? company?.logoDarkPath : null) ?? company?.logoPath;
    const [openMenu, setOpenMenu] = useState<string | null>(null);

    const isActive = (path: string) => isNavPathActive(location.pathname, path);
    const isShown = isExpanded || isHovered || isMobileOpen;

    const canSee = (entry: NavItem | NavSubItem) => {
        if (entry.adminOnly && !isCompanyAdmin(user)) {
            return false;
        }

        if (entry.moduleKey && !hasPermission(user, entry.moduleKey, entry.action ?? 'listar')) {
            return false;
        }

        if (entry.isVisible && !entry.isVisible(user)) {
            return false;
        }

        return true;
    };

    const items = user?.isSuperAdmin ? SUPERADMIN_NAV_ITEMS : NAV_ITEMS;

    const visibleItems = items.map((item) => {
        if (!item.subItems) {
            return canSee(item) ? item : null;
        }

        const subItems = item.subItems.filter(canSee);
        return subItems.length > 0 ? { ...item, subItems } : null;
    }).filter((item): item is NavItem => item !== null);

    const toggleMenu = (name: string) => {
        setOpenMenu((current) => (current === name ? null : name));
    };

    // `top-19` = alto del header en móvil; el drawer se ajusta con top/bottom para no salirse de la pantalla.
    return (
        <aside
            className={`fixed top-19 bottom-0 left-0 flex flex-col lg:top-0 px-5 bg-white dark:bg-gray-900 dark:border-gray-800 text-gray-800 dark:text-white/90 transition-all duration-300 ease-in-out z-50 border-r border-gray-200 ${
                isExpanded || isMobileOpen ? 'w-72.5' : isHovered ? 'w-72.5' : 'w-22.5'
            } ${isMobileOpen ? 'translate-x-0' : '-translate-x-full'} lg:translate-x-0`}
            onMouseEnter={() => !isExpanded && setIsHovered(true)}
            onMouseLeave={() => setIsHovered(false)}
        >
            <div className={`py-8 flex items-center ${!isShown ? 'lg:justify-center' : 'justify-start'}`}>
                <Link to="/" onClick={closeMobileSidebar} className="flex items-center gap-2 text-lg font-semibold text-gray-800 dark:text-white/90">
                    {logoPath ? (
                        <img
                            src={`${API_ROOT}/${logoPath}`}
                            alt={company?.name}
                            className={isShown ? 'max-h-8 max-w-40' : 'size-8 object-contain'}
                        />
                    ) : (
                        <span>{isShown ? (company?.name ?? 'Sistema de Créditos') : 'SC'}</span>
                    )}
                </Link>
            </div>

            <nav className="flex min-h-0 flex-1 flex-col overflow-y-auto pb-6 duration-300 ease-linear no-scrollbar">
                <ul className="flex flex-col gap-4">
                    {visibleItems.map((item) => {
                        const hasChildren = !!item.subItems && item.subItems.length > 0;
                        const parentActive = hasChildren && item.subItems!.some((sub) => isActive(sub.path));
                        const isOpen = openMenu === item.name || parentActive;

                        return (
                            <li key={item.name}>
                                {hasChildren ? (
                                    <>
                                        <button
                                            type="button"
                                            onClick={() => toggleMenu(item.name)}
                                            className={`menu-item group ${parentActive ? 'menu-item-active' : 'menu-item-inactive'}`}
                                        >
                                            <span
                                                className={`menu-item-icon-size ${
                                                    parentActive ? 'menu-item-icon-active' : 'menu-item-icon-inactive'
                                                }`}
                                            >
                                                <item.icon />
                                            </span>
                                            {isShown && <span className="menu-item-text">{item.name}</span>}
                                            {isShown && (
                                                <ChevronDown
                                                    className={`menu-item-arrow ml-auto size-4 ${
                                                        isOpen ? 'menu-item-arrow-active' : 'menu-item-arrow-inactive'
                                                    }`}
                                                />
                                            )}
                                        </button>

                                        {isOpen && isShown && (
                                            <ul className="mt-2 ml-9 flex flex-col gap-1">
                                                {item.subItems!.map((sub) => (
                                                    <li key={sub.path}>
                                                        <Link
                                                            to={sub.path}
                                                            onClick={closeMobileSidebar}
                                                            className={
                                                                isActive(sub.path)
                                                                    ? 'menu-dropdown-item menu-dropdown-item-active'
                                                                    : 'menu-dropdown-item menu-dropdown-item-inactive'
                                                            }
                                                        >
                                                            {sub.icon && (
                                                                <span className="menu-dropdown-item-icon-size size-4">
                                                                    <sub.icon />
                                                                </span>
                                                            )}
                                                            {sub.name}
                                                        </Link>
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </>
                                ) : (
                                    <Link
                                        to={item.path ?? '/'}
                                        onClick={closeMobileSidebar}
                                        className={`menu-item group ${
                                            isActive(item.path ?? '') ? 'menu-item-active' : 'menu-item-inactive'
                                        }`}
                                    >
                                        <span
                                            className={`menu-item-icon-size ${
                                                isActive(item.path ?? '') ? 'menu-item-icon-active' : 'menu-item-icon-inactive'
                                            }`}
                                        >
                                            <item.icon />
                                        </span>
                                        {isShown && <span className="menu-item-text">{item.name}</span>}
                                    </Link>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </nav>
        </aside>
    );
};
