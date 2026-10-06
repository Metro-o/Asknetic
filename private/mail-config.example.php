<?php
/**
 * TEMPLATE ONLY - contains no real credentials. Do not put real values in this file.
 *
 * On the live server, copy it OUTSIDE the public web root as:
 *   /home/ACCOUNT/asknetic-private/mail-config.php
 * fill in the real values, and restrict its permissions (e.g. chmod 600 or 640).
 *
 * Any of these keys can instead be supplied as an environment variable of the same name;
 * environment variables take precedence over this file.
 */

return [
    // SMTP server of the ASKNETIC mailbox (from cPanel > Email Accounts > Connect Devices).
    'SMTP_HOST'       => 'mail.example.com',
    // 465 with 'ssl' (implicit TLS) or 587 with 'tls' (STARTTLS).
    'SMTP_PORT'       => 465,
    'SMTP_ENCRYPTION' => 'ssl',

    // Mailbox that authenticates to the SMTP server.
    'SMTP_USERNAME'   => 'mailbox@example.com',
    'SMTP_PASSWORD'   => 'CHANGE_ME',

    // From address: must be the authenticated ASKNETIC mailbox (or a domain address it may send as).
    // Defaults to SMTP_USERNAME if omitted. The visitor's address is only ever used as Reply-To.
    'SMTP_FROM_EMAIL' => 'mailbox@example.com',
    'SMTP_FROM_NAME'  => 'Asknetic Group Website',

    // Optional: extra hostnames allowed to post the form, comma-separated (e.g. a staging domain).
    // The site's own host is always allowed. Leave empty unless needed.
    'ALLOWED_ORIGINS' => '',
];
