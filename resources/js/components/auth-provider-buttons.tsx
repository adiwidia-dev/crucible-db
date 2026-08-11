import { KeyRound } from 'lucide-react';
import { Button } from '@/components/ui/button';

export type AuthProviderButton = {
    id: number;
    provider: string;
    name: string;
    redirect_url: string;
};

type Props = {
    providers: AuthProviderButton[];
    label?: string;
};

export function AuthProviderButtons({
    providers,
    label = 'Continue with',
}: Props) {
    if (providers.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-3">
            <div className="flex items-center gap-3">
                <div className="h-px flex-1 bg-border" />
                <span className="text-xs font-medium text-muted-foreground uppercase">
                    SSO
                </span>
                <div className="h-px flex-1 bg-border" />
            </div>
            <div className="grid gap-2">
                {providers.map((provider) => (
                    <Button
                        key={provider.id}
                        variant="outline"
                        className="w-full justify-center"
                        asChild
                    >
                        <a href={provider.redirect_url}>
                            <KeyRound />
                            {label} {provider.name}
                        </a>
                    </Button>
                ))}
            </div>
        </div>
    );
}
