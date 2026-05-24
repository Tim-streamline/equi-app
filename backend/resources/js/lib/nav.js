import {
    LayoutDashboard,
    Users,
    Heart,
    Stethoscope,
    BookOpen,
    FolderTree,
    CalendarHeart,
    Sparkles,
    ScanLine,
    Package,
    FlaskConical,
    MessagesSquare,
    Flag,
    Bot,
    CalendarClock,
    CreditCard,
    Receipt,
    ClipboardList,
    Download,
    Bell,
    RadioTower,
    ScrollText,
    Settings,
} from '@lucide/svelte';

/**
 * Sidebar structure for the admin console. `roles` (when present) gates a
 * link to admin role scopes; `owner` always sees everything. Hrefs are the
 * real /admin URLs — no route() helper needed.
 */
export const navSections = [
    {
        title: null,
        items: [{ label: 'Dashboard', href: '/admin', icon: LayoutDashboard, match: '/admin' }],
    },
    {
        title: 'Support',
        items: [
            { label: 'Users', href: '/admin/users', icon: Users },
            { label: 'Horses', href: '/admin/horses', icon: Heart },
            { label: 'Therapists', href: '/admin/therapists', icon: Stethoscope, roles: ['admin', 'therapist_admin'] },
            { label: 'Intake bookings', href: '/admin/bookings', icon: CalendarClock },
            { label: 'Protocols', href: '/admin/protocols', icon: ClipboardList },
        ],
    },
    {
        title: 'Content',
        roles: ['content_editor'],
        items: [
            { label: 'Library', href: '/admin/library', icon: BookOpen },
            { label: 'Categories', href: '/admin/library-categories', icon: FolderTree },
            { label: 'Focus topics', href: '/admin/focus-topics', icon: Sparkles },
            { label: 'Seasonal tips', href: '/admin/seasonal-tips', icon: CalendarHeart },
            { label: 'Nova chat', href: '/admin/nova', icon: Bot },
        ],
    },
    {
        title: 'Scanner',
        items: [
            { label: 'Scan results', href: '/admin/scans', icon: ScanLine },
            { label: 'Products', href: '/admin/products', icon: Package },
            { label: 'Ingredients', href: '/admin/ingredients', icon: FlaskConical },
        ],
    },
    {
        title: 'Community',
        roles: ['moderator', 'therapist_admin'],
        items: [
            { label: 'Posts & replies', href: '/admin/community', icon: MessagesSquare },
            { label: 'Reports', href: '/admin/reports', icon: Flag },
        ],
    },
    {
        title: 'Billing',
        roles: ['billing'],
        items: [
            { label: 'Plans', href: '/admin/plans', icon: CreditCard },
            { label: 'Subscriptions', href: '/admin/subscriptions', icon: Receipt },
            { label: 'Payments', href: '/admin/payments', icon: Receipt },
        ],
    },
    {
        title: 'Operations',
        items: [
            { label: 'Data exports', href: '/admin/exports', icon: Download },
            { label: 'Notifications', href: '/admin/notifications', icon: Bell },
            { label: 'Sync health', href: '/admin/sync-health', icon: RadioTower },
            { label: 'Audit log', href: '/admin/audit-log', icon: ScrollText, roles: ['admin'] },
            { label: 'Settings', href: '/admin/settings', icon: Settings, roles: ['admin'] },
        ],
    },
];

/** True if the current admin role may see an item/section. */
export function canSee(node, role) {
    if (!node.roles) return true;
    if (role === 'owner') return true;
    return node.roles.includes(role);
}
