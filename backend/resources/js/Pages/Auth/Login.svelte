<script>
    import { useForm } from '@inertiajs/svelte';
    import { Button, Input, Card, CardContent, CardHeader, CardTitle, CardDescription } from '$lib/components/ui';
    import Field from '$lib/components/Field.svelte';
    import { ShieldCheck } from '@lucide/svelte';

    const form = useForm({ email: '', password: '', remember: false });

    function submit(e) {
        e.preventDefault();
        $form.post('/admin/login', { onFinish: () => $form.reset('password') });
    }
</script>

<svelte:head><title>Sign in · EquiNova Admin</title></svelte:head>

<div class="flex min-h-screen items-center justify-center bg-secondary px-4">
    <div class="w-full max-w-sm">
        <div class="mb-6 flex flex-col items-center gap-2">
            <div class="flex size-12 items-center justify-center rounded-xl bg-primary text-primary-foreground">
                <ShieldCheck class="size-6" />
            </div>
            <div class="text-center">
                <div class="text-lg font-semibold">EquiNova Admin</div>
                <div class="text-sm text-muted-foreground">Back-office console</div>
            </div>
        </div>

        <Card>
            <CardHeader>
                <CardTitle>Sign in</CardTitle>
                <CardDescription>Use your admin credentials to continue.</CardDescription>
            </CardHeader>
            <CardContent>
                <form onsubmit={submit} class="space-y-4">
                    <Field label="Email" error={$form.errors.email}>
                        <Input type="email" bind:value={$form.email} autocomplete="username" required />
                    </Field>
                    <Field label="Password" error={$form.errors.password}>
                        <Input type="password" bind:value={$form.password} autocomplete="current-password" required />
                    </Field>
                    <label class="flex items-center gap-2 text-sm text-muted-foreground">
                        <input type="checkbox" bind:checked={$form.remember} class="size-4 rounded border-input" />
                        Remember me
                    </label>
                    <Button type="submit" class="w-full" disabled={$form.processing}>
                        {$form.processing ? 'Signing in…' : 'Sign in'}
                    </Button>
                </form>
            </CardContent>
        </Card>
        <p class="mt-4 text-center text-xs text-muted-foreground">
            Demo: admin@equinova.test · password
        </p>
    </div>
</div>
