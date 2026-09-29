# Supabase Auth setup

Sign-in in this app is handled by Supabase. This project does not generate,
store or compare one-time codes itself.

Supabase answers one question: *is this person who they say they are?*
This app answers the second, separate question: *which laundry are they
signing in to, and what may they do there?*

## Why a laundry short code is needed

A phone number on its own cannot answer the second question, because the
same person can be a customer of two different laundries. `users.mobile` is
therefore not unique.

Each laundry has a short code on its `businesses` row — `ABC` for one, `XYZ`
for another. It is printed on the bill and the collection slip, and it is
what the person types at sign-in.

## 1. Create the project

1. Go to <https://supabase.com/dashboard> and create a project.
2. Wait for the database to finish provisioning.
3. Open **Project Settings → API**.

You need three values:

| Setting | Where it is | What it does |
| --- | --- | --- |
| Project URL | Project Settings → API | The base URL of your project |
| `anon` key | Project Settings → API | Public. Safe in a browser. Used for all sign-in calls |
| `service_role` key | Project Settings → API | **Secret.** Bypasses row-level security |

The `service_role` key is only used by the server for administrative reads
such as looking up a user by id. It is never sent for a sign-in and never
leaves the server. `tests/Feature/SupabaseAuthTest.php` asserts both of
those, so a regression here fails the test suite rather than being noticed
in production.

## 2. Fill in `.env`

```dotenv
SUPABASE_AUTH_ENABLED=true
SUPABASE_URL=https://your-project-ref.supabase.co
SUPABASE_ANON_KEY=your-anon-key
SUPABASE_SERVICE_ROLE_KEY=your-service-role-key
SUPABASE_COUNTRY_CODE=91
```

Then clear the caches:

```bash
php artisan config:clear
```

Leave `SUPABASE_AUTH_ENABLED=false` until the keys above are in place. With
it off, the login page explains that sign-in is not configured yet instead
of failing with an error.

## 3. Turn on the way people sign in

Under **Authentication → Providers**, enable **Email**. Supabase treats a
sign-in identifier as an email address unless a phone number is supplied, so
this is needed for email codes as well as for new accounts created by phone.

New accounts are created automatically on first successful code
verification. The application then decides what role the new profile gets,
based on rows an Admin created in advance — never from anything typed at the
login screen.

## 4. SMS for phone codes

Phone codes cost money and need a provider attached. Go to
**Authentication → Providers → Phone** and connect one of:

- **Twilio** — the most common. Costs per message.
- **MessageBird** — also per message.
- **A custom hook** — if you have a gateway of your own, Supabase can POST
  the message to your endpoint instead.

Without a provider attached, phone sign-in fails at the provider, and the
login screen reports "We could not send the code" without exposing any
provider detail.

Email codes work out of the box, but Supabase's free tier caps how many
emails can be sent per hour, so it is suitable for development and staff, not
for a busy customer base.

## 5. Give each laundry a short code

Every row in `businesses` needs a `slug`, which is the code its customers
type:

```bash
php artisan tinker
```

```php
$business = App\Models\Business::where('business_name', 'ABC Laundry')->first();
$business->update(['slug' => 'ABC']);
```

The demo seeder already does this for the two demo laundries. `slug` is
unique, so two laundries cannot share a code.

A new laundry gets its code when it is created, not on first sign-in. Nobody
can sign in to a laundry whose code they do not have.

## 6. Confirm it works

```bash
npm run build          # only if you have not built the assets yet
php artisan serve
```

Open <http://127.0.0.1:8000/login>. You should see the login form with a
field for the laundry short code and a note asking for your name on first
sign-in.

To check it without a real SMS, run the test suite instead — it exercises
the whole flow against a faked Supabase:

```bash
php artisan test --filter=SupabaseAuthTest
```

## What the login screen asks for, and why

| Field | Needed when |
| --- | --- |
| Mobile number or email | Always |
| Laundry short code | The first time a person signs in on a device |
| Your name | The first time, if the laundry has no customer record for that number |

After the first sign-in the code is remembered on the Supabase account, so
later visits only need the one-time code.

## If something is wrong

**"Sign-in is not configured yet"**
`SUPABASE_AUTH_ENABLED` is `false`, or the URL and anon key are blank.

**"We could not send the code"**
Check **Authentication → Providers → Phone** has a provider attached. Run
`php artisan config:clear` after editing `.env`.

**"We could not match this number to a laundry"**
No `businesses` row has that `slug`, or its `status` is not `ACTIVE`. The
laundry needs its short code setting (step 5).

**A staff member is signed in as a customer**
Their number has no employee row in that laundry. An Admin creates the
employee account first; sign-in adopts that row and keeps the role assigned
to it. A role is never taken from the login form.

**"This account has been deactivated"**
The `users` row exists with a non-active `status`. An Admin must reactivate
it. This is how a staff member's access is withdrawn without deleting their
history.
