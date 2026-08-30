# ThirtyDayHomes — Local Review Guide

Every menu item, where it goes, what works today and what is still to come.
Follow this top to bottom on your own machine and you will have seen the whole
of Milestone 1. It takes about twenty minutes.

---

## Before you start

Start **Apache** and **MySQL** in the XAMPP control panel, then open the site.

| | |
|---|---|
| Website | `localhost/thirtydayhomes` |
| Admin area | `localhost/thirtydayhomes/wp-admin` |
| Username | `admin` |
| Password | `tdh_local_2026` |

---

## What the three labels mean

You will see these throughout.

| Label | Meaning |
|---|---|
| **Works** | Fully built and tested |
| **Says so** | Built, and the page itself states that a feature is still coming |
| **Milestone 2** | Not started yet, by plan |

The middle one matters most in a review. The page is built and looks finished,
but it tells the visitor plainly that a feature is coming rather than pretending
to work. That is a deliberate choice, not an unfinished page.

---

## The menu, and where each item goes

Six items in the header. The fifth changes depending on whether you are signed in.

| Menu item | Takes you to | What is on it |
|---|---|---|
| About | `/about/` | Company copy. Placeholder until the client approves wording. |
| Find a home | `/homes/` | All published homes. Three right now. |
| Renter FAQ | `/how-it-works/` | How it works for renters and owners, plus four questions. |
| List your property | `/pricing/` | Membership page. Prices not confirmed, and the page says so. |
| Sign in | `/login/` | Becomes **Dashboard** → `/account/` once you sign in. |
| **List your home** *(gold button)* | `/register/` | Create a landlord account. |

Footer links go to the same pages plus `/terms/`, `/privacy/` and `/fair-housing/`.

---

## The walkthrough

Ten stops in order. Each says what to click, what should happen, and what to
judge with your eyes.

### 1. The homepage — Works

Open `localhost/thirtydayhomes` and scroll all the way down. There are five
separate sections, in this order:

1. Hero with the search box
2. Four audience cards
3. Three property cards
4. Split photo with the verified badge
5. Dark owner call to action

**Look at:**
- Every section heading sits the same distance from its own text
- The gold button in the dark band clears the paragraph above it
- Nothing scrolls sideways

---

### 2. The search box in the hero — Says so

Type *Shadyside*, pick any two dates, press **Search homes**. You land on the
results page and it says:

> Showing all homes. Filtering for "Shadyside" arrives with the search module.

That is deliberate. The search box already puts your words into the web address,
so the plumbing is there. Actually narrowing the results is the next piece of work.

---

### 3. The property cards — Works

Each card carries a photo, a badge, a save heart, the neighbourhood, beds, baths,
pet policy, the price, and a sand-coloured band reading something like
**0.3 mi from UPMC Shadyside**.

That distance is **calculated, not typed in**. The property and the hospital both
store map coordinates and the site measures between them. This is the promise the
whole business rests on, so it is worth pointing at when you show your boss.

**Try it:** click a heart. It fills red and stays filled if you reload the page.

---

### 4. A single home — Says so

Click any property card, or go to `/homes/sunlit-shadyside-retreat/`. You get the
photo, quick facts, description, amenities, availability, and the nearest hospital.

Two things are placeholders and say so on the page: the map area, and the enquiry
form, which reads *"The enquiry form arrives with the inquiry pipeline in
Milestone 2."*

---

### 5. The listings page — Works

Click **Find a home**. Three homes appear.

There are five in the database. One is awaiting approval and one is on billing
hold, and neither is allowed to reach the public. **That is the moderation rule
working, not a bug.**

---

### 6. Create a landlord account — Works

Click the gold **List your home** button.

First try a short password like `1234`. It should refuse, keep everything else you
typed, and create nothing.

Now use a password of twelve characters or more. You are signed in immediately and
land on the dashboard.

---

### 7. The landlord dashboard — Says so

`/account/` greets you by name and shows four boxes: membership, plan, listings
used, renewal date.

A brand-new landlord reads **No active plan** and **0 of 0**.

Those zeroes are correct, not broken. Payments are not built yet, so nobody can
have a plan, and the page says that honestly rather than inventing one.

---

### 8. The admin area is closed to landlords — Works

While signed in as that landlord, type `localhost/thirtydayhomes/wp-admin` into the
address bar. You are sent straight back to the dashboard.

A landlord is a customer of the marketplace. They should never see a WordPress screen.

---

### 9. Account details — Works

Go to `/profile/`. Change your name and phone, save — it confirms.

Now try changing the **email address** without filling in the current password box.
It refuses.

Anyone who walks up to an unlocked laptop could otherwise take the account over by
changing its email.

---

### 10. Forgotten password — Works

Sign out. Click **Forgotten your password?**, enter the email, submit.

Your computer cannot send real email, so open the folder `D:\xampp\mailoutput\` —
the newest file is the message. Copy the reset link out of it and paste it into the
browser.

**Notice:** the reply is the same whether or not the address has an account. Saying
"no account with that email" would hand an attacker a list of who has registered.

---

## The grey bar at the top

If you see **Viewing as: Renter** with a dropdown, that is client review mode. It
lets someone walk the site as five different people without knowing five passwords.

| Persona | What they see |
|---|---|
| Renter | The public site, signed out |
| New landlord | Dashboard with no plan |
| Active landlord | Dashboard with a running membership |
| Failed payment | Listings hidden, warning shown |
| Administrator | The moderation side |

**Worth knowing:** these are real WordPress accounts with real permissions, not a
pretend switch. It only ever runs on a review site — it refuses to load on a live
one, because a control that logs anyone in as anyone would be a way into the site.

---

## One command checks what clicking cannot

Some rules are invisible in a browser. A page that hides a button still hands over
the data if the permission behind it is wrong.

```
plugins\thirtydayhomes-core\tools\verify.bat
```

It runs 34 checks and prints **all 34 checks passed**. It proves:

- One landlord cannot read another's enquiries
- Unapproved homes stay hidden from the public
- A new landlord can publish nothing
- The hospital distances are correct
- Account pages stay out of search results

It cleans up after itself and changes nothing permanently.

> This is how a real problem was caught: any landlord could read every other
> landlord's enquiries. Nothing on screen looked wrong. Only the check found it.

---

## Milestone 1, for your boss

The contract lists seven things that must work. Where each one stands:

| # | Requirement | Status |
|---|---|---|
| 1 | Register, sign in, reset password, edit profile | **Works** |
| 2 | Start a test subscription, see it in the dashboard | Needs billing |
| 3 | Paid, failed, cancelled and expired scenarios | Needs billing |
| 4 | One landlord cannot reach another's data | **Works** |
| 5 | Screens match the approved design | Public pages done |
| 6 | An administrator can edit sections in Elementor | **Works** |
| 7 | No serious defects outstanding | **Clean** |

In one sentence:

> **The public half of Milestone 1 is finished; the payments half has not
> started** — and it has not started because it is waiting on decisions rather
> than on work.

---

## Three things only the client can unblock

Worth raising in the same conversation, because two of them have long lead times.

| Needed | Why it blocks us |
|---|---|
| **A staging site** | The contract says Milestone 1 is accepted on staging. However well it runs on this laptop, that is not the same thing. |
| **Plan structure and prices** | Payments cannot be built without them. The homepage currently claims "3 listings per plan", which nobody has confirmed. |
| **The company EIN** | Required to register for business text messaging in the United States. Carrier approval takes weeks, so it should be started now even though the texting work comes later. |

---

## How the pieces fit together

Useful if anyone asks why it was built this way.

| Piece | Holds |
|---|---|
| **The theme** | Only how things look. Delete it and no information is lost. |
| **Our plugin** | Every home, landlord, membership, enquiry, hospital and permission rule. Everything the business owns. |
| **Elementor (free)** | Lets an administrator edit headings, text and images without a developer. No paid version, no add-ons. |

The reason for the split: if the site is redesigned in three years, the theme is
thrown away and every listing, member and enquiry survives untouched. Nothing the
business depends on has an annual licence fee.

---

*Local review guide · ThirtyDayHomes · Milestone 1. Everything above was checked
on this machine before writing.*
