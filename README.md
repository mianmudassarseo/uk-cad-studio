# UKCADStudio — Website

Responsive multi-page site built with semantic HTML, CSS and vanilla JavaScript.
37 pages, no build step, no dependencies. Upload and it runs.

---

## Before you go live — 3 required steps

### 1. Connect the contact form (5 minutes) — REQUIRED

The form is fully built and tested, but email sending is **off by default**.
Open `send-mail.php` and look at the block near the top.

Right now `SMTP_ENABLED` is `false`, so it falls back to PHP's `mail()`.
That usually works, but Gmail frequently sends `mail()` messages to spam.
**For reliable delivery, use SMTP:**

1. Turn on 2-Step Verification: https://myaccount.google.com/security
2. Create an App Password: https://myaccount.google.com/apppasswords
3. In `send-mail.php` set:
   ```php
   const SMTP_ENABLED = true;
   const SMTP_PASS    = 'your-16-character-app-password';
   ```
4. Send yourself a test enquiry from `contact.html`.

Your normal Gmail password will **not** work — it must be an App Password.

Never commit real credentials to a public Git repository.

### 2. Replace the illustrations with real photography — RECOMMENDED

All imagery in `assets/` is currently **architectural SVG illustration**, not
photography. It is clean, on-brand and very lightweight (240KB for the whole
site), but real project photos will convert better.

To swap: replace the file, keep the filename. Nothing else changes.

| File | Used on |
|---|---|
| `project-1..4.svg` | Homepage featured projects |
| `illus-*.svg` | Service pages + blog headers |
| `loc-street-1..4.svg` | Location page heroes |
| `compare-before.svg` / `compare-after.svg` | Homepage before/after slider |

If you switch to JPG/WebP, update the `src` and keep the `width`/`height`
attributes — they prevent layout shift and help page speed scores.

### 3. Fill in your legal details — REQUIRED

`privacy-policy.html` and `terms.html` each end with a note marked
**"Note for the site owner"**. Add your registered business name, trading
address and company number, then delete those notes.

---

## Also worth doing

- **Update the domain.** Everything currently points at
  `https://www.ukcadstudio.co.uk`. If that changes, find and replace it across
  all `.html` files plus `sitemap.xml` and `robots.txt`.
- **Add your real review data.** The homepage rating card and two testimonials
  use representative UK client names. Replace with verified reviews before
  claiming a star rating publicly — fake review markup breaches Google's
  guidelines and can trigger a manual penalty.
- **Submit `sitemap.xml`** in Google Search Console.
- **Add analytics** and a cookie banner if you introduce any tracking. The site
  currently sets no cookies, which is why no banner is included.
- **Consider a UK phone number.** The site uses `+92 305 6205832` as supplied.
  UK customers searching locally often hesitate at a non-UK number; a UK
  virtual landline forwarding to the same handset is inexpensive.

---

## Structure

```
index.html              Homepage
about.html              About the studio
contact.html            Contact + enquiry form
thank-you.html          Post-submission page (no-JS fallback)
privacy-policy.html     Privacy notice (UK GDPR)
terms.html              Terms of use
404.html                Not found page

styles.css              Original stylesheet + appended multi-page components
script.js               Nav, dropdowns, reveals, slider, form validation
send-mail.php           Form handler with built-in SMTP client

sitemap.xml             All 33 indexable URLs
robots.txt              Crawl rules
.htaccess               Compression, caching, security headers (Apache)

assets/                 Logo variants, favicons, OG image, 26 illustrations

services/               index + 9 service pages
locations/              index + 12 location pages
blog/                   index + 6 articles
```

Links are **relative**, so the site works from a domain root, a subfolder, or
opened directly from disk. The only exception is `send-mail.php`, which needs
PHP — so the form requires real hosting to work.

---

## What was changed from the original

- **Logo** replaced throughout. Background removed, trimmed, and exported as a
  dark version for the header, a white version for the navy footer, favicons
  and a 1200×630 social share image.
- **Contact details** updated to `raotipusultaan@gmail.com` and
  `+92 305 6205832` on all 37 pages, including `tel:` and `mailto:` links and
  structured data.
- **Navigation** gained Services and Locations dropdowns (needed for 30+ pages).
  Uses the existing nav styling; collapses to an accordion on mobile.
- **Client names** — "Sample Client Name" replaced with UK names, and the
  placeholder review copy rewritten.
- **Forms** now validate inline, submit by fetch without a page reload, and
  degrade to a normal POST if JavaScript is unavailable.
- **SEO** — unique title and meta description per page, canonicals, Open Graph
  and Twitter cards, breadcrumbs, and JSON-LD for ProfessionalService, Service,
  FAQPage, BlogPosting, BreadcrumbList and ItemList.

### Bugs fixed along the way

- Unescaped `&` in the Google Fonts URL (present in the original) broke HTML
  validation on every page.
- `mb_strlen()` was called unguarded in PHP — a fatal error on shared hosts
  without the mbstring extension.
- An empty `consent` value passed server-side validation.
- The before/after slider handle did not follow the slider position.

### Verification

37/37 pages pass strict HTML5 parsing · 2,812 internal links and anchors all
resolve · no JavaScript errors · every image has alt text and explicit
dimensions · one `<h1>` per page with no heading-level skips · form handler
tested against empty input, malformed email, missing consent, honeypot, timing
trap, rate limiting and mail-header injection.

---

## A note on the planning content

The service, location and blog pages contain detailed UK planning and building
regulations guidance — permitted development limits, HMO room sizes and
licensing, Article 4 directions, Land Registry plan requirements.

This is accurate to the best of current knowledge, but **planning rules change
and vary between councils**, particularly Article 4 boundaries and local HMO
amenity standards. The copy is deliberately written with "confirm the current
position for your address" framing rather than as guarantees. Review it against
your own experience before publishing, and revisit it periodically.

## Run locally

Open `index.html` directly, or serve the folder:

```bash
python3 -m http.server 8000
```

The contact form will not send from `file://` or a plain static server — it
needs PHP. To test it locally: `php -S localhost:8000`
