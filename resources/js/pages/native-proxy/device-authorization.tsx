import { Form, Head, Link, setLayoutProps } from '@inertiajs/react';
import { CircleCheck, ShieldCheck, ShieldX } from 'lucide-react';
import { useState } from 'react';
import {
    confirm,
    resolve,
} from '@/actions/App/Http/Controllers/NativeProxy/DeviceAuthorizationController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSeparator,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Spinner } from '@/components/ui/spinner';

const DEVICE_CODE_LENGTH = 8;
const DEVICE_CODE_PATTERN = '^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]+$';

type DeviceAuthorization = {
    id: string;
    code_status: 'pending' | 'approved' | 'denied' | 'consumed' | 'expired';
    connection: string;
    protocol: 'mysql' | 'pgsql';
    access_mode: 'read' | 'write';
    cli_version: string;
    operating_system: string;
    architecture: string;
    device_label: string | null;
    expires_at: string;
};

type Props = {
    authorization: DeviceAuthorization | null;
};

export default function NativeProxyDeviceAuthorization({
    authorization,
}: Props) {
    const isAwaitingApproval =
        authorization === null || authorization.code_status === 'pending';
    const isAuthorized =
        authorization?.code_status === 'approved' ||
        authorization?.code_status === 'consumed';
    const isDenied = authorization?.code_status === 'denied';
    const isExpired = authorization?.code_status === 'expired';
    const [deviceCode, setDeviceCode] = useState('');
    const pageTitle = isAuthorized
        ? 'Device approved'
        : isDenied
          ? 'Device denied'
          : isExpired
            ? 'Device code expired'
            : 'Approve a device';
    const pageDescription = isAuthorized
        ? 'The CLI can now finish authorizing this connection.'
        : isDenied
          ? 'This device was not granted Native Client Access.'
          : isExpired
            ? 'This device code can no longer be used.'
            : 'Enter the short code shown by the Crucible CLI to approve this device for your active access window.';

    setLayoutProps({
        title: pageTitle,
        description: pageDescription,
    });

    return (
        <>
            <Head title={pageTitle} />

            {isAwaitingApproval && (
                <Form
                    {...resolve.form()}
                    disableWhileProcessing
                    className="grid gap-6"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="user_code"
                                value={
                                    deviceCode.length === DEVICE_CODE_LENGTH
                                        ? `${deviceCode.slice(0, 4)}-${deviceCode.slice(4)}`
                                        : ''
                                }
                            />
                            <div className="grid gap-3 text-center">
                                <span className="text-sm font-medium">
                                    Device code
                                </span>
                                <div className="flex justify-center">
                                    <InputOTP
                                        aria-label="Device code"
                                        maxLength={DEVICE_CODE_LENGTH}
                                        value={deviceCode}
                                        onChange={(value) =>
                                            setDeviceCode(value.toUpperCase())
                                        }
                                        pattern={DEVICE_CODE_PATTERN}
                                        pasteTransformer={(value) =>
                                            value
                                                .replace(
                                                    /[^23456789A-HJ-NP-Z]/gi,
                                                    '',
                                                )
                                                .toUpperCase()
                                        }
                                        inputMode="text"
                                        autoComplete="off"
                                        disabled={processing}
                                        autoFocus
                                    >
                                        <InputOTPGroup>
                                            {Array.from(
                                                { length: 4 },
                                                (_, index) => (
                                                    <InputOTPSlot
                                                        key={index}
                                                        index={index}
                                                        className="h-11 w-9 font-mono text-base font-semibold sm:w-10"
                                                    />
                                                ),
                                            )}
                                        </InputOTPGroup>
                                        <InputOTPSeparator />
                                        <InputOTPGroup>
                                            {Array.from(
                                                { length: 4 },
                                                (_, index) => (
                                                    <InputOTPSlot
                                                        key={index + 4}
                                                        index={index + 4}
                                                        className="h-11 w-9 font-mono text-base font-semibold sm:w-10"
                                                    />
                                                ),
                                            )}
                                        </InputOTPGroup>
                                    </InputOTP>
                                </div>
                                <InputError message={errors.user_code} />
                            </div>

                            <Button
                                type="submit"
                                className="w-full"
                                disabled={
                                    processing ||
                                    deviceCode.length !== DEVICE_CODE_LENGTH
                                }
                            >
                                {processing ? <Spinner /> : <ShieldCheck />}
                                Approve device
                            </Button>

                            <p className="text-center text-xs leading-5 text-muted-foreground">
                                Only enter a code visible in your own terminal.
                                Approval applies to the active access request
                                and its remaining window.
                            </p>
                        </>
                    )}
                </Form>
            )}

            {authorization && isAuthorized && (
                <div className="flex flex-col items-center gap-4 text-center">
                    <div className="flex size-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                        <CircleCheck className="size-6" />
                    </div>
                    <p className="max-w-sm text-sm leading-6 text-muted-foreground">
                        Return to the terminal to continue. You can close this
                        tab using your browser's tab control.
                    </p>
                </div>
            )}

            {authorization && (isDenied || isExpired) && (
                <div className="flex flex-col items-center gap-4 text-center">
                    <div className="flex size-12 items-center justify-center rounded-full bg-destructive/10 text-destructive">
                        <ShieldX className="size-6" />
                    </div>
                    <p className="max-w-sm text-sm leading-6 text-muted-foreground">
                        {isDenied
                            ? 'Start a new connection if you still need access.'
                            : 'Start the CLI connection again to generate a new device code.'}
                    </p>
                    <Button asChild variant="outline" className="w-full">
                        <Link href={confirm()}>Enter another code</Link>
                    </Button>
                </div>
            )}
        </>
    );
}
