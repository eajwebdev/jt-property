import InputError from '@/components/input-error';
import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type User } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Users', href: '/settings/users' }];

type Role = User['role'];

const roleHelp: Record<Role, string> = {
    owner: 'Owner: full access, including managing users.',
    staff: 'Staff: day-to-day work such as tenants, billing and payments.',
    accountant: 'Accountant: reviews financial records and reports.',
};

type ListedUser = Pick<User, 'id' | 'name' | 'email' | 'role' | 'created_at'>;

export default function Users({ users }: { users: ListedUser[] }) {
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

                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th scope="col" className="px-3 py-2 font-medium">Name</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Email</th>
                                    <th scope="col" className="px-3 py-2 font-medium">Role</th>
                                </tr>
                            </thead>
                            <tbody>
                                {users.map((user) => (
                                    <tr key={user.id} className="border-t">
                                        <td className="px-3 py-2">{user.name}</td>
                                        <td className="px-3 py-2">{user.email}</td>
                                        <td className="px-3 py-2">
                                            <Badge variant={user.role === 'owner' ? 'default' : 'secondary'} className="capitalize">
                                                {user.role}
                                            </Badge>
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