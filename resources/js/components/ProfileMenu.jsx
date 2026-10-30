import React, { useEffect, useRef, useState } from 'react';
import Icon from './Icon';
import { useLanguage } from '../hooks/useLanguage';

export default function ProfileMenu({ user, onLogout, compact = false }) {
    const { isRTL, t } = useLanguage();
    const [open, setOpen] = useState(false);
    const ref = useRef(null);

    useEffect(() => {
        const onClick = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
        const onKey = (e) => e.key === 'Escape' && setOpen(false);
        document.addEventListener('click', onClick);
        document.addEventListener('keydown', onKey);
        return () => { document.removeEventListener('click', onClick); document.removeEventListener('keydown', onKey); };
    }, []);

    const name = user?.name || t.userName;
    const email = user?.email || 'user@institution.dz';
    const role = t.userRole;

    const dropdownSide = isRTL ? 'left-0 origin-top-left' : 'right-0 origin-top-right';
    const textAlign = isRTL ? 'text-right' : 'text-left';
    const borderClasses = compact
        ? 'pl-1 border-l border-outline-variant dark:border-[#2f4239]'
        : isRTL
            ? 'pr-1 border-r border-outline-variant dark:border-[#2f4239]'
            : 'pl-1 border-l border-outline-variant dark:border-[#2f4239]';

    return (
        <div ref={ref} className={`relative flex items-center gap-2 ${borderClasses}`}>
            <button type="button" aria-expanded={open} aria-haspopup="true" onClick={() => setOpen(o => !o)}
                className="group flex items-center gap-2 cursor-pointer focus:outline-none"
            >
                <div className={`${compact ? 'w-7 h-7' : 'w-8 h-8'} rounded-full bg-primary dark:bg-[#2e5e4e] group-hover:bg-primary-container dark:group-hover:bg-[#376757] transition-colors flex items-center justify-center ring-1 ring-outline/20 dark:ring-[#475b52]`}>
                    <Icon name="person" className={`text-on-primary dark:text-[#ecf3ef] ${compact ? 'text-[15px]' : 'text-[18px]'}`} />
                </div>
                {!compact && (
                    <div className={`flex flex-col ${textAlign}`}>
                        <span className="text-[12px] leading-[16px] font-semibold text-on-surface dark:text-[#ecf3ef] group-hover:text-primary dark:group-hover:text-[#9fd1bd] transition-colors leading-tight truncate max-w-[140px]">{name}</span>
                        <span className="text-[11px] leading-[14px] text-on-surface-variant dark:text-[#9bb2a8] leading-none mt-0.5 truncate max-w-[140px]">{role}</span>
                    </div>
                )}
            </button>
            <div className={`absolute ${dropdownSide} top-full mt-2 w-52 bg-surface-container-lowest dark:bg-[#202f29] border border-outline-variant dark:border-[#2f4239] rounded shadow-lg z-50 py-1 flex flex-col ${textAlign} transition-all duration-200 ease-out ${open ? 'opacity-100 pointer-events-auto scale-100 translate-y-0' : 'opacity-0 pointer-events-none scale-95 -translate-y-1'}`}>
                <div className="px-3 py-2 border-b border-outline-variant dark:border-[#2f4239] bg-surface-container-low/50 dark:bg-[#182620]/50">
                    <p className="text-[12px] leading-[16px] font-semibold text-on-surface dark:text-[#ecf3ef] leading-tight truncate">{name}</p>
                    <p className="font-mono text-[10px] leading-[14px] tracking-[0.02em] text-on-surface-variant dark:text-[#9bb2a8] truncate mt-0.5" dir="ltr">{email}</p>
                </div>
                <a href="#" className="flex items-center gap-2 px-3 py-2 text-on-surface dark:text-[#cad7d0] hover:bg-surface-container-high dark:hover:bg-[#2c3d36] transition-colors">
                    <Icon name="account_circle" className="text-[16px] text-outline dark:text-[#9bb2a8]" />
                    <span className="text-[12px] leading-[16px]">{t.profile}</span>
                </a>
                <a href="#" className="flex items-center gap-2 px-3 py-2 text-on-surface dark:text-[#cad7d0] hover:bg-surface-container-high dark:hover:bg-[#2c3d36] transition-colors">
                    <Icon name="settings" className="text-[16px] text-outline dark:text-[#9bb2a8]" />
                    <span className="text-[12px] leading-[16px]">{t.settings}</span>
                </a>
                <div className="border-t border-outline-variant dark:border-[#2f4239] my-1" />
                <button type="button" onClick={onLogout} className="flex items-center gap-2 px-3 py-2 text-error dark:text-[#f87171] hover:bg-error-container dark:hover:bg-[#4a2626]/40 transition-colors w-full">
                    <Icon name="logout" className={`text-[16px] ${isRTL ? 'rotate-180' : ''}`} />
                    <span className="text-[12px] leading-[16px] font-medium">{t.logout}</span>
                </button>
            </div>
        </div>
    );
}
