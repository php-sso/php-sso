---
title: "Security headers"
weight: 1
lead: "What the browser is told about every page."
---

Every response the server makes (the pages, the OAuth and SCIM endpoints, the errors and `/healthz`) carries these headers. A header that
the response sets itself is left as it is.

| Header | Value | What it stops |
| --- | --- | --- |
| `Content-Security-Policy` | the [default policy](#the-default-policy), or `SSO_CSP` | Running a script or loading a resource from somewhere that is not this server, and framing the pages |
| `X-Frame-Options` | `DENY` | The same framing, for browsers that do not know `frame-ancestors` |
| `X-Content-Type-Options` | `nosniff` | A browser treating a response as a different type than the one it was sent as |
| `Referrer-Policy` | `same-origin` | Sending the address of a page (which can hold a `state` or a code) to another site |
| `Cache-Control` | `no-store` | Keeping a page that shows a person's data in a browser or proxy cache |
| `Strict-Transport-Security` | `max-age=31536000` | Opening the site over plain HTTP again. Only sent when `SSO_PUBLIC_URL` is an `https://` address |

`Cache-Control` is only added when the response did not choose one: the discovery document and the signing keys (`/jwks`) stay cacheable,
so apps do not fetch them on every request.

The stylesheet, script and font files in `public/assets` are served by your web server, not by PHP, so they do not carry these headers.
They are not documents a browser would frame or run as a page. Add the headers in the web server too if you want them there.

`Strict-Transport-Security` is sent without `includeSubDomains` and `preload`, because those affect every site on the domain, which is for you to decide.
Add them at your reverse proxy if you want them.

## The default policy

```
default-src 'self'; script-src 'self'; style-src 'self'; style-src-attr 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; object-src 'none'
```

- `default-src 'self'` and `script-src 'self'`: scripts, fonts, requests made by scripts and everything not named below may only come from the
  server itself. The pages contain no inline script, so an injected one does not run.
- `style-src 'self'` and `style-src-attr 'unsafe-inline'`: stylesheets only from the server, but `style="..."` attributes are allowed, because the admin
  panel colours its avatars and chips with them. A `<style>` element is not.
- `img-src 'self' data:`: images from the server, and `data:` for the QR code of the two-step setup.
- `frame-ancestors 'none'`, `base-uri 'none'` and `object-src 'none'`: no page may be framed by another site, and neither a `<base>` element nor a plug-in
  can be used to redirect where relative addresses or content come from.
- There is no `form-action`. A browser applies it to the redirect after a form is posted, so `form-action 'self'` would stop the sign-in form from sending
  people on to the app they came from.

## Changing the policy for a theme

A [theme]({{< relref "theming.md" >}}) that loads something from another server (a font from a font service, an image from a CDN) is blocked by the default.
Set `SSO_CSP` to the **whole** policy you want, not an addition to the default. Start from the default above and widen only the directive you need:

```bash
SSO_CSP="default-src 'self'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; style-src-attr 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; object-src 'none'"
```

Keep `script-src 'self'`, `frame-ancestors 'none'` and `object-src 'none'` unless you have a reason: they are what protect the sign-in and admin pages. Your
browser's developer console reports every resource a policy blocks, with the directive that blocked it.
