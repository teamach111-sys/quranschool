import React, { useState } from 'react';
import Icon from '../components/Icon';
import LanguageSwitcher from '../components/LanguageSwitcher';
import ThemeToggle from '../components/ThemeToggle';
import YearSelector from '../components/YearSelector';
import ProfileMenu from '../components/ProfileMenu';
import useTheme from '../hooks/useTheme';
import { useLanguage } from '../hooks/useLanguage';

export default function Dashboard({ user }) {
    const { theme, toggleTheme } = useTheme();
    const { isRTL, t } = useLanguage();
    const [drawerOpen, setDrawerOpen] = useState(false);

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
    const sidebarItems = t.sidebar;

    // RTL-aware classes
    const sidebarSide = isRTL ? 'right-0 border-l' : 'left-0 border-r';
    const contentPad = isRTL ? 'pr-64' : 'pl-64';
    const headerPos = isRTL ? 'right-64 left-0' : 'left-64 right-0';
    const subItemPad = isRTL ? 'pr-6 pl-1' : 'pl-6 pr-1';
    const drawerTextAlign = isRTL ? 'text-right' : 'text-left';
    const dateGreg = isRTL ? t.dateGreg : t.dateGreg;

    return (
        <div className={`bg-surface dark:bg-[#182620] ${fontClass} text-on-surface dark:text-[#ecf3ef] antialiased transition-colors duration-200`}>
            {/* ==================== DESKTOP LAYOUT (lg+) ==================== */}
            <div className="hidden lg:block">
                {/* Sidebar */}
                <aside className={`fixed ${sidebarSide} top-0 bottom-0 w-64 bg-surface-container-low dark:bg-[#202f29] border-outline-variant dark:border-[#2f4239] z-50 flex flex-col justify-between select-none transition-colors duration-200`}>
                    <div className="flex flex-col flex-1">
                        {/* Logo */}
                        <div className="h-16 px-4 flex items-center justify-between border-b border-outline-variant dark:border-[#2f4239] bg-surface-container-low dark:bg-[#202f29] transition-colors duration-200">
                            <div className="flex items-center gap-2 w-full">
                                <div className="w-8 h-8 rounded border border-dashed border-outline dark:border-[#475b52] flex items-center justify-center bg-surface-container dark:bg-[#182620]">
                                    <Icon name="category" className="text-[18px] text-outline dark:text-[#9bb2a8]" />
                                </div>
                                <span className="text-[18px] leading-[24px] tracking-[-0.01em] font-semibold uppercase tracking-wider text-on-surface dark:text-[#ecf3ef] leading-none">{t.logo}</span>
                            </div>
                        </div>
                        {/* Nav label */}
                        <div className="px-3 py-2 border-b border-outline-variant dark:border-[#2f4239] bg-surface-container dark:bg-[#182620]/60 transition-colors duration-200">
                            <span className="font-mono text-[10px] leading-[14px] tracking-[0.02em] font-medium uppercase text-on-surface-variant dark:text-[#9bb2a8] tracking-wider">{t.navModule}</span>
                        </div>
                        {/* Nav items */}
                        <nav className="p-2 flex flex-col gap-1 flex-1">
                            {sidebarItems.map((item, i) => item.children ? (
                                <details key={i} className="group rounded transition-colors" open>
                                    <summary className="flex items-center justify-between px-3 py-2 rounded text-on-surface-variant dark:text-[#cad7d0] hover:bg-surface-container-high dark:hover:bg-[#2c3d36] hover:text-on-surface dark:hover:text-[#ecf3ef] cursor-pointer select-none list-none transition-colors">
                                        <div className="flex items-center gap-3">
                                            <Icon name={item.icon} className="text-[18px]" />
                                            <span className="text-[13px] leading-[18px]">{item.label}</span>
                                        </div>
                                        <Icon name="expand_more" className="text-[18px] text-outline dark:text-[#9bb2a8] transition-transform duration-200 group-open:rotate-180" />
                                    </summary>
                                    <div className={`flex flex-col gap-1 ${subItemPad} pt-1 pb-1`}>
                                        {item.children.map((child, j) => (
                                            <a key={j} href="#" className={`flex items-center px-3 py-1 rounded text-[12px] leading-[16px] text-on-surface-variant dark:text-[#cad7d0] hover:bg-surface-container-high dark:hover:bg-[#2c3d36] hover:text-on-surface dark:hover:text-[#ecf3ef] transition-colors`}><span>{child}</span></a>
                                        ))}
                                    </div>
                                </details>
                            ) : (
                                <a key={i} href="#" aria-current={item.active ? 'page' : undefined}
                                    className={`flex items-center justify-between px-3 py-2 rounded transition-colors ${item.active ? 'bg-primary dark:bg-[#2e5e4e] text-on-primary dark:text-[#ecf3ef] font-semibold' : 'text-on-surface-variant dark:text-[#cad7d0] hover:bg-surface-container-high dark:hover:bg-[#2c3d36] hover:text-on-surface dark:hover:text-[#ecf3ef]'}`}>
                                    <div className="flex items-center gap-3">
                                        <Icon name={item.icon} className="text-[18px]" />
                                        <span className="text-[13px] leading-[18px]">{item.label}</span>
                                    </div>
                                </a>
                            ))}
                        </nav>
                    </div>
                    {/* Logout */}
                    <div className="p-2 border-t border-outline-variant dark:border-[#2f4239] bg-surface-container-lowest dark:bg-[#202f29] transition-colors duration-200">
                        <button type="button" onClick={handleLogout} className="flex items-center justify-between px-3 py-2 rounded text-error dark:text-[#f87171] hover:bg-error-container dark:hover:bg-[#4a2626]/40 hover:text-on-error-container dark:hover:text-[#fca5a5] transition-colors w-full">
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
                    <header className={`fixed top-0 ${headerPos} h-16 bg-surface-container-lowest/90 dark:bg-[#202f29]/90 backdrop-blur border-b border-outline-variant dark:border-[#2f4239] z-40 px-4 flex items-center justify-between transition-colors duration-200`}>
                        <div className="flex items-center gap-3">
                            <YearSelector />
                            <div className="h-4 w-px bg-outline-variant dark:bg-[#2f4239]" />
                            <div className="flex items-center gap-1 text-on-surface-variant dark:text-[#9bb2a8] bg-surface-container-low dark:bg-[#182620] px-3 rounded border border-outline-variant dark:border-[#2f4239] h-8 transition-colors duration-200">
                                <Icon name="schedule" className="text-[16px] text-outline dark:text-[#9bb2a8]" />
                                <span className="text-[12px] leading-[18px] text-on-surface-variant dark:text-[#9bb2a8]">{dateGreg}</span>
                                <span className="text-outline dark:text-[#707974] font-mono text-[10px] leading-[14px] tracking-[0.02em] font-medium">•</span>
                                <span className="text-[12px] leading-[18px] text-on-surface-variant dark:text-[#9bb2a8]">{t.dateHijri}</span>
                            </div>
                        </div>
                        <div className="flex items-center gap-3">
                            <LanguageSwitcher />
                            <ThemeToggle theme={theme} onToggle={toggleTheme} />
                            <div className="flex items-center text-outline dark:text-[#9bb2a8]">
                                <button className="w-8 h-8 rounded border border-outline-variant dark:border-[#2f4239] bg-surface-container-lowest dark:bg-[#202f29] flex items-center justify-center hover:bg-surface-container-high dark:hover:bg-[#2c3d36] hover:text-on-surface dark:hover:text-[#ecf3ef] transition-colors" title={t.notifications} type="button">
                                    <Icon name="notifications" className="text-[18px]" />
                                </button>
                            </div>
                            <div className="h-4 w-px bg-outline-variant dark:bg-[#2f4239]" />
                            <ProfileMenu user={user} onLogout={handleLogout} />
                        </div>
                    </header>

                    {/* Page title + content */}
                    <div className="pt-16 flex-1 flex flex-col">
                        <section className="bg-surface-container-lowest dark:bg-[#202f29] border-b border-outline-variant dark:border-[#2f4239] px-4 py-3 transition-colors duration-200">
                            <h1 className="text-[22px] leading-[28px] tracking-[-0.015em] font-semibold text-on-surface dark:text-[#ecf3ef] tracking-tight">{t.pageTitle}</h1>
                            <p className="text-[13px] leading-[18px] text-on-surface-variant dark:text-[#9bb2a8] mt-1 max-w-4xl">{t.pageSubtitle}</p>
                        </section>
                        <main className="flex-1 bg-surface dark:bg-[#182620] transition-colors duration-200" style={{ padding: '10px' }}>
                            <div className="flex flex-col w-full p-[10px] gap-3 select-none">
                                <div className="w-full flex-1 min-h-[70vh] bg-surface-container-low dark:bg-[#202f29] border border-dashed border-outline-variant dark:border-[#384d43] rounded flex flex-col items-center justify-center p-[10px] transition-colors duration-200">
                                    <div className="flex items-center gap-2 text-on-surface-variant dark:text-[#cad7d0]">
                                        <Icon name="dashboard_customize" className="text-[24px]" />
                                        <span className="text-[18px] leading-[24px] tracking-[-0.01em] font-semibold uppercase tracking-wider">{t.componentsHere}</span>
                                    </div>
                                    <span className="text-[12px] leading-[18px] text-outline dark:text-[#9bb2a8] mt-2">{t.spacingLabel}</span>
                                </div>
                            </div>
                        </main>
                    </div>
                </div>
            </div>

            {/* ==================== MOBILE/TABLET LAYOUT (<lg) ==================== */}
            <div className="lg:hidden flex flex-col min-h-screen bg-surface dark:bg-[#182620]">
                <header className="sticky top-0 z-40 bg-surface-container-lowest/90 dark:bg-[#202f29]/90 backdrop-blur border-b border-outline-variant dark:border-[#2f4239] select-none transition-colors duration-200">
                    {/* Primary Mobile App Bar */}
                    <div className="h-14 px-[10px] flex items-center justify-between border-b border-outline-variant dark:border-[#2f4239] bg-surface-container-lowest dark:bg-[#202f29] transition-colors duration-200">
                        <div className="flex items-center gap-2">
                            <div className="w-7 h-7 rounded border border-dashed border-outline dark:border-[#475b52] flex items-center justify-center bg-surface-container dark:bg-[#182620]">
                                <Icon name="category" className="text-[16px] text-outline dark:text-[#9bb2a8]" />
                            </div>
                            <span className="text-[14px] leading-[24px] tracking-[-0.01em] font-semibold uppercase tracking-wider text-on-surface dark:text-[#ecf3ef] leading-none">{t.logoMobile || t.logo}</span>
                        </div>
                        <div className="flex items-center gap-1.5">
                            <LanguageSwitcher compact />
                            <ThemeToggle theme={theme} onToggle={toggleTheme} compact />
                            <button className="w-7 h-7 rounded border border-outline-variant dark:border-[#2f4239] bg-surface-container-lowest dark:bg-[#202f29] flex items-center justify-center hover:bg-surface-container-high dark:hover:bg-[#2c3d36] text-outline dark:text-[#9bb2a8] hover:text-on-surface dark:hover:text-[#ecf3ef] transition-colors" title={t.notifications} type="button">
                                <Icon name="notifications" className="text-[15px]" />
                            </button>
                            <ProfileMenu user={user} onLogout={handleLogout} compact />
                        </div>
                    </div>
                    {/* Subheader: Date & Academic Year pill row */}
                    <div className="py-1.5 dark:bg-[#182620] flex items-center justify-between gap-1.5 border-b border-outline-variant dark:border-[#2f4239] text-[11px] w-full overflow-visible select-none px-[10px] transition-colors duration-200 bg-surface-container-lowest">
                        <div className="flex flex-row items-center gap-1.5 flex-nowrap w-full min-w-0">
                            <YearSelector compact />
                            <div className="flex items-center justify-center gap-1.5 text-on-surface-variant dark:text-[#9bb2a8] bg-surface-container-low dark:bg-[#182620] px-2 py-0.5 rounded border border-outline-variant dark:border-[#2f4239] h-6 min-w-0 flex-1 overflow-hidden truncate text-center transition-colors duration-200">
                                <Icon name="schedule" className="text-[13px] text-outline dark:text-[#9bb2a8] shrink-0" />
                                <span className="font-mono text-[10px] sm:text-[11px] truncate shrink-0 font-medium text-on-surface-variant dark:text-[#9bb2a8]">{isRTL ? t.dateGregMobile : t.dateGreg}</span>
                                <span className="text-outline dark:text-[#707974] font-mono text-[10px] shrink-0 font-medium">•</span>
                                <span className="font-mono text-[10px] sm:text-[11px] truncate min-w-0 font-medium text-on-surface-variant dark:text-[#9bb2a8]">{t.dateHijri}</span>
                            </div>
                        </div>
                    </div>
                </header>

                {/* Collapsible Navigation Drawer */}
                <div className="border-b border-outline-variant dark:border-[#2f4239] bg-surface-container-low dark:bg-[#202f29] select-none transition-colors duration-200">
                    <button aria-expanded={drawerOpen} className={`w-full flex items-center justify-between py-2 cursor-pointer bg-surface-container dark:bg-[#182620]/60 hover:bg-surface-container-high dark:hover:bg-[#2c3d36] transition-colors px-[10px] ${drawerTextAlign}`} type="button" onClick={() => setDrawerOpen(o => !o)}>
                        <div className="flex items-center gap-2">
                            <span className="font-mono text-[11px] leading-[14px] tracking-[0.02em] font-medium uppercase tracking-wider text-on-surface-variant dark:text-[#9bb2a8] font-semibold">{t.navModuleMobile}</span>
                        </div>
                        <div className="flex items-center gap-1">
                            <span className="font-mono text-[10px] leading-[14px] tracking-[0.02em] font-medium text-outline dark:text-[#9bb2a8] uppercase">{drawerOpen ? t.drawerClose : t.drawerOpen}</span>
                            <Icon name="expand_more" className={`text-[18px] text-outline dark:text-[#9bb2a8] transition-transform duration-300 ${drawerOpen ? 'rotate-180' : ''}`} />
                        </div>
                    </button>
                    {drawerOpen && (
                        <div className="flex flex-col gap-1 bg-surface-container-low dark:bg-[#202f29] p-[10px]">
                            {sidebarItems.map((item, i) => item.children ? (
                                <details key={i} className="group/nested rounded transition-colors" open>
                                    <summary className="flex items-center justify-between px-3 py-2 rounded text-on-surface-variant dark:text-[#cad7d0] hover:bg-surface-container-high dark:hover:bg-[#2c3d36] hover:text-on-surface dark:hover:text-[#ecf3ef] cursor-pointer select-none list-none transition-colors">
                                        <div className="flex items-center gap-2">
                                            <Icon name={item.icon} className="text-[18px]" />
                                            <span className="text-[13px] leading-[18px]">{item.label}</span>
                                        </div>
                                        <Icon name="expand_more" className="text-[18px] text-outline dark:text-[#9bb2a8] transition-transform duration-200 group-open/nested:rotate-180" />
                                    </summary>
                                    <div className={`flex flex-col gap-1 ${subItemPad} pt-1 pb-1`}>
                                        {item.children.map((child, j) => (
                                            <a key={j} href="#" className="flex items-center px-3 py-1 rounded text-[12px] leading-[16px] text-on-surface-variant dark:text-[#cad7d0] hover:bg-surface-container-high dark:hover:bg-[#2c3d36] hover:text-on-surface dark:hover:text-[#ecf3ef] transition-colors"><span>{child}</span></a>
                                        ))}
                                    </div>
                                </details>
                            ) : (
                                <a key={i} href="#" aria-current={item.active ? 'page' : undefined}
                                    className={`flex items-center justify-between px-3 py-2 rounded ${item.active ? 'bg-primary dark:bg-[#2e5e4e] text-on-primary dark:text-[#ecf3ef] font-semibold' : 'text-on-surface-variant dark:text-[#cad7d0] hover:bg-surface-container-high dark:hover:bg-[#2c3d36] hover:text-on-surface dark:hover:text-[#ecf3ef]'} transition-colors`}>
                                    <div className="flex items-center gap-2">
                                        <Icon name={item.icon} className="text-[18px]" />
                                        <span className="text-[13px] leading-[18px]">{item.label}</span>
                                    </div>
                                </a>
                            ))}
                            <div className="pt-1 mt-1 border-t border-outline-variant dark:border-[#2f4239]">
                                <button type="button" onClick={handleLogout} className="flex items-center justify-between px-3 py-2 rounded text-error dark:text-[#f87171] hover:bg-error-container dark:hover:bg-[#4a2626]/40 hover:text-on-error-container dark:hover:text-[#fca5a5] transition-colors w-full">
                                    <div className="flex items-center gap-2">
                                        <Icon name="power_settings_new" className="text-[18px]" />
                                        <span className="text-[13px] leading-[18px] font-medium">{t.logout}</span>
                                    </div>
                                    <span className="font-mono text-[10px] leading-[14px] tracking-[0.02em] font-medium border border-error/30 dark:border-red-400/30 px-1.5 py-0.5 rounded uppercase">{t.exitBadge}</span>
                                </button>
                            </div>
                        </div>
                    )}
                </div>

                {/* Page Title Section */}
                <section className="bg-surface-container-lowest dark:bg-[#202f29] border-b border-outline-variant dark:border-[#2f4239] py-3 px-[10px] transition-colors duration-200">
                    <div>
                        <h1 className="text-[20px] leading-[28px] tracking-[-0.015em] font-semibold text-on-surface dark:text-[#ecf3ef] tracking-tight">{t.pageTitle}</h1>
                        <p className="text-[12px] sm:text-[13px] leading-[18px] text-on-surface-variant dark:text-[#9bb2a8] mt-0.5 max-w-4xl">{t.pageSubtitle}</p>
                    </div>
                </section>

                {/* Main Content Area */}
                <main className="flex-1 bg-surface dark:bg-[#182620] transition-colors duration-200 p-[10px]">
                    <div className="flex flex-col w-full h-full min-h-[70vh]">
                        <div className="w-full flex-1 min-h-[70vh] bg-surface-container-low dark:bg-[#202f29] border border-dashed border-[#c0ccc2] dark:border-[#384d43] rounded flex flex-col items-center justify-center p-[10px] text-center select-none transition-colors duration-200">
                            <div className="flex items-center gap-2 text-on-surface-variant dark:text-[#cad7d0]">
                                <Icon name="dashboard_customize" className="text-[24px]" />
                                <span className="text-[18px] leading-[24px] tracking-[-0.01em] font-semibold uppercase tracking-wider">{t.componentsHere}</span>
                            </div>
                            <span className="font-mono text-[10px] leading-[14px] tracking-[0.02em] font-medium text-outline dark:text-[#9bb2a8] mt-2 uppercase tracking-wide">{t.spacingLabel}</span>
                        </div>
                    </div>
                </main>
            </div>
        </div>
    );
}
