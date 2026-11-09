import React, { useEffect, useState } from 'react';
import Icon from '../components/Icon';
import Logo from '../components/Logo';
import LanguageSwitcher from '../components/LanguageSwitcher';
import ThemeToggle from '../components/ThemeToggle';
import PastYearBanner from '../components/PastYearBanner';
import YearSelector from '../persisters/yearselector';
import ProfileMenu from '../components/ProfileMenu';
import { PageNav } from '../components/Navigation';
import useTheme from '../hooks/useTheme';
import useHashRoute from '../hooks/useHashRoute';
import { useLanguage } from '../hooks/useLanguage';
import { findPage, canSeePage, ROLE_DEFAULT_PAGE } from './registry';

const yearScopedPageKeys = new Set([
    'tableau-de-bord-admin',
    'eleves',
    'groupes-attribution',
    'emploi-du-temps',
    'presences',
    'seances-memorisation',
    'paiements-finances',
]);
const desktopMediaQuery = '(min-width: 1086px)';

export default function Dashboard({ user }) {
    const { theme, toggleTheme } = useTheme();
    const { isRTL, t } = useLanguage();
    const { route, routeParams, navigate } = useHashRoute();
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [isDesktop, setIsDesktop] = useState(() => window.matchMedia(desktopMediaQuery).matches);

    useEffect(() => {
        const mediaQuery = window.matchMedia(desktopMediaQuery);
        const syncLayout = (event) => setIsDesktop(event.matches);

        mediaQuery.addEventListener('change', syncLayout);
        return () => mediaQuery.removeEventListener('change', syncLayout);
    }, []);

    const role = user?.role;
    const defaultPageKey = ROLE_DEFAULT_PAGE[role] || 'tableau-de-bord-admin';
    const activePage = findPage(route);
    const allowedPage = canSeePage(activePage, role) ? activePage : findPage(defaultPageKey);
    const ActivePageComponent = allowedPage?.component ?? null;
    const showPastYearBanner = role === 'admin' && yearScopedPageKeys.has(allowedPage?.key);
    const pageInfo = t.pages[allowedPage?.key] || t.pages[defaultPageKey] || { title: '', subtitle: '' };

    useEffect(() => {
        if (role && !canSeePage(activePage, role)) {
            navigate(defaultPageKey);
        }
    }, [activePage, defaultPageKey, navigate, role]);

    const handlePageChange = (key) => {
        navigate(key);
        setDrawerOpen(false);
    };

    const handleLogout = async () => {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        await fetch('/logout', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            credentials: 'same-origin',
        });
        window.location.href = '/login';
    };

    const fontClass = isRTL ? 'font-cairo' : 'font-geist';

    // RTL-aware classes
    const sidebarSide = isRTL ? 'right-0 border-l' : 'left-0 border-r';
    const contentPad = isRTL ? 'pr-64' : 'pl-64';
    const headerPos = isRTL ? 'right-64 left-0' : 'left-64 right-0';
    const drawerTextAlign = isRTL ? 'text-right' : 'text-left';
    const dateGreg = t.dateGreg;

    return (
        <div className={`bg-surface dark:bg-[#1c1b1a] ${fontClass} text-on-surface dark:text-[#e7e3de] antialiased transition-colors duration-200`}>
            {/* ==================== DESKTOP LAYOUT (>1085px) ==================== */}
            {isDesktop && (
            <div className="block">
                {/* Sidebar */}
                <aside className={`fixed ${sidebarSide} top-0 bottom-0 w-64 bg-surface-container-low dark:bg-[#232221] border-outline-variant dark:border-[#3a3835] z-50 flex flex-col justify-between select-none transition-colors duration-200`}>
                    <div className="flex flex-col flex-1 min-h-0">
                        {/* Logo */}
                        <div className="h-16 px-4 flex items-center justify-between border-b border-outline-variant dark:border-[#3a3835] bg-surface-container-low dark:bg-[#232221] transition-colors duration-200">
                            <Logo className="h-9" />
                        </div>
                        {/* Nav label */}
                        <div className="px-3 py-2 border-b border-outline-variant dark:border-[#3a3835] bg-surface-container dark:bg-[#1c1b1a]/60 transition-colors duration-200">
                            <span className="font-mono text-[10px] leading-[14px] tracking-[0.02em] font-medium uppercase text-on-surface-variant dark:text-[#918d88] tracking-wider">{t.navModule}</span>
                        </div>
                        {/* Nav items */}
                        <PageNav activeKey={allowedPage?.key} onNavigate={handlePageChange} role={role} />
                    </div>
                    {/* Logout */}
                    <div className="p-2 border-t border-outline-variant dark:border-[#3a3835] bg-surface-container-lowest dark:bg-[#232221] transition-colors duration-200">
                        <button type="button" onClick={handleLogout} className="flex items-center justify-between px-3 py-2 rounded text-error dark:text-[#d96055] hover:bg-error-container dark:hover:bg-[#3a2624]/40 hover:text-on-error-container dark:hover:text-[#e59a92] transition-colors w-full">
                            <div className="flex items-center gap-3">
                                <Icon name="power_settings_new" className={`text-[18px] ${isRTL ? 'rotate-180' : ''}`} />
                                <span className="text-[13px] leading-[18px] font-medium">{t.logout}</span>
                            </div>
                            <span className="font-mono text-[10px] leading-[14px] tracking-[0.02em] font-medium border border-error/30 dark:border-red-400/30 px-1.5 py-0.5 rounded uppercase font-sans">{t.exitBadge}</span>
                        </button>
                    </div>
                </aside>

                {/* Main */}
                <div className={`${contentPad} flex flex-col min-h-screen`}>
                    {/* Header */}
                    <header className={`fixed top-0 ${headerPos} h-16 bg-surface-container-lowest/90 dark:bg-[#232221]/90 backdrop-blur border-b border-outline-variant dark:border-[#3a3835] z-40 px-4 flex items-center justify-between transition-colors duration-200`}>
                        <div className="flex items-center gap-3">
                            <YearSelector />
                            <div className="h-4 w-px bg-outline-variant dark:bg-[#3a3835]" />
                            <div className="flex items-center gap-1 text-on-surface-variant dark:text-[#918d88] bg-surface-container-low dark:bg-[#1c1b1a] px-3 rounded border border-outline-variant dark:border-[#3a3835] h-8 transition-colors duration-200">
                                <Icon name="schedule" className="text-[16px] text-outline dark:text-[#918d88]" />
                                <span className="text-[12px] leading-[18px] text-on-surface-variant dark:text-[#918d88]">{dateGreg}</span>
                                <span className="text-outline dark:text-[#6f6b66] font-mono text-[10px] leading-[14px] tracking-[0.02em] font-medium">•</span>
                                <span className="text-[12px] leading-[18px] text-on-surface-variant dark:text-[#918d88]">{t.dateHijri}</span>
                            </div>
                        </div>
                        <div className="flex items-center gap-3">
                            <LanguageSwitcher />
                            <ThemeToggle theme={theme} onToggle={toggleTheme} />
                            <ProfileMenu user={user} onLogout={handleLogout} />
                        </div>
                    </header>

                    {/* Page title + content */}
                    <div className="pt-16 flex-1 flex flex-col">
                        <section className="bg-surface-container-lowest dark:bg-[#232221] border-b border-outline-variant dark:border-[#3a3835] px-4 py-3 transition-colors duration-200">
                            <h1 className="text-[22px] leading-[28px] tracking-[-0.015em] font-semibold text-on-surface dark:text-[#e7e3de] tracking-tight">{pageInfo.title}</h1>
                            <p className="text-[13px] leading-[18px] text-on-surface-variant dark:text-[#918d88] mt-1 max-w-4xl">{pageInfo.subtitle}</p>
                        </section>
                        <main className="flex-1 bg-surface dark:bg-[#1c1b1a] transition-colors duration-200" style={{ padding: '10px' }}>
                            <div className="flex flex-col w-full gap-3">
                                <div className={`w-full flex-1 min-h-[70vh] bg-surface-container-low dark:bg-[#232221] border border-solid border-outline-variant dark:border-[#3a3835] rounded flex flex-col p-[10px] transition-colors duration-200 ${ActivePageComponent ? '' : 'items-center justify-center select-none'}`}>
                                    <PastYearBanner visible={showPastYearBanner} />
                                    {ActivePageComponent ? <ActivePageComponent routeParams={routeParams} /> : (
                                        <div className="flex flex-col items-center justify-center">
                                            <div className="flex items-center gap-2 text-on-surface-variant dark:text-[#c6c2bd]">
                                                <Icon name="dashboard_customize" className="text-[24px]" />
                                                <span className="text-[18px] leading-[24px] tracking-[-0.01em] font-semibold uppercase tracking-wider">{t.componentsHere}</span>
                                            </div>
                                            <span className="text-[12px] leading-[18px] text-outline dark:text-[#918d88] mt-2">{t.spacingLabel}</span>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </main>
                    </div>
                </div>
            </div>
            )}

            {/* ==================== MOBILE/TABLET LAYOUT (≤1085px) ==================== */}
            {!isDesktop && (
            <div className="flex flex-col min-h-screen bg-surface dark:bg-[#1c1b1a]">
                <header className="sticky top-0 z-40 bg-surface-container-lowest/90 dark:bg-[#232221]/90 backdrop-blur border-b border-outline-variant dark:border-[#3a3835] select-none transition-colors duration-200">
                    {/* Primary Mobile App Bar */}
                    <div className="h-14 px-[10px] flex items-center justify-between border-b border-outline-variant dark:border-[#3a3835] bg-surface-container-lowest dark:bg-[#232221] transition-colors duration-200">
                        <div className="flex items-center gap-2">
                            <Logo className="h-7" />
                        </div>
                        <div className="flex items-center gap-1.5">
                            <LanguageSwitcher compact />
                            <ThemeToggle theme={theme} onToggle={toggleTheme} compact />
                            <ProfileMenu user={user} onLogout={handleLogout} compact />
                        </div>
                    </div>
                    {/* Subheader: Date & Academic Year pill row */}
                    <div className="py-1.5 dark:bg-[#1c1b1a] flex items-center justify-between gap-1.5 border-b border-outline-variant dark:border-[#3a3835] text-[11px] w-full overflow-visible select-none px-[10px] transition-colors duration-200 bg-surface-container-lowest">
                        <div className="flex flex-row items-center gap-1.5 flex-nowrap w-full min-w-0">
                            <YearSelector compact />
                            <div className="flex items-center justify-center gap-1.5 text-on-surface-variant dark:text-[#918d88] bg-surface-container-low dark:bg-[#1c1b1a] px-2 py-0.5 rounded border border-outline-variant dark:border-[#3a3835] h-6 sm:h-8 min-w-0 flex-1 overflow-hidden truncate text-center transition-colors duration-200">
                                <Icon name="schedule" className="text-[13px] text-outline dark:text-[#918d88] shrink-0" />
                                <span className="font-mono text-[10px] sm:text-[11px] truncate shrink-0 font-medium text-on-surface-variant dark:text-[#918d88]">{isRTL ? t.dateGregMobile : t.dateGreg}</span>
                                <span className="text-outline dark:text-[#6f6b66] font-mono text-[10px] shrink-0 font-medium">•</span>
                                <span className="font-mono text-[10px] sm:text-[11px] truncate min-w-0 font-medium text-on-surface-variant dark:text-[#918d88]">{t.dateHijri}</span>
                            </div>
                        </div>
                    </div>
                </header>

                {/* Collapsible Navigation Drawer */}
                <div className="border-b border-outline-variant dark:border-[#3a3835] bg-surface-container-low dark:bg-[#232221] select-none transition-colors duration-200">
                    <button aria-expanded={drawerOpen} className={`w-full flex items-center justify-between py-2 cursor-pointer bg-surface-container dark:bg-[#1c1b1a]/60 hover:bg-surface-container-high dark:hover:bg-[#32302e] transition-colors px-[10px] ${drawerTextAlign}`} type="button" onClick={() => setDrawerOpen(o => !o)}>
                        <div className="flex items-center gap-2">
                            <span className="font-mono text-[11px] leading-[14px] tracking-[0.02em] font-medium uppercase tracking-wider text-on-surface-variant dark:text-[#918d88] font-semibold">{t.navModule}</span>
                        </div>
                        <div className="flex items-center gap-1">
                            <span className="font-mono text-[10px] leading-[14px] tracking-[0.02em] font-medium text-outline dark:text-[#918d88] uppercase">{drawerOpen ? t.drawerClose : t.drawerOpen}</span>
                            <Icon name="expand_more" className={`text-[18px] text-outline dark:text-[#918d88] transition-transform duration-300 ${drawerOpen ? 'rotate-180' : ''}`} />
                        </div>
                    </button>
                    {drawerOpen && (
                        <div className="flex flex-col gap-1 bg-surface-container-low dark:bg-[#232221]">
                            <PageNav activeKey={allowedPage?.key} onNavigate={handlePageChange} role={role} compact />
                            <div className="px-[10px] pb-[10px]">
                                <div className="pt-1 mt-1 border-t border-outline-variant dark:border-[#3a3835]">
                                    <button type="button" onClick={handleLogout} className="flex items-center justify-between px-3 py-2 rounded text-error dark:text-[#d96055] hover:bg-error-container dark:hover:bg-[#3a2624]/40 hover:text-on-error-container dark:hover:text-[#e59a92] transition-colors w-full">
                                        <div className="flex items-center gap-2">
                                            <Icon name="power_settings_new" className="text-[18px]" />
                                            <span className="text-[13px] leading-[18px] font-medium">{t.logout}</span>
                                        </div>
                                        <span className="font-mono text-[10px] leading-[14px] tracking-[0.02em] font-medium border border-error/30 dark:border-red-400/30 px-1.5 py-0.5 rounded uppercase">{t.exitBadge}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    )}
                </div>

                {/* Page Title Section */}
                <section className="bg-surface-container-lowest dark:bg-[#232221] border-b border-outline-variant dark:border-[#3a3835] py-3 px-[10px] transition-colors duration-200">
                    <div>
                        <h1 className="text-[20px] leading-[28px] tracking-[-0.015em] font-semibold text-on-surface dark:text-[#e7e3de] tracking-tight">{pageInfo.title}</h1>
                        <p className="text-[12px] sm:text-[13px] leading-[18px] text-on-surface-variant dark:text-[#918d88] mt-0.5 max-w-4xl">{pageInfo.subtitle}</p>
                    </div>
                </section>

                {/* Main Content Area */}
                <main className="flex-1 bg-surface dark:bg-[#1c1b1a] transition-colors duration-200 p-[10px]">
                    <div className="flex flex-col w-full h-full min-h-[70vh]">
                        <div className={`w-full flex-1 min-h-[70vh] bg-surface-container-low dark:bg-[#232221] border border-solid border-[#c0ccc2] dark:border-[#3a3835] rounded flex flex-col p-[10px] transition-colors duration-200 ${ActivePageComponent ? '' : 'items-center justify-center text-center select-none'}`}>
                            <PastYearBanner visible={showPastYearBanner} />
                            {ActivePageComponent ? <ActivePageComponent routeParams={routeParams} /> : (
                                <div className="flex flex-col items-center justify-center">
                                    <div className="flex items-center gap-2 text-on-surface-variant dark:text-[#c6c2bd]">
                                        <Icon name="dashboard_customize" className="text-[24px]" />
                                        <span className="text-[18px] leading-[24px] tracking-[-0.01em] font-semibold uppercase tracking-wider">{t.componentsHere}</span>
                                    </div>
                                    <span className="font-mono text-[10px] leading-[14px] tracking-[0.02em] font-medium text-outline dark:text-[#918d88] mt-2 uppercase tracking-wide">{t.spacingLabel}</span>
                                </div>
                            )}
                        </div>
                    </div>
                </main>
            </div>
            )}
        </div>
    );
}
