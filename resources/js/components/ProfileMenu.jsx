import React, { useEffect, useRef, useState } from 'react';
import Icon from './Icon';
import { useLanguage } from '../hooks/useLanguage';

export default function ProfileMenu({ user, onLogout, compact = false }) {
    const { isRTL, t } = useLanguage();
    const [open, setOpen] = useState(false);
    const [profileName, setProfileName] = useState(null);
    const [profileEmail, setProfileEmail] = useState(null);
    const ref = useRef(null);

    useEffect(() => {
        const onClick = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
        const onKey = (e) => e.key === 'Escape' && setOpen(false);
        const onProfileUpdated = (event) => {
            if (event.detail?.full_name) setProfileName(event.detail.full_name);
            if (event.detail?.email) setProfileEmail(event.detail.email);
        };
        document.addEventListener('click', onClick);
        document.addEventListener('keydown', onKey);
        window.addEventListener('tahfiz:profile-updated', onProfileUpdated);
        return () => {
            document.removeEventListener('click', onClick);
            document.removeEventListener('keydown', onKey);
            window.removeEventListener('tahfiz:profile-updated', onProfileUpdated);
        };
    }, []);

    const name = profileName ?? user?.name ?? t.userName;
    const email = profileEmail ?? user?.email ?? 'user@institution.dz';
    const role = (user?.role && t.roles?.[user.role]) || t.userRole;

    const dropdownSide = isRTL ? 'left-0 origin-top-left' : 'right-0 origin-top-right';
    const textAlign = isRTL ? 'text-right' : 'text-left';
    const borderClasses = compact
        ? 'pl-1 border-l border-outline-variant dark:border-[#3a3835]'
        : isRTL
            ? 'pr-1 border-r border-outline-variant dark:border-[#3a3835]'
            : 'pl-1 border-l border-outline-variant dark:border-[#3a3835]';

    return (
        <div ref={ref} className={`relative flex items-center gap-2 ${borderClasses}`}>
            <button type="button" aria-expanded={open} aria-haspopup="true" onClick={() => setOpen(o => !o)}
                className="group flex items-center gap-2 cursor-pointer focus:outline-none"
            >
                <div className={`${compact ? 'w-7 h-7' : 'w-8 h-8'} rounded-full bg-primary dark:bg-[#32302e] group-hover:bg-primary-container dark:group-hover:bg-[#3a3835] transition-colors flex items-center justify-center ring-1 ring-outline/20 dark:ring-[#3a3835]`}>
                    <Icon name="person" className={`text-on-primary dark:text-[#e7e3de] ${compact ? 'text-[15px]' : 'text-[18px]'}`} />
                </div>
                {!compact && (
                    <div className={`flex flex-col ${textAlign}`}>
                        <span className="text-[12px] leading-[16px] font-semibold text-on-surface dark:text-[#e7e3de] group-hover:text-primary dark:group-hover:text-[#918d88] transition-colors leading-tight truncate max-w-[140px]">{name}</span>
                        <span className="text-[11px] leading-[14px] text-on-surface-variant dark:text-[#918d88] leading-none mt-0.5 truncate max-w-[140px]">{role}</span>
                    </div>
                )}
            </button>
            <div className={`absolute ${dropdownSide} top-full mt-2 w-52 bg-surface-container-lowest dark:bg-[#232221] border border-outline-variant dark:border-[#3a3835] rounded shadow-lg z-50 py-1 flex flex-col ${textAlign} transition-all duration-200 ease-out ${open ? 'opacity-100 pointer-events-auto scale-100 translate-y-0' : 'opacity-0 pointer-events-none scale-95 -translate-y-1'}`}>
                <div className="px-3 py-2 border-b border-outline-variant dark:border-[#3a3835] bg-surface-container-low/50 dark:bg-[#1c1b1a]/50">
                    <p className="text-[12px] leading-[16px] font-semibold text-on-surface dark:text-[#e7e3de] leading-tight truncate">{name}</p>
                    <p className="font-mono text-[10px] leading-[14px] tracking-[0.02em] text-on-surface-variant dark:text-[#918d88] truncate mt-0.5" dir="ltr">{email}</p>
                </div>
                <a href="#/mon-profil" onClick={() => setOpen(false)} className="flex items-center gap-2 px-3 py-2 text-on-surface dark:text-[#c6c2bd] hover:bg-surface-container-high dark:hover:bg-[#32302e] transition-colors">
                    <Icon name="account_circle" className="text-[16px] text-outline dark:text-[#918d88]" />
                    <span className="text-[12px] leading-[16px]">{t.profile}</span>
                </a>
                <div className="border-t border-outline-variant dark:border-[#3a3835] my-1" />
                <button type="button" onClick={onLogout} className="flex items-center gap-2 px-3 py-2 text-error dark:text-[#d96055] hover:bg-error-container dark:hover:bg-[#3a2624]/40 transition-colors w-full">
                    <Icon name="logout" className={`text-[16px] ${isRTL ? 'rotate-180' : ''}`} />
                    <span className="text-[12px] leading-[16px] font-medium">{t.logout}</span>
                </button>
            </div>
        </div>
    );
}
