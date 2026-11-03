import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import SelectInput from '../../../components/SelectInput';
import Icon from '../../../components/Icon';
import SearchInput from '../../../components/SearchInput';
import ConfirmDeleteModal from '../../../modals/confirmdelete';
import Modal from '../../../modals/desktopmodal';
import { apiFetch } from '../../../lib/api';
import { useLanguage } from '../../../hooks/useLanguage';
import DesktopTable from '../../../tables/desktoptable';
import MobileTable from '../../../tables/mobiletable';
import UserFormModal from './UserFormModal';

export default function UsersTable({ routeParams = {} }) {
    const { isRTL, lang, t } = useLanguage();
    const [users, setUsers] = useState([]);
    const [roles, setRoles] = useState([]);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(false);
    const [search, setSearch] = useState('');
    const [roleFilter, setRoleFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [action, setAction] = useState(null);
    const [pendingStatus, setPendingStatus] = useState(null);
    const [formState, setFormState] = useState(null);
    const openedAlertUserId = useRef(null);
    const [roleForm, setRoleForm] = useState({ role_id: '', reason: '' });
    const [passwordForm, setPasswordForm] = useState({ password: '', password_confirmation: '' });
    const [saving, setSaving] = useState(false);
    const [actionError, setActionError] = useState('');

    const loadUsers = useCallback(async ({ signal, showLoading = false } = {}) => {
        if (showLoading) setLoading(true);
        setLoadError(false);

        try {
            const response = await apiFetch('/admin/users/table', { signal });
            if (!response.ok) throw new Error('Failed to load users');
            const data = await response.json();

            if (signal?.aborted) return;
            setUsers(Array.isArray(data.users) ? data.users : []);
            setRoles(Array.isArray(data.roles) ? data.roles : []);
        } catch (error) {
            if (error.name !== 'AbortError') setLoadError(true);
        } finally {
            if (!signal?.aborted) setLoading(false);
        }
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        loadUsers({ signal: controller.signal, showLoading: true });

        return () => controller.abort();
    }, [loadUsers]);

    useEffect(() => {
        const alertUserId = String(routeParams.user_id ?? '');
        if (!alertUserId || loading || openedAlertUserId.current === alertUserId) return;

        const user = users.find((item) => String(item.id) === alertUserId);
        if (!user) return;

        openedAlertUserId.current = alertUserId;
        setFormState({ user });
    }, [loading, routeParams.user_id, users]);

    const filteredUsers = useMemo(() => {
        const normalizedSearch = search.trim().toLocaleLowerCase();

        return users.filter((user) => {
            const matchesSearch = !normalizedSearch
                || [user.full_name, user.email, user.phone]
                    .filter(Boolean)
                    .some((value) => value.toLocaleLowerCase().includes(normalizedSearch));
            const matchesRole = !roleFilter || String(user.role_id) === roleFilter;
            const matchesStatus = !statusFilter
                || (statusFilter === 'active' ? user.is_active : !user.is_active);

            return matchesSearch && matchesRole && matchesStatus;
        });
    }, [users, search, roleFilter, statusFilter]);

    const openRoleChange = (user) => {
        setRoleForm({ role_id: String(user.role_id), reason: '' });
        setActionError('');
        setAction({ type: 'role', user });
    };

    const openPasswordReset = (user) => {
        setPasswordForm({ password: '', password_confirmation: '' });
        setActionError('');
        setAction({ type: 'password', user });
    };

    const openUserForm = (user = null) => setFormState({ user });

    const closeAction = () => {
        if (saving) return;
        setAction(null);
        setActionError('');
        setPasswordForm({ password: '', password_confirmation: '' });
    };

    const submitAction = async (event) => {
        event.preventDefault();
        if (!action) return;

        setSaving(true);
        setActionError('');

        const isRoleChange = action.type === 'role';
        const endpoint = isRoleChange
            ? `/admin/users/${action.user.id}/role`
            : `/admin/users/${action.user.id}/access`;
        const payload = isRoleChange
            ? { ...roleForm, role_id: Number(roleForm.role_id) }
            : passwordForm;

        try {
            const response = await apiFetch(endpoint, {
                method: isRoleChange ? 'PATCH' : 'PUT',
                body: JSON.stringify(payload),
            });
            const data = await response.json();

            if (!response.ok) {
                if (data?.errors?.role_id) setActionError(t.users.roleValidationError);
                else if (data?.errors?.reason) setActionError(t.users.reasonValidationError);
                else if (data?.errors?.password) setActionError(t.users.passwordValidationError);
                else if (response.status === 422) setActionError(t.users.protectedAdminError);
                else setActionError(t.users.operationError);
                return;
            }

            setAction(null);
            setPasswordForm({ password: '', password_confirmation: '' });
            await loadUsers();
        } catch {
            setActionError(t.users.operationError);
        } finally {
            setSaving(false);
        }
    };

    const updateStatus = async () => {
        if (!pendingStatus) return;

        setSaving(true);
        setActionError('');

        try {
            const response = await apiFetch(`/admin/users/${pendingStatus.id}/status`, {
                method: 'PATCH',
                body: JSON.stringify({ is_active: !pendingStatus.is_active }),
            });

            if (!response.ok) {
                setActionError(response.status === 422
                    ? t.users.protectedAdminError
                    : t.users.operationError);
                return;
            }

            setPendingStatus(null);
            await loadUsers();
        } catch {
            setActionError(t.users.operationError);
        } finally {
            setSaving(false);
        }
    };

    const locale = lang === 'ar' ? 'ar' : 'fr';
    const roleLabel = (role) => locale === 'ar' ? role.name_ar : role.name_fr;
    const columns = [
        {
            key: 'full_name',
            label: t.users.columns.name,
            sortable: true,
            mobile: 'title',
        },
        {
            key: 'email',
            label: t.users.columns.email,
            sortable: true,
            mobile: 'subtitle',
        },
        {
            key: 'phone',
            label: t.users.columns.phone,
            sortable: true,
            mobile: 'detail',
            value: (user) => user.phone || t.users.emptyValue,
        },
        {
            key: 'role_name',
            label: t.users.columns.role,
            sortable: true,
            mobile: 'detail',
            value: (user) => locale === 'ar' ? user.role_name_ar : user.role_name_fr,
        },
        {
            key: 'language',
            label: t.users.columns.language,
            sortable: true,
            mobile: 'detail',
            value: (user) => t.users.languages[user.language] ?? user.language,
        },
        {
            key: 'is_active',
            label: t.users.columns.status,
            sortable: true,
            mobile: 'badge',
            value: (user) => user.is_active ? t.users.active : t.users.inactive,
            render: (status, user) => (
                <span className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ${
                    user.is_active
                        ? 'bg-primary-fixed text-on-primary-fixed-variant dark:bg-primary-container dark:text-on-primary-container'
                        : 'bg-surface-container-high text-on-surface-variant dark:bg-tertiary-container dark:text-on-tertiary-container'
                }`}>
                    {status}
                </span>
            ),
        },
    ];
    const actions = [
        {
            key: 'edit',
            icon: 'edit',
            label: t.users.edit,
            onClick: openUserForm,
        },
        {
            key: 'role',
            icon: 'manage_accounts',
            label: t.users.changeRole,
            hidden: (user) => user.is_self,
            onClick: openRoleChange,
        },
        {
            key: 'access',
            icon: 'key',
            label: t.users.resetAccess,
            onClick: openPasswordReset,
        },
        {
            key: 'deactivate',
            icon: 'person_off',
            label: t.users.deactivate,
            hidden: (user) => user.is_self || !user.is_active,
            onClick: setPendingStatus,
            className: 'text-error dark:text-error-container',
        },
        {
            key: 'reactivate',
            icon: 'person_check',
            label: t.users.reactivate,
            hidden: (user) => user.is_self || user.is_active,
            onClick: setPendingStatus,
        },
    ];
    const labels = {
        actions: t.users.table.actions,
        pagination: t.users.table.pagination,
        previous: t.users.table.previous,
        next: t.users.table.next,
        page: (page) => t.users.table.page(page),
        selectPage: t.users.table.selectPage,
        selectRow: (user) => t.users.table.selectRow(user.full_name),
        sortBy: (label) => t.users.table.sortBy(label),
        range: (first, last, total) => t.users.table.range(first, last, total),
    };
    const inputClass = 'min-h-10 w-full rounded-lg border border-outline-variant bg-surface-container-low px-3 text-[13px] text-on-surface outline-none focus:border-primary dark:border-outline-variant dark:bg-inverse-surface dark:text-inverse-on-surface';
    const labelClass = 'flex flex-col gap-1.5 text-[12px] font-semibold text-on-surface dark:text-inverse-on-surface';
    const toolbar = (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0 flex-1 sm:max-w-sm">
                <SearchInput
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    isRTL={isRTL}
                    placeholder={t.users.search}
                    ariaLabel={t.users.search}
                />
            </div>
            <div className="flex flex-col gap-2 sm:flex-row">
                <SelectInput
                    value={roleFilter}
                    onChange={(event) => setRoleFilter(event.target.value)}
                    aria-label={t.users.roleFilter}
                    className={inputClass}
                >
                    <option value="">{t.users.allRoles}</option>
                    {roles.map((role) => <option key={role.id} value={role.id}>{roleLabel(role)}</option>)}
                </SelectInput>
                <SelectInput
                    value={statusFilter}
                    onChange={(event) => setStatusFilter(event.target.value)}
                    aria-label={t.users.statusFilter}
                    className={inputClass}
                >
                    <option value="">{t.users.allStatuses}</option>
                    <option value="active">{t.users.active}</option>
                    <option value="inactive">{t.users.inactive}</option>
                </SelectInput>
                <button
                    type="button"
                    onClick={() => openUserForm()}
                    className="inline-flex min-h-10 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg bg-primary-container px-3.5 py-2 text-[12px] font-semibold text-on-primary transition-colors hover:opacity-90 dark:bg-primary-container dark:text-on-primary"
                >
                    <Icon name="add" className="text-[17px]" />
                    {t.users.create}
                </button>
            </div>
        </div>
    );

    if (loadError) {
        return <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{t.users.loadError}</p>;
    }

    if (loading) {
        return <p role="status" className="text-[13px] leading-5 text-on-surface-variant dark:text-tertiary-fixed-dim">{t.users.loading}</p>;
    }

    const emptyState = users.length > 0 ? t.users.noResults : t.users.empty;
    const actionTitle = action?.type === 'role'
        ? t.users.roleTitle(action.user.full_name)
        : t.users.passwordTitle(action?.user.full_name ?? '');

    return (
        <section className="flex w-full flex-col gap-3" dir={isRTL ? 'rtl' : 'ltr'}>
            <div className="hidden md:block">
                <DesktopTable
                    ariaLabel={t.users.listLabel}
                    columns={columns}
                    emptyState={emptyState}
                    isRTL={isRTL}
                    labels={labels}
                    rows={filteredUsers}
                    toolbar={toolbar}
                    actions={actions}
                />
            </div>
            <div className="md:hidden">
                <MobileTable
                    ariaLabel={t.users.listLabel}
                    columns={columns}
                    emptyState={emptyState}
                    isRTL={isRTL}
                    labels={labels}
                    rows={filteredUsers}
                    toolbar={toolbar}
                    actions={actions}
                />
            </div>
            {formState && (
                <UserFormModal
                    open
                    user={formState.user}
                    roles={roles}
                    onClose={() => setFormState(null)}
                    onSaved={async () => {
                        setFormState(null);
                        await loadUsers();
                    }}
                />
            )}
            <Modal
                open={Boolean(action)}
                title={actionTitle}
                icon={action?.type === 'role' ? 'manage_accounts' : 'key'}
                onClose={closeAction}
                isRTL={isRTL}
                closeLabel={t.users.cancel}
                maxWidth="max-w-[520px]"
            >
                <form onSubmit={submitAction} className="flex flex-col gap-4">
                    {action?.type === 'role' ? (
                        <>
                            <label className={labelClass}>
                                {t.users.columns.role}
                                <SelectInput
                                    required
                                    value={roleForm.role_id}
                                    onChange={(event) => setRoleForm((current) => ({ ...current, role_id: event.target.value }))}
                                    className={inputClass}
                                >
                                    {roles.map((role) => <option key={role.id} value={role.id}>{roleLabel(role)}</option>)}
                                </SelectInput>
                            </label>
                            <label className={labelClass}>
                                {t.users.reason}
                                <textarea
                                    required
                                    maxLength={255}
                                    rows={3}
                                    value={roleForm.reason}
                                    onChange={(event) => setRoleForm((current) => ({ ...current, reason: event.target.value }))}
                                    className={`${inputClass} resize-y py-2`}
                                />
                            </label>
                        </>
                    ) : (
                        <>
                            <label className={labelClass}>
                                {t.users.newPassword}
                                <input
                                    required
                                    type="password"
                                    minLength={12}
                                    maxLength={255}
                                    autoComplete="new-password"
                                    value={passwordForm.password}
                                    onChange={(event) => setPasswordForm((current) => ({ ...current, password: event.target.value }))}
                                    className={inputClass}
                                />
                            </label>
                            <label className={labelClass}>
                                {t.users.confirmPassword}
                                <input
                                    required
                                    type="password"
                                    minLength={12}
                                    maxLength={255}
                                    autoComplete="new-password"
                                    value={passwordForm.password_confirmation}
                                    onChange={(event) => setPasswordForm((current) => ({ ...current, password_confirmation: event.target.value }))}
                                    className={inputClass}
                                />
                            </label>
                            <p className="text-[11px] leading-4 text-on-surface-variant dark:text-inverse-on-surface">
                                {t.users.passwordHint}
                            </p>
                        </>
                    )}
                    {actionError && <p role="alert" className="text-[13px] leading-5 text-error dark:text-error-container">{actionError}</p>}
                    <footer className="flex flex-col-reverse gap-3 border-t border-outline-variant pt-4 dark:border-outline-variant sm:flex-row">
                        <button
                            type="button"
                            disabled={saving}
                            onClick={closeAction}
                            className="min-h-11 flex-1 rounded-lg bg-surface-container px-4 py-2 text-[13px] font-semibold text-on-surface transition-colors hover:bg-surface-container-high disabled:opacity-60 dark:bg-inverse-surface dark:text-inverse-on-surface dark:hover:bg-tertiary-container"
                        >
                            {t.users.cancel}
                        </button>
                        <button
                            type="submit"
                            disabled={saving || (action?.type === 'role' && roleForm.role_id === String(action.user.role_id))}
                            className="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-lg bg-primary-container px-4 py-2 text-[13px] font-semibold text-on-primary transition-colors hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-primary-container dark:text-on-primary"
                        >
                            <Icon name={saving ? 'progress_activity' : 'save'} className={`text-[18px] ${saving ? 'animate-spin' : ''}`} />
                            {saving ? t.users.saving : t.users.save}
                        </button>
                    </footer>
                </form>
            </Modal>
            <ConfirmDeleteModal
                open={Boolean(pendingStatus)}
                title={pendingStatus?.is_active ? t.users.deactivateTitle : t.users.reactivateTitle}
                description={pendingStatus?.is_active
                    ? t.users.deactivateMessage(pendingStatus.full_name)
                    : t.users.reactivateMessage(pendingStatus?.full_name ?? '')}
                confirmLabel={pendingStatus?.is_active ? t.users.deactivate : t.users.reactivate}
                cancelLabel={t.users.cancel}
                onConfirm={updateStatus}
                onClose={() => {
                    if (!saving) {
                        setPendingStatus(null);
                        setActionError('');
                    }
                }}
                isLoading={saving}
                error={actionError}
                isRTL={isRTL}
            />
        </section>
    );
}
