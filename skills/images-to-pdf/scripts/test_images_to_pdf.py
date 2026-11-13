from __future__ import annotations

import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

from PIL import Image
from pypdf import PdfReader


SCRIPT = Path(__file__).with_name("images_to_pdf.py")


class ImagesToPdfTests(unittest.TestCase):
    def run_script(self, workspace: Path, *args: str) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(SCRIPT), *args],
            cwd=workspace,
            text=True,
            capture_output=True,
            check=False,
        )

    def test_mixed_images_create_ordered_portrait_and_landscape_pages(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            workspace = Path(tmp)
            portrait = workspace / "01-portrait.jpg"
            landscape = workspace / "02-landscape.png"
            Image.new("RGB", (300, 600), "red").save(portrait, quality=90)
            Image.new("RGBA", (800, 400), (0, 128, 255, 120)).save(landscape)

            result = self.run_script(
                workspace,
                "--output",
                "family.pdf",
                portrait.name,
                landscape.name,
            )

            self.assertEqual(result.returncode, 0, result.stderr)
            payload = json.loads(result.stdout)
            self.assertEqual(payload["page_count"], 2)
            self.assertLessEqual(payload["byte_size"], 24 * 1024 * 1024)
            pdf = PdfReader(workspace / "family.pdf")
            self.assertEqual(len(pdf.pages), 2)
            self.assertGreater(float(pdf.pages[0].mediabox.height), float(pdf.pages[0].mediabox.width))
            self.assertGreater(float(pdf.pages[1].mediabox.width), float(pdf.pages[1].mediabox.height))

    def test_exif_rotation_controls_page_orientation(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            workspace = Path(tmp)
            rotated = workspace / "rotated.jpg"
            exif = Image.Exif()
            exif[274] = 6
            Image.new("RGB", (600, 300), "green").save(rotated, exif=exif)

            result = self.run_script(workspace, "--output", "rotated.pdf", rotated.name)

            self.assertEqual(result.returncode, 0, result.stderr)
            page = PdfReader(workspace / "rotated.pdf").pages[0]
            self.assertGreater(float(page.mediabox.height), float(page.mediabox.width))

    def test_rejects_corrupt_input_without_partial_pdf(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            workspace = Path(tmp)
            corrupt = workspace / "broken.jpg"
            corrupt.write_bytes(b"not an image")

            result = self.run_script(workspace, "--output", "broken.pdf", corrupt.name)

            self.assertEqual(result.returncode, 2)
            self.assertIn("invalid or corrupt image", result.stderr)
            self.assertFalse((workspace / "broken.pdf").exists())

    def test_rejects_input_outside_workspace(self) -> None:
        with tempfile.TemporaryDirectory() as parent_tmp:
            parent = Path(parent_tmp)
            workspace = parent / "workspace"
            workspace.mkdir()
            outside = parent / "outside.jpg"
            Image.new("RGB", (100, 100), "blue").save(outside)

            result = self.run_script(
                workspace,
                "--output",
                "outside.pdf",
                str(outside),
            )

            self.assertEqual(result.returncode, 2)
            self.assertIn("outside the current workspace", result.stderr)
            self.assertFalse((workspace / "outside.pdf").exists())

    def test_rejects_output_that_is_also_an_input(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            workspace = Path(tmp)
            disguised = workspace / "image.pdf"
            Image.new("RGB", (100, 100), "purple").save(disguised, format="PNG")

            result = self.run_script(
                workspace,
                "--output",
                disguised.name,
                disguised.name,
            )

            self.assertEqual(result.returncode, 2)
            self.assertIn("different from every input", result.stderr)
            with Image.open(disguised) as preserved:
                self.assertEqual(preserved.format, "PNG")


if __name__ == "__main__":
    unittest.main()
