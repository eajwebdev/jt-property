import InputError from '@/components/input-error';
import HeadingSmall from '@/components/heading-small';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type SharedData, type User } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Users', href: '/settings/users' }];

type Role = User['role'];

const roleHelp: Record<Role, string> = {
    owner: 'Owner: full access, including managing users.',
    staff: 'Staff: day-to-day work such as tenants, billing and payments.',
    accountant: 'Accountant: read-only access to financial records and reports. Cannot change anything.',
};

type ListedUser = Pick<User, 'id' | 'name' | 'email' | 'role' | 'created_at'> & { deactivated_at: string | null };

function UserActions({ user, isSelf }: { user: ListedUser; isSelf: boolean }) {
    const active = user.deactivated_at === null;
    const [open, setOpen] = useState(false);

    const send = (action: 'users.deactivate' | 'users.reactivate') =>
        router.patch(route(action, user.id), {}, { preserveScroll: true, onFinish: () => setOpen(false) });

    if (!active) {
        return (
            <Button variant="outline" size="sm" onClick={() => send('users.reactivate')}>
                Reactivate
            </Button>
        );
    }

    if (isSelf) {
        return <span className="text-xs text-muted-foreground">You</span>;
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    Deactivate
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Deactivate {user.name}?</DialogTitle>
                    <DialogDescription>They will be signed out and can't sign in until reactivated. Their history stays.</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button variant="destructive" onClick={() => send('users.deactivate')}>
                        Deactivate
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function RoleCell({ user, isSelf }: { user: ListedUser; isSelf: boolean }) {
    const [pending, setPending] = useState<Role | null>(null);

    const confirm = () => {
        if (!pending) return;
        router.patch(route('users.update', user.id), { name: user.name, role: pending }, { preserveScroll: true, onFinish: () => setPending(null) });
    };

    return (
        <div className="grid gap-1">
            <Select value={user.role} onValueChange={(role) => setPending(role as Role)} disabled={isSelf}>
                <SelectTrigger className="h-8 w-32" aria-label={`Role for ${user.name}`}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="owner">Owner</SelectItem>
                    <SelectItem value="staff">Staff</SelectItem>
                    <SelectItem value="accountant">Accountant</SelectItem>
                </SelectContent>
            </Select>
            {isSelf && <p className="text-xs text-muted-foreground">Ask another owner to change your role.</p>}
            <Dialog open={pending !== null} onOpenChange={(open) => !open && setPending(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Change {user.name}'s role?</DialogTitle>
                        <DialogDescription>{pending && roleHelp[pending]}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button variant="secondary">Cancel</Button>
                        </DialogClose>
                        <Button onClick={confirm}>Change role</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

export default function Users({ users }: { users: ListedUser[] }) {
    const { auth, flash, errors: pageErrors } = usePage<SharedData>().props;
    const rowError = (pageErrors as Record<string, string>).role ?? (pageErrors as Record<string, string>).user;
    const { data, setData, post, errors, processing, reset, recentlySuccessful } = useForm({
        name: '',
        email: '',
        role: 'staff' as Role,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('users.store'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Users" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Users" description="People who can sign in to this system" />

                    {flash.success && (
                        <Alert role="status">
                            <AlertDescription>{flash.success}</AlertDescription>
                        </Alert>
                    )}
                    {rowError && (
                        <Alert variant="destructive" role="alert">
                            <AlertDescription>{rowError}</AlertDescription>
                        </Alert>
                    )}

                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th scope="col" className="px-3 py-2 font-medium">Name</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Email</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Role</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Status</th>
                                    <th scope="col" className="px-3 py-2 font-medium"><span className="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                {users.map((user) => (
                                    <tr key={user.id} className="border-t">
                                        <td className="px-3 py-2">{user.name}</td>
                                        <td className="px-3 py-2">{user.email}</td>
                                        <td className="px-3 py-2">
                                            <RoleCell user={user} isSelf={user.id === auth.user.id} />
                                        </td>
                                        <td className="px-3 py-2">
                                            {user.deactivated_at ? (
                                                <Badge variant="secondary">Deactivated</Badge>
                                            ) : (
                                                <Badge className="border-transparent bg-success text-success-foreground">Active</Badge>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-right">
                                            <UserActions user={user} isSelf={user.id === auth.user.id} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                <form onSubmit={submit} className="space-y-6">
                    <HeadingSmall title="Add user" description="Create a login for someone on your team" />

                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} autoComplete="off" required />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">Email address</Label>
                        <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} autoComplete="off" required />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="role">Role</Label>
                        <Select value={data.role} onValueChange={(value) => setData('role', value as Role)}>
                            <SelectTrigger id="role" aria-describedby="role-help">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="owner">Owner</SelectItem>
                                <SelectItem value="staff">Staff</SelectItem>
                                <SelectItem value="accountant">Accountant</SelectItem>
                            </SelectContent>
                        </Select>
                        <p id="role-help" className="text-sm text-muted-foreground">
                            {roleHelp[data.role]}
                        </p>
                        <InputError message={errors.role} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password">Password</Label>
                        <Input
                            id="password"
                            type="password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            autoComplete="new-password"
                            required
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">Confirm password</Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            autoComplete="new-password"
                            required
                        />
                    </div>

                    <div className="flex items-center gap-4">
                        <Button disabled={processing}>Add user</Button>
                        {recentlySuccessful && (
                            <p role="status" className="text-sm text-success">
                                User added
                            </p>
                        )}
                    </div>
                </form>
            </SettingsLayout>
        </AppLayout>
    );
}