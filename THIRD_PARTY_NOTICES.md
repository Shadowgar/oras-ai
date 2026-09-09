# Third-Party Notices

## tuxonice/suncalc-php

ORAS AI declares `tuxonice/suncalc-php` version `1.0.1`, source commit
`8b8ca60f8af20d38c00121615beab999ca5dcd55`, through Composer for local
Sun and Moon calculations.

- Source: https://github.com/tuxonice/suncalc-php
- License: GPL-2.0-or-later
- Copyright and attribution remain with the upstream authors and contributors.

The dependency's source and license are installed from the pinned Composer lock
for development. Composer's `vendor/` directory is not bundled or tracked in
this repository; a future deployable
package may install production dependencies from that lock.

## OpenNGC

The generated files in `data/openngc/` are adapted from the OpenNGC `NGC.csv`
and `addendum.csv` catalog data at exact source commit
`da90466031b0372c896588b85be6016c617e205b`.

- Project: OpenNGC by Mattia Verga and contributors
- Source: https://github.com/mattiaverga/OpenNGC
- License: Creative Commons Attribution-ShareAlike 4.0 International
  (CC BY-SA 4.0)
- Source files were transformed into deterministic sharded PHP lookup data;
  unrelated catalog fields and repository assets were omitted.
- Exact source checksums and generated record counts are recorded in
  `data/openngc/manifest.php`.
- The full applicable license text is preserved at
  `data/openngc/CC-BY-SA-4.0.txt`.

The generated/adapted lookup data remains available under CC BY-SA 4.0; it is
not represented as original GPL plugin code.
