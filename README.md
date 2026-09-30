# Setlo

**Receipt-Scanned Itemized Splitting with Verified Settlement**

Setlo helps a group of friends split a shared food bill by scanning the restaurant receipt instead of manually typing out every item, and only marks a payment as settled once the receiver actually confirms receiving it.

---

## 1. Problem Statement

Splitting a restaurant bill among friends usually happens one of two ways: everyone pays roughly the same amount regardless of what they actually ordered ("just split it evenly"), or someone manually types out every item and price from the receipt into a calculator or spreadsheet to do it fairly. The second approach is accurate but tedious — and even after the math is done, there's still no way to confirm that a payment claimed as "sent" was actually received.

This creates two distinct, related problems:
- Itemized splitting is accurate but requires slow, error-prone manual data entry from a physical receipt.
- Once balances are known, a sender's "I paid" claim is treated as final, with no confirmation step from the receiver.

## 2. Innovation Statement

> Existing expense splitters require users to manually type each item and price from a receipt, and even when they calculate balances correctly, they treat a sender's payment claim as final without confirmation. Setlo scans a restaurant receipt to auto-extract itemized costs for splitting, and requires the receiver to confirm each payment before it's marked settled — removing manual entry error at the split stage and trust ambiguity at the payment stage.

**Two distinct, reinforcing innovation categories:**

1. **Automation (receipt OCR → itemized split):** the user photographs a receipt, Setlo extracts item names and prices automatically, and items are assigned to members with a tap instead of manual typing.
2. **Error prevention / trust (two-sided settlement confirmation):** a payment only becomes "Settled" after the sender marks it paid *and* the receiver confirms — with a visible timestamp for each step.

**Measurable improvement over the manual process:**
- Manual item entry (typing each line item and price) vs. OCR-assisted entry (scan → review/correct) — fewer steps, less typing, fewer transcription errors.
- No more assumed payments — every settled balance is backed by an explicit receiver confirmation, not just a status flip.

## 3. Target User

**Small groups splitting a shared meal or food bill** — friends eating out together, an org's food order, a group merienda run — where one person pays the restaurant/vendor up front and the rest need to pay them back for exactly what they ordered.

## 4. Scope

### In Scope
- User registration/login
- Create a **Bill** (a single shared food expense event) and add members
- **Receipt photo upload with OCR extraction** of item names and prices
- **Review/edit screen** for OCR results — user corrects any misread item or price before saving (fallback for OCR errors)
- Assign extracted items to the members who ordered them (itemized split)
- Equal split as a secondary option (for shared items like a communal appetizer, tax, or service charge)
- Automatic per-member balance calculation
- Settlement generation (who pays whom)
- Two-sided payment confirmation flow (Pending → Awaiting Confirmation → Settled)
- Basic dispute/reject path
- Bill history and per-bill breakdown
- Dashboard (you owe / you're owed / pending count)
- Lightweight in-app notifications panel

### Out of Scope (explicitly, for this build)
- Multi-day trips, transportation, or accommodation expenses — food bills only
- Recurring/ongoing group ledgers (each Bill is a single, closeable event)
- Actual money transfer / GCash or bank integration
- Automated reminders or timeouts for unconfirmed payments (acknowledged limitation)
- Multi-language or non-English/Filipino receipt OCR
- Handling severely damaged, faded, or handwritten receipts — flagged for manual entry instead

## 5. User Roles

One account type — **registered user** — with a situational permission:

| Role | Description |
|---|---|
| **Bill Creator** | The user who created the Bill and uploaded the receipt. Can invite members and edit item assignments. Not automatically the settlement receiver. |
| **Bill Member** | Any user added to a Bill. Can view their assigned items, balances, mark payments as paid, and confirm payments received. |

Role is never fixed by who created the Bill — it's determined by who paid the vendor and who owes what, per the actual item assignments.

## 6. Core Workflow

1. User registers/logs in.
2. User creates a Bill and adds members.
3. User photographs the receipt; Setlo runs OCR and extracts item names + prices.
4. User reviews the extracted list, correcting any misread items before saving.
5. Items are assigned to the members who ordered them (itemized), with shared items (tax, service charge) split equally.
6. Setlo calculates each member's share and net balance.
7. Setlo generates a settlement plan (who pays whom).
8. Sender pays outside the app and marks the settlement as **Paid**.
9. Receiver is notified and either **Confirms** (→ Settled) or **Rejects/Reports** (→ Disputed).
10. Bill closes once all settlements are Settled.

## 7. Page List

| Page | Notes |
|---|---|
| Register / Login | Auth |
| Dashboard | Owe/owed summary, my bills, recent activity |
| My Bills | List, tagged Active / Settling / Closed |
| Create Bill *(modal)* | Name, members |
| Scan Receipt | Camera/upload capture |
| Review Extracted Items | Editable table: item, price, quantity — corrects OCR errors before saving |
| Assign Items | Tap-to-assign each item to member(s); shared items split equally |
| Bill Detail | Members, balances, settlement plan, bill status |
| Bill History | Past bills list |
| Bill Breakdown | Full itemized detail for one bill |
| My Settlements | You owe / you're owed, with Mark Paid / Confirm / Reject actions |
| Settlement Audit Trail | Timestamp for "marked paid" vs. "confirmed" per settlement |
| Notifications | Dropdown panel, not a full page |

## 8. Key Design Decisions

- **OCR is assistive, not authoritative.** Every scan result goes through a human review/edit step before it's saved — this removes OCR accuracy as a live-demo failure point, since a misread item is just corrected, not a broken feature.
- **Expenses (Bill items) and Settlements are separate entities.** An item assignment records what was ordered and by whom; a settlement records the resulting amount owed. This separation lets a corrected item re-run the balance calculation without corrupting settlement history.
- **Validation is inline and blocking.** Assigned item totals must equal the receipt total before a Bill can move to Settling; the UI shows a running "unassigned: ₱X" indicator.
- **Mobile-first layout.** Receipt scanning and bill settling both happen on a phone in the moment, so the Tailwind layout is mobile-first from the start.
- **Editing after settlement begins is restricted.** Once a Bill enters "Settling," item assignments are locked; corrections require reversing and recalculating, so in-progress settlements are never silently invalidated.
- **No auto-timeout on unconfirmed payments.** A settlement can sit in "Awaiting Confirmation" indefinitely if the receiver doesn't act — a stated, acknowledged limitation rather than an unaddressed gap.

## 9. Technical Architecture

| Layer | Technology |
|---|---|
| Frontend | HTML, Vue.js, Tailwind CSS |
| Backend | PHP |
| OCR | Google Gemini (multimodal) via REST — the server sends the phone photo and gets back structured JSON (items, qty, prices, tax, service charge, discount, total) |
| Database | MySQL (MariaDB) |
| Dev Environment | XAMPP (Apache + MariaDB) |
| Hosting | Google Compute Engine e2-micro (Always Free), HTTPS via Let's Encrypt — see [DEPLOY.md](DEPLOY.md) |

**Frontend responsibilities:** receipt capture UI, OCR review/edit table, item-assignment interface, live balance previews, settlement status UI, responsive layout.

**Backend (PHP) responsibilities:** authentication, Bill/membership management, receiving the uploaded image and returning OCR results for review, saving corrected item data, balance calculation, settlement generation, payment status transitions, confirmation logic, notifications.

**Database — core entities:**
- `users`
- `bills`
- `bill_members`
- `receipt_items` (extracted/corrected item name, price, quantity)
- `item_assignments` (which member(s) each item is assigned to)
- `settlements` (sender, receiver, amount, status, timestamps for paid/confirmed)
- `notifications`

## 10. Demo Scenario (fixed for consistency across mockups and defense)

- 4 members
- 1 restaurant receipt with 6–8 line items, tax, and service charge
- 1 deliberately misread item, to demonstrate the review/correction step
- 1 deliberately included dispute, to demonstrate the reject path

## 11. Criteria Mapping

| Capstone Requirement | How Setlo Meets It |
|---|---|
| Apply Vue.js in a working application | Vue.js drives the OCR review table, item-assignment UI, live balance calc, and settlement actions |
| Use Tailwind CSS for a clear, responsive interface | Mobile-first Tailwind layout throughout |
| Connect to persistent data via backend/API/database | PHP + MySQL on XAMPP |
| Improve an existing process for a specific target user | OCR-assisted itemized splitting + verified settlement for food-bill groups (see Innovation Statement) |
| Focused, not oversized scope | Narrowed to a single expense type (food bills), single-event lifecycle, no recurring ledger |
| Demonstrate testing, debugging, technical understanding | OCR accuracy handling, review/correction fallback, and the settlement state machine give concrete points for the live defense walkthrough |

## 12. Acknowledged Limitations

- OCR accuracy varies by receipt condition (faded thermal paper, unusual layouts); mitigated by a mandatory review/edit step, not solved outright.
- Receipt scanning needs an internet connection and is subject to the Gemini API free-tier rate/daily limits; when it fails, the photo is kept and items are entered manually (the offline "Load demo receipt" remains as a demo backup).
- On the Gemini API free tier, Google may use submitted content to improve its products, so receipt photos should not be treated as private.
- No automatic money transfer — Setlo tracks and verifies settlement status, it does not move funds.
- No reminder/timeout mechanism if a receiver never confirms a payment.
- Non-food expense types (trips, transport, accommodation) are out of scope for this build.
- Dispute handling is intentionally simple, not a full resolution workflow.
## Login & sign-up landing

- Guests land on `pages/login`; the old splash page redirects there. Log in and **Create account** share one design: brand and features on the left, the card in the middle, an illustrative phone on the right. The side columns hide on smaller screens.
- **Remember me** keeps you signed in for 30 days. Untick it on shared computers.
- **Continue with Google** works once a Google OAuth client ID is set (`GOOGLE_CLIENT_ID`; see DEPLOY.md → *Google sign-in*). Until then, the button explains that it isn't set up.
- **Forgot password?** The app can't send email, so the app manager resets it in **Admin → Users → Reset password** and gives the user a temporary password to change on their Profile.

## Pay-me QR, validation and security

- **Pay-me QR (every account):** each account gets its own QR automatically; no uploads. Scanning it opens `pages/pay?u=…` with the person's payment method and number, plus anything the scanner still owes them. Profile → **Your Pay-me QR** has Download, Share link, and **Get a new QR** (the old one stops working). "Show QR" on My Settlements shows the receiver's QR.
- **SweetAlert2** handles every confirmation, message and notification (no browser `confirm()`/`prompt()` boxes).
- **Validation while typing** on every form (`assets/js/validate.js`), with the same rules enforced by the API (`includes/validate.php`). Passwords need 8+ characters with a letter and a number.
- **Security:** sign-in is locked for 15 minutes after 5 wrong passwords per email and device. Pages send security headers (Content-Security-Policy, no framing, no MIME sniffing), and CDN scripts are pinned with integrity hashes. Sessions use strict mode, and the cookie is marked `Secure` on HTTPS.

## API keys on a local copy

- Copy `config/local.example.php` to `config/local.php` and paste in the Gemini key (receipt scanning), Google client ID and PayMongo test key. No Apache restart needed. Git ignores `config/local.php`, so share the keys privately, never through the repo.
- `SetEnv` lines in `httpd.conf` still work and take priority over `config/local.php`.

## Backups

- **One click:** double-click `tools\backup.cmd` (MySQL must be running). It saves the database to `backups\setlo_<date>.sql` and commits any code changes to git. Apache blocks web access to `backups\`.
- **Undo a mistake in the code:** `git status` shows what changed, `git restore <file>` puts a file back as of the last backup, and `git log` lists every backup.
- **Restore the database:** `C:\xampp\mysql\bin\mysql -u root setlo < backups\setlo_<date>.sql`
- Everything is still on one disk: copy the SplitMate folder, including `backups\`, to a USB drive or Google Drive now and then.
