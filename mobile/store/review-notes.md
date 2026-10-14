# Review notes (English)

Paste the "Notes for the reviewer" block into **App Store Connect > App Review Information > Notes** and into
**Play Console > App content > App access**. Fill every `TODO` first. Then use the privacy mapping below for **App
Store Connect > App Privacy** and **Play Console > Data safety**.

## Notes for the reviewer (paste this)

```
OceanMall is the customer shopping app of OceanMall, an online store that sells physical consumer electronics and
accessories in Indonesia. Everything in the app is in Indonesian.

Payment is for physical goods that are shipped to the customer, so there is no in-app purchase. The customer pays by
bank virtual account or QRIS through our payment provider (Komerce). Card or bank details are never entered in the app.

What works without an account: browsing the catalog, search, product pages and the cart.

How to reach checkout:
1. Open any product and tap "Beli Sekarang" (or add it to the cart and open the Keranjang tab).
2. Sign in with the demo account below when asked.
   Email:    TODO(devops): production demo account email
   Password: TODO(devops): production demo account password
   The account already has a saved shipping address.
3. On the checkout screen choose a courier, choose a payment method (virtual account or QRIS) and tap "Buat pesanan".
4. The order is created and appears under Akun > Belum bayar. Payment instructions (virtual account number or QR code)
   are shown there. The order cannot be paid during review, so it stays unpaid. That is expected.

Sessions: an account is active on one device at a time. Signing in on a second device signs the first one out, so
please use the demo account on one device at a time. TODO(owner): see "Open blockers" (c) in the section below and
rewrite or delete this paragraph once the demo account is handled.

Account creation: Akun > Masuk > Daftar.

User-generated content: customers who received an order can write a product review (order detail > Tulis ulasan).
Reviews are moderated before they appear (they stay hidden until a moderator approves them). There is no chat, no
messaging and no other user-to-user interaction, and a reviewer is shown by name next to the review.
TODO(dev): once open blockers (a) and (d) below are built, add one sentence each here for where account deletion is in
the app and where the per-review report button is. Do not describe either before it exists.

Contact for questions: TODO(owner): <name, email, phone>
```

## Open blockers (must be solved before submitting)

None of the four exists today. The backend has no account-deletion endpoint, no review-report endpoint and no public
web pages. Do not paste claims about them into the store forms.

- **(a) In-app account deletion. Not built.** Needs a backend endpoint plus an in-app screen. Required by Apple App
  Review 5.1.1(v) and the Google Play account-deletion policy. Owner: Backend + Mobile, in a separate follow-up session.
  A ready-made implementation lives outside the repo at
  `/Users/apriansyahrs/.claude/projects/-Users-apriansyahrs-Documents-Code-oceanmall/c82da155-7d6a-41de-b718-12e352ce569b/plan/backend-compliance/`
  (patch + tarball).
- **(b) Public URLs. Not built.** Both stores require a public privacy policy URL, and App Store Connect also requires a
  support URL and Play Data safety an account-deletion URL. Terms are expected too. None exists on the Laravel site
  (no legal, support or account-deletion pages). The draft text exists only inside the app
  (`lib/legal-content.json`). Owner: owner (text) + Backend/Devops (pages).
- **(c) One device per account vs the reviewer demo account.** `AuthController::login` signs out the user's other
  mobile sessions on purpose (one device per account), and changing the password signs out the others. A demo account
  shared by an App Review reviewer, the Play pre-launch robot or our own team will sign itself out. The owner must
  decide how to exempt a demo account (for example one demo account per store and per person, or a backend exemption).
- **(d) Per-review report / block. Not built.** Public reviews are user-generated content, so Apple App Review 1.2
  needs a way to report objectionable content (backend endpoint and moderation queue plus a button next to each
  review in the app). Reviews are already pre-moderated, which helps but does not replace it. Owner: Backend + Mobile.

## Before you submit (not part of the paste block)

These are the things that make reviewers see a "broken" app if they are skipped.

- **Shipping quote quota.** Production runs on the Komerce Starter plan: Shipping Cost Check is limited to 100 requests
  per day. A pre-launch crawler plus reviewers can exhaust it and leave checkout without couriers. Devops: warm the
  quote cache for the demo address route and weight before submitting, and watch the daily quota during review week
  (`php artisan komerce:capabilities`).
- **Demo data.** The demo account needs a saved address with a Komerce destination, and the product reviewers will
  open must be in stock with a weight set.
- **Backend reachable.** The production API must be up and answer over https for the whole review window.
- **Age rating and content questions.** The app has user-generated content: purchase-verified, pre-moderated product
  reviews, visible to every visitor. Answer the Apple "User-Generated Content" capability and the Play / IARC
  user-interaction and user-generated-content questions **Yes** (describe it as moderated reviews, and do not mention
  in-app reporting until open blocker (d) is built), then take the final age rating from the questionnaire result. Do
  not hard-code "4+" or "Everyone". Target audience: not for children. No ads.
- **Moderation is part of the release.** Reviews stay hidden until approved in `/cpanel` > Reviews. Someone must watch
  that list during review week and approve or delete promptly (Apple 1.2).
- **A support contact is a build requirement.** `metro.config.js` refuses to bundle a release build until
  `EXPO_PUBLIC_SUPPORT_EMAIL` or `EXPO_PUBLIC_SUPPORT_WHATSAPP` in `.env.production` holds a valid value. Put the same
  contact in the store listing.
- **The legal text must be approved.** `lib/legal-content.json` still has `"draft": true` and `[TODO ...]` markers
  (entity name, returns and warranty, retention). The release build fails until they are filled and `draft` is `false`
  (EAS profile `preview` is exempt). That only fixes the in-app text: the public URLs the stores need are open blocker
  (b).

## Privacy mapping

OceanMall collects the data below to run the store. None of it is used for tracking, none of it is sold, and none of it
is shared for advertising. The app contains no analytics, advertising or attribution SDK. Order data goes to service
providers only to fulfil the order: the payment provider (Komerce) and the couriers (via Komerce and RajaOngkir). That is
service-provider processing, not sharing for advertising. One item is public: an approved product review is shown to
every visitor with the reviewer's name (first and last name from the account). All traffic to the API is https (encrypted in transit).
Payment details are entered on the payment provider's own pages only, never in the app.

| Data | Why | Linked to the user | Apple App Privacy type | Play Data safety type |
| --- | --- | --- | --- | --- |
| Name | Account, shipping label | Yes | Contact Info > Name | Personal info > Name |
| Email address | Account, order and payment emails | Yes | Contact Info > Email Address | Personal info > Email address |
| Phone number | Shipping, courier contact | Yes | Contact Info > Phone Number | Personal info > Phone number |
| Shipping address | Shipping | Yes | Contact Info > Physical Address | Personal info > Address |
| Order history | Order list, tracking | Yes | Purchases > Purchase History | Financial info > Purchase history |
| Account ID and guest cart token | Keep the session and the cart | Yes | Identifiers > User ID | Personal info > User IDs |
| Product reviews (rating, title, text; shown publicly with the reviewer's name) | Product pages | Yes | User Content > Other User Content | App activity > Other user-generated content |

Purpose for every row: **App functionality** only (not analytics, not advertising, not personalization).
Collection is required for checkout and optional for browsing. Account deletion and the deletion URL that Play Data
safety asks for do not exist yet (open blockers (a) and (b)): answer those questions from what is really built at the
time you submit, not from this document.

Notes for whoever fills the forms:

- The seven Apple types above are also declared in `ios/OceanMall/PrivacyInfo.xcprivacy` (generated from `app.json`
  `ios.privacyManifests`, and edited by hand in both because prebuild is never run in place). Keep the three places (App
  Privacy answers, privacy manifest, Play Data safety) identical.
- Confirm with the backend whether search queries or product views are logged per user. If they are, add Apple
  "Search History" and "Product Interaction" (and the Play equivalents) before submitting.
- Play "Financial features" declaration: the app does not provide financial services (it sells physical goods), so
  answer "my app doesn't provide any financial features". Check this with the owner's counsel once.
- If crash reporting (Sentry, Crashlytics) is added later, update the privacy manifest, App Privacy answers, Data safety
  answers and the "no analytics SDK" sentence above in the same change.
- Export compliance: the app only uses the operating system's https, so `ITSAppUsesNonExemptEncryption` is `false`
  and App Store Connect will not ask the export question again.
