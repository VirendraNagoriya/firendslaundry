# Google sign-in setup

Sign-in in this app is handled by this app, not by a third-party auth
service. Google answers one question — *is this person who they say they
are?* — and this app answers the separate second question: *which laundry
are they signing in to, and what may they do there?*

That second question is never taken from anything on the login screen except
the laundry's short code, and the role always comes from a `users` row that
already existed. Nothing typed at sign-in can grant a role.

---

## 1. Create a Google Cloud project

1. Open <https://console.cloud.google.com>.
2. Pick an existing project or click **Select a project → New project**.
3. Name it (for example `Friends Laundry`) and create it.
4. Make sure the project selector at the top is set to that project. It is
   easy to create the client in the wrong project and then spend an hour
   wondering why the button does nothing.

## 2. Configure the consent screen

In the left sidebar open **Google Auth Platform → Branding** (older consoles
call this **OAuth consent screen**).

- **App name** — e.g. `Friends Laundry`. This is the name people see on
  Google's consent page.
- **User support email** — your address.
- **Developer contact email** — your address.

Then open **Google Auth Platform → Audience**.

- **External** unless every user is inside your organisation's Workspace
  domain, in which case **Internal** is simpler and skips verification.

While the consent screen is in **Testing**, only accounts you list as test
users can sign in. To let anybody in, publish it: set **Publish status** to
**In production**.

> In production, Google normally asks you to verify the app. That needs the
> domain on your consent screen's **Authorized domains** list to be verified,
> which in turn needs a `/.well-known/` file served from it. Until that is
> done you can leave the app in **Testing** and add each user's Google address
> as a test user, which is fine for a laundry that is not open to the public.

You do **not** need to add yourself as a test user for step 3 to work; the
client is created either way.

## 3. Create the OAuth client

1. **Google Auth Platform → Clients**.
2. **Create client**.
3. **Application type: Web application**.
4. **Name** — e.g. `Friends Laundry web`.
5. **Authorized redirect URIs** — add exactly one of:

   ```
   http://127.0.0.1:8000/auth/google/callback     # local development
   https://your-domain.example/auth/google/callback   # production
   ```

   The match is exact: scheme, host, port, path and any trailing slash all
   matter. `localhost` and `127.0.0.1` are **different** to Google — pick one
   and use it consistently in the browser address bar too.

6. **Create**.
7. Copy the **Client ID** and **Client secret**.

The client secret belongs on the server. It is never sent to the browser,
never put in a URL, and never committed.

## 4. Configure this app

Copy `.env.example` to `.env` if you have not already, then set:

```dotenv
GOOGLE_AUTH_ENABLED=true
GOOGLE_CLIENT_ID=xxxxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=xxxxx
GOOGLE_REDIRECT_URI=http://127.0.0.1:8000/auth/google/callback
```

Then clear the cached config, or the old values will keep being used:

```bash
php artisan config:clear
```

For the hosted site, change `GOOGLE_REDIRECT_URI` to the production URL and
add that same URL as a second authorized redirect URI on the client. Both can
be registered at once; that is what lets one client serve local and live.

## 5. Try it

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Visit `/login` and press **Continue with Google**.

---

## How the pieces fit together

| Concern | Where it lives |
| --- | --- |
| "Is this person real?" | Google, via an OAuth code exchange |
| Which Google account | `oauth_accounts.provider_id` (Google's permanent `sub`) |
| Which laundry | `businesses.slug`, the short code on the bill |
| Role and branch | `users.role`, `users.branch_id`, set by an Admin beforehand |
| Signed-in state | An ordinary Laravel session cookie |

The Google identity is matched on `sub`, **never** on the email address. An
Admin can type any address into a staff record, so an email is not proof that
the person arriving owns it. Google's `email_verified` claim is recorded on
`users.email_verified_at` but is not used to attach a sign-in to an account
that is not already linked.

Sign-in is deliberately **stateful**. Socialite's `stateless()` mode skips the
`state` round trip, which is the only thing proving the person who started the
sign-in is the person who finished it. Skipping it allows login CSRF, where an
attacker starts a flow with their own Google account and walks a victim into
it. No token is placed in a URL or handed to JavaScript; the browser ends up
with a plain session cookie.

## One account, one laundry

A Google identity is tied to exactly one profile, which belongs to one laundry
— enforced by the unique index on `(provider, provider_id)`. If somebody is a
customer of two laundries they need two accounts, so they sign in with a
different Google account for the second one. This is deliberate: letting one
identity pick a tenant per sign-in is how cross-tenant mistakes happen.

## Staff accounts

A laundry's owner creates staff from **Employees**, choosing the role. That
creates a `users` row with a mobile number and no password and no linked
Google account. When that person signs in for the first time — with Google or
by registering a password — the existing row is **adopted**, so the role the
owner assigned survives. Nothing about the sign-in can change a role.

## Known limitation: no password reset

There is deliberately no "forgot password" flow. This app has not proved that
a self-registered person can read their inbox, and Google-verified addresses
belong to Google accounts rather than to the `users` rows that would be reset.
Wiring up a reset on that basis would mean handing a password to whoever can
read an unverified mailbox — including any staff account whose address an
Admin typed in. Two mitigations are in place: password sign-in is rate
limited, and password minimum length is 12.

Add a reset only alongside real email verification, so the address can be
proven before it is used to take over an account.

## Troubleshooting

**`Error 400: redirect_uri_mismatch`**
The URI in `GOOGLE_REDIRECT_URI` does not exactly match one on the client.
Check scheme, `localhost` vs `127.0.0.1`, port and trailing slash.

**The button is missing from `/login`**
`GOOGLE_CLIENT_ID` or `GOOGLE_CLIENT_SECRET` is empty, or
`GOOGLE_AUTH_ENABLED=false`. Run `php artisan config:clear` after editing
`.env` — a cached config keeps the old values.

**`Access blocked: ... has not completed the Google verification process`**
The consent screen is in **Testing** and this Google address is not a test
user. Add it under **Audience → Test users**, or publish the app.

**`Error 403: restricted_client`**
An OAuth verification or branding issue on the client. Check the client's
OAuth consent screen is linked to a completed Branding page.

**Signed in as the wrong person**
This should be impossible. If it happens, the client secret is probably
leaked, or a proxy is rewriting the callback host. Both need fixing at the
source; do not work around it in the app.

## Environment flags

| Variable | Default | Effect |
| --- | --- | --- |
| `GOOGLE_AUTH_ENABLED` | `true` | Hides every Google route and the button when false |
| `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | empty | Google is unavailable until both are set |
| `GOOGLE_REDIRECT_URI` | `http://127.0.0.1:8000/auth/google/callback` | Must match the client |
| `PASSWORD_AUTH_ENABLED` | `true` | Set false for Google-only sign-in |
| `PASSWORD_MIN_LENGTH` | `12` | |
| `OPEN_CUSTOMER_REGISTRATION` | `true` | Set false to stop self sign-up; staff are unaffected |
| `LOGIN_MAX_ATTEMPTS` / `LOGIN_DECAY_MINUTES` | `5` / `15` | Password brute-force limit |
| `BUSINESS_CODE_LABEL` | `Laundry code` | Wording for the short code on a bill |