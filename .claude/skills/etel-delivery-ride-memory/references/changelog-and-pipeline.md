# Pipelines and recipes (sandbox is ephemeral - /tmp is lost between sessions)

## Sandbox facts
- Outbound goes through an agent proxy. Reachable: npm registry, fonts.googleapis.com /
  fonts.gstatic.com (curl). Blocked: cdnjs/jsdelivr/unpkg, upload.higgsfield.ai, Higgsfield
  CloudFront. three.js came from the npm tarball (r149).
- Chromium: `/opt/pw-browsers/chromium`, launch args `--no-sandbox`; WebGL via SwiftShader
  (`--use-gl=angle --use-angle=swiftshader --enable-unsafe-swiftshader`). playwright-core is
  in the session scratchpad node_modules.
- ImageMagick (`convert`, `montage`), ffmpeg available. No PIL / numpy.
- ImageMagick security policy blocks `-draw @file`; pass `-draw` strings directly.

## Rebuild recipes
- Circular logos: `convert LOGO -resize 600x600^ -gravity center -extent 600x600
  ( -size 600x600 xc:none -fill white -draw "circle 300,300 300,4" ) -alpha set -compose DstIn -composite out.png`
- Office 3D: a Python script writes a self-contained HTML (three.js inlined + logos as data
  URIs, 15 camera views with hide-walls lists); a Playwright script calls
  `window.renderView(i)` and screenshots the canvas (1600x1000). Exposure 0.62, gold LED
  = MeshBasicMaterial 0xffcf5a + warm point lights.
- Logo-on-desk: measure the 4 corners of the SERVICE DESK sign per view (grid overlay crop),
  sign aspect ~3.3:1, homography plane->image, strip image of 6 logos (diameter 0.8, gap
  0.18 sign-heights, margin 0.3), distort and composite. The NW view needs an affine fit.
- Videos: images -> `zoompan` clips with fades -> concat (`-c copy`); captions in gold
  DejaVu-Sans-Bold along a bottom gradient. Coming Soon video: HTML timeline with
  `window.setT(t)` and per-frame screenshots at 25 fps, then ffmpeg.

## Higgsfield job IDs worth knowing
- Base office render (gpt_image_2): 1401a664-616f-4bde-917f-fe1a6583f1a0 (reference for views)
- Views: NE a03c298f..., NW 79927918..., SE 00af673f..., SW 798c8067..., window c3e8240e...,
  overview 66b2d32d...

## Added 2026-10-09
- Transparent logo: `convert src.png -fx "1-min(min(r,g),b)" -level 0,24% mask.png`, then
  `convert src.png mask.png -channel RGB -fx "(u-1+v)/max(v,0.004)" +channel -clamp tmp.png` and
  `-compose CopyOpacity` with the mask.
- Navy 3D scene (`office3d_v4.py`): walls 0x1a3068, back wall 0x0a1a45, slat texture #0d2150/#1a3a82,
  floor #0b1636 with #223d78 grid, counter ExtrudeGeometry curved shape (quadratic front),
  logos placed along the curve with frontZ(x)/frontAng(x), exposure 0.72, hemisphere 0.26.
- Coming Soon navy: same HTML/Playwright pipeline; background radial #12337a -> #030818,
  logo `etel.png` with a blue drop-shadow glow, gold text gradient #ffe98a/#f8c01a/#d99a06.
- Pinterest: not reachable from the sandbox; Windsor.ai `get_data` on `pinterest_organic` only
  returns board names/counts (pins come back empty); Windsor trial plan blocks `force_refresh`.
