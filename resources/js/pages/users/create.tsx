import { Form, Head, Link } from '@inertiajs/react';
import { MailPlus, Send, X } from 'lucide-react';
import { PageHeader } from '@/components/crucible/page-header';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index, store } from '@/routes/users';

export default function UsersCreate() {
    return (
        <>
            <Head title="New user" />

            <div className="crucible-page">
                <PageHeader
                    icon={MailPlus}
                    eyebrow="Identity"
                    title="New User"
                    description="Invite a user to verify email and set their first password."
                />

                <Form
                    {...store.form()}
                    disableWhileProcessing
                    className="grid gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <Card>
                                <CardHeader className="border-b px-4 pb-4 sm:px-6">
                                    <CardTitle>Invitation</CardTitle>
                                    <CardDescription>
                                        New users start without roles. Assign
                                        roles after the invitation is accepted.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid max-w-2xl gap-5 pt-6">
                                    <div className="grid gap-5 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="first_name">
                                                First Name
                                            </Label>
                                            <Input
                                                id="first_name"
                                                name="first_name"
                                                autoComplete="given-name"
                                                required
                                                autoFocus
                                            />
                                            <InputError
                                                message={errors.first_name}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="last_name">
                                                Last Name
                                            </Label>
                                            <Input
                                                id="last_name"
                                                name="last_name"
                                                autoComplete="family-name"
                                                required
                                            />
                                            <InputError
                                                message={errors.last_name}
                                            />
                                        </div>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="email">
                                            Email Address
                                        </Label>
                                        <Input
                                            id="email"
                                            type="email"
                                            name="email"
                                            autoComplete="email"
                                            placeholder="user@example.com"
                                            required
                                        />
                                        <InputError message={errors.email} />
                                    </div>
                                </CardContent>
                            </Card>

                            <div className="flex flex-wrap items-center gap-3">
                                <Button disabled={processing}>
                                    {processing ? <Spinner /> : <Send />}
                                    Invite
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={index()}>
                                        <X />
                                        Cancel
                                    </Link>
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

UsersCreate.layout = {
    breadcrumbs: [
        {
            title: 'Users',
            href: index(),
        },
        {
            title: 'New User',
            href: '#',
        },
    ],
};
