import AdminLayout from "@/components/administrators/admin-layout";
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { index, show } from "@/routes/administrators/users";
import { update as updateAccess } from "@/routes/administrators/users/access";
import { destroy as destroySessions } from "@/routes/administrators/users/sessions";
import { destroy as destroyToken } from "@/routes/administrators/users/tokens";
import type { User } from "@/types/user";
import { Head, Link, router } from "@inertiajs/react";
import type { FormEvent } from "react";
import { useState } from "react";

interface Account {
    id: number;
    name: string;
    email: string;
    role: string;
    school: string | null;
    department: string | null;
    roles: string[];
    email_verified_at: string | null;
    created_at: string | null;
    last_login_at: string | null;
    last_active_at: string | null;
    recently_active: boolean;
    login_challenges_enabled: boolean;
    authenticator_configured: boolean;
    email_authentication: boolean;
    passkeys_count: number;
    suspended_at: string | null;
    suspension_reason: string | null;
}

interface SecurityLog {
    reason: string | null;
    id: number;
    event: string;
    outcome: string;
    browser: string;
    platform: string;
    actor: string;
    created_at: string | null;
}

interface Props {
    can_manage_access: boolean;
    sessions: { supported: boolean; data: { reference: string; browser: string; platform: string; last_active_at: string }[] };
    tokens: { id: number; name: string; abilities: string[]; last_used_at: string | null; expires_at: string | null }[];
    account: Account;
    user: User;
    logs: { data: SecurityLog[]; current_page: number; last_page: number; total: number };
    filters: { event?: string; outcome?: string };
    event_options: string[];
}

const dateFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" });
function date(value: string | null) {
    return value ? dateFormatter.format(new Date(value)) : "Not recorded";
}
function label(value: string) {
    return value.replaceAll("_", " ");
}

export default function UserDetails({ account, user, logs, filters, event_options, can_manage_access, sessions, tokens }: Props) {
    const [action, setAction] = useState<{ type: "suspend" | "reactivate" | "sessions" | "token"; reference?: string; token?: number } | null>(null);
    const [reason, setReason] = useState("");
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    function confirm() {
        if (!action) return;
        setBusy(true);
        setError("");
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setAction(null);
                setReason("");
            },
            onError: (errors: Record<string, string>) => setError(Object.values(errors).join(" ")),
            onFinish: () => setBusy(false),
        };
        if (action.type === "suspend" || action.type === "reactivate") {
            router.put(updateAccess.url(account.id), { suspended: action.type === "suspend", reason }, options);
        } else if (action.type === "token" && action.token) {
            router.delete(destroyToken.url({ user: account.id, token: action.token }), options);
        } else {
            router.delete(destroySessions.url(account.id), { ...options, data: { reference: action.reference || null } });
        }
    }
    function filter(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        router.get(
            show.url(account.id),
            { event: String(data.get("event") || ""), outcome: String(data.get("outcome") || "") },
            { preserveScroll: true },
        );
    }
    function page(number: number) {
        router.get(show.url(account.id), { ...filters, page: number }, { preserveScroll: true });
    }
    const methods = [account.authenticator_configured && "Authenticator", account.email_authentication && "Email code"].filter(Boolean);

    return (
        <AdminLayout user={user} title="View user">
            <Head title={`${account.name} • User details`} />
            <main className="mx-auto flex w-full max-w-7xl flex-col gap-6">
                <header className="flex flex-col gap-3">
                    <Link
                        href={index.url()}
                        className="text-muted-foreground hover:text-foreground w-fit rounded-md text-sm underline underline-offset-4 focus-visible:outline-2"
                    >
                        Back to users
                    </Link>
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-3xl font-semibold tracking-tight text-balance">{account.name}</h1>
                        <Badge variant={account.recently_active ? "secondary" : "outline"}>
                            {account.recently_active ? "Recently active" : "No recent activity"}
                        </Badge>
                    </div>
                    <p className="text-muted-foreground text-sm break-all">
                        {account.email} · Account #{account.id}
                    </p>
                </header>

                <Card>
                    <CardHeader>
                        <CardTitle>Account access</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <p className="text-sm">
                            Status: <strong>{account.suspended_at ? "Suspended" : "Active"}</strong>
                            {account.suspension_reason && ` · ${account.suspension_reason}`}
                        </p>
                        {can_manage_access && (
                            <div className="flex flex-wrap gap-3">
                                <Button
                                    variant={account.suspended_at ? "outline" : "destructive"}
                                    onClick={() => setAction({ type: account.suspended_at ? "reactivate" : "suspend" })}
                                >
                                    {account.suspended_at ? "Reactivate account" : "Suspend account"}
                                </Button>
                                <Button variant="outline" onClick={() => setAction({ type: "sessions" })}>
                                    Revoke all sessions and API tokens
                                </Button>
                            </div>
                        )}
                        <p className="text-muted-foreground text-sm">
                            Suspension blocks sign in and existing access. Reactivation requires a fresh sign in; previous sessions and tokens stay
                            revoked.
                        </p>
                    </CardContent>
                </Card>
                <section aria-label="Account overview" className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Account and access</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid gap-4 text-sm sm:grid-cols-2">
                                {[
                                    ["Primary role", account.role],
                                    ["School", account.school || "Not assigned"],
                                    ["Department", account.department || "Not assigned"],
                                    ["Permission roles", account.roles.join(", ") || "None assigned"],
                                    ["Email verification", account.email_verified_at ? `Verified ${date(account.email_verified_at)}` : "Unverified"],
                                    ["Account created", date(account.created_at)],
                                ].map(([name, value]) => (
                                    <div key={name} className="min-w-0">
                                        <dt className="text-muted-foreground">{name}</dt>
                                        <dd className="mt-1 font-medium break-words">{value}</dd>
                                    </div>
                                ))}
                            </dl>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Activity and security</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <dl className="grid gap-4 text-sm sm:grid-cols-2">
                                {[
                                    ["Last completed sign in", date(account.last_login_at)],
                                    ["Last active", date(account.last_active_at)],
                                    ["Login challenges", account.login_challenges_enabled ? "Enabled" : "Disabled"],
                                    ["Configured challenge methods", methods.join(", ") || "None"],
                                    ["Registered passkeys", String(account.passkeys_count)],
                                ].map(([name, value]) => (
                                    <div key={name}>
                                        <dt className="text-muted-foreground">{name}</dt>
                                        <dd className="mt-1 font-medium">{value}</dd>
                                    </div>
                                ))}
                            </dl>
                            <p className="text-muted-foreground text-sm text-pretty">
                                Recent activity means observed activity within 15 minutes, not a live connection. Activity timestamps update at most
                                once per minute. Historical activity before tracking began is not available.
                            </p>
                        </CardContent>
                    </Card>
                </section>

                <Card>
                    <CardHeader>
                        <CardTitle>Browser sessions and API tokens</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <p className="text-muted-foreground text-sm">
                            Up to 100 recent sessions and tokens shown. Revoking all access covers every session and token, including entries not
                            displayed.
                        </p>
                        {!sessions.supported && (
                            <p className="text-sm">
                                Individual browser sessions are available only with database session storage. Revoke all access remains available.
                            </p>
                        )}
                        {sessions.supported && sessions.data.length === 0 && (
                            <p className="text-muted-foreground text-sm">No unexpired database sessions recorded.</p>
                        )}
                        {sessions.data.map((session) => (
                            <div key={session.reference} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4">
                                <div className="text-sm">
                                    <p className="font-medium">
                                        {session.browser} / {session.platform}
                                    </p>
                                    <p className="text-muted-foreground">Last active {date(session.last_active_at)}</p>
                                </div>
                                {can_manage_access && (
                                    <Button variant="outline" onClick={() => setAction({ type: "sessions", reference: session.reference })}>
                                        Revoke session
                                    </Button>
                                )}
                            </div>
                        ))}
                        <h2 className="font-medium">API tokens</h2>
                        {tokens.length === 0 && <p className="text-muted-foreground text-sm">No API tokens recorded.</p>}
                        {tokens.map((token) => (
                            <div key={token.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4">
                                <div className="text-sm">
                                    <p className="font-medium">{token.name}</p>
                                    <p className="text-muted-foreground">
                                        Scopes: {token.abilities.join(", ")} · Last used {date(token.last_used_at)} · Expires{" "}
                                        {token.expires_at ? date(token.expires_at) : "No expiry"}
                                    </p>
                                </div>
                                {can_manage_access && (
                                    <Button variant="outline" onClick={() => setAction({ type: "token", token: token.id })}>
                                        Revoke token
                                    </Button>
                                )}
                            </div>
                        ))}
                    </CardContent>
                </Card>
                <AlertDialog
                    open={action !== null}
                    onOpenChange={(open) => {
                        if (!open && !busy) {
                            setAction(null);
                            setError("");
                        }
                    }}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Confirm account access change</AlertDialogTitle>
                            <AlertDialogDescription>
                                This affects {account.name}. Suspension or revoking all access signs out browser sessions and removes API tokens.
                                Individual revocations affect only the selected credential.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        {(action?.type === "suspend" || action?.type === "reactivate") && (
                            <label className="flex flex-col gap-2 text-sm">
                                Reason
                                <textarea
                                    value={reason}
                                    onChange={(event) => setReason(event.target.value)}
                                    maxLength={500}
                                    className="bg-background rounded-md border p-3"
                                    required
                                />
                            </label>
                        )}
                        {error && (
                            <p role="alert" className="text-destructive text-sm">
                                {error}
                            </p>
                        )}
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={busy}>Cancel</AlertDialogCancel>
                            <Button
                                disabled={busy || ((action?.type === "suspend" || action?.type === "reactivate") && !reason.trim())}
                                onClick={confirm}
                            >
                                {busy ? "Updating access…" : "Confirm change"}
                            </Button>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
                <Card>
                    <CardHeader>
                        <CardTitle>Authentication and security history</CardTitle>
                        <p className="text-muted-foreground text-sm">
                            Recorded events for this account. Times use your browser timezone. IP addresses and credentials are not exposed here.
                        </p>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <form onSubmit={filter} className="flex flex-wrap items-end gap-3">
                            <label className="flex flex-col gap-2 text-sm">
                                Event
                                <select
                                    name="event"
                                    defaultValue={filters.event || ""}
                                    className="bg-background h-10 rounded-md border px-3 focus-visible:outline-2"
                                >
                                    <option value="">All events</option>
                                    {event_options.map((event) => (
                                        <option key={event} value={event}>
                                            {label(event)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="flex flex-col gap-2 text-sm">
                                Outcome
                                <select
                                    name="outcome"
                                    defaultValue={filters.outcome || ""}
                                    className="bg-background h-10 rounded-md border px-3 focus-visible:outline-2"
                                >
                                    <option value="">All outcomes</option>
                                    <option value="success">Success</option>
                                    <option value="failure">Failure</option>
                                </select>
                            </label>
                            <Button type="submit" variant="outline">
                                Apply filters
                            </Button>
                        </form>
                        {logs.data.length === 0 ? (
                            <div className="rounded-lg border p-8 text-center">
                                <h2 className="font-medium">No recorded events</h2>
                                <p className="text-muted-foreground mt-2 text-sm">
                                    No history matches these filters. Older authentication activity cannot be reconstructed.
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full text-left text-sm">
                                    <caption className="sr-only">Security events for {account.name}</caption>
                                    <thead className="bg-muted">
                                        <tr>
                                            {["Time", "Event", "Outcome", "Device", "Actor"].map((title) => (
                                                <th key={title} scope="col" className="p-3 font-medium">
                                                    {title}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {logs.data.map((log) => (
                                            <tr key={log.id} className="border-t">
                                                <td className="p-3 whitespace-nowrap">{date(log.created_at)}</td>
                                                <td className="p-3">
                                                    <p className="capitalize">{label(log.event)}</p>
                                                    {log.reason && <p className="text-muted-foreground mt-1 text-xs">{log.reason}</p>}
                                                </td>
                                                <td className="p-3">
                                                    <Badge variant={log.outcome === "failure" ? "destructive" : "secondary"}>{log.outcome}</Badge>
                                                </td>
                                                <td className="p-3">
                                                    {log.browser || "Unknown"} / {log.platform || "Unknown"}
                                                </td>
                                                <td className="p-3">{log.actor}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                        <nav aria-label="Security history pages" className="flex flex-wrap items-center justify-between gap-3">
                            <p className="text-muted-foreground text-sm">
                                {logs.total} events · Page {logs.current_page} of {logs.last_page}
                            </p>
                            <div className="flex gap-2">
                                <Button variant="outline" disabled={logs.current_page <= 1} onClick={() => page(logs.current_page - 1)}>
                                    Previous
                                </Button>
                                <Button variant="outline" disabled={logs.current_page >= logs.last_page} onClick={() => page(logs.current_page + 1)}>
                                    Next
                                </Button>
                            </div>
                        </nav>
                    </CardContent>
                </Card>
            </main>
        </AdminLayout>
    );
}
