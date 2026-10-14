# ADR 0012: Digital tap profiles

Status: accepted

## Context

A smart NFC card is only as useful as the page it opens. Without one, buyers had to find their own link-in-bio service, and the store had no reason for a card's taps to come back to it. The owner asked for "futuristic features" without new paid services.

## Decision

- Each user with a verified email can have one tap profile at `/p/{handle}`. It holds only what they type in (name, title, company, bio, contact buttons, social links, theme). Nothing is copied from their account or orders, and it is hidden until published.
- Handles are 3-30 lowercase letters, numbers and single hyphens, unique, with a reserved list (`admin`, `shop`, `esewa` and similar) so a profile cannot pass for the store.
- Links are restricted: the website must be http or https, and social values are a username or an `https://` link, so no `javascript:` or other schemes reach the page.
- "Save contact" serves a vCard 3.0 from `/p/{handle}/contact.vcf`, without a session cookie. Text is escaped per RFC 2426, so a value cannot start a new property.
- Counting: each profile view (API fetch) is a tap, and each vCard download is a save. A device counts once per half hour, using a keyed hash of IP and user agent held only in the cache. Known bots are not counted. Daily rows (`card_profile_daily_stats`) are kept by business-time-zone day. Nothing identifies visitors.
- An approved affiliate's profile shows "Want a card like this?" linking to `/ref/{their code}`, so every tap can become a credited referral. It falls back to `/shop` as soon as they are no longer eligible to earn. Normal referral rules apply (ADR 0007).
- QR codes are drawn in the browser (the `qrcode` package), so profile links are not sent to a QR service. Writing a tag uses Web NFC, which only Chrome on Android supports; other devices see a hint instead of the button.
- A profile page is a 404 when it is unpublished or the owner's account is disabled.

## Consequences

- Changing a handle breaks links already written to cards. The editor warns about this; redirects from old handles are not kept.
- Profiles are public by design. Owners choose what to show, and the editor says the contact buttons are public.
- Rate limits follow the store's (60 a minute per IP).
