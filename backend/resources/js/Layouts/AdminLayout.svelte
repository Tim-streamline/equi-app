<script>
    import { page, Link, router } from '@inertiajs/svelte';
    import { navSections, canSee } from '$lib/nav.js';
    import { LogOut, Menu, X, ShieldCheck } from '@lucide/svelte';
    import { cn } from '$lib/utils.js';

    let { title = '', children } = $props();

    const user = $derived($page.props.auth?.user);
    const flash = $derived($page.props.flash ?? {});
    const url = $derived($page.url);

    let mobileOpen = $state(false);

    const sections = $derived(
        navSections
            .filter((s) => canSee(s, user?.role))
            .map((s) => ({ ...s, items: s.items.filter((i) => canSee(i, user?.role)) }))
            .filter((s) => s.items.length),
    );

    function isActive(item) {
        if (item.match) return url === item.match || url === item.match + '/';
        return url === item.href || url.startsWith(item.href + '/') || url.startsWith(item.href + '?');
    }

    function logout() {
        router.post('/admin/logout');
    }
</script>

<div class="flex h-full min-h-screen bg-background">
    <!-- Sidebar -->
    <aside
        class={cn(
            'fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-sidebar text-sidebar-foreground transition-transform lg:static lg:translate-x-0',
            mobileOpen ? 'translate-x-0' : '-translate-x-full',
        )}
    >
        <div class="flex h-16 items-center gap-2 border-b border-sidebar-border px-5">
            <div class="flex size-8 items-center justify-center rounded-md bg-sidebar-accent text-white">
                <ShieldCheck class="size-5" />
            </div>
            <div class="leading-tight">
                <div class="text-sm font-semibold">EquiNova</div>
                <div class="text-xs text-sidebar-muted">Admin console</div>
            </div>
            <button class="ml-auto lg:hidden" onclick={() => (mobileOpen = false)} aria-label="Close menu">
                <X class="size-5" />
            </button>
        </div>

        <nav class="flex-1 space-y-5 overflow-y-auto px-3 py-4">
            {#each sections as section (section.title ?? 'main')}
                <div>
                    {#if section.title}
                        <div class="mb-1 px-2 text-[11px] font-semibold uppercase tracking-wider text-sidebar-muted">
                            {section.title}
                        </div>
                    {/if}
                    {#each section.items as item (item.href)}
                        <Link
                            href={item.href}
                            class={cn(
                                'flex items-center gap-3 rounded-md px-2.5 py-2 text-sm font-medium transition-colors',
                                isActive(item)
                                    ? 'bg-sidebar-accent text-white'
                                    : 'text-sidebar-muted hover:bg-white/5 hover:text-sidebar-foreground',
                            )}
                            onclick={() => (mobileOpen = false)}
                        >
                            <item.icon class="size-4 shrink-0" />
                            <span>{item.label}</span>
                        </Link>
                    {/each}
                </div>
            {/each}
        </nav>

        <div class="border-t border-sidebar-border p-3">
            <div class="flex items-center gap-3 rounded-md px-2 py-2">
                <div class="flex size-8 items-center justify-center rounded-full bg-sidebar-accent text-xs font-semibold text-white">
                    {user?.initials ?? '?'}
                </div>
                <div class="min-w-0 flex-1 leading-tight">
                    <div class="truncate text-sm font-medium">{user?.name}</div>
                    <div class="truncate text-xs capitalize text-sidebar-muted">{user?.role?.replace('_', ' ')}</div>
                </div>
                <button onclick={logout} class="text-sidebar-muted hover:text-white" title="Log out" aria-label="Log out">
                    <LogOut class="size-4" />
                </button>
            </div>
        </div>
    </aside>

    {#if mobileOpen}
        <div
            class="fixed inset-0 z-30 bg-black/40 lg:hidden"
            onclick={() => (mobileOpen = false)}
            role="presentation"
        ></div>
    {/if}

    <!-- Main -->
    <div class="flex min-w-0 flex-1 flex-col">
        <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b bg-background/95 px-4 backdrop-blur lg:px-8">
            <button class="lg:hidden" onclick={() => (mobileOpen = true)} aria-label="Open menu">
                <Menu class="size-5" />
            </button>
            <h1 class="text-lg font-semibold">{title}</h1>
        </header>

        {#if flash.success || flash.error}
            <div class="px-4 pt-4 lg:px-8">
                <div
                    class={cn(
                        'rounded-md border px-4 py-3 text-sm',
                        flash.error
                            ? 'border-destructive/30 bg-destructive/10 text-destructive'
                            : 'border-success/30 bg-success/10 text-success',
                    )}
                >
                    {flash.error ?? flash.success}
                </div>
            </div>
        {/if}

        <main class="flex-1 px-4 py-6 lg:px-8">
            {@render children?.()}
        </main>
    </div>
</div>
