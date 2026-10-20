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
import { Input } from "@/components/ui/input";
import { useOnlinePresence } from "@/contexts/online-presence-context";
import { create, destroy, impersonate, resetPassword, verifyEmail } from "@/routes/administrators/users";
import { update as updateAccess } from "@/routes/administrators/users/access";
import { destroy as revokeSessions } from "@/routes/administrators/users/sessions";
import type { User } from "@/types/user";
import { Head, Link, router, usePoll } from "@inertiajs/react";
import { ArrowUpRight, ChevronDown, CirclePause, Clock3, Plus, ShieldCheck, Users } from "lucide-react";
import { useMemo, useState } from "react";
import { AnalyticsData, UserAnalytics } from "./analytics";
import { createColumns, ExtendedUser } from "./columns";
import { DataTable } from "./data-table";

interface PageProps {
    can_create: boolean;
    users: {
        data: ExtendedUser[];
        total: number;
    };
    analytics: AnalyticsData;
    online_user_ids: number[];
    filters: {
        search?: string;
        role?: string;
        school_id?: string;
        department_id?: string;
        email_verified?: string;
        trashed?: string;
    };
    options: {
        roles: string[];
        schools: { id: number; name: string }[];
        departments: { id: number; name: string }[];
    };
    flash?: {
        type: string;
        message: string;
    };
    user: User;
}

export type ActionType = "delete" | "impersonate" | "verify" | "reset_password" | "suspend" | "reactivate" | "revoke";
type Queue = "all" | "active" | "suspended" | "unverified" | "security" | "recent";

export default function UserIndex({ users, analytics, online_user_ids, options, user, can_create }: PageProps) {
    const presence = useOnlinePresence();
    const liveOnlineUserIds = presence.isReady ? presence.onlineUserIds : online_user_ids;
    const liveAnalytics = useMemo(
        () => ({
            ...analytics,
            online_users: liveOnlineUserIds.length,
            online_rate: analytics.total_users > 0 ? Number(((liveOnlineUserIds.length / analytics.total_users) * 100).toFixed(1)) : 0,
        }),
        [analytics, liveOnlineUserIds],
    );

    usePoll(30000, {
        only: ["users", "analytics", "online_user_ids"],
    });

    const [actionState, setActionState] = useState<{
        type: ActionType | null;
        userId: number | null;
        userName: string | null;
    }>({ type: null, userId: null, userName: null });

    const [queue, setQueue] = useState<Queue>("all");
    const [reason, setReason] = useState("");
    const [processing, setProcessing] = useState(false);
    const [actionError, setActionError] = useState("");
    const counts = useMemo(
        () => ({
            all: users.data.length,
            active: users.data.filter((account) => !account.suspended_at).length,
            suspended: users.data.filter((account) => !!account.suspended_at).length,
            unverified: users.data.filter((account) => !account.email_verified_at).length,
            security: users.data.filter((account) => !account.mfa_configured || !account.security_two_factor_enabled).length,
            recent: users.data.filter((account) => liveOnlineUserIds.includes(account.id) && !account.suspended_at).length,
        }),
        [users.data, liveOnlineUserIds],
    );
    const directory = users.data.filter((account) => {
        if (queue === "active") return !account.suspended_at;
        if (queue === "suspended") return !!account.suspended_at;
        if (queue === "unverified") return !account.email_verified_at;
        if (queue === "security") return !account.mfa_configured || !account.security_two_factor_enabled;
        if (queue === "recent") return liveOnlineUserIds.includes(account.id) && !account.suspended_at;
        return true;
    });
    const closeDialog = () => {
        if (processing) return;
        setActionState({ type: null, userId: null, userName: null });
        setActionError("");
        setReason("");
    };
    const openDialog = (type: ActionType, userId: number, userName: string) => {
        setReason("");
        setActionError("");
        setActionState({ type, userId, userName });
    };
    const confirmAction = () => {
        const { type, userId } = actionState;
        if (!userId || !type || processing) return;
        setProcessing(true);
        setActionError("");
        const visitOptions = {
            preserveScroll: true,
            onSuccess: () => {
                setActionState({ type: null, userId: null, userName: null });
                setReason("");
            },
            onError: (errors: Record<string, string>) => setActionError(Object.values(errors).join(" ")),
            onFinish: () => setProcessing(false),
        };
        if (type === "delete") router.delete(destroy.url(userId), visitOptions);
        if (type === "impersonate") router.post(impersonate.url(userId), {}, visitOptions);
        if (type === "verify") router.put(verifyEmail.url(userId), {}, visitOptions);
        if (type === "reset_password") router.post(resetPassword.url(userId), {}, visitOptions);
        if (type === "revoke") router.delete(revokeSessions.url(userId), visitOptions);
        if (type === "suspend" || type === "reactivate")
            router.put(updateAccess.url(userId), { suspended: type === "suspend", reason }, visitOptions);
    };
    const actionCopy = {
        delete: ["Archive account", "Remove this account from the active directory. Related records are retained."],
        impersonate: ["Impersonate user", "Open a session with this user's access. This action is audited."],
        verify: ["Mark email verified", "Override email verification. Only continue after confirming mailbox ownership."],
        reset_password: ["Send password reset", "Send password reset instructions to this account's email address."],
        suspend: ["Suspend account", "Block sign in and revoke all browser sessions and API tokens. A reason is required."],
        reactivate: ["Reactivate account", "Allow fresh sign in. Previous sessions and API tokens remain revoked. A reason is required."],
        revoke: ["Revoke all access", "Sign out all browser sessions and remove all API tokens. The user can sign in again unless suspended."],
    };
    const requiresReason = actionState.type === "suspend" || actionState.type === "reactivate";
    const columns = createColumns({ onAction: openDialog, onlineUserIds: liveOnlineUserIds });

    return (
        <AdminLayout user={user} title="User Management">
            <Head title="Administrators • Users" />

            <div className="mx-auto flex w-full max-w-[1600px] flex-col gap-6">
                <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div className="flex flex-col gap-2">
                        <p className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">People & access</p>
                        <h1 className="text-3xl font-semibold tracking-tight text-balance">Your user workspace</h1>
                        <p className="text-muted-foreground max-w-2xl text-sm text-pretty">
                            Find an account, understand its access, and act with confidence.
                        </p>
                    </div>
                    {can_create && (
                        <Button render={<Link href={create.url()} />} nativeButton={false} className="h-10 gap-2 px-4">
                            <Plus aria-hidden="true" className="size-4" />
                            Create account
                        </Button>
                    )}
                </header>

                <section aria-label="Account review queues" className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    {(
                        [
                            ["all", "Total accounts", "Your current directory", Users],
                            ["recent", "Recently online", "Observed presence, not live status", Clock3],
                            ["security", "MFA needs review", "Not configured or challenges off", ShieldCheck],
                            ["suspended", "Suspended", "Sign in blocked", CirclePause],
                        ] as const
                    ).map(([key, title, description, Icon]) => (
                        <button
                            key={key}
                            type="button"
                            aria-pressed={queue === key}
                            onClick={() => setQueue(key)}
                            className={`hover:bg-muted/50 flex flex-col gap-4 rounded-xl border p-4 text-left transition-colors duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] focus-visible:outline-2 focus-visible:outline-offset-2 ${queue === key ? "bg-primary/5 border-primary/50" : "bg-card"}`}
                        >
                            <span className="text-muted-foreground flex items-center justify-between gap-2 text-sm">
                                <span>{title}</span>
                                <Icon aria-hidden="true" className="size-4" />
                            </span>
                            <span className="text-3xl font-semibold tracking-tight tabular-nums">{counts[key].toLocaleString()}</span>
                            <span className="text-muted-foreground text-xs">{description}</span>
                        </button>
                    ))}
                </section>

                <section aria-label="User directory" className="bg-card min-w-0 rounded-xl border shadow-xs">
                    <div className="flex flex-wrap items-center justify-between gap-4 p-4 sm:p-6">
                        <div className="flex items-center gap-3">
                            <h2 className="text-lg font-semibold">Account directory</h2>
                            <Badge variant="outline">{counts.all} accounts</Badge>
                        </div>
                        <p className="text-muted-foreground flex items-center gap-2 text-xs">
                            <span className="size-2 rounded-full bg-emerald-500" aria-hidden="true" />
                            Activity refreshes every 30 seconds
                        </p>
                    </div>
                    <nav aria-label="Directory views" className="flex gap-2 overflow-x-auto px-4 pb-4 sm:px-6">
                        {(
                            [
                                ["all", "All accounts"],
                                ["active", "Active"],
                                ["suspended", "Suspended"],
                                ["unverified", "Unverified email"],
                                ["security", "MFA review"],
                                ["recent", "Recently online"],
                            ] as const
                        ).map(([key, title]) => (
                            <button
                                key={key}
                                type="button"
                                aria-pressed={queue === key}
                                onClick={() => setQueue(key)}
                                className={`inline-flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium focus-visible:outline-2 ${queue === key ? "bg-foreground text-background" : "text-muted-foreground hover:bg-muted hover:text-foreground"}`}
                            >
                                {title}
                                <span className="text-xs tabular-nums opacity-70">{counts[key]}</span>
                            </button>
                        ))}
                    </nav>
                    <div className="px-4 pb-4 sm:px-6 sm:pb-6">
                        <DataTable columns={columns} data={directory} options={options} />
                    </div>
                </section>
                <aside className="bg-muted/30 flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 text-sm">
                    <div>
                        <p className="font-medium">Every account has a story</p>
                        <p className="text-muted-foreground mt-1">
                            Open a user to inspect authentication history, devices, API tokens, and access changes.
                        </p>
                    </div>
                    <span className="text-muted-foreground flex items-center gap-2 text-xs">
                        Select a name to inspect
                        <ArrowUpRight aria-hidden="true" className="size-4" />
                    </span>
                </aside>

                <details className="group bg-card rounded-xl border shadow-xs">
                    <summary className="hover:bg-muted/40 focus-visible:ring-ring/45 flex cursor-pointer list-none items-center justify-between gap-4 px-4 py-4 transition-colors outline-none focus-visible:ring-2 focus-visible:ring-inset [&::-webkit-details-marker]:hidden">
                        <div>
                            <h2 className="font-medium">Account analytics</h2>
                            <p className="text-muted-foreground mt-1 text-sm">Registration, verification, and role coverage.</p>
                        </div>
                        <ChevronDown
                            aria-hidden="true"
                            className="text-muted-foreground size-5 shrink-0 transition-transform group-open:rotate-180"
                        />
                    </summary>
                    <div className="border-t p-4">
                        <UserAnalytics stats={liveAnalytics} detailed />
                    </div>
                </details>

                {/* Action confirmation dialog */}
                <AlertDialog open={!!actionState.type} onOpenChange={closeDialog}>
                    <AlertDialogContent className="sm:max-w-md">
                        <AlertDialogHeader>
                            <AlertDialogTitle>{actionState.type ? actionCopy[actionState.type][0] : "Account action"}</AlertDialogTitle>
                            <AlertDialogDescription>
                                {actionState.userName}. {actionState.type && actionCopy[actionState.type][1]}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        {requiresReason && (
                            <label className="flex flex-col gap-2 text-sm font-medium">
                                Reason
                                <Input
                                    value={reason}
                                    onChange={(event) => setReason(event.target.value)}
                                    maxLength={500}
                                    placeholder="Explain this access change"
                                    disabled={processing}
                                />
                            </label>
                        )}
                        {actionError && (
                            <p role="alert" className="text-destructive text-sm">
                                {actionError}
                            </p>
                        )}
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={processing}>Cancel</AlertDialogCancel>
                            <Button
                                disabled={processing || (requiresReason && !reason.trim())}
                                onClick={confirmAction}
                                variant={
                                    actionState.type === "suspend" || actionState.type === "delete" || actionState.type === "revoke"
                                        ? "destructive"
                                        : "default"
                                }
                            >
                                {processing ? "Processing…" : "Confirm action"}
                            </Button>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            </div>
        </AdminLayout>
    );
}
