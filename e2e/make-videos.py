#!/usr/bin/env python3
"""
Build one MP4 per use case from the screenshots that spec captured.

Each frame is scaled to fit a fixed canvas (the full-page shots vary wildly in
height) and gets a caption bar naming the case and step, so the clip reads as a
walkthrough. Captions are drawn with Pillow because the local ffmpeg build has
no drawtext filter.
"""
import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

W, H, BAR = 1280, 900, 64
SECS = float(os.environ.get("SECS", "2.5"))
ROOT = Path(__file__).parent
EVIDENCE = ROOT / "evidence"
OUT = EVIDENCE / "video"


def font(size):
    for p in ("/System/Library/Fonts/Helvetica.ttc",
              "/System/Library/Fonts/Supplemental/Arial.ttf"):
        if Path(p).exists():
            try:
                return ImageFont.truetype(p, size)
            except OSError:
                pass
    return ImageFont.load_default()


def frame(src, case, step, dest):
    img = Image.open(src).convert("RGB")
    avail = H - BAR
    scale = min(W / img.width, avail / img.height)
    img = img.resize((max(1, int(img.width * scale)),
                      max(1, int(img.height * scale))), Image.LANCZOS)

    canvas = Image.new("RGB", (W, H), "white")
    canvas.paste(img, ((W - img.width) // 2, 0))

    d = ImageDraw.Draw(canvas)
    d.rectangle([0, H - BAR, W, H], fill=(17, 17, 17))
    d.text((24, H - BAR + 12), case, font=font(20), fill=(150, 190, 255))
    d.text((24, H - BAR + 36), step, font=font(22), fill="white")
    canvas.save(dest)


def main():
    if not shutil.which("ffmpeg"):
        sys.exit("ffmpeg not found")

    OUT.mkdir(parents=True, exist_ok=True)
    built = []

    for case_dir in sorted(EVIDENCE.iterdir()):
        if not case_dir.is_dir() or case_dir.name == "video":
            continue
        shots = sorted(case_dir.glob("*.png"))
        if not shots:
            continue

        case = case_dir.name
        with tempfile.TemporaryDirectory() as work:
            for i, s in enumerate(shots):
                step = re.sub(r"^\d+-", "", s.stem).replace("-", " ")
                frame(s, case, step, Path(work) / f"{i:03d}.png")

            mp4 = OUT / f"{case}.mp4"
            subprocess.run([
                "ffmpeg", "-y", "-loglevel", "error",
                "-framerate", f"1/{SECS}", "-i", f"{work}/%03d.png",
                "-c:v", "libx264", "-pix_fmt", "yuv420p", "-r", "25",
                "-vf", "tpad=stop_mode=clone:stop_duration=2",
                str(mp4),
            ], check=True)

        built.append((case, len(shots), mp4))
        print(f"  ✓ {mp4.relative_to(ROOT)}  ({len(shots)} frames)")

    print(f"\nbuilt {len(built)} videos in {OUT.relative_to(ROOT)}")


if __name__ == "__main__":
    main()
