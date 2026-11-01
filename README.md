# CoderVex Landing Page

A responsive, SEO-focused static website for CoderVex website development and technical support services.

## Pages

- `index.html` — Main service landing page
- `contact.html` — General contact form
- `quote.html` — Detailed project quote form

## Local preview

Place the project in your web server directory and open:

```text
http://localhost/codervex_landing/
```

Alternatively, run any static HTTP server from the project root.

## Form behavior

The contact and quote forms validate visitor input and submit it asynchronously to `send-form.php`, which sends a structured email to `support@codervex.com`. No form data is stored by the website.

### Required form configuration

Configure these values in the web server/PHP process environment (the project does not load `.env` files):

- `TURNSTILE_SITE_KEY`: public Cloudflare Turnstile site key.
- `TURNSTILE_SECRET_KEY`: private Turnstile secret; never put it in HTML, JavaScript, or source control.
- `TURNSTILE_EXPECTED_HOSTNAME`: exact hostname configured in Turnstile, such as `www.codervex.com` (use `localhost` in development).
- `TRUSTED_PROXIES`: optional comma-separated IP addresses of reverse proxies whose `CF-Connecting-IP` or `X-Forwarded-For` header may be trusted. Leave blank for direct hosting.
- `FORM_RATE_LIMIT_DIR`: optional writable private directory for rate-limit records. It defaults to the operating-system temporary directory. For production, use a persistent directory outside the public web root.

After setting environment values, restart Apache/PHP. In XAMPP development, set the variables with Apache `SetEnv` directives in a local virtual-host configuration, then restart Apache. Do not commit real keys. Turnstile must allow the development and production hostnames.

The endpoint uses PHP sessions for same-origin CSRF tokens and filesystem records (with file locks) for the limit of three attempts per IP in ten minutes. Rejection logs contain only a reason code and form type.

## Technology

The site uses semantic HTML, responsive CSS and vanilla JavaScript. No build step or third-party framework is required.
