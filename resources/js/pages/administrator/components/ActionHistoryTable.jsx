import React, { useEffect, useMemo, useState } from 'react';
import DateInput from '../../../components/DateInput';
import SearchInput from '../../../components/SearchInput';
import SelectInput from '../../../components/SelectInput';
import { useLanguage } from '../../../hooks/useLanguage';
import { apiFetch } from '../../../lib/api';
import DesktopTable from '../../../tables/desktoptable';
import MobileTable from '../../../tables/mobiletable';

export default function ActionHistoryTable() {
    const { isRTL, lang, t } = useLanguage();
    const [events, setEvents] = useState([]);
    const [actors, setActors] = useState([]);
    const [actions, setActions] = useState([]);
    const [entities, setEntities] = useState([]);
    const [years, setYears] = useState([]);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [retryToken, setRetryToken] = useState(0);
    const [filters, setFilters] = useState({
        search: '',
        actor_id: '',
        action_type: '',
        entity_type: '',
        year_id: '',
        date_from: '',
        date_to: '',
    });

    useEffect(() => {
        const controller = new AbortController();
        const query = new URLSearchParams(
            Object.entries(filters).filter(([, value]) => value !== ''),
        );

        const loadEvents = async () => {
            setLoading(true);
            setLoadError(false);

            try {
                const response = await apiFetch(`/admin/action-history/table?${query}`, {
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('Failed to load action history');
                const data = await response.json();

                if (controller.signal.aborted) return;
                setEvents(Array.isArray(data.events) ? data.events : []);
                setActors(Array.isArray(data.actors) ? data.actors : []);
                setActions(Array.isArray(data.actions) ? data.actions : []);
                setEntities(Array.isArray(data.entities) ? data.entities : []);
                setYears(Array.isArray(data.years) ? data.years : []);
            } catch (error) {
                if (error.name !== 'AbortError') setLoadError(true);
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        };

        loadEvents();

        return () => controller.abort();
    }, [filters, retryToken]);

    const locale = lang === 'ar' ? 'ar' : 'fr';
    const formatDate = (value) => new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
    const actionLabel = (action) => t.actionHistory.actions[action] ?? action;
    const entityLabel = (entity) => t.actionHistory.entities[entity] ?? entity;
    const summaryLabel = (summary) => t.actionHistory.summaries[summary] ?? summary;

    const columns = useMemo(() => [
        {
            key: 'summary_key',
            label: t.actionHistory.columns.summary,
            value: (event) => summaryLabel(event.summary_key),
            sortable: true,
            mobile: 'title',
        },
        {
            key: 'action_type',
            label: t.actionHistory.columns.action,
            value: (event) => actionLabel(event.action_type),
            sortValue: (event) => actionLabel(event.action_type),
            sortable: true,
            mobile: 'subtitle',
        },
        {
            key: 'record',
            label: t.actionHistory.columns.record,
            value: (event) => event.entity_id === null
                ? entityLabel(event.entity_type)
                : `${entityLabel(event.entity_type)} #${event.entity_id}`,
            sortable: true,
            mobile: 'detail',
        },
        {
            key: 'actor_name',
            label: t.actionHistory.columns.actor,
            sortable: true,
            mobile: 'detail',
        },
        {
            key: 'year_id',
            label: t.actionHistory.columns.year,
            value: (event) => years.find((year) => year.id === event.year_id)?.label
                ?? t.actionHistory.allYears,
            sortable: true,
            mobile: 'detail',
        },
        {
            key: 'created_at',
            label: t.actionHistory.columns.date,
            value: (event) => formatDate(event.created_at),
            sortValue: (event) => event.created_at,
            sortable: true,
            mobile: 'detail',
        },
    ], [t.actionHistory, years, locale]);

    const labels = {
        actions: t.actionHistory.table.actions,
        pagination: t.actionHistory.table.pagination,
        previous: t.actionHistory.table.previous,
        next: t.actionHistory.table.next,
        page: (page) => t.actionHistory.table.page(page),
        selectPage: t.actionHistory.table.selectPage,
        selectRow: (event) => t.actionHistory.table.selectRow(summaryLabel(event.summary_key)),
        sortBy: (label) => t.actionHistory.table.sortBy(label),
        range: (first, last, total) => t.actionHistory.table.range(first, last, total),
    };
    const inputClass = 'min-h-10 w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 text-[13px] text-on-surface outline-none focus:border-primary dark:border-outline-variant dark:bg-inverse-surface dark:text-inverse-on-surface';
    const updateFilter = (key) => (event) => {
        setFilters((current) => ({ ...current, [key]: event.target.value }));
    };
    const toolbar = (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <div className="min-w-0">
                <SearchInput
                    value={filters.search}
                    onChange={updateFilter('search')}
                    isRTL={isRTL}
                    placeholder={t.actionHistory.search}
                    ariaLabel={t.actionHistory.search}
                />
            </div>
            <SelectInput value={filters.actor_id} onChange={updateFilter('actor_id')} aria-label={t.actionHistory.actorFilter} className={inputClass}>
                <option value="">{t.actionHistory.allActors}</option>
                {actors.map((actor) => <option key={actor.id} value={actor.id}>{actor.name}</option>)}
            </SelectInput>
            <SelectInput value={filters.action_type} onChange={updateFilter('action_type')} aria-label={t.actionHistory.actionFilter} className={inputClass}>
                <option value="">{t.actionHistory.allActions}</option>
                {actions.map((action) => <option key={action} value={action}>{actionLabel(action)}</option>)}
            </SelectInput>
            <SelectInput value={filters.entity_type} onChange={updateFilter('entity_type')} aria-label={t.actionHistory.entityFilter} className={inputClass}>
                <option value="">{t.actionHistory.allEntities}</option>
                {entities.map((entity) => <option key={entity} value={entity}>{entityLabel(entity)}</option>)}
            </SelectInput>
            <SelectInput value={filters.year_id} onChange={updateFilter('year_id')} aria-label={t.actionHistory.yearFilter} className={inputClass}>
                <option value="">{t.actionHistory.allYears}</option>
                {years.map((year) => <option key={year.id} value={year.id}>{year.label}</option>)}
            </SelectInput>
            <div className="grid grid-cols-2 gap-2">
                <DateInput
                    name="date_from"
                    value={filters.date_from}
                    onChange={updateFilter('date_from')}
                    max={filters.date_to}
                    aria-label={t.actionHistory.dateFrom}
                    placeholder={t.actionHistory.dateFromPlaceholder}
                    className={inputClass}
                />
                <DateInput
                    name="date_to"
                    value={filters.date_to}
                    onChange={updateFilter('date_to')}
                    min={filters.date_from}
                    aria-label={t.actionHistory.dateTo}
                    placeholder={t.actionHistory.dateToPlaceholder}
                    className={inputClass}
                />
            </div>
        </div>
    );

    const hasFilters = Object.values(filters).some(Boolean);
    const emptyState = events.length > 0 || hasFilters
        ? t.actionHistory.noResults
        : t.actionHistory.empty;

    return (
        <section className="flex w-full flex-col gap-3" dir={isRTL ? 'rtl' : 'ltr'}>
            {loadError && (
                <div role="alert" className="flex flex-wrap items-center justify-between gap-3 text-[13px] leading-5 text-error dark:text-error-container">
                    <span>{t.actionHistory.loadError}</span>
                    <button
                        type="button"
                        onClick={() => setRetryToken((current) => current + 1)}
                        className="min-h-10 rounded-lg border border-outline-variant px-3 font-semibold text-on-surface transition-colors hover:bg-surface-container dark:border-[#384c43] dark:text-inverse-on-surface dark:hover:bg-[#34483f]"
                    >
                        {t.actionHistory.retry}
                    </button>
                </div>
            )}
            {!loadError && (
                <>
                    <div className="hidden md:block">
                        <DesktopTable
                            ariaLabel={t.actionHistory.listLabel}
                            columns={columns}
                            emptyState={emptyState}
                            isRTL={isRTL}
                            labels={labels}
                            rows={events}
                            toolbar={toolbar}
                            loading={loading}
                        />
                    </div>
                    <div className="md:hidden">
                        <MobileTable
                            ariaLabel={t.actionHistory.listLabel}
                            columns={columns}
                            emptyState={emptyState}
                            isRTL={isRTL}
                            labels={labels}
                            rows={events}
                            toolbar={toolbar}
                            loading={loading}
                        />
                    </div>
                </>
            )}
        </section>
    );
}
