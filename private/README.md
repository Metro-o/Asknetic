# Contact form backend: setup and testing

The Contact form (`contact.html`, `#contactForm`) posts with `fetch()` to `/api/contact.php`.
That script sends the enquiry through authenticated SMTP (PHPMailer) to **<info@askneticgroup.co.tz>**
and replies with JSON. The recipient is fixed in `api/contact.php`; visitors cannot change it.

This `private/` folder is a template for the server. **Do not upload it to the public web root.**

## Server layout (cPanel / shared hosting with PHP 7.4+)

```text
/home/ACCOUNT/
├── asknetic-private/            <- NOT web-accessible
│   ├── mail-config.php          <- real SMTP settings (copied from mail-config.example.php)
│   └── vendor/  or  PHPMailer/  <- PHPMailer (see below)
└── public_html/                 <- web root (the website files)
    ├── contact.html
    └── api/contact.php
```

`api/contact.php` finds this folder at `../../asknetic-private` relative to itself. If the site lives
somewhere else, set the environment variable `ASKNETIC_PRIVATE_DIR` to the folder's absolute path
(for example `SetEnv ASKNETIC_PRIVATE_DIR /home/ACCOUNT/asknetic-private` in `.htaccess`, if the host allows it).

The site must be served from the domain root, because the form posts to the absolute path `/api/contact.php`.

## 1. Install PHPMailer (choose one)

**Composer (preferred)**, over SSH or cPanel Terminal:

```text
cd ~/asknetic-private
composer require phpmailer/phpmailer
```

This creates `asknetic-private/vendor/autoload.php`, which the endpoint loads automatically.

**Manual upload**: download a PHPMailer release (6.x or 7.x) from <https://github.com/PHPMailer/PHPMailer/releases>
and upload its `src/` folder so that these files exist:

```text
asknetic-private/PHPMailer/src/Exception.php
asknetic-private/PHPMailer/src/PHPMailer.php
asknetic-private/PHPMailer/src/SMTP.php
```

## 2. Configure SMTP

Copy `mail-config.example.php` to `asknetic-private/mail-config.php`, fill in the real values, and
restrict its permissions (e.g. `chmod 600`). Expected keys:

| Key               | Example                       | Notes                                          |
|-------------------|-------------------------------|------------------------------------------------|
| `SMTP_HOST`       | `mail.askneticgroup.co.tz`    | From cPanel > Email Accounts > Connect Devices |
| `SMTP_PORT`       | `465` or `587`                |                                                |
| `SMTP_ENCRYPTION` | `ssl` (465) or `tls` (587)    | Unencrypted SMTP is not accepted               |
| `SMTP_USERNAME`   | the full mailbox address      |                                                |
| `SMTP_PASSWORD`   | the mailbox password          | Never place it in the web root or in Git       |
| `SMTP_FROM_EMAIL` | the authenticated mailbox     | Optional, defaults to `SMTP_USERNAME`          |
| `SMTP_FROM_NAME`  | `Asknetic Group Website`      | Optional                                       |
| `ALLOWED_ORIGINS` | `staging.example.com`         | Optional extra hosts allowed to post the form  |

Alternatively, set any of these as environment variables. Environment variables override the file.

The email is sent **From** the authenticated mailbox and uses the visitor's address only as **Reply-To**,
so SPF/DKIM/DMARC pass. Make sure SPF and DKIM are enabled for the domain (cPanel > Email Deliverability).

## 3. Test on the real server

1. Submit the form on `contact.html` and check that the email arrives at <info@askneticgroup.co.tz>,
   and that pressing Reply in the mail client addresses the visitor.
2. If you see the orange error message, check the PHP error log (cPanel > Errors, or `error_log` in
   `public_html/api/`). Lines are prefixed with `[asknetic contact]` and say whether the config,
   PHPMailer or the SMTP login failed. Visitors never see these details. If the host writes an
   `error_log` file inside `public_html/api/`, delete it after debugging (or point PHP's `error_log`
   setting outside the web root), since files in the web root can be downloaded.
3. Quick checks from a terminal (replace the domain):
   - `curl -i https://DOMAIN/api/contact.php` should return `405` with JSON.
   - `curl -i -X POST -d "name=&email=x&message=" https://DOMAIN/api/contact.php` should return `422`.

## Netlify (temporary static preview only)

Netlify does not run PHP. On Netlify you can test the layout, responsiveness, navigation,
accessibility and the form's client-side validation and sending state. A real submission there will
always show the orange error message, because `/api/contact.php` cannot run. Actual delivery has to be
tested on a PHP server (staging/live cPanel, or locally with `php -S localhost:8000` from the site root
plus a reachable SMTP account).

## How the endpoint protects itself

- POST only (`405` otherwise); always JSON with `Cache-Control: no-store`.
- Same-origin check: requests whose `Origin`/`Referer` belongs to another website get `403`.
  Requests with neither header are allowed, because the remaining checks still apply.
- Honeypot `bot_field`: if it is filled in, the bot gets `{"success": true}` and no email is sent.
- Server-side validation: trimmed, non-empty, valid UTF-8, length limits (name 100, email 254,
  message 5000 characters), `filter_var()` email check, no line breaks/control characters in name or email
  (header injection), control characters stripped from the message.
- All visitor content is HTML-escaped in the HTML body; a plain-text alternative is included.
- Fixed recipient, fixed From; SMTP errors and exceptions are written to the server log only and the
  visitor only gets a generic message.
