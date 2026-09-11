# Event Horizon robot centerpiece

Replace the central company insignia in Event Horizon and Event Horizon Blue with the supplied ShoeGPT robot. Keep the masthead branding and all other design families intact. The robot sits inside the existing singularity, with blue eye lighting, orbital accents, and a soft lower fade. The user's follow-up asks for a little animation to draw the eye: add a slow ocular glow pulse and restrained chest-emblem light sweep, alongside the existing gentle drift.

## Asset provenance

The current pages use the [armor-integrated insignia](ROBOT-ARMOR.md), `public/brand/shoegpt-robot-armor.png`. The original extraction described below is preserved unchanged.

- User reference: `/Users/shoemoney/Desktop/shoegpt-bg.jpg` (unchanged).
- Project asset: `public/brand/shoegpt-robot.png`, 1397 × 1126 PNG with alpha.
- Editing mode: built-in image-generation tool, background extraction. The tool returned an opaque checkerboard on both attempts; connected background regions were subsequently converted to transparent alpha with ImageMagick. The enclosed white chest emblem remains opaque. This is a generated cutout, not a claim of pixel-identical extraction.
- The page applies its bottom fade and surrounding effects in CSS rather than painting them into the asset.

Final image-tool prompt:

> Remove the background from this robot image. The previous result incorrectly painted an opaque checkerboard. Deliver actual transparent pixels in the PNG alpha channel outside the robot silhouette, including the gaps between arms and body. No painted checkerboard, no white background, no black background. Preserve the robot unchanged: silver frontal head and torso, both arms, blue eye, ShoeMoney chest shield. This must be a production transparent cutout PNG asset, not a visual illustration of transparency.

## Verification

Computer-use review covered the original and blue Horizon editions at desktop and phone widths, with additional 320 px compact and 820 px tablet checks. The transparent robot blends into both particle palettes and preserves readable captions and telemetry without document overflow. Its alpha channel is present: sampled outer corners and the arm/body gap are transparent, while the chest logo remains opaque.

Verified the existing effects toggle stops decorative movement, keyboard pair inspection opens the actual details panel, Escape restores focus, and focused market graphics replace the robot without competing for attention. The intentional `renderer=2d` fallback displays the robot and markets with no canvas. The production build passes; the existing large-chunk advisory remains.

The final follow-up adds a 4.8-second eye pulse and an 11.8-second chest-emblem sheen. Browser sampling confirmed the eye opacity changes over time; pausing effects sets the eye, halo, sheen, and drift animations to `none`. Both WebGL and CSS fallback modes retain the robot. Original-to-blue navigation retains one renderer, and a Supernova regression check retains its original centerpiece with no robot. Browser warning/error logs were empty. The two gallery captures were refreshed from the final build.

System reduced-motion settings and physical GPU loss were not changed during this review; the component includes media-query handling, visibility/intersection pauses, and cleanup. Previews use simulated data.
