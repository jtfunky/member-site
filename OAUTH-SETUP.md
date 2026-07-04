# Social Login (OAuth) — Setup Checklist

Step-by-step guide to enabling "Continue with Google / Facebook / Discord" on
**members.zachalcasid.com**. You can enable just one provider to start — leave the
others blank in `config.php` and their buttons disappear automatically.

---

## 0. The big picture

Each provider must know about your site before its button can log anyone in. For
each one you:
1. Register your site in the provider's developer console.
2. Register the **redirect/callback URL** (must match character-for-character).
3. Copy the **Client ID** + **Client Secret** into `includes/config.php`.

Redirect/callback URLs (one per provider):
```
https://members.zachalcasid.com/oauth-callback.php?provider=google
https://members.zachalcasid.com/oauth-callback.php?provider=facebook
https://members.zachalcasid.com/oauth-callback.php?provider=discord
```

---

## 1. Google — https://console.cloud.google.com

### a) OAuth consent screen  (do this first)
"APIs & Services" → **OAuth consent screen**. Fill in:
- App name (e.g. **DrumKit**)
- User support email
- App logo (optional)
- App domain / homepage: `https://members.zachalcasid.com`
- Privacy policy URL + Terms of service URL (Google usually requires these)
- Authorized domains: `zachalcasid.com`
- Developer contact email
- Scopes: `openid`, `email`, `profile` — basic, no special verification needed

⚠️ A new consent screen starts in **Testing** mode — only emails in the "Test users"
list can log in. When ready, click **Publish app** to move it to Production (instant
for these basic scopes).

### b) Credentials
"APIs & Services" → **Credentials** → Create Credentials → **OAuth client ID** →
"Web application".
- Authorized redirect URI: `https://members.zachalcasid.com/oauth-callback.php?provider=google`
- Copy the **Client ID** and **Client Secret**.

---

## 2. Facebook — https://developers.facebook.com

- "My Apps" → **Create App**.
- Add the **Facebook Login** product.
- Facebook Login → Settings → **Valid OAuth Redirect URIs**:
  `https://members.zachalcasid.com/oauth-callback.php?provider=facebook`
- Settings → Basic → copy **App ID** (= Client ID) and **App Secret** (= Client Secret).

⚠️ The app starts in **Development** mode (only you/test users can log in). Flip it to
**Live** when ready to launch.

---

## 3. Discord — https://discord.com/developers/applications

- **New Application** → open the **OAuth2** section.
- **Redirects** → add:
  `https://members.zachalcasid.com/oauth-callback.php?provider=discord`
- Copy the **Client ID** and **Client Secret**.

(No consent-screen / review step — Discord is the most lenient.)

---

## 4. Put the keys in config.php

Edit `includes/config.php`, lines 42–47. Leave a Client ID as `''` to hide that
provider's button.

```php
define('OAUTH_GOOGLE_CLIENT_ID',       '...');
define('OAUTH_GOOGLE_CLIENT_SECRET',   '...');
define('OAUTH_FACEBOOK_CLIENT_ID',     '...');
define('OAUTH_FACEBOOK_CLIENT_SECRET', '...');
define('OAUTH_DISCORD_CLIENT_ID',      '...');
define('OAUTH_DISCORD_CLIENT_SECRET',  '...');
```

---

## 5. Upload files to Hostinger

**New files**
- `includes/oauth.php`
- `includes/social-buttons.php`
- `oauth-start.php`
- `oauth-callback.php`
- `migrate-oauth.php`  (delete after step 6)

**Modified files (overwrite the old ones)**
- `includes/config.php`  (with your keys)
- `login.php`
- `register.php`
- `profile.php`
- `assets/css/auth.css`
- `.htaccess`  (protects the include files — re-upload if your live copy is older)

---

## 6. Run the database migration (once)

1. Visit `https://members.zachalcasid.com/migrate-oauth.php` in a browser
   (creates the `oauth_accounts` table).
2. **Delete `migrate-oauth.php` immediately** afterward.

---

## 7. Test each enabled provider

- Click the button on the login page → provider consent → you should land logged in.
- Log in with a provider whose email matches an existing account → it should **link**
  to that account, not create a duplicate.
- On the profile page, test **Connect** and **Disconnect** for a provider.

---

## Things worth knowing

- Providers must return a **verified email**, or the login is rejected. Facebook/Discord
  users without a shared email are told to register with email instead.
- A social-only account is created with an empty password. The user can set one later
  via **Forgot Password** to enable normal email/password login.
- The redirect URL must match **exactly** — including `https`, the domain, and the
  `?provider=...` suffix — or the provider returns a "redirect_uri mismatch" error.
