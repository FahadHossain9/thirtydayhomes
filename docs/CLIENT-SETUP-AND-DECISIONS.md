# What the client has provided, decided, and still owes

A running record of the setup items and decisions Rob has answered, so
nobody has to scroll a WhatsApp thread to find out whether something is
settled. Last updated **23 September 2026**.

> **No password, API key, EIN or token is written in this file, and none
> ever should be.** AGENTS.md rule 11: secrets live in `wp-config.php`
> constants, never in the repo, and this repo is deployed to the live
> server on every push. Where a value is needed, this file says who holds
> it and when it was supplied. See **Credentials to rotate** at the end.

---

## 1. Accounts — all created

| What | State | Notes |
|---|---|---|
| **Twilio** | Created and upgraded off trial | Rob set it up 18 Sep, upgraded 19 Sep. Login is in the WhatsApp thread |
| **Google Cloud** | Billing **active**, both API keys created and restricted | "ThirtyDayHomes website" restricted to Maps JavaScript API and to our domains; "ThirtyDayHomes server" restricted to Geocoding API. Both live and working |
| **Cloudflare** | Login supplied 22 Sep | Where the domain's DNS lives; needed for the business mailbox |
| **Gmail** (project account) | Two accounts in play | The Twilio/Google Cloud account, and a second one used for Cloudflare |

## 2. Details for the A2P 10DLC carrier registration

Everything the registration form asks for **has been supplied** — since
19 September.

| Field | Status |
|---|---|
| Legal business name | **Thirty Day Homes LLC** |
| EIN | Supplied 19 Sep. Held in the WhatsApp thread; deliberately not written here |
| Business address | 3000 W Memorial Rd, Suite 123-707, Oklahoma City, OK 73120 |
| Contact name and title | Robert Moses, Managing Member |
| Contact email and phone | Supplied 19 Sep, same thread |
| Test phone for real SMS | Supplied 19 Sep |

**Nothing is outstanding from Rob for this filing.** See §5.

## 3. Setup items from the original list

| # | Item | State |
|---|---|---|
| 1 | Twilio account and registration details | **Done** |
| 2 | Google Cloud with billing | **Done** — map and hospital distances live |
| 3 | Hospital list | **Done** — "you can include all the hospital/medical locations" |
| 4 | Test addresses | **Done** — sent by email; two corrected to "8 Lewis Ave" and "106 W McNutt St" |
| 5 | Test phone | **Done** |
| 6 | Business email `support@thirtydayhomes.com` + DNS | **Part done** — Cloudflare login supplied; the mailbox itself is not working yet, and Rob has asked for help setting it up |

## 4. The sixteen decisions

**Answered 19 Sep — "7-11 approve":**

| # | Decision | Answer |
|---|---|---|
| 7 | Text message wording | Approved as drafted |
| 8 | After a failed payment, homes stay visible 7 days, then hide | Approved |
| 9 | Property pages show the 3 nearest hospitals within 15 miles | Approved |
| 10 | Maps show an approximate area, never the exact address | Approved |
| 11 | Phone number optional for renters on the inquiry form | Approved |

**Deferred by Rob:**

| # | Decision | Answer |
|---|---|---|
| 15 | Multi-listing discount (10% for 2, 15% for 3) | "we can hold off" — out of scope, quote separately if wanted |

**Still unanswered — "i'm still thinking thru the others":**

| # | Decision | What it blocks |
|---|---|---|
| 12 | **A copy of every inquiry email goes to you — which address?** | D3's admin-copy switch. Built and shipped **off**, which is correct: there is no address to copy to until he names one. Register decision 5 says copies should be on; it cannot be honoured until #12 is answered |
| 13 | Editing a live home: which changes go back for approval | Already built to the proposed rule (A4). If he answers differently it is a change, not a gap |
| 14 | Email verification at sign-up is part of Milestone 2 | Built as F1 on the reviewer's go-ahead (24 Sep 2026). **Rob's written yes is still to be recorded** — ask in the next update |
| 16 | How many listings each plan allows | Membership quotas |

**Separately confirmed:** the ZIP/area search should return the closest
properties even when nothing sits inside the postcode — "yes. it makes
sense" (22 Sep). Built as C6/R44 and live.

## 5. The one thing actually outstanding on our side

**The A2P 10DLC registration was filed on 24 September 2026 at 11:59 am
BD** — Campaign SID `CM0479506a7244d9184262d1c591d8c9e7`, *In review*.
The 1–3 week carrier clock is running; the decision email goes to Rob's
Twilio Gmail. How it got there:

Rob was told on 22 September *"You've already sent me everything I need
for it, so I'm filing it now."* That is true of the information — every
field in §2 has been in hand since 19 September — and the filing began
on the evening of 23 September (BD time): signed in to Rob's Twilio
account, a Pittsburgh (412) number chosen, and the compliance profile
reached. Twilio now requires that profile **before** the number can be
bought, and it opens with a **Persona identity check — a photo of the
authorized representative's government ID and a selfie**. That was Rob's
to do personally. He did the whole profile himself on his laptop between
11:58 pm and 12:06 am BD (the website field only accepted
`https://thirtydayhomes.com/`; no ID photo was asked for in the end), and
Twilio **approved it at 12:28 am BD on 24 September** — "Twilio Business
Profile Approved", Bundle SID `BUef9e02ea86b05bffa31f52e784f1768e`. On the
morning of 24 September (BD) the number was bought — **+1 412 278 7837**
— the messaging service created, and the **Brand submitted** (Low Volume
Standard) — status *In Review*. The brand was approved within the hour and
the campaign form reached the same morning. Its message-flow screen
demands clauses on the Privacy and Terms pages that live did not yet
have, so those were built (R46, continued), deployed and imported on live the
same morning, and read back over HTTP with every clause present. The
form was then completed and submitted; Twilio's pre-check asked for two
adjustments first (no verification-code sample under Account
Notification; the proof-image URL inside the consent text) and accepted
it as "needs additional review", which is a human reviewer, not a
rejection.

This is R45, and it is the only open item that can slip Milestone 2 on its
own: without carrier approval D4 cannot send, acceptance criterion 6
cannot be met, and the payment gate's real-phone SMS evidence cannot be
produced. R46, which blocked it, is now closed — the privacy page carries
the texting clause.

## 6. Credentials to rotate before hand-over

Passwords and both Google API keys were pasted as plain text into a
WhatsApp group with six numbers on it. None of that can be un-sent, so all
of it is treated as exposed.

Rotate at hand-over, and confirm each one afterwards:

- [ ] Google **Geocoding** key (server) — already R43
- [ ] Google **Maps JavaScript** key (website) — same exposure, same thread
- [ ] Twilio account password, and the Auth Token if it was ever shared
- [ ] Both Gmail account passwords
- [ ] Cloudflare account password
- [ ] Re-enable 2-Step Verification on the Google accounts it was turned
      off for, once we no longer need to sign in

The keys are restricted (one to our domains, one to the Geocoding API), so
the exposure is limited rather than open — but restricted is not the same
as private, and a rotation costs minutes.

**Going forward:** ask Rob to send credentials through a one-time link
rather than the group chat, or set up a project-only account he hands over
and re-passwords afterwards.
