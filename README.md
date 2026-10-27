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

## Technology

The site uses semantic HTML, responsive CSS and vanilla JavaScript. No build step or third-party framework is required.
