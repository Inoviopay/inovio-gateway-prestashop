#!/usr/bin/env bash
#
# Build one MP4 per use case from the screenshots that spec captured.
#
# Each frame is shown for a few seconds with a caption bar naming the step, so
# the clip reads as a walkthrough of that use case. Screenshots are full-page
# and wildly different heights, so every frame is scaled to fit a fixed canvas
# and padded rather than stretched.
set -euo pipefail
cd "$(dirname "$0")"

W=1280; H=900; SECS=${SECS:-2.5}
OUT=evidence/video
mkdir -p "$OUT"

command -v ffmpeg >/dev/null || { echo "ffmpeg not found"; exit 1; }

shopt -s nullglob
built=0

for dir in evidence/*/; do
  name=$(basename "$dir")
  [ "$name" = "video" ] && continue
  shots=("$dir"*.png)
  [ ${#shots[@]} -eq 0 ] && continue

  work=$(mktemp -d)
  i=0
  for s in "${shots[@]}"; do
    label=$(basename "$s" .png | sed 's/^[0-9]*-//; s/-/ /g')
    # Scale to fit, pad to canvas, then draw a caption bar along the bottom.
    ffmpeg -y -loglevel error -i "$s" \
      -vf "scale=${W}:${H}:force_original_aspect_ratio=decrease,\
pad=${W}:${H}:(ow-iw)/2:0:color=white,\
drawbox=y=ih-60:w=iw:h=60:color=black@0.85:t=fill,\
drawtext=text='${name}  |  ${label}':fontcolor=white:fontsize=26:x=24:y=h-42" \
      "$work/$(printf '%03d' $i).png"
    i=$((i+1))
  done

  # Hold each frame for SECS; pad the last frame so it doesn't flash by.
  ffmpeg -y -loglevel error -framerate "1/${SECS}" -i "$work/%03d.png" \
    -c:v libx264 -pix_fmt yuv420p -r 25 \
    -vf "tpad=stop_mode=clone:stop_duration=2" \
    "$OUT/${name}.mp4"

  rm -rf "$work"
  echo "  ✓ $OUT/${name}.mp4  (${#shots[@]} frames)"
  built=$((built+1))
done

echo
echo "built $built videos in $OUT"
