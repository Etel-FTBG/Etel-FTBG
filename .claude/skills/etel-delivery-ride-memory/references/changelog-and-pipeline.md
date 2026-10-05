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
