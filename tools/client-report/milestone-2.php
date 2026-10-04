<?php
/**
 * Milestone 2 client report — the CONTENT only.
 *
 * Rebuild the .docx after editing:
 *     D:\xampp\php\php.exe tools\client-report\make-report.php milestone-2
 *
 * Written for the client. Three sections, nothing else: what is done, how to
 * check it on the live site, what is still to come. No reasoning, no test
 * counts, no internal detail, no dates anywhere in the text (reviewer's
 * instruction, 19 Sep 2026). After a task gets "100% OK" and is live, move
 * it from "Still to come" into "Completed" and add its live check.
 *
 * Row kinds: title and sub (the cover band), role (a navy band,
 * "Title|line under it"), h1, h2, p, li (bullet),
 * step (numbered, restarts under every heading), note (shaded box).
 */

return [
	'file' => 'Milestone-2-Report.docx',
	'rows' => [
		[ 'title', 'Milestone 2' ],
		[ 'sub', 'Progress report' ],

		[ 'h1', 'Completed so far' ],
		[ 'p', 'Milestone 2 is built and live on thirtydayhomes.com. Here is what it adds, grouped by the part of the site it touches.' ],

		[ 'h2', 'How every part was built' ],
		[ 'p', 'Each part of the site was built and checked against the same three rules.' ],
		[ 'li', 'The whole journey, not just the page. We followed each person from start to finish: a renter searching and sending an inquiry, a landlord adding and managing a home, and your team approving it. Every screen says where you are, shows one main button for the next step, and confirms what happened after you press it.' ],
		[ 'li', 'The unusual cases, not just the normal one. We tried what real people do by mistake: a search that finds nothing, a form left half empty, the Send button pressed twice, the back button, a payment that fails, a photo or hospital that was removed. In each case the page explains what happened and what to do, and nothing typed is lost.' ],
		[ 'li', 'A calm, premium look. One design standard now runs through every screen: text that is easy to read, clear words instead of technical terms, status shown in words (such as "Live" or "Waiting for approval"), and nothing that only works with a mouse. Every screen was checked on a phone, a tablet and a computer.' ],

		[ 'h2', 'The listing form and photos' ],
		[ 'li', 'The listing form now covers every detail of a home: security deposit, application, cleaning and pet fees; utilities (Included, Partly included or Not included); pet policy; square feet, rooms, parking and backyard; and where inquiries should go.' ],
		[ 'li', 'Before submitting, the landlord sees every answer, can edit any part, and is told if anything required is missing.' ],
		[ 'li', 'The property page shows the new fees, utilities and home details.' ],
		[ 'li', 'A home waiting for approval can be previewed by its landlord and the team; nobody else can open it.' ],
		[ 'li', 'Photos: the landlord sets the order, chooses the cover photo and can describe each photo. Large phone photos are resized automatically, and their hidden location data is removed.' ],
		[ 'li', 'The property page shows the photos in the landlord\'s order, with "Show all photos" to see every one.' ],
		[ 'li', 'The listing form names its four steps (Basics, Features, Photos, Review), shows the $ sign inside every amount, and its last step lists what is worth improving before submitting.' ],

		[ 'h2', 'Managing homes from the dashboard' ],
		[ 'li', 'The landlord\'s dashboard now explains each home\'s status in one line and shows one main button for the next step. A live home can be paused and resumed; resuming does not need a second review, unless its main details were changed while it was paused.' ],
		[ 'li', 'A landlord\'s homes appear as tidy cards with a photo, the rent and the neighbourhood, and every delete asks inside the page before anything is removed.' ],
		[ 'li', 'On a phone, the dashboard has a tab bar along the bottom: Overview, Listings, Inquiries, Plan and Account.' ],
		[ 'li', 'My listings can be filtered by status, such as Live or Paused, and each live home shows how many views and inquiries it has had.' ],
		[ 'li', 'The Plan page shows the plan, its monthly price and how many homes it covers, with a Manage billing button.' ],
		[ 'li', 'Landlords can edit a live home. Rent, fees, availability, amenities, utilities, pets and contact details update straight away, and the home stays live.' ],
		[ 'li', 'Changes to the title, description, photos, address, property type, bedrooms or bathrooms go back to your team for approval. Before saving, the landlord is told this and can go back and discard the changes. This list follows our suggestion in question 13; tell us if you want it different.' ],
		[ 'li', 'An email tells your team which details of an edited home were changed.' ],
		[ 'li', 'Landlords can say when a home is free: an "Available from" date and the periods it is already taken, from the listing form or straight from their dashboard.' ],
		[ 'li', 'Overlapping periods are joined automatically, and a period entered the wrong way round is refused with the reason.' ],
		[ 'li', 'The property page shows when a renter can move in and a month-by-month calendar with the taken days crossed out. Home cards show "Available now" or the date.' ],
		[ 'li', 'Deleting a home asks the landlord to confirm first. Your team can restore a deleted home for 30 days.' ],

		[ 'h2', 'Approval by your team' ],
		[ 'li', '"Request changes" now asks you for a short note. The landlord sees the note on their dashboard, makes the changes and resubmits.' ],
		[ 'li', 'An email goes to the site\'s administration email address whenever a landlord submits or resubmits a home.' ],
		[ 'li', 'Every home\'s address is now turned into a map location by itself, using your Google Maps account. If an address cannot be found, your team is told and can place the home by hand.' ],
		[ 'li', 'A home is only approved once its address is found on the map, so every live home shows its distance to hospitals.' ],
		[ 'li', 'Your Overview opens with the work: the first tile says "1 home waiting · Review now", and you can approve or request changes right there.' ],
		[ 'li', 'On Listings every filter shows its count, homes without a map point are one "Needs location" filter with a note, and every row has Edit and View.' ],
		[ 'li', 'Members can be searched by name or email and filtered by plan status. Plans read in words, such as "Standard plan", and a member with more homes than their plan covers shows "Over limit".' ],
		[ 'li', 'Facilities show each hospital\'s address and how many property pages list it. Inquiries mark unread messages "New" and show when each arrived.' ],

		[ 'h2', 'Your own homes on the map' ],
		[ 'li', 'The 14 addresses you sent are now homes on the site, under your account, so you can see the map and the hospital distances working with your real places.' ],
		[ 'li', 'The rent, rooms and photos on them are samples, and each home says so ("Sample details"). The street address is never shown; renters see only the neighbourhood circle.' ],
		[ 'li', 'Change any of them whenever you like: sign in, open My listings, press Edit, and put in the real rent, rooms and photos.' ],

		[ 'h2', 'Hospitals nearby' ],
		[ 'li', 'Each property page lists the nearest medical facilities under "Close to care", with the distance to each and a heading that says how far the farthest one is. A home with nothing nearby says so rather than leaving the section empty.' ],
		[ 'li', 'You choose how many facilities to list and how far to look, from Listing setup.' ],
		[ 'li', 'Adding a hospital only needs its address: the location fills in by itself.' ],

		[ 'h2', 'Finding a home' ],
		[ 'li', 'Renters can now narrow the homes list by price, bedrooms, bathrooms, property type and pets, and sort by newest or by price.' ],
		[ 'li', 'Searching is one panel: type a place, set any filters, press Show homes once. Sort by sits beside the count of homes found.' ],
		[ 'li', 'Each home card reads the way a renter decides: the rent first, with "Available now" or the date beside it, then the name, the neighbourhood, the rooms and the nearest hospital.' ],
		[ 'li', 'The filters chosen appear as labels above the results and can be removed one at a time or cleared all at once, and the heading counts what was found.' ],
		[ 'li', 'A search that matches nothing says which filter caused it and offers to clear it, instead of quietly showing every home again.' ],
		[ 'li', 'On a phone the filters open in a panel, so the list stays easy to read.' ],
		[ 'li', 'The city, property type and neighbourhood pages now use the same filters and results. A page with no homes yet says so by name, for example "No homes in South Side yet".' ],
		[ 'li', 'The move-in and move-out dates on the home page now work: a renter sees only the homes that are free for their whole stay and that accept a stay that long.' ],
		[ 'li', 'Each of those homes says "Free for your dates", and its own page repeats the stay above the calendar with a tick or a cross.' ],
		[ 'li', 'Dates that cannot be used are explained rather than ignored: a stay shorter than 30 days, a date already past, or a move-out before the move-in.' ],
		[ 'li', 'A renter can choose a hospital and see the homes nearest to it first, with the distance to that hospital on every home. They can set how far to look, and if nothing is found the page offers the next distance out.' ],
		[ 'li', 'Typing a hospital name in the search box now finds the homes near it.' ],
		[ 'li', 'Searching a ZIP code or an area now brings up the nearest homes, closest first, even when no home sits inside that ZIP code itself. Each home shows how far it is from the place that was searched.' ],
		[ 'li', 'Searching a Pittsburgh neighbourhood such as Oakland or Lawrenceville finds the Pittsburgh homes, never a town of the same name in another state.' ],
		[ 'li', 'Homes can now be seen on a map as well as in a list, each one showing its price.' ],
		[ 'li', 'The map shows a circle around each home\'s neighbourhood, never a pin on the address. Each property page shows the same circle under "Approximate area". The exact address never leaves our server.' ],
		[ 'li', 'The search results and the "Close to care" list are now blocks you can place on any page you build with Elementor, and their headings can be changed there.' ],

		[ 'h2', 'Inquiries and text alerts' ],
		[ 'li', 'Renters can send an inquiry from a property page: name, email, phone if they want to be called, move-in date, how long they need the home, and a message. They must tick a box agreeing that their details go to the owner.' ],
		[ 'li', 'The price and an "Ask the owner" button stay beside the renter as they read the page, and jump to the form. On a phone they sit in a bar along the bottom of the screen.' ],
		[ 'li', 'After sending, the form is replaced by a confirmation naming the home, so nobody is left wondering whether it went.' ],
		[ 'li', 'Every inquiry is saved and appears under Inquiries for your team, with everything the renter typed and which version of the rules they agreed to.' ],
		[ 'li', 'Pressing Send twice, or going back and forward in the browser, cannot send the same message twice.' ],
		[ 'li', 'Landlords read their inquiries in their own dashboard, under Inquiries: unread ones stand out, and each can be archived.' ],
		[ 'li', 'Each inquiry is also emailed to the landlord straight away. If the email fails, it is tried again, and your team can see and resend it.' ],
		[ 'li', 'Landlords can choose to get a text message for each inquiry, after confirming their number with a code. They can stop texts at any time by replying STOP.' ],
		[ 'li', 'The Privacy Policy and Terms of Service now cover text messages, as the phone carriers require.' ],
		[ 'li', 'The whole site now uses the American spelling, inquiry, as you asked: the form, the button, the emails and the texts.' ],
		[ 'li', 'Your business email, support@thirtydayhomes.com, is set up and signed, so its emails are trusted and not faked.' ],

		[ 'h2', 'Accounts and payments' ],
		[ 'li', 'Landlords can sign in with either their email address or their username.' ],
		[ 'li', 'Passwords now need at least 8 characters, as you asked.' ],
		[ 'li', 'The sign-in page now says it is for landlords and tells renters they need no account. Every password field has a Show button, and the sign-up page states the price before anyone types.' ],
		[ 'li', 'The pricing buttons say which plan they start and what it costs each month, and the page says what to do with more than three homes.' ],
		[ 'li', 'New landlords confirm their email address with a link we send them. Until they do, they can look around their dashboard but cannot start a plan or submit a home, and a panel tells them so. Existing landlords are not asked.' ],
		[ 'li', 'If a landlord\'s payment fails, their homes stay visible for 7 days while they update their card, and their dashboard says until when.' ],
		[ 'li', 'After that, or when a plan ends, their homes are hidden from renters. Nothing is deleted: when they pay again, exactly those homes come back on their own.' ],
		[ 'li', 'While a landlord has not paid, their new home cannot be sent for review or approved, and your team is shown why.' ],
		[ 'li', 'A landlord with more homes than their plan covers now sees "7 homes · plan covers 2" and what to do, instead of the confusing "7 of 2 used".' ],

		[ 'h2', 'Every screen, every device' ],
		[ 'li', 'Every page was checked on phone, tablet and computer, with the keyboard and zoomed in; small fixes were made to the search form, buttons, photos and calendar.' ],
		[ 'li', 'The look is being tuned page by page against a written design standard: text that is never too small or too faint, one main button per screen, and dropdown lists that match the site. Every part is done: the renter\'s pages, the information pages, the sign-in pages, the landlord\'s dashboard, the listing form and your team\'s screens.' ],
		[ 'li', 'In WordPress, the Listings table now shows each home\'s status, landlord, rent and map point, and the Logs page opens on the week\'s warnings.' ],
		[ 'li', 'The Terms, Privacy and Fair Housing pages have a plain header, a "Last updated" date and clickable links. Notes meant for you, such as "prices not final", are now shown only when you are signed in.' ],

		[ 'h1', 'How to check it yourself' ],
		[ 'p', 'Three short walks: first as a renter, then as a landlord, then as your team. Each step says where to click and what you should see. Steps marked "Try it" are things that should not work.' ],
		[ 'note', 'Before you start: press Ctrl + Shift + R on a page if it looks unchanged. Try things on a test home or a test landlord, never on a real landlord\'s home.' ],

		[ 'role', 'As a renter|No sign-in needed. Open a private window: Ctrl + Shift + N in Chrome.' ],

		[ 'h2', 'Search and filters' ],
		[ 'step', 'Open https://thirtydayhomes.com/homes/ — one panel holds the search box and the filters, with one Show homes button; below it, how many homes were found.' ],
		[ 'step', 'Put 1000 in Min price and 2500 in Max price, then press Show homes. Only homes in that range are listed, with two labels above them.' ],
		[ 'step', 'Press the small cross on a label to remove that filter, or Clear all to remove them all.' ],
		[ 'step', 'Set Sort by, beside the count, to "Price: low to high". The cheapest home comes first and your filters stay.' ],
		[ 'step', 'Try it: put 90000 in Min price. The page says no homes cost that much and offers to clear the filter. It never shows every home instead.' ],
		[ 'step', 'On a phone, a Filters button sits beside Show homes. Tap it and a panel slides up with its own Show homes at the bottom.' ],

		[ 'h2', 'Move-in dates' ],
		[ 'step', 'Open https://thirtydayhomes.com/ and pick a move-in and a move-out date at least 30 days apart, then press Search. Only the homes free for those dates are listed, each saying "Free for your dates".' ],
		[ 'step', 'Open one of them. Above the calendar it repeats your dates with a tick.' ],
		[ 'step', 'Try it: set the move-out nine days after the move-in. The page explains that stays are 30 days or longer, and your dates stay in the boxes.' ],

		[ 'h2', 'Places, ZIP codes and hospitals' ],
		[ 'step', 'On https://thirtydayhomes.com/homes/ type 15226 in the search box and press Show homes. The nearest homes are listed, closest first, each saying how far it is.' ],
		[ 'step', 'Type Oakland instead. You get the Pittsburgh homes, never Oakland in California.' ],
		[ 'step', 'Choose a hospital in "Near a hospital" and press Show homes. The homes nearest to it come first, with the distance on each.' ],
		[ 'step', 'Change "Within" to 5 miles. Fewer homes are listed; if none are left, the page offers the next distance out.' ],
		[ 'step', 'Press Map, at the right of the count. The homes appear on a map with their prices.' ],
		[ 'step', 'Try it: type abcxyz and press Show homes. The page says it found nothing.' ],

		[ 'h2', 'A home\'s page' ],
		[ 'step', 'Open any home from https://thirtydayhomes.com/homes/.' ],
		[ 'step', 'You see the photos with "Show all photos", the bedrooms, bathrooms and size in one line, "Included" and "House rules", the calendar with taken days crossed out, and "Close to care" with the nearest hospitals.' ],
		[ 'step', 'Scroll down. The price box with the fees, the deposit and "Ask the owner" stays beside you; on a phone, a bar at the bottom shows the rent and the button. Press it and you land on the inquiry form.' ],
		[ 'step', 'The map shows a circle around the neighbourhood. Try it: look for the street address anywhere on the page. It is not there.' ],

		[ 'h2', 'Sending an inquiry' ],
		[ 'step', 'On a test home, fill in "Inquire about this home" with your own email, tick the box and press Send inquiry.' ],
		[ 'step', 'The form turns into a green "Message sent" panel naming the home.' ],
		[ 'step', 'Try it: press Send with the name left empty. The page asks for the name and keeps everything else you typed.' ],

		[ 'role', 'As a landlord|Sign in with a landlord account at https://thirtydayhomes.com/login/' ],

		[ 'h2', 'Your homes' ],
		[ 'step', 'Open https://thirtydayhomes.com/account/?view=listings — each home is a card with its photo, rent, neighbourhood, status in words and one main button.' ],
		[ 'step', 'On a live home press Pause. It disappears for renters and the button becomes Resume. Press Resume and it is live again.' ],
		[ 'step', 'Press Live in the row above the list. Only live homes are shown; press All to see every home again.' ],
		[ 'step', 'Try it: press Delete, then Keep it. Nothing is deleted. On a phone, Pause and Delete are under More.' ],

		[ 'h2', 'Availability' ],
		[ 'step', 'On the same page press Availability on a home. Enter a first and a last day and press Save availability. A message confirms it.' ],
		[ 'step', 'Add a second period that overlaps the first and save. The message says the two were joined into one.' ],
		[ 'step', 'Open the home\'s page. The calendar crosses those days out. Remove the periods again when you are done.' ],

		[ 'h2', 'Editing a home' ],
		[ 'step', 'Press Edit on a live home. Step 1 has "Rent & fees" and "Inquiries"; step 2 has Availability, Utilities and Pets; step 3 has the photos, with arrows to reorder and "Make cover"; step 4 shows the home\'s status, anything worth fixing, and every answer with an Edit link.' ],
		[ 'step', 'Change the rent and press Save and continue. The page says "Saved. Anything you changed here is live now." Change it back the same way.' ],
		[ 'step', 'Change the title and press Save and continue. A page explains that this goes back to your team for review. Press "Go back and discard changes" to keep the old title.' ],

		[ 'h2', 'Inquiries' ],
		[ 'step', 'After sending an inquiry to one of your homes, open https://thirtydayhomes.com/account/?view=inquiries — it is there, marked unread.' ],
		[ 'step', 'Open it to see the renter\'s details. Press Archive; the Archived tab brings it back.' ],
		[ 'step', 'The landlord\'s email inbox also receives the inquiry.' ],

		[ 'h2', 'Text alerts' ],
		[ 'step', 'Open https://thirtydayhomes.com/account/?view=profile and turn on Text message alerts with your mobile number.' ],
		[ 'step', 'Type in the code you receive by text.' ],
		[ 'step', 'Send an inquiry to one of your homes. A text arrives within a minute.' ],

		[ 'h2', 'A new landlord confirms their email' ],
		[ 'step', 'In a private window open https://thirtydayhomes.com/register/ and sign up with an email address you can open.' ],
		[ 'step', 'The dashboard shows a yellow "Confirm your email address" panel.' ],
		[ 'step', 'Click the link in the email from ThirtyDayHomes. The dashboard says "Email confirmed" and the panel is gone.' ],
		[ 'step', 'Try it: click the same link again. It says the address is already confirmed.' ],

		[ 'role', 'As your team|Sign in with your administrator account at https://thirtydayhomes.com/login/' ],

		[ 'h2', 'Approving homes' ],
		[ 'step', 'Open https://thirtydayhomes.com/account/ — the first tile says how many homes are waiting. Press it, or open https://thirtydayhomes.com/account/?view=listings — homes waiting for you are under "Waiting for approval". An email also arrives each time a home is submitted.' ],
		[ 'step', 'On a test home press Request changes, type what should change and press Send to landlord. Try it: an empty note is not accepted.' ],
		[ 'step', 'The landlord sees your note in a pink box on their dashboard and can resubmit.' ],
		[ 'step', 'A home whose address cannot be found on the map cannot be approved, and the page says why.' ],

		[ 'h2', 'Members and payments' ],
		[ 'step', 'Open https://thirtydayhomes.com/account/?view=members — type a name or email in the search box and press Search. Only matching members are listed; "Show all members" brings everyone back.' ],
		[ 'step', 'Open a test landlord who has a live home.' ],
		[ 'step', 'Set Membership status to Payment failed and press Save member. That landlord\'s dashboard now shows a red panel: their homes stay visible for 7 days, with "Update your card".' ],
		[ 'step', 'Set them to Expired. Their home disappears from Find a home, and their dashboard says nothing is deleted.' ],
		[ 'step', 'Set them back to Active. The home is live again, and their dashboard says "Payment received — your home is back online."' ],
		[ 'step', 'Try it: press Delete member. The question appears inside the page. Press Keep them and nothing changes.' ],

		[ 'h2', 'Inquiries, hospitals and settings' ],
		[ 'step', 'Open https://thirtydayhomes.com/account/?view=inquiries — every inquiry, unread ones marked "New", each with its date and whether the landlord\'s email was delivered.' ],
		[ 'step', 'Open https://thirtydayhomes.com/account/?view=facilities, press Add facility and add a hospital with only its address. Its location fills in by itself.' ],
		[ 'step', 'Open https://thirtydayhomes.com/account/?view=listing-setup — Manage opens each menu, and "Save hospital settings" saves how many hospitals each home lists and how far to look.' ],
		[ 'step', 'Edit any page with Elementor and search the left panel for "Search results" or "Nearby hospitals". Drag one in to see real homes or hospitals.' ],

		[ 'h1', 'Text messages' ],
		[ 'note', 'The phone carriers have approved the registration, and text alerts are switched on. We tested the whole flow on the live site with a US phone number: the confirmation code arrived, the number was confirmed, and an inquiry text arrived within seconds, with a link that opens that inquiry. Twilio, the text service, shows both texts as Delivered.' ],
		[ 'p', 'Who gets the text: only the landlord who owns the home, and only after they switch on text alerts and confirm their number. The renter sees "Message sent" on the page. Your team sees every inquiry under Inquiries, marked "Emailed" and "Texted".' ],
		[ 'p', 'To get texts on your own phone, three short steps:' ],
		[ 'step', 'Sign in at https://thirtydayhomes.com/login/ with furnishedhomes26@gmail.com.' ],
		[ 'step', 'Open https://thirtydayhomes.com/account/?view=profile — under "Text message alerts", type your mobile number, tick the box and press Send code.' ],
		[ 'step', 'Type in the 6-digit code from the text and press Verify. From then on, every inquiry to your home sends you a text.' ],

		[ 'h1', 'Still to come in Milestone 2' ],
		[ 'li', 'Switch on text alerts with the three steps above, send one inquiry to Shady Fun in the Sun, and send us a screenshot of the text.' ],
		[ 'li', 'Your walkthrough of the site, and your sign-off.' ],
	],
];
