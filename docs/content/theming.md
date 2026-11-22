---
title: Theming
weight: 10
lead: Change the pages people see without rebuilding the image.
---

The pages people see (sign in, account settings, "no access", errors) are Twig templates that you can
override without changing or rebuilding the SSO image. Mount a folder at `/theme`:

```
theme/
  templates/            Twig files that replace the built-in ones (any subset)
    layout.html.twig
    login.html.twig            sign-in step 1: who are you
    login_password.html.twig   sign-in step 2: the password
    login_mfa.html.twig        sign-in step 3 (when asked): the code from the authenticator app
    mfa_setup.html.twig        setting up the authenticator app (when signing in, and from the account page)
    mfa_recovery.html.twig     the recovery codes, shown once
    password_change.html.twig  choosing a new password, when an administrator requires it
    consent.html.twig          a notice to accept before signing in, one page per notice
    account.html.twig
    no_access.html.twig
    device.html.twig
    logout_confirm.html.twig
    signed_out.html.twig
    error.html.twig
  assets/               served at /theme/assets/...
    theme.css           linked automatically on every page when it exists
    logo.svg
```

A file in `theme/templates/` replaces the built-in file with the same name. Everything you leave out
keeps working as before. The admin panel is not themeable.

## Three levels of change

1. **Colours and fonts.** Put CSS in `assets/theme.css`. The built-in pages are styled with CSS variables
   (below), so overriding a few of them restyles every page.
2. **Pieces of a page.** Extend the built-in template and override blocks:

   ```twig
   {% extends '@sso/login.html.twig' %}
   {% block intro %}<p>Welcome to Acme.</p>{% endblock %}
   ```

   `@sso/<file>` always refers to the built-in file, even when your theme has a file of the same name.
3. **A whole page.** Write the template from scratch. Keep the form fields and URLs listed below.

Overriding `layout.html.twig` changes every page at once (header, logo, footer). It must extend
`@sso/layout.html.twig`, not `layout.html.twig`, or it would extend itself.

## Variables

Every page receives:

| Name | Value |
| --- | --- |
| `app_name` | `SSO_APP_NAME` |
| `csrf` | The token to send back as `_csrf` in every form |
| `user` | `null` when nobody is signed in, otherwise `name`, `username`, `email`, `is_admin` |

| Page | Extra variables |
| --- | --- |
| `login` | `error` (text, may be empty), `username` (what was typed), `identifier` (`label` and `type` of the sign-in field, set by `SSO_LOGIN_IDENTIFIER`), `social` (the providers to offer: a list of `key` and `label`, empty when none) |
| `login_password` | `error` (text, may be empty), `username` (who is signing in, as the first step understood it) |
| `login_mfa` | `error` (text, may be empty) |
| `mfa_setup` | `error`, `secret` (the key, in groups of four), `uri` (the `otpauth://` link), `qr` (the QR code for it as inline SVG to print with `|raw`, or null when it could not be drawn), `action` (where the form posts), `cancel_href` (or null), `required` (true when the policy forces it) |
| `mfa_recovery` | `codes` (list), `continue_action` (a form to post to, or null), `continue_href` (a link, or null) |
| `password_change` | `error` (text, may be empty), `username` (who is signing in), `min_length` (the shortest password allowed) |
| `consent` | `error` (text, may be empty), `consent` (`id`, `version`, `name`, `message`, `url` or null), `step` (`of`: how many notices are left, this one included) |
| `no_access` | `client_name` |
| `device` | `step` (`enter`, `confirm` or `done`), `error`, `user_code`, `client_name`, `scopes`, `approved` |
| `logout_confirm` | `client_name` (or null), `fields` (hidden values to send back: name => value) |
| `signed_out` | none |
| `error` | `title`, `message`, and `code` (the OAuth error, such as `invalid_request`) only for sign-in requests an app got wrong, such as an unregistered redirect URI. Test it with `code is defined`, because the other error pages do not have it |
| `account` | `flash` (`type`, `message`, or null), `values` (`name`, `email`), `errors` (see below), `groups` (names), `sessions`, `current_sid`, `mfa` (`enrolled`, `codes_left`, `required`), `social` (`linked`: `provider`, `label`, `email`, `created_at`, `last_login_at`; `available`: `key`, `label`; `can_unlink`) |

`account` errors: `errors.profile.name`, `errors.profile.email`, `errors.password.current_password`,
`errors.password.new_password`, `errors.password.confirm_password`. Read them with a default, for example
`errors.profile.name ?? ''`. Each entry of `sessions` has `id`, `ip`, `user_agent`, `created_at` and `last_seen`.

Templates run with `strict_variables`: using a variable that does not exist is an error.

## Blocks

| Template | Blocks |
| --- | --- |
| `layout` | `title`, `styles`, `head`, `page_name`, `impersonation`, `brand`, `logo`, `nav`, `content`, `footer` |
| `login`, `login_password`, `login_mfa`, `mfa_setup`, `password_change`, `consent` | `intro`, `after_form` |
| `mfa_recovery` | `intro` |
| `account` | `nav`, `profile`, `password`, `social`, `mfa`, `sessions` |

`impersonation` is the banner shown while an administrator tests the server as someone else ("You are signed in as ..."). It only renders when that is happening, so override it to restyle or reword the banner, and keep the form that posts to `/impersonate/stop`.

`intro` and `after_form` already hold text on some pages (for example the code step and the recovery codes). Start your block with `{{ parent() }}` to keep it and add yours, or leave `parent()` out to replace it.

## Forms

| Form | Method and URL | Fields |
| --- | --- | --- |
| Sign in, step 1 (`login`) | `POST /login` | `_csrf`, `username` (whatever the identifier setting asks for) |
| Sign in, step 2 (`login_password`) | `POST /login/password` | `_csrf`, `password` |
| Sign in, step 3 (`login_mfa`) | `POST /login/mfa` | `_csrf`, `code` (6 digits or a recovery code) |
| Set up the app (`mfa_setup`) | `POST /login/mfa/setup` or `POST /account/mfa/setup` | `_csrf`, `code` |
| After the recovery codes (`mfa_recovery`, when signing in) | `POST /login/mfa/continue` | `_csrf` |
| New recovery codes / turn off two-step | `POST /account/mfa/recovery`, `POST /account/mfa/disable` | `_csrf`, `code` |
| Choose a new password (`password_change`) | `POST /login/required` | `_csrf`, `new_password`, `confirm_password` |
| Accept a notice (`consent`) | `POST /login/required` | `_csrf`, `consent_id`, `version` (both from `consent`), `accept` (the checkbox) |
| Sign in with a provider | link to `GET /login/social/{provider}` (the `key` from `social`) | none |
| Connect or disconnect a provider | `POST /account/social/{provider}/link`, `POST /account/social/{provider}/unlink` | `_csrf` |
| Profile | `POST /account/profile` | `_csrf`, `name`, `email` |
| Password | `POST /account/password` | `_csrf`, `current_password`, `new_password`, `confirm_password` |
| Device code (`device`) | `POST /device` | `_csrf`, `user_code`, and `action` (`approve` or `deny`) on the confirm step |
| Confirm sign-out (`logout_confirm`) | `POST /end-session` | `_csrf` and every entry of `fields` |
| Sign out one session | `POST /account/sessions/{id}/end` | `_csrf` |
| Sign out all others | `POST /account/sessions/end-others` | `_csrf` |

Signing out of this browser is a link to `GET /logout`.

A `login.html.twig` that replaces the built-in page instead of extending it only shows provider buttons if it
loops over `social` and links to `/login/social/{key}`.

A theme written for the earlier one-step sign-in page, with `username` and `password` in one form posting to
`POST /login`, still works: when `password` is sent along, the sign-in finishes in that one request. New themes
should use the two steps, which is what lets the server choose the next step per person.

## Helpers

- `asset('pages.css')`: a built-in file from `public/assets`, with a cache-busting query string.
- `theme_asset('logo.svg')`: a file from your `assets/` folder.
- `has_theme_asset('theme.css')`: whether that file exists.
- `ago(timestamp)`, `device(user_agent)`, `hue(name)`, `initials(name)`.

## CSS variables

Defined in `pages.css` on `:root`. Dark mode redefines the colours under `prefers-color-scheme: dark`,
so override them in both places if you want both modes.

`--bg`, `--surface`, `--text`, `--muted`, `--line`, `--tint`, `--primary`, `--primary-strong`,
`--primary-text`, `--danger`, `--danger-tint`, `--ok`, `--ok-tint`, `--radius`, `--radius-lg`,
`--font-display`, `--font-body`.

## Static files

`/theme/assets/` serves css, js, svg, png, jpg, gif, webp, ico, woff, woff2, ttf and otf, and nothing
outside that folder. SVGs are served with a policy that blocks scripts when they are opened directly.

## When a template is broken

Normally the built-in page is shown instead, and the error goes to the server log
(`SSO theme error in login: ...`), so a typo never locks everyone out. Set `SSO_DEBUG=1` while
developing to see the Twig error in the browser instead.

Theme templates run on the server. Only mount themes you trust.
