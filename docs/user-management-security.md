# Administrator user inspection

Open **Administrators → Users → account actions → View user**.

## Permissions

- `ViewAny:User`: directory access.
- `View:User`: account overview and security history.
- `Create:User`: account creation.
- `Update:User`: edit, password reset links, manual email verification, impersonation, suspension/reactivation, session and token revocation.
- `Delete:User`: soft deletion.

Existing super administrator/developer permission bypass remains. Mutations and impersonation additionally require strictly higher authority than the target. Self deletion and modification of equal/higher authority accounts through this controller are blocked. Primary roles must be manageable; additional permission roles cannot grant permissions the actor lacks. Existing role assignments are not automatically rewritten.

## Tracking semantics

`last_login_at` records completed sign in, not password acceptance before MFA. Web Login/Logout events are finalized by web activity middleware after the response, excluding pending challenges and impersonation. Password failures for known accounts and password reset completion use Laravel authentication events. Portal MFA outcomes and administrator user actions are recorded explicitly. The mobile token controller explicitly records token sign in, known-account password failure, MFA outcome, and device logout.

`last_active_at` persists observed authenticated web activity at most once per minute per account, without changing `updated_at`. Mobile token issuance also records activity. General authenticated API requests are not yet tracked by web middleware. Recent activity means within 15 minutes, not a guaranteed live connection. Unknown historical timestamps remain null and display **Not recorded**. There is no backfill from account `updated_at` or session expiration.

The directory's existing online presence indicator continues using its session/real-time sources. It is independent of the durable last-active timestamp.

## Security history

History uses the existing Spatie activity log, under `user-security`, with target account as subject and actor as causer. Pagination is 20 events per page; filters support event and outcome. Account-specific queries constrain both subject type and ID.

Stored metadata is allowlisted: outcome, guard, parsed browser and OS, and administrator-supplied reason for access changes. This feature does not store raw request payloads, credentials, OTPs, recovery codes, tokens, session identifiers, or raw user agents. The detail response exposes only explicit overview and history fields. Other activity-log infrastructure may independently add request metadata; existing audit-log access controls still apply.

Activity logging follows `activitylog.enabled` and existing activity-log retention settings. Turning off activity logging stops new history; it does not stop durable timestamp updates. Configure and enable scheduled activity pruning in **System Settings → Logs & Retention** for the installation's retention policy. History before deployment cannot be reconstructed.

## Deployment and checks

Run `php artisan migrate` before serving updated code. Build frontend assets with `npm run build`; rebuild cached routes/events using the installation's normal deployment process. No permission grants are silently added: assign existing permissions to the intended support/admin roles.

Focused checks:

```sh
php artisan test --compact tests/Feature/AdministratorUserDetailsTest.php tests/Feature/OnlineUserPresenceTest.php tests/Feature/AdministratorUserManagementAnalyticsTest.php
php artisan test --compact tests/Feature/TwoFactorLoginChallengeToggleTest.php tests/Feature/PasskeyAuthenticationTest.php tests/Feature/MagicLinkAuthTest.php tests/Feature/UserAuthorizationAndAccessTest.php
```

Not included: invitations, bulk operations, historical backfill, full profile-field diffs, complete credential-management event coverage, and suspicious-login alerts.

## Access controls and session revocation

The account detail page includes suspension/reactivation with a required reason, database browser-session inspection, API-token inspection, individual credential revocation, and revoke-all access. Every mutation requires `Update:User`, strictly higher authority than the target, and a non-impersonated administrator. This also prevents self suspension and lower-authority administrators from locking out their super administrator.

Suspension blocks session-guard login events, mobile token issuance, and existing authenticated web/API requests. Reactivation does not restore old credentials. Revoking all access increments a durable session version, rotates remember-me credentials, removes API tokens, and deletes DB sessions where configured. Existing legacy sessions default to version zero; fresh logins stamp the current version. Version checks cover Redis/file/cookie sessions without scanning those stores. Authentication middleware runs before access enforcement so Sanctum bearer users are checked too.

Individual session listing and revocation require `SESSION_DRIVER=database` and use the configured session connection/table. The page displays up to 100 unexpired sessions and 100 API tokens. Session action references are encrypted; raw session IDs and token secrets are not exposed. Individual session removal rotates the account's remember-me token; this invalidates remembered login on other devices but does not delete their active sessions. Already-running requests are not canceled by revocation. Use suspension or revoke-all for account compromise; individual DB session deletion alone cannot prevent a concurrent request from writing its session back.

Suspension reasons appear to authorized account viewers and in account security history. Do not put passwords or other secrets into reasons. Activity-log retention policy applies to historical reasons; reactivation clears the current suspension reason on the account.
