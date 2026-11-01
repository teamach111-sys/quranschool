// Pages are grouped by their exact role access. Folders named for role
// combinations contain pages used by those roles. Each role folder also has a
// `components/` folder for sub-components that only that role uses.
import TableauDeBordAdmin from './administrator/TableauDeBordAdmin';
import Ecole from './administrator/Ecole';
import AnneesScolaires from './administrator/AnneesScolaires';
import GroupesAttribution from './administrator/GroupesAttribution';
import EmploiDuTemps from './administrator/EmploiDuTemps';
import UtilisateursRoles from './administrator/UtilisateursRoles';
import HistoriqueChangementsRole from './administrator/HistoriqueChangementsRole';

import TableauDeBordEnseignant from './teacher/TableauDeBordEnseignant';
import MesGroupesEleves from './teacher/MesGroupesEleves';
import MonEmploiDuTemps from './teacher/MonEmploiDuTemps';

import TableauDeBordSecretariat from './secretary/TableauDeBordSecretariat';

import Eleves from './administrator-secretary/Eleves';
import PaiementsFinances from './administrator-secretary/PaiementsFinances';
import Presences from './administrator-teacher-secretary/Presences';
import MonProfil from './administrator-teacher-secretary/MonProfil';
import SeancesMemorisation from './administrator-teacher/SeancesMemorisation';

/**
 * Single source of truth for the app pages.
 *
 * Each page key doubles as its hash route (`/dashboard#/<key>`) and as the
 * `activeKey` used by the navigation. `roles` lists the roles allowed to see
 * the page (see the gates in AppServiceProvider). Add a page here and it is
 * linked everywhere automatically.
 */
export const PAGE_GROUPS = [
    {
        key: 'tableaux-de-bord',
        label: { fr: 'Tableaux de bord', ar: 'لوحات التحكم' },
        pages: [
            { key: 'tableau-de-bord-admin', icon: 'dashboard', label: { fr: 'Administrateur', ar: 'المدير' }, roles: ['admin'], component: TableauDeBordAdmin },
            { key: 'tableau-de-bord-enseignant', icon: 'school', label: { fr: 'Enseignant', ar: 'الأستاذ' }, roles: ['teacher'], component: TableauDeBordEnseignant },
            { key: 'tableau-de-bord-secretariat', icon: 'badge', label: { fr: 'Secrétariat', ar: 'السكرتارية' }, roles: ['secretary'], component: TableauDeBordSecretariat },
        ],
    },
    {
        key: 'scolarite',
        label: { fr: 'Scolarité', ar: 'الشؤون الدراسية' },
        pages: [
            { key: 'ecole', icon: 'account_balance', label: { fr: 'École', ar: 'المؤسسة' }, roles: ['admin'], component: Ecole },
            { key: 'annees-scolaires', icon: 'calendar_month', label: { fr: 'Années scolaires', ar: 'السنوات الدراسية' }, roles: ['admin'], component: AnneesScolaires },
            { key: 'eleves', icon: 'group', label: { fr: 'Élèves', ar: 'الطلبة' }, roles: ['admin', 'secretary'], component: Eleves },
            { key: 'groupes-attribution', icon: 'workspaces', label: { fr: 'Groupes & attribution', ar: 'الأفواج والتوزيع' }, roles: ['admin'], component: GroupesAttribution },
            { key: 'emploi-du-temps', icon: 'calendar_today', label: { fr: 'Emploi du temps', ar: 'الجدول الزمني' }, roles: ['admin'], component: EmploiDuTemps },
            { key: 'presences', icon: 'fact_check', label: { fr: 'Présences', ar: 'الحضور' }, roles: ['admin', 'teacher', 'secretary'], component: Presences },
            { key: 'seances-memorisation', icon: 'menu_book', label: { fr: 'Séances de mémorisation', ar: 'حصص الحفظ' }, roles: ['admin', 'teacher'], component: SeancesMemorisation },
            { key: 'mes-groupes-eleves', icon: 'groups', label: { fr: "Mes groupes d'élèves", ar: 'أفواجي' }, roles: ['teacher'], component: MesGroupesEleves },
            { key: 'mon-emploi-du-temps', icon: 'event', label: { fr: 'Mon emploi du temps', ar: 'جدولي الزمني' }, roles: ['teacher'], component: MonEmploiDuTemps },
        ],
    },
    {
        key: 'administration',
        label: { fr: 'Administration', ar: 'الإدارة' },
        pages: [
            { key: 'utilisateurs-roles', icon: 'manage_accounts', label: { fr: 'Utilisateurs & rôles', ar: 'المستخدمون والأدوار' }, roles: ['admin'], component: UtilisateursRoles },
            { key: 'historique-changements-role', icon: 'history', label: { fr: 'Historique des rôles', ar: 'سجل الأدوار' }, roles: ['admin'], component: HistoriqueChangementsRole },
        ],
    },
    {
        key: 'finances',
        label: { fr: 'Finances', ar: 'المالية' },
        pages: [
            { key: 'paiements-finances', icon: 'payments', label: { fr: 'Paiements & finances', ar: 'المدفوعات والمالية' }, roles: ['admin', 'secretary'], component: PaiementsFinances },
        ],
    },
    {
        key: 'compte',
        label: { fr: 'Compte', ar: 'الحساب' },
        pages: [
            { key: 'mon-profil', icon: 'person', label: { fr: 'Mon profil', ar: 'ملفي الشخصي' }, roles: ['admin', 'teacher', 'secretary'], component: MonProfil },
        ],
    },
];

export const PAGES = PAGE_GROUPS.flatMap((group) => group.pages);

/** Page shown as the title fallback for each role. */
export const ROLE_DEFAULT_PAGE = {
    admin: 'tableau-de-bord-admin',
    teacher: 'tableau-de-bord-enseignant',
    secretary: 'tableau-de-bord-secretariat',
};

export function findPage(key) {
    return PAGES.find((page) => page.key === key) ?? null;
}

export function canSeePage(page, role) {
    return Boolean(page) && (!page.roles || page.roles.includes(role));
}

export function pageGroupsForRole(role) {
    return PAGE_GROUPS
        .map((group) => ({
            ...group,
            pages: group.pages.filter((page) => !page.roles || page.roles.includes(role)),
        }))
        .filter((group) => group.pages.length > 0);
}
