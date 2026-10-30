import React from 'react';
import Icon from './Icon';
import Logo from './Logo';

export const NAV_ITEMS = [
    { key: 'dashboard', label: 'Tableau de bord', icon: 'grid_view', href: '/dashboard' },
    { key: 'students', label: 'Étudiants', icon: 'group', href: '#' },
    {
        key: 'hifz',
        label: 'Programme de Hifz',
        icon: 'layers',
        href: '#',
        children: [
            { key: 'sabaq', label: 'Sabaq', href: '#' },
            { key: 'sabqi', label: 'Sabqi', href: '#' },
            { key: 'manzil', label: 'Manzil', href: '#' },
        ],
    },
    { key: 'schedule', label: 'Emplois du temps', icon: 'calendar_today', href: '#' },
    { key: 'recitation', label: 'Récitations', icon: 'graphic_eq', href: '#' },
    { key: 'settings', label: 'Paramètres', icon: 'tune', href: '#' },
];

function NavItems({ activeKey, onLogout, gap = 'gap-3' }) {
    return (
        <>
            {NAV_ITEMS.map((item) =>
                item.children ? (
                    <details key={item.key} className="group/nested rounded transition-colors" open>
                        <summary className="flex items-center justify-between px-3 py-2 rounded text-on-surface-variant dark:text-[#cad7d0] hover:bg-surface-container-high dark:hover:bg-[#2c3d36] hover:text-on-surface dark:hover:text-[#ecf3ef] cursor-pointer select-none list-none transition-colors">
                            <div className={`flex items-center ${gap}`}>
                                <Icon name={item.icon} className="text-[18px]" />
                                <span className="text-[13px] leading-[18px]">{item.label}</span>
                            </div>
                            <Icon
                                name="expand_more"
                                className="text-[18px] text-outline dark:text-[#9bb2a8] transition-transform duration-200 group-open/nested:rotate-180"
                            />
                        </summary>
                        <div className="flex flex-col gap-1 pl-6 pr-1 pt-1 pb-1">
                            {item.children.map((child) => (
                                <a
                                    key={child.key}
                                    href={child.href}
                                    className="flex items-center px-3 py-1 rounded text-[12px] leading-[16px] text-on-surface-variant dark:text-[#cad7d0] hover:bg-surface-container-high dark:hover:bg-[#2c3d36] hover:text-on-surface dark:hover:text-[#ecf3ef] transition-colors"
                                >
                                    {child.label}
                                </a>
                            ))}
                        </div>
                    </details>
                ) : (
                    <a
                        key={item.key}
                        href={item.href}
                        aria-current={item.key === activeKey ? 'page' : undefined}
                        className={`flex items-center justify-between px-3 py-2 rounded transition-colors ${
                            item.key === activeKey
                                ? 'bg-primary dark:bg-[#2e5e4e] text-on-primary dark:text-[#ecf3ef] font-semibold'
                                : 'text-on-surface-variant dark:text-[#cad7d0] hover:bg-surface-container-high dark:hover:bg-[#2c3d36] hover:text-on-surface dark:hover:text-[#ecf3ef]'
                        }`}
                    >
                        <div className={`flex items-center ${gap}`}>
                            <Icon name={item.icon} className="text-[18px]" />
                            <span className="text-[13px] leading-[18px]">{item.label}</span>
                        </div>
                    </a>
                )
            )}

            {onLogout && (
                <div className="pt-1 mt-1 border-t border-outline-variant dark:border-[#2f4239]">
                    <button
                        type="button"
                        onClick={onLogout}
                        className="w-full flex items-center justify-between px-3 py-2 rounded text-error dark:text-[#f87171] hover:bg-error-container dark:hover:bg-[#4a2626]/40 hover:text-on-error-container dark:hover:text-[#fca5a5] transition-colors cursor-pointer"
                    >
                        <div className={`flex items-center ${gap}`}>
                            <Icon name="power_settings_new" className="text-[18px]" />
                            <span className="text-[13px] leading-[18px] font-medium">Déconnexion</span>
                        </div>
                        <span className="font-mono text-[10px] leading-[14px] tracking-[0.02em] border border-error/30 dark:border-red-400/30 px-1.5 py-0.5 rounded uppercase">
                            Exit
                        </span>
                    </button>
                </div>
            )}
        </>
    );
}

export function SidebarNav({ activeKey = 'dashboard', onLogout }) {
    return (
        <div className="flex flex-col flex-1">
            <div className="h-16 px-4 flex items-center justify-between border-b border-outline-variant dark:border-[#2f4239] bg-surface-container-low dark:bg-[#202f29] transition-colors duration-200">
                <Logo className="h-9" />
            </div>

            <div className="px-3 py-2 border-b border-outline-variant dark:border-[#2f4239] bg-surface-container dark:bg-[#182620]/60 transition-colors duration-200">
                <span className="font-mono text-[10px] leading-[14px] uppercase text-on-surface-variant dark:text-[#9bb2a8] tracking-wider">
                    Navigation Module
                </span>
            </div>

            <nav className="p-2 flex flex-col gap-1 flex-1">
                <NavItems activeKey={activeKey} />
            </nav>

            <div className="p-2 border-t border-outline-variant dark:border-[#2f4239] bg-surface-container-lowest dark:bg-[#202f29] transition-colors duration-200">
                <button
                    type="button"
                    onClick={onLogout}
                    className="w-full flex items-center justify-between px-3 py-2 rounded text-error dark:text-[#f87171] hover:bg-error-container dark:hover:bg-[#4a2626]/40 hover:text-on-error-container dark:hover:text-[#fca5a5] transition-colors cursor-pointer"
                >
                    <div className="flex items-center gap-3">
                        <Icon name="power_settings_new" className="text-[18px]" />
                        <span className="text-[13px] leading-[18px] font-medium">Déconnexion</span>
                    </div>
                    <span className="font-mono text-[10px] leading-[14px] tracking-[0.02em] border border-error/30 dark:border-red-400/30 px-1.5 py-0.5 rounded uppercase">
                        Exit
                    </span>
                </button>
            </div>
        </div>
    );
}

export function DrawerNav({ activeKey = 'dashboard', onLogout }) {
    return (
        <div className="flex flex-col gap-1 bg-surface-container-low dark:bg-[#202f29] p-[10px]">
            <NavItems activeKey={activeKey} onLogout={onLogout} gap="gap-2" />
        </div>
    );
}
