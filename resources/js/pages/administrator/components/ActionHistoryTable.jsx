import React, { useEffect, useMemo, useState } from 'react';
import DateInput from '../../../components/DateInput';
import Icon from '../../../components/Icon';
import SearchInput from '../../../components/SearchInput';
import SelectInput from '../../../components/SelectInput';
import Modal from '../../../modals/desktopmodal';
import { useLanguage } from '../../../hooks/useLanguage';
import { useToast } from '../../../components/ToastProvider';
import { apiFetch } from '../../../lib/api';
import DesktopTable from '../../../tables/desktoptable';
import MobileTable from '../../../tables/mobiletable';

export default function ActionHistoryTable() {
    const { isRTL, lang, t } = useLanguage();
    const toast = useToast();
    const [events, setEvents] = useState([]);
    const [actors, setActors] = useState([]);
    const [actions, setActions] = useState([]);
    const [entities, setEntities] = useState([]);
    const [years, setYears] = useState([]);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [retryToken, setRetryToken] = useState(0);
    const [selectedEventId, setSelectedEventId] = useState(null);
    const [selectedEvent, setSelectedEvent] = useState(null);
    const [detailLoading, setDetailLoading] = useState(false);
    const [detailError, setDetailError] = useState(false);
    const [detailRetry, setDetailRetry] = useState(0);
    const [exportingFormat, setExportingFormat] = useState('');
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

    useEffect(() => {
        if (selectedEventId === null) return undefined;
        const controller = new AbortController();

        const loadEvent = async () => {
            setDetailLoading(true);
            setDetailError(false);
            setSelectedEvent(null);

            try {
                const response = await apiFetch(`/admin/action-history/${selectedEventId}`, {
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('Failed to load action event');
                const data = await response.json();
                if (!controller.signal.aborted) setSelectedEvent(data.event);
            } catch (error) {
                if (error.name !== 'AbortError') setDetailError(true);
            } finally {
                if (!controller.signal.aborted) setDetailLoading(false);
            }
        };

        loadEvent();
        return () => controller.abort();
    }, [selectedEventId, detailRetry]);

    const locale = lang === 'ar' ? 'ar' : 'fr';
    const formatDate = (value) => new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
    const actionLabel = (action) => t.actionHistory.actions[action] ?? action;
    const entityLabel = (entity) => t.actionHistory.entities[entity] ?? entity;
    const summaryLabel = (summary) => t.actionHistory.summaries[summary] ?? summary;
    const displayValue = (value) => {
        if (value === null || value === undefined) return t.actionHistory.emptyValue;
        if (typeof value === 'boolean') return value ? t.actionHistory.values.true : t.actionHistory.values.false;
        if (typeof value === 'object') return JSON.stringify(value);

        return String(value);
    };

    const exportHistory = async (format) => {
        if (exportingFormat || events.length === 0) return;
        setExportingFormat(format);

        try {
            const query = new URLSearchParams({
                ...Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== '')),
                format,
                lang,
            });
            const response = await apiFetch(`/admin/action-history/export?${query}`, {
                headers: {
                    Accept: {
                        csv: 'text/csv',
                        pdf: 'application/pdf',
                        xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    }[format],
                },
            });
            if (response.status === 422) throw new Error('Action history export was empty');
            if (!response.ok) throw new Error('Action history export failed');

            const expectedType = {
                csv: 'text/csv',
                pdf: 'application/pdf',
                xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            }[format];
            if (!response.headers.get('Content-Type')?.toLowerCase().includes(expectedType)) {
                throw new Error('Action history export response had an invalid format');
            }
            const exportCount = Number(response.headers.get('X-Export-Record-Count'));
            if (!Number.isInteger(exportCount) || exportCount < 1) {
                throw new Error('Action history export count was invalid');
            }

            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `action-history.${format}`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 1000);
            toast.success(t.actionHistory.exported(format, exportCount));
        } catch {
            toast.error(t.actionHistory.exportError);
        } finally {
            setExportingFormat('');
        }
    };

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
    const actionsForRow = [{
        key: 'details',
        icon: 'visibility',
        label: t.actionHistory.viewDetails,
        disabled: () => detailLoading,
        onClick: (event) => setSelectedEventId(event.id),
    }];
    const inputClass = 'min-h-10 w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 text-[13px] text-on-surface outline-none focus:border-primary dark:border-[#3a3835] dark:bg-inverse-surface dark:text-inverse-on-surface';
    const updateFilter = (key) => (event) => {
        setFilters((current) => ({ ...current, [key]: event.target.value }));
    };
    const toolbar = (
        <div className="flex flex-col gap-3">
            <div className={`flex flex-wrap gap-2 ${isRTL ? 'justify-start' : 'justify-end'}`}>
                <div className="w-full sm:w-auto sm:min-w-52">
                    <SelectInput
                        value={exportingFormat}
                        aria-label={t.exportSelect}
                        onChange={(event) => {
                            if (event.target.value) exportHistory(event.target.value);
                        }}
                        disabled={loading || events.length === 0 || Boolean(exportingFormat)}
                        className="w-full"
                    >
                        <option value="">{t.exportSelect}</option>
                        <option value="csv">{exportingFormat === 'csv' ? t.actionHistory.exportingCsv : t.actionHistory.exportCsv}</option>
                        <option value="pdf">{exportingFormat === 'pdf' ? t.actionHistory.exportingPdf : t.actionHistory.exportPdf}</option>
                        <option value="xlsx">{exportingFormat === 'xlsx' ? t.actionHistory.exportingXlsx : t.actionHistory.exportXlsx}</option>
                    </SelectInput>
                </div>
            </div>
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
        </div>
    );

    const hasFilters = Object.values(filters).some(Boolean);
    const emptyState = events.length > 0 || hasFilters
        ? t.actionHistory.noResults
        : t.actionHistory.empty;

    return (
        <section className="flex w-full flex-col gap-3" dir={isRTL ? 'rtl' : 'ltr'}>
            {loadError && (
                <div role="alert" className="flex flex-wrap items-center justify-between gap-3 text-[13px] leading-5 text-error dark:text-[#e59a92]">
                    <span>{t.actionHistory.loadError}</span>
                    <button
                        type="button"
                        onClick={() => setRetryToken((current) => current + 1)}
                        className="min-h-10 rounded-lg border border-outline-variant px-3 font-semibold text-on-surface transition-colors hover:bg-surface-container dark:border-[#3a3835] dark:text-inverse-on-surface dark:hover:bg-[#32302e]"
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
                            actions={actionsForRow}
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
                            actions={actionsForRow}
                            loading={loading}
                        />
                    </div>
                </>
            )}
            <Modal
                open={selectedEventId !== null}
                title={selectedEvent ? summaryLabel(selectedEvent.summary_key) : t.actionHistory.detailTitle}
                icon="history"
                onClose={() => {
                    setSelectedEventId(null);
                    setSelectedEvent(null);
                    setDetailError(false);
                }}
                loading={detailLoading}
                isRTL={isRTL}
                closeLabel={t.actionHistory.close}
                maxWidth="max-w-[720px]"
            >
                {detailError ? (
                    <div role="alert" className="flex flex-wrap items-center justify-between gap-3 text-[13px] leading-5 text-error dark:text-[#e59a92]">
                        <span>{t.actionHistory.detailError}</span>
                        <button
                            type="button"
                            onClick={() => setDetailRetry((current) => current + 1)}
                            className="min-h-10 rounded-lg border border-outline-variant px-3 font-semibold text-on-surface transition-colors hover:bg-surface-container dark:border-[#3a3835] dark:text-inverse-on-surface dark:hover:bg-[#32302e]"
                        >
                            {t.actionHistory.retry}
                        </button>
                    </div>
                ) : selectedEvent && (
                    <div className="flex flex-col gap-4">
                        <dl className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            {[
                                [t.actionHistory.columns.action, actionLabel(selectedEvent.action_type)],
                                [t.actionHistory.columns.record, selectedEvent.entity_id === null
                                    ? entityLabel(selectedEvent.entity_type)
                                    : `${entityLabel(selectedEvent.entity_type)} #${selectedEvent.entity_id}`],
                                [t.actionHistory.columns.actor, selectedEvent.actor_name],
                                [t.actionHistory.columns.year, years.find((year) => year.id === selectedEvent.year_id)?.label
                                    ?? t.actionHistory.allYears],
                                [t.actionHistory.columns.date, formatDate(selectedEvent.created_at)],
                            ].map(([label, value]) => (
                                <div key={label} className="min-w-0 rounded-lg bg-surface-container-low p-3 dark:bg-[#1c1b1a]">
                                    <dt className="text-[11px] text-on-surface-variant dark:text-[#918d88]">{label}</dt>
                                    <dd className="break-words text-[13px] font-medium text-on-surface dark:text-inverse-on-surface">{value}</dd>
                                </div>
                            ))}
                        </dl>
                        <section className="flex flex-col gap-2">
                            <h3 className="text-[13px] font-semibold text-on-surface dark:text-inverse-on-surface">
                                {t.actionHistory.changes}
                            </h3>
                            {selectedEvent.changes && Object.keys(selectedEvent.changes).length > 0 ? (
                                <dl className="flex flex-col gap-2">
                                    {Object.entries(selectedEvent.changes).map(([field, change]) => {
                                        const hasComparison = change !== null
                                            && typeof change === 'object'
                                            && ('before' in change || 'after' in change);

                                        return (
                                            <div key={field} className="grid grid-cols-1 gap-2 rounded-lg border border-outline-variant p-3 dark:border-[#3a3835] sm:grid-cols-3">
                                                <dt className="text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface">
                                                    {t.actionHistory.fields[field] ?? field}
                                                </dt>
                                                {hasComparison ? (
                                                    <>
                                                        <dd className="break-words text-[12px] text-on-surface-variant dark:text-[#c6c2bd]">
                                                            <span className="font-semibold">{t.actionHistory.before}:</span> {displayValue(change.before)}
                                                        </dd>
                                                        <dd className="break-words text-[12px] text-on-surface-variant dark:text-[#c6c2bd]">
                                                            <span className="font-semibold">{t.actionHistory.after}:</span> {displayValue(change.after)}
                                                        </dd>
                                                    </>
                                                ) : (
                                                    <dd className="break-words text-[12px] text-on-surface-variant dark:text-[#c6c2bd] sm:col-span-2">
                                                        {displayValue(change)}
                                                    </dd>
                                                )}
                                            </div>
                                        );
                                    })}
                                </dl>
                            ) : (
                                <p className="text-[12px] text-on-surface-variant dark:text-[#c6c2bd]">
                                    {t.actionHistory.noChanges}
                                </p>
                            )}
                        </section>
                        <div className="rounded-lg bg-surface-container-low p-3 dark:bg-[#1c1b1a]">
                            <h3 className="mb-1 text-[11px] text-on-surface-variant dark:text-[#918d88]">{t.actionHistory.reason}</h3>
                            <p className="break-words text-[13px] text-on-surface dark:text-inverse-on-surface">
                                {selectedEvent.reason || t.actionHistory.emptyValue}
                            </p>
                        </div>
                    </div>
                )}
            </Modal>
        </section>
    );
}
