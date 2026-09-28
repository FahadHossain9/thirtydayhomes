# Medical facilities — Rob's list

Source: `Pittsburgh_Greensburg_Washington_Hospital_List.docx`, emailed by Rob
on 18 Sep 2026 (the original is kept locally in `rob/`, not in git).
Answers question 3 of the 17 Sep request list, and is the data for tasks
B1 (geocoding), B2 (nearest facilities on the property page) and C3
(search by facility).

Addresses checked against the hospitals' published addresses on 18 Sep
2026. Coordinates are not in the list — B1 fills them by geocoding.

| # | Facility (as it should read on the site) | Street | City | State | ZIP | Area | Rob's priority |
|---|---|---|---|---|---|---|---|
| 1 | UPMC Presbyterian | 200 Lothrop St | Pittsburgh | PA | 15213 | Oakland | yes |
| 2 | UPMC Shadyside | 5230 Centre Ave | Pittsburgh | PA | 15232 | Shadyside | yes |
| 3 | UPMC Mercy | 1400 Locust St | Pittsburgh | PA | 15219 | Uptown / downtown | yes |
| 4 | UPMC Magee-Womens Hospital | 300 Halket St | Pittsburgh | PA | 15213 | Oakland | yes |
| 5 | UPMC Children's Hospital of Pittsburgh | 4401 Penn Ave | Pittsburgh | PA | 15224 | Lawrenceville | yes |
| 6 | Allegheny General Hospital | 320 E North Ave | Pittsburgh | PA | 15212 | North Side | yes |
| 7 | West Penn Hospital | 4800 Friendship Ave | Pittsburgh | PA | 15224 | Bloomfield | yes |
| 8 | UPMC St. Margaret | 815 Freeport Rd | Pittsburgh | PA | 15215 | Aspinwall area | no |
| 9 | UPMC Passavant – McCandless | 9100 Babcock Blvd | Pittsburgh | PA | 15237 | North Hills | no |
| 10 | Westmoreland Hospital (Independence Health System) | 532 W Pittsburgh St | Greensburg | PA | 15601 | Greensburg | yes |
| 11 | UPMC Washington | 155 Wilson Ave | Washington | PA | 15301 | Washington | yes |

## What this means for the build

- **Five are already in the local sample data** (Presbyterian, Shadyside,
  Children's, Allegheny General, Mercy). Six are new: Magee-Womens, West
  Penn, St. Margaret, Passavant, Westmoreland, UPMC Washington.
- **Two new cities.** Greensburg and Washington are outside Pittsburgh.
  The site's city list has only Pittsburgh today, so Greensburg and
  Washington need adding as cities before homes there can be listed.
- **All 11 go on the site** — Rob, 18 Sep 2026: "you can include all the
  hospital/medical locations" (he had marked 9 as priority; St. Margaret
  and Passavant are included too).
- Question 9 (sent 17 Sep) proposed showing the 3 nearest within 15 miles;
  with Greensburg and Washington homes, the nearest may be the only one in
  range, which B2's "one facility" case already covers.
