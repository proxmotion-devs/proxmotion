# 53. Branding

- **Status:** Accepted
- **Date:** 2026-11-03
- **Revised 2026-11-04:** the colour no longer sets `--ring`. With a pale or yellow colour (e.g. `#f5c400`, 1.6:1 on white) the components' 3px focus ring at 50% fell to about 1.3:1 and keyboard focus was effectively invisible (WCAG 2.2 1.4.11, 2.4.7). The ring keeps the neutral colours of `app.css` (near-black in light mode, light grey in dark), which stay at least 3.7:1 against the page at 50% for any brand colour. The form also warns when the colour looks like the amber of warnings or the red of errors and deleting (`resemblesStatus()` in `lib/brandColor.ts`): primary buttons in those hues read as a status.

## Context

Proxmotion is run by hosting providers under their own name. The panel showed `APP_NAME` from `.env`, a name fixed into the JavaScript bundle at build time (`VITE_APP_NAME`), the Proxmotion mark and favicons from `public/`, and the black and white starter-kit theme. A provider could change none of it without editing code or rebuilding, and nothing company-specific may be built in (AGENTS.md §1, §6).

Most people who see the panel are its administrators. Customers see one page of it: the browser console opened from the billing system (ADR 0007, 0008). That page carried no name at all, and a customer whose console link had expired was told to go back to the client area with no way to reach the provider.

## Decision

1. **A *Branding* page on *Panel settings*** (ADR 0038, category `branding`) with:
   - the panel's name (`brand_name`, at most 60 characters; empty: `APP_NAME`);
   - a colour (`brand_color`, `#rrggbb`; empty: the default theme);
   - a support email and a support page (`support_email`, `support_url` over HTTPS) for customers.

   Each has a default in `config/proxmotion.php` from `.env` (`PROXMOTION_BRAND_NAME`, `PROXMOTION_BRAND_COLOR`, `PROXMOTION_SUPPORT_EMAIL`, `PROXMOTION_SUPPORT_URL`), like every other panel setting, so images and installers can set them before the first sign-in. Defaults are checked as the form checks its values: a colour in any case is used in lowercase, and a support address or a page not over HTTPS is left out rather than linked. Saving the page is audited as `settings.updated`.
2. **Uploaded images:** a logo, an optional logo for dark mode, and a favicon, uploaded on the same page at once (not with its form), audited as `branding.image_uploaded` / `branding.image_removed`.
   - **PNG, JPEG or WebP only**, checked from the file's content, not its name. **No SVG:** an SVG is a document that can carry scripts, and making uploaded SVG safe means sanitising it, a parser we would have to keep up with. A raster logo of 256 pixels looks sharp at the sizes the panel shows it.
   - Logos up to 1 MB and 2048 pixels; the favicon square, 32 to 512 pixels, up to 256 KB.
   - **Metadata is removed** before storing (`AppSupportImagesImageMetadata`): a logo exported from a photo could otherwise publish its camera, author or the place it was taken. Whole blocks are dropped without decoding the image: PNG text, time and `eXIf` chunks, JPEG APP1 (EXIF, XMP), APP13 (IPTC) and comments, WebP `EXIF` and `XMP ` chunks (with the RIFF size and VP8X flags fixed). Colour profiles stay. A file whose structure cannot be followed is refused. Images are not re-encoded, which would add the `gd` extension to the requirements (ADR 0052 §2); the response headers below make a file that only claims to be an image harmless.
   - Stored on the `local` disk under `storage/app/private/branding/` with a random 40-character name; the setting (`brand_logo`, `brand_logo_dark`, `brand_favicon`) holds the name, and the previous file is deleted when it is replaced or removed, under a cache lock so two uploads at once never leave a file nobody refers to. A file that could not be written keeps the current image and says so next to it. These three settings are only saved by uploading (`PanelSettings::UPLOADS`).
   - Served by `GET /branding/{file}` to everyone, since the sign-in pages and the customer console need them: only a file that is one of the three in use, with its type from the extension the panel chose, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'` and a year's `immutable` caching (a new upload is a new address). The route is outside the `web` middleware group: a publicly cached response must never carry a session or XSRF cookie, and an image needs none of the page middleware. No `storage:link`, so nothing under `public/` depends on the upload directory; deployments keep `storage/` on a volume anyway.
3. **Where it shows:**
   - Every Inertia page gets a shared `branding` prop (`Branding::forPages()`), and `name` is the brand name. Page titles read the name from the page instead of `VITE_APP_NAME`.
   - The menu, the header and the sign-in pages show the uploaded logo (`BrandMark`, the dark one in dark mode) or the Proxmotion mark.
   - The root view links the favicon (instead of `/favicon.ico`, `/favicon.svg` and `/apple-touch-icon.png`) and, with a colour, sets `--primary`, `--sidebar-primary` and `--ring` for light and dark mode, with black or white text on it, whichever contrasts more (WCAG 2 luminance, the same formula on the server and in the browser).
   - The customer console shows the logo and name, a *Support* button, and a support link under its errors, e.g. an expired link. The support page wins over the email when both are set and opens in a new tab; an email opens the mail client. Administrators opening a console from the panel get neither: the contacts are for customers.
   - Mail: the subject of notifications and the header, footer and greeting of Laravel's mail views (copied to `resources/views/vendor/` with `Branding::name()` in place of `config('app.name')`), which also covers password reset mail. `BrandedMailViewsTest` fails when a Laravel update changes the originals, so the copies are renewed instead of drifting.
4. **The colour is checked on the page, not refused:** the form previews a button in light and dark mode and warns when the colour's contrast with a page background is below 3:1 (WCAG 2's minimum for interface parts), since such buttons are hard to see.

## Consequences

- A provider brands the panel without a rebuild or an `.env` change, and a fresh install still looks like Proxmotion.
- `VITE_APP_NAME` no longer decides anything; it can stay in `.env`.
- Logos are not shown in mail: they would need absolute addresses mail clients fetch from the panel, and many block remote images.
- Every page reads the settings from the cache (`PanelSettings`), as before; uploads add one file read per image request, cached by browsers for a year.
- Not done: a custom stylesheet, colours beyond the primary one, branding per billing system, and a footer text.
- Tested with the test suite's SQLite and a fake disk only.
