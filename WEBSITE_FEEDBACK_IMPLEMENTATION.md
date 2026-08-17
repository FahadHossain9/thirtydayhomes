# ThirtyDayHomes website feedback implementation

Source: `Thirtydayhomes website comments 8162026.docx` (reviewed with its embedded homepage screenshot).

## Implemented changes

1. Added **About** before **Find a home** in the public navigation.
2. Added a renter FAQ entry in navigation and a dedicated FAQ section under that page.
3. Increased the header brand mark and “ThirtyDayHomes” wordmark by approximately 30%.
4. Replaced stay length on the homepage with required start and end dates; search remains disabled until both are entered.
5. Added property-type filtering for Any, House, Duplex, and Apartment.
6. Location/ZIP searches default to closest-first ordering.
7. Added list and map result views with approximate property markers.
8. Added automatic multi-listing discounts: 10% for two listings and 15% for three.
9. Added application fee, pet fee, and refundable deposit details to listings.
10. Added listing availability with available-from and unavailable date ranges.
11. Neighborhood remains a landlord-provided value because automatic ZIP geocoding requires a production mapping/geocoding provider. Search includes both ZIP and neighborhood.
12. Expanded property details and amenities, including parking, square footage, total rooms, backyard type, safety items, laundry, kitchen, workspace, and utilities.
13. Added visible rules-and-regulations language and a reminder at the inquiry action. Production should store the accepted rules version with each inquiry.
14. Added a click-to-call urgent-support phone number to Contact.
15. Recreated the attached screenshot’s homepage audience section for medical professionals, corporate travelers, construction crews, and student housing.

## Production integration notes

- Replace the demo map with the selected map/geocoding provider and calculate road or geographic distance from the submitted ZIP.
- Persist listing fees, availability blocks, amenities, rules acceptance, and multi-listing discounts in the backend.
- Replace the demo emergency number `(412) 555-0184` with the approved support number before launch.
