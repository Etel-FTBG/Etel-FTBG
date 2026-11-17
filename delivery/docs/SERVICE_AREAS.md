# Service areas (city-scoped services)

Each city Etel serves is a centre point plus a radius (Admin > System > Service areas). A person only gets services in the city they are in.

- Detection: `js/etel-area.js` asks the phone for its location, calls `get_service_areas`, shows a city chip, and lets the person pick a city if location is off.
- Maps: the script wraps `google.maps.places.Autocomplete` so every search (Ride, Driver, Courier, Customer, Agent portal) is limited to the city (bounds + strictBounds).
- Server (the part that cannot be bypassed): `api/lib/service_areas.php`.
  - Ride booking, agent booking, admin phone booking: pickup must be in a served city and the drop-off in the same one.
  - Nearby drivers / driver requests: filtered to the city.
  - Delivery items list: `?area=` or `?lat=&lng=` limits to shops in that city; outside every city returns an empty list with `outside_area`.
  - Delivery order: shop and customer must be in the same city. Package orders: pickup and drop in the same city.
  - Courier job list: only jobs in the courier's last known city.
  - GPS delivery fee uses the centre of the city the point is in.
- Safe by default: no coordinates, no active city, or a database fault means the rule is skipped (never blocks everyone). Master switch: "Keep people inside their city".
- Shops without coordinates are matched by their `city` text; add lat/lng to a shop for exact matching.
- Tests: `php delivery/tests/areas/backend_areas.php` (needs the local MariaDB used by the other backend tests).

## Quick zones per city (the address shortcuts)
- The "Quick zones" box on the Ride Book tab lists places a rider taps to set pickup or drop-off without GPS. Each city has its own list.
- Admin > Service areas > a city's **Quick zones** button: add, edit, hide, delete and order the places of that city. A point must be inside the city circle (centre + radius). At most 100 per city.
- Table `service_area_places` (migration 109; the app also creates it by itself). Hawassa's twelve zones are seeded once the first time the table is created; after that the admin owns the list.
- API: public `get_area_places?area=<city code>`; admin `admin_area_places`, `admin_area_place_save`, `admin_area_place_delete` (settings.view / settings.manage).
- Rider app: `js/ride-city-zones.js` (independent add-on tag in ride/index.html) fills the box from the city chosen in etel-area.js. A city with no places shows a short note.
- To open a new city (for example Addis Ababa): add the city here first (name, centre, radius), then add its Quick zones.
- Tests: `php delivery/tests/areas/backend_places.php` (local MariaDB, database `fa_test`).
