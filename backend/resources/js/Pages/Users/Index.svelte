<script>
    import AdminLayout from '../../Layouts/AdminLayout.svelte';
    import PageHeader from '$lib/components/PageHeader.svelte';
    import Pagination from '$lib/components/Pagination.svelte';
    import { Link, router } from '@inertiajs/svelte';
    import { Card, CardContent, Input, Select, Badge, Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from '$lib/components/ui';
    import { formatDate } from '$lib/utils.js';
    import { Search } from '@lucide/svelte';

    let { users, filters } = $props();
    let q = $state(filters.q ?? '');
    let status = $state(filters.status ?? '');

    let timer;
    function applyFilters() {
        clearTimeout(timer);
        timer = setTimeout(() => {
            router.get('/admin/users', { q, status }, { preserveState: true, replace: true });
        }, 250);
    }
</script>

<AdminLayout title="Users">
    <PageHeader title="Users" description={`${users.total} accounts`} />

    <Card>
        <CardContent class="p-4">
            <div class="mb-4 flex flex-wrap gap-3">
                <div class="relative flex-1 min-w-56">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input class="pl-9" placeholder="Search name or email…" bind:value={q} oninput={applyFilters} />
                </div>
                <Select
                    class="w-44"
                    bind:value={status}
                    onchange={applyFilters}
                    options={[
                        { value: '', label: 'All statuses' },
                        { value: 'onboarded', label: 'Onboarded' },
                        { value: 'pending', label: 'Pending onboarding' },
                        { value: 'disabled', label: 'Disabled' },
                    ]}
                />
            </div>

            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead>Plan</TableHead>
                        <TableHead>Horses</TableHead>
                        <TableHead>Scans</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Joined</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {#each users.data as u (u.id)}
                        <TableRow class="cursor-pointer" onclick={() => router.visit(`/admin/users/${u.id}`)}>
                            <TableCell>
                                <div class="font-medium">{u.name}</div>
                                <div class="text-xs text-muted-foreground">{u.email}</div>
                            </TableCell>
                            <TableCell>{u.plan ? u.plan : '—'}</TableCell>
                            <TableCell>{u.horses_count}</TableCell>
                            <TableCell>{u.scans_count}</TableCell>
                            <TableCell>
                                {#if u.disabled}
                                    <Badge variant="destructive">disabled</Badge>
                                {:else if u.onboarded}
                                    <Badge variant="success">onboarded</Badge>
                                {:else}
                                    <Badge variant="muted">pending</Badge>
                                {/if}
                            </TableCell>
                            <TableCell class="text-muted-foreground">{formatDate(u.created_at)}</TableCell>
                        </TableRow>
                    {:else}
                        <TableRow><TableCell colspan="6" class="py-8 text-center text-muted-foreground">No users found.</TableCell></TableRow>
                    {/each}
                </TableBody>
            </Table>

            <Pagination paginator={users} />
        </CardContent>
    </Card>
</AdminLayout>
