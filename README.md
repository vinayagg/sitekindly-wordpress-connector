# SiteKindly WordPress connector

Small website changes, reviewed before publication.

This is the open-source WordPress connector used by [SiteKindly](https://sitekindly.appcafe.com). The PHP source is identical to the connector already available from the SiteKindly website. This repository contains the plugin, its license and documentation.

[Try the free website check](https://sitekindly.appcafe.com/scan) · [See a fictional repair-review example](https://sitekindly.appcafe.com/example#repair-preview) · [Current service pricing](https://sitekindly.appcafe.com/pricing)

## Install

1. Download `sitekindly-connector.zip` from [release 0.4.0](https://github.com/vinayagg/sitekindly-wordpress-connector/releases/tag/v0.4.0). Use this installable ZIP rather than GitHub's source-code archive.
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, upload the ZIP, install it and activate it.
3. Follow your private SiteKindly website setup to verify ownership and connect with a dedicated WordPress Application Password. You can connect for read-only compatibility review before payment.
4. Agree on a supported repair scope before checkout. The SiteKindly service requires approval of the exact prepared change before publishing it.

The plugin is free under GPL-2.0-or-later. SiteKindly's repair and care services are separate paid services. Installing the plugin does not start a subscription or authorize a repair.

## What the connector does

- Reads requested title/content fields from published pages and supported image alternative-text fields, with WordPress permission checks.
- Checks expected SHA-256 field hashes before applying up to three changes in a request. Transactional storage is required for writes.
- Supports a bounded missing form-field label change in editable page content.
- Renders current/proposed page content through the installed WordPress theme using an expiring overlay. Previewing leaves the saved page title and content unchanged.
- Reads and changes the main From-address setting of a compatible Contact Form 7 form, bound to the selected public page and the unchanged recipient/settings fingerprint.
- Prepares one-use real-form test links without submitting a form or sending mail. The owner submits normally; a confirmation code is added only to the main notification, never the visitor auto reply.
- Makes preview documents inert and disables scripts, forms and frames. Preview links expire after at most 15 minutes and become invalid when the source or relevant editing permissions change.

The plugin provides low-level read, preview and write operations. Payment, agreed scope, owner approval, recovery records and post-publication checks are managed by the SiteKindly service; the plugin alone is not that workflow.

## Compatibility and limits

The plugin header requires **WordPress 6.6+ and PHP 8.1+**. HTTPS and the relevant WordPress editing permissions are required. Writes require transactional InnoDB storage for the posts and post-meta tables.

Compatibility must be checked for the particular theme and host. Page-builder fields stored outside normal page content, plugin-generated form fields outside the separate CF7 sender integration, redesigns, malware cleanup, booking systems and checkout behavior are outside the current service package. The inert page-layout preview does not test interactive behavior or form-message delivery. A CF7 delivery test is a separate, normal form page with its scripts and consent/anti-spam checks intact. Image alternative-text changes are reviewed as text.

CF7 must satisfy its own WordPress/PHP requirements and expose the APIs checked by this connector. The service checks actual native/public compatibility before offering checkout. Initial sender scopes support one fixed main recipient (including the site-admin tag), no Cc/Bcc, and no demo, mail-disabled or subscriber-only mode. SMTP/DNS settings, recipients and templates are unchanged. The replacement is one plain owner-confirmed address on the website domain. Delivery remains pending until the owner enters the main-notification code and the service rechecks settings and agreed public results. A form success message alone is not receipt proof.

Test links expire within 15 minutes and allow one marked send after normal validation. Correcting validation/consent/spam errors does not consume the send. The private native test context is stored in non-autoloaded options; scheduled cleanup depends on WordPress cron and this connector running. Expiry is enforced even if cleanup is delayed. The service stores a verification hash, not inbox credentials or the submitted message.

Keep your own website backups. A database transaction is not a full-site backup and cannot undo every side effect of third-party WordPress hooks or integrations. The public example uses fictional test data, not a customer result.

## Access and revocation

Connection credentials permit editing. Use a separate WordPress user with only the capabilities you need and a dedicated Application Password. A separate Application Password is revocable; do not assume it limits access to only this plugin's routes. WordPress account permissions still matter. See [WordPress's Application Password documentation](https://developer.wordpress.org/advanced-administration/security/application-passwords/).

Revoke the dedicated Application Password in the WordPress user profile to withdraw that credential. Deactivating the plugin removes its routes and preview/test handlers. Previously issued short-lived links are separate from the Application Password; they expire or invalidate on the relevant source/permission change. Neither credential revocation nor deactivation is a promise that retained private test options are immediately erased. Neither action automatically reverses previously published content changes.

Keep passwords, application credentials, private preview URLs and customer content out of public issues. Use [SiteKindly support](https://sitekindly.appcafe.com/contact) for private reports.

## Source layout

`appcafe-connector/appcafe-connector.php` is the main plugin; `appcafe-connector/contact-form7.php` contains its optional CF7 integration. Its existing folder name, function prefix and `appcafe/v1` REST namespace are retained for installed-site compatibility. The displayed product name is SiteKindly.

| Route | Purpose |
| --- | --- |
| `GET /wp-json/appcafe/v1/status` | Version and supported capabilities |
| `POST /wp-json/appcafe/v1/snapshot` | Requested field values and hashes |
| `POST /wp-json/appcafe/v1/label-preview` | Bounded field-label transformation |
| `POST /wp-json/appcafe/v1/render-preview` | Expiring current/proposed theme views |
| `POST /wp-json/appcafe/v1/patch` | Checked field changes |
| `POST /wp-json/appcafe/v1/form-info` | Bounded CF7 sender and compatibility fingerprint |
| `POST /wp-json/appcafe/v1/form-test` | Fixed-expiry one-use test context; no submission or mail |

These routes require authenticated HTTPS requests and WordPress editing permissions. Preview and delivery-test view links use expiring bearer tokens; treat them as private. Form settings additionally require CF7 editing rights and editing rights for the selected public page.

Release ZIP SHA-256:

```text
5b9397bef216e6aaa2cd47af7c06d6681ed856f5c39db50aade36c2697ddd477
```

## License and affiliation

[GPL-2.0-or-later](LICENSE), matching the existing plugin header. Maintained for SiteKindly, operated by FunCove LLC. This source release was prepared by the owner's authorized AI assistant. It is not an official WordPress.org directory listing or an endorsement by WordPress.
