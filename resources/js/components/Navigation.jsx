import React from 'react';
import Icon from './Icon';
import { useLanguage } from '../hooks/useLanguage';
import { pageGroupsForRole } from '../pages/registry';

/**
 * Registry-driven page navigation.
 *
 * Every entry in `PAGE_GROUPS` is rendered as a link to its hash route
 * (`/dashboard#/<key>`), so adding a page in `pages/registry.js` links it
 * here automatically. Pages are filtered by the signed-in user's role.
 */
export function PageNav({ activeKey = null, onNavigate, role, compact = false }) {
    const { lang, isRTL } = useLanguage();
    const gap = compact ? 'gap-2' : 'gap-3';
    const align = isRTL ? 'text-right' : 'text-left';
    const groups = pageGroupsForRole(role);

    return (
        <nav className={`flex flex-col gap-3 overflow-y-auto min-h-0 ${compact ? 'p-[10px]' : 'p-2 flex-1'}`}>
            {groups.map((group) => (
                <div key={group.key} className="flex flex-col gap-1">
                    <div className={`px-3 pb-1 font-mono text-[10px] leading-[14px] tracking-[0.02em] font-medium uppercase text-outline dark:text-[#918d88] tracking-wider ${align}`}>
                        {group.label[lang]}
                    </div>
                    {group.pages.map((page) => {
                        const isActive = page.key === activeKey;

                        return (
                            <a
                                key={page.key}
                                href={`#/${page.key}`}
                                onClick={() => onNavigate?.(page.key)}
                                aria-current={isActive ? 'page' : undefined}
                                className={`flex items-center justify-between px-3 py-2 rounded transition-colors w-full ${
                                    isActive
                                        ? 'bg-primary dark:bg-[#2f7d4f] text-on-primary dark:text-white font-semibold'
                                        : 'text-on-surface-variant dark:text-[#c6c2bd] hover:bg-surface-container-high dark:hover:bg-[#32302e] hover:text-on-surface dark:hover:text-[#e7e3de]'
                                }`}
                            >
                                <div className={`flex items-center ${gap} min-w-0`}>
                                    <Icon name={page.icon} className="text-[18px] shrink-0" />
                                    <span className="text-[13px] leading-[18px] truncate">{page.label[lang]}</span>
                                </div>
                            </a>
                        );
                    })}
                </div>
            ))}
        </nav>
    );
}

export default PageNav;
