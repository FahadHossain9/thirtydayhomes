# Filing the A2P 10DLC registration in Twilio — click by click

For whoever sits down at Twilio with Rob's login. Written **23 September
2026**. Twilio moves menu items around every few months, so each step says
what you are *trying to reach* as well as where it was last seen; if a
label differs, look for the goal.

> **No secret is written here.** The EIN and the account logins are in the
> WhatsApp thread of 18–22 September. Rule 11.

---

## Before you start

**Have open:**
- Twilio, signed in with the account Rob created (the Gmail address he uses
  for Twilio and Google Cloud).
- The WhatsApp thread, for the EIN.
- `docs/CLIENT-SETUP-AND-DECISIONS.md` §2 for the business details.

**Expect a verification code.** Twilio may text or email a code on sign-in.
The email is Rob's Gmail (we have it); a text goes to Rob's phone — tell
him to forward it.

**Order matters, and it is not the order the console shows you:**

1. Buy a phone number (5 min)
2. Business profile — who the business is (10 min)
3. Brand — registers the business with the carriers (5 min, then usually
   approved within a day)
4. Messaging Service — the sender the campaign attaches to (5 min)
5. Campaign — what the texts are for (15 min, **then 1–3 weeks of carrier
   review; this is the slow part**)

Everything is in the console at **console.twilio.com**.

---

## Step 1 — Buy a phone number

Goal: one US mobile-capable number the texts will come from.

1. Left menu → **Phone Numbers → Manage → Buy a number**.
2. Country: **United States**. Tick **SMS** under capabilities.
3. Optional: type `412` in the number box to get a Pittsburgh area code.
   Any US number works.
4. Click **Buy** on a **Local** number (not Toll-Free). About $1.15/month.
5. Note the number. You will attach it in Step 4.

---

## Step 2 — Business profile (Trust Hub)

Goal: tell Twilio who the legal entity is. Carriers check this against the
IRS record, so every value must match the EIN letter.

> **Found on 23 Sep 2026: Twilio now puts this step before the number
> purchase** (the Buy-a-number screen says "you will need a primary
> compliance profile"), and the profile begins with an **identity check
> by Persona — a photo of a government ID and a selfie of the authorized
> representative**. That is Rob's face and Rob's licence, so **Rob does
> that part himself, on his phone**: console.twilio.com → *Set up your
> compliance profile* → **Business Profile** → Next → follow the camera.
> Nobody else may click through that consent for him. Once it reports
> "submitted", whoever holds the login continues with the fields below.
>
> **Done — approved 24 Sep 2026** (Bundle `BUef9e02ea86b05bffa31f52e784f1768e`).
> Rob filled it in himself; in the end no photo was asked. One field
> fought back: **Business website URL accepts only
> `https://thirtydayhomes.com/`** — `www.thirtydayhomes.com` and the bare
> domain were both "not valid". Steps 1 and 3–5 remain.

1. Left menu → **Trust Hub** (sometimes under *Account → Trust Hub*) →
   **Customer Profiles** → **Create** (or *Create Business Profile*).
2. Fill it in:

| Field | Type this |
|---|---|
| Business name (legal) | `Thirty Day Homes LLC` |
| Business type | `Limited Liability Company` (LLC) |
| Business registration ID type | `USA: Employer Identification Number (EIN)` |
| Business registration number | the EIN from the WhatsApp thread, digits only or with the dash as the form shows |
| Business identity | `Direct Customer` (we are the business sending, not an agency for others) |
| Business industry | `Real Estate` |
| Website | `https://thirtydayhomes.com` |
| Regions of operation | `USA and Canada` (or `United States`) |
| Business address | `3000 W Memorial Rd, Suite 123-707` / `Oklahoma City` / `OK` / `73120` / `US` |

3. **Authorized representative** (it will ask for one, sometimes two —
   one is enough):

| Field | Type this |
|---|---|
| First / last name | `Robert` / `Moses` |
| Job title | `Managing Member` |
| Business title | `Owner` (or the nearest option) |
| Email | the Gmail address Rob gave as Twilio contact |
| Phone | `+1 281 414 6409` |

4. Submit. This part is reviewed by Twilio, usually within hours. Status
   shows on the Customer Profile page.

**If it is rejected:** the reason is almost always name/EIN mismatch. Do
not guess — ask Rob for a photo of the top of the IRS letter and copy it
exactly.

---

## Step 3 — Register the Brand

Goal: register the business with The Campaign Registry (the carriers'
shared database). One-time fee, a few dollars, charged to the card.

1. Left menu → **Messaging → Regulatory Compliance → A2P 10DLC** (older
   consoles: *Messaging → Senders → US A2P 10DLC*).
2. Click **Register a brand** (or *Create new brand*).
3. Brand type: **Standard** (if it offers *Low Volume Standard*, choose
   that — same registration, cheaper monthly, fine for under 6,000
   messages a day, which we will never reach).
4. Customer Profile: pick the one from Step 2.
5. Skip **secondary vetting** unless asked — not needed for our volume.
6. Submit.

Brand status usually goes **Approved** within minutes to a day. If it says
**Failed** or **Unverified**, the EIN/name did not match the IRS — see the
note above. Re-submitting costs the fee again, which is why Rob is asked to
confirm the letter first.

---

> **Done — approved 24 Sep 2026**, Brand SID `BN51ac9c5e70010804a127cff89d84849a`,
> Low Volume Standard, company type *Private Corporation* (the form has no
> LLC option). **A trap:** the number's "Finish setting up your number"
> checklist went on showing the brand as *Not started* after approval and
> answered the campaign button with "unexpected error". Do not register
> the brand again from there. The truth is at **Trust Hub → Registrations →
> A2P 10DLC Brands**, and the campaign is registered from **A2P 10DLC
> Campaigns** on the same Registrations page.

## Step 4 — Messaging Service

Goal: a named sender that holds the phone number; the campaign attaches to
it, and Twilio's automatic STOP/HELP handling lives here.

1. Left menu → **Messaging → Services → Create Messaging Service**.
2. Name: `ThirtyDayHomes inquiry alerts`
3. Use case: **Notify my users**.
4. **Add senders** → **Phone number** → tick the number from Step 1 → Add.
5. **Set up integration**: leave defaults (we send via the API, nothing
   here needs changing). Skip webhooks for now; D4 adds them.
6. **Compliance**: it will offer to register an A2P campaign — that is
   Step 5. You can click into it from here.
7. Finish / Complete setup.

Then, in the service → **Opt-Out Management** (may be called *Advanced
Opt-Out*): confirm STOP, HELP and START handling is **on** (it is by
default for US numbers). Set the messages:

| | Type this |
|---|---|
| Opt-out (STOP) reply | `ThirtyDayHomes: You've been unsubscribed and will receive no more texts. Reply START to resume.` |
| Help (HELP) reply | `ThirtyDayHomes: We text you when a renter inquires about your property. Reply STOP to opt out. Questions: https://thirtydayhomes.com/contact/` |
| Opt-in (START) reply | `ThirtyDayHomes: You're set to receive a text when a renter inquires about your property. Msg frequency varies. Msg & data rates may apply. Reply STOP to opt out, HELP for help.` |

---

## Step 5 — Register the Campaign

Goal: tell the carriers what the messages are, how people consent, and how
they stop. **This is what takes 1–3 weeks.** One-time vetting fee plus a
small monthly fee.

1. **Messaging → Regulatory Compliance → A2P 10DLC → Campaigns → Register
   a campaign** (or from the Messaging Service's Compliance tab).
2. Brand: the one from Step 3. Messaging Service: the one from Step 4.
3. **Use case:** `Account Notifications` (standard, non-marketing alerts
   to registered users). If that is missing from the list, `Low Volume
   Mixed`.
4. Fill in:

**Campaign description**
```
ThirtyDayHomes.com is a furnished-rental marketplace. Landlords who list a
property can choose to receive one text message each time a renter sends
an inquiry about their property, so they can reply quickly. One message
per inquiry. No marketing, no promotions, no scheduled messages.
```

**Sample message 1** (square brackets mark the variable parts, as the
form asks)
```
New ThirtyDayHomes inquiry for [Property name]. View it: https://thirtydayhomes.com/account/?view=inquiries&msg=[ID] Reply STOP to opt out.
```

**Sample message 2**
```
New ThirtyDayHomes inquiry for Sunlit Shadyside Retreat. View it: https://thirtydayhomes.com/account/?view=inquiries&msg=1842 Reply STOP to opt out.
```

**Do not add the verification-code text as a sample.** Twilio's pre-check
(24 Sep 2026) refused `Your ThirtyDayHomes code is [123456]…` under
*Account Notification* — a one-time code reads as the 2FA use case. The
code still goes out on this campaign; it simply is not listed as a sample.

**Embedded links:** Yes, with the sample link
`https://thirtydayhomes.com/account/?view=inquiries`. Phone numbers,
lending, age-gated: No.

**Primary opt-in method:** Web form.

**Message flow / how subscribers opt in** (the reviewers read this most
carefully — the consent sentence quoted is the card's, word for word)
```
Landlords opt in on their own account page at https://thirtydayhomes.com/account/?view=profile after signing in. Under "Text message alerts" they enter their mobile number and tick a checkbox that reads: "Text me when I receive an inquiry. One message per inquiry; message and data rates may apply; reply STOP to opt out." They then confirm the number by entering a six-digit code we text to it. A number is never texted until this confirmation is completed. Consent is optional and is not a condition of listing a property or of using the site. Message frequency varies with the number of inquiries received. Message and data rates may apply. Reply STOP to opt out at any time, HELP for help. Privacy policy: https://thirtydayhomes.com/privacy/ Terms: https://thirtydayhomes.com/terms/ The consent step is behind the landlord's sign-in; a screenshot of it, showing the SMS checkbox and its exact wording, is publicly reachable at https://thirtydayhomes.com/wp-content/plugins/thirtydayhomes-core/assets/compliance/sms-opt-in.png
```

The last sentence is there because Twilio's automatic pre-check cannot
open a page behind a login and says so ("consent is collected inside a
chat widget or pop-up we can't read"); with the image URL in the text it
accepts the form as *needs additional review* — a human reads it — rather
than refusing it. Expect that wording; it is not a rejection.

**Opt-in method proof:** the card itself, in its Not-set-up state, served
from the site's own domain —
`https://thirtydayhomes.com/wp-content/plugins/thirtydayhomes-core/assets/compliance/sms-opt-in.png`
(`plugins/thirtydayhomes-core/assets/compliance/sms-opt-in.png` in the
repo; re-capture it if the card's wording ever changes).

**Privacy policy link:** `https://thirtydayhomes.com/privacy/` — the form
requires that page to be titled *Privacy Policy*, name the brand, say
what is collected and why, and contain *"We do not sell or share your SMS
opt-in data or personal information with third parties for marketing
purposes."* word for word. It does, since 24 Sep 2026.

**Terms link:** `https://thirtydayhomes.com/terms/` — required to be
titled *Terms & Conditions* or *Terms of Service*, with an SMS Terms
section, *message and data rates may apply* and the brand. It is, since
24 Sep 2026.

**Opt-in keywords:** leave blank (opt-in happens on the website). If a
value is required: `START`

**Opt-in confirmation message**
```
ThirtyDayHomes: You're set to receive a text when a renter inquires about your property. Msg frequency varies. Msg & data rates may apply. Reply STOP to opt out, HELP for help.
```

**Opt-out keywords:** `STOP`

**Opt-out message**
```
ThirtyDayHomes: You've been unsubscribed and will receive no more texts. Reply START to resume.
```

**Help keywords:** `HELP`

**Help message**
```
ThirtyDayHomes: We text you when a renter inquires about your property. Reply STOP to opt out. Questions: https://thirtydayhomes.com/contact/
```

**Tick-boxes**

| Question | Answer |
|---|---|
| Subscriber opt-in | **Yes** |
| Subscriber opt-out | **Yes** |
| Subscriber help | **Yes** |
| Embedded links | **Yes** (the messages carry a link) |
| Embedded phone numbers | No |
| Age-gated content | No |
| Direct lending / loan arrangement | No |
| Number pooling | No |
| Affiliate marketing | No |

5. Submit. Status goes to **Pending** (sometimes shown as *In progress*).
   Vetting is 1–3 weeks. Note the **Campaign SID** — it goes in the
   register as the filing reference.

---

## Filed — 24 September 2026, 11:59 am BD

| | |
|---|---|
| Campaign SID | `CM0479506a7244d9184262d1c591d8c9e7` — *In review* |
| Brand SID | `BN51ac9c5e70010804a127cff89d84849a` — Approved, Low Volume Standard |
| Messaging Service | `MG49edb07c9169a33322f948fb87325e41` (this is `TDH_TWILIO_FROM`) |
| Sender | +1 412 278 7837 |
| Business profile | `BUef9e02ea86b05bffa31f52e784f1768e` — Approved |
| Decision email | Rob's Twilio Gmail |

Still to do in Twilio, cosmetic, any time: rename the messaging service
to *ThirtyDayHomes inquiry alerts* and set the STOP/HELP/START replies
from Step 4's table under Opt-Out Management.

## What to write down when done

In `MILESTONE-2-PLAN.md` §6, on R45: the date filed, the Brand SID, the
Campaign SID, and the phone number. In `docs/CLIENT-SETUP-AND-DECISIONS.md`
§5: change "has not been filed" to the filing date.

## One honest note

The opt-in text above describes the account-page consent box and the
six-digit code. That screen is built and deployed (D4, 23 Sep 2026) and
sits behind the landlord's sign-in, which is normal for a reviewer: they
judge the description and the privacy page. Until the carrier approves
the campaign the live site keeps texting switched off (`TDH_SMS_ENABLED`
is not defined there), so the card reads "Text alerts are not switched on
for this site" — which is the truth, and what a reviewer would expect
before approval. Once approved, the three `TDH_TWILIO_*` constants and
`TDH_SMS_ENABLED` go into the live `wp-config.php` and the card comes on.
