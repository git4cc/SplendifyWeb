# Splendify Website Update

## Completed

- New About page
- Original Product & Services icons integrated
- Consultation form
- Homepage consultation CTA linked to form
- CSV lead storage
- Resend email notification integration
- Success confirmation page
- Client/server validation
- PHP tests

## Local run

From this folder, run:

```sh
php -S localhost:8000
```

Then visit [http://localhost:8000/](http://localhost:8000/).

## Required environment variables

- `RESEND_API_KEY`
- `RESEND_FROM_EMAIL`
- `LEAD_NOTIFICATION_EMAIL`

Configure these as server/environment variables. Do not place secrets in frontend code. See `.env.example` for placeholder values only.

## Storage

Submitted leads are currently appended to `data/leads.csv`. The `data/` folder must be writable by the PHP server.

## Production note

- Production hosting must support PHP.
- Outbound HTTPS must be allowed for Resend.
- `data/` must be writable and persistent if CSV storage is kept.
- Company-approved storage, retention, access, and backup policies still apply.
