import React, { useEffect, useMemo, useState } from 'react';
import SelectInput from '../../../components/SelectInput';
import SearchInput from '../../../components/SearchInput';
import DateInput from '../../../components/DateInput';
import { apiFetch } from '../../../lib/api';
import { useLanguage } from '../../../hooks/useLanguage';
import DesktopTable from '../../../tables/desktoptable';
import MobileTable from '../../../tables/mobiletable';

export default function RoleChangeHistoryTable() {
    const { isRTL, lang, t } = useLanguage();
    const [history, setHistory] = useState([]);
    const [users, setUsers] = useState([]);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [search, setSearch] = useState('');
    const [userFilter, setUserFilter] = useState('');
    const [dateFilter, setDateFilter] = useState('');

    useEffect(() => {
        const controller = new AbortController();

        const loadHistory = async () => {
            setLoading(true);
            setLoadError(false);

            try {
                const response = await apiFetch('/admin/role-change-history/table', {
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('Failed to load role change history');
                const data = await response.json();

                if (controller.signal.aborted) return;
                setHistory(Array.isArray(data.history) ? data.history : []);
                setUsers(Array.isArray(data.users) ? data.users : []);
            } catch (error) {
                if (error.name !== 'AbortError') setLoadError(true);
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        };

        loadHistory();

        return () => controller.abort();
    }, []);

    const locale = lang === 'ar' ? 'ar' : 'fr';
    const roleLabel = (entry, side) => {
        const roleName = locale === 'ar'
            ? entry[`${side}_role_name_ar`]
            : entry[`${side}_role_name_fr`];

        return roleName || t.roleHistory.initialRole;
    };
    const formatDate = (value) => new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));

    const filteredHistory = useMemo(() => {
        const normalizedSearch = search.trim().toLocaleLowerCase();

        return history.filter((entry) => {
            const matchesSearch = !normalizedSearch
                || [
                    entry.user_name,
                    entry.changed_by_name,
                    entry.old_role_name_fr,
                    entry.old_role_name_ar,
                    entry.new_role_name_fr,
                    entry.new_role_name_ar,
                    entry.reason,
                ].filter(Boolean).some((value) => value.toLocaleLowerCase().includes(normalizedSearch));
            const matchesUser = !userFilter || String(entry.user_id) === userFilter;
            const matchesDate = !dateFilter || entry.created_at?.slice(0, 10) === dateFilter;

            return matchesSearch && matchesUser && matchesDate;
        });
    }, [history, search, userFilter, dateFilter]);

    const columns = [
        {
            key: 'user_name',
            label: t.roleHistory.columns.user,
            sortable: true,
            mobile: 'title',
        },
        {
            key: 'role_change',
            label: t.roleHistory.columns.change,
            value: (entry) => `${roleLabel(entry, 'old')} → ${roleLabel(entry, 'new')}`,
            sortable: true,
            mobile: 'subtitle',
        },
        {
            key: 'changed_by_name',
            label: t.roleHistory.columns.changedBy,
            sortable: true,
            mobile: 'detail',
        },
        {
            key: 'reason',
            label: t.roleHistory.columns.reason,
            sortable: true,
            mobile: 'detail',
            value: (entry) => entry.reason || t.roleHistory.emptyValue,
        },
        {
            key: 'created_at',
            label: t.roleHistory.columns.date,
            value: (entry) => formatDate(entry.created_at),
            sortValue: (entry) => entry.created_at,
            sortable: true,
            mobile: 'detail',
        },
    ];
    const labels = {
        actions: t.roleHistory.table.actions,
        pagination: t.roleHistory.table.pagination,
        previous: t.roleHistory.table.previous,
        next: t.roleHistory.table.next,
        page: (page) => t.roleHistory.table.page(page),
        selectPage: t.roleHistory.table.selectPage,
        selectRow: (entry) => t.roleHistory.table.selectRow(entry.user_name),
        sortBy: (label) => t.roleHistory.table.sortBy(label),
        range: (first, last, total) => t.roleHistory.table.range(first, last, total),
    };
    const inputClass = 'min-h-10 w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 text-[13px] text-on-surface outline-none focus:border-primary dark:border-[#3a3835] dark:bg-inverse-surface dark:text-inverse-on-surface';
    const toolbar = (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-[minmax(24rem,2fr)_minmax(10rem,1fr)_minmax(9rem,1fr)]">
            <div className="min-w-0 sm:col-span-2 lg:col-span-1">
                <SearchInput
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    isRTL={isRTL}
                    placeholder={t.roleHistory.search}
                    ariaLabel={t.roleHistory.search}
                />
            </div>
            <div className="min-w-0">
                <SelectInput
                    value={userFilter}
                    onChange={(event) => setUserFilter(event.target.value)}
                    aria-label={t.roleHistory.userFilter}
                    className={inputClass}
                >
                    <option value="">{t.roleHistory.allUsers}</option>
                    {users.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}
                </SelectInput>
            </div>
            <div className="min-w-0">
                <DateInput
                    name="date_filter"
                    value={dateFilter}
                    onChange={(event) => setDateFilter(event.target.value)}
                    aria-label={t.roleHistory.dateFilter}
                    className={inputClass}
                />
            </div>
        </div>
    );

    if (loadError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-[#e59a92]">{t.roleHistory.loadError}</p>;
    }

    const emptyState = history.length > 0 ? t.roleHistory.noResults : t.roleHistory.empty;

    return (
        <section className="flex w-full flex-col gap-3" dir={isRTL ? 'rtl' : 'ltr'}>
            <div className="hidden md:block">
                <DesktopTable
                    ariaLabel={t.roleHistory.listLabel}
                    columns={columns}
                    emptyState={emptyState}
                    isRTL={isRTL}
                    labels={labels}
                    rows={filteredHistory}
                    toolbar={toolbar}
                    loading={loading}
                />
            </div>
            <div className="md:hidden">
                <MobileTable
                    ariaLabel={t.roleHistory.listLabel}
                    columns={columns}
                    emptyState={emptyState}
                    isRTL={isRTL}
                    labels={labels}
                    rows={filteredHistory}
                    toolbar={toolbar}
                    loading={loading}
                />
            </div>
        </section>
    );
}
