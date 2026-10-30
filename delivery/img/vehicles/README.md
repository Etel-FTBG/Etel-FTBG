# Vehicle pictures (Ride app)

Drop one picture per vehicle type in this folder. The rider app shows them in the vehicle list on the Book screen, and on the map
(nearby drivers, the driver on the way, the driver's own marker in the driver app). A type with no picture keeps the drawn icon, so
you can add them one at a time. Nothing needs to be rebuilt or released: upload the files and reload.

| File          | Vehicle                                   |
|---------------|-------------------------------------------|
| `bajaj.png`   | Bajaj three-wheeler (tuk-tuk)             |
| `qute.png`    | Bajaj Qute (small four-wheeler)           |
| `moto.png`    | Motorcycle                                |
| `car.png`     | Car                                       |
| `ev_car.png`  | Electric vehicle (currently the electric bajaj; optional: uses `car.png` when missing) |
| `minibus.png` | Minibus / van                             |
| `bicycle.png` | Bicycle courier (optional, same rules; shown in the admin, customer and courier screens when the file exists) |
| `on_foot.png` | Courier on foot, e.g. a delivery backpack (optional, same rules) |

Optional: `<name>_left.png` (for example `bajaj_left.png`) is the same vehicle **facing left**. On the map a vehicle that drives west
uses it. Without one the right-facing picture is mirrored, which works but flips any lettering or logo on the vehicle.

Requirements for each picture:

- **PNG with a transparent background** (no white or coloured box around the vehicle).
- **Three-quarter front view from the front-right, the vehicle facing RIGHT.** On the map a vehicle that moves west is mirrored so it
  always faces the way it travels, which only works if every picture faces the same way.
- **Square, about 448 x 448 px** (anything from 256 to 1024), the whole vehicle visible with a little empty margin on every side, centred.
- Under 250 KB each (they load on a phone connection). Keep the same viewing angle and light for all of them so they look like a set.
- No text, plates or people in the picture.
