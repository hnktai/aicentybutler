#!/usr/bin/env python3
"""Safely combine workspace images into a bounded A4 PDF."""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import sys
import tempfile
import warnings

from PIL import Image, ImageOps
from pypdf import PdfReader


MAX_IMAGES = 30
MAX_INPUT_BYTES = 25 * 1024 * 1024
MAX_TOTAL_INPUT_BYTES = 250 * 1024 * 1024
MAX_OUTPUT_BYTES = 24 * 1024 * 1024
MAX_IMAGE_PIXELS = 50_000_000
MARGIN_POINTS = 24
SUPPORTED_FORMATS = {"JPEG", "PNG", "WEBP"}
RENDER_PROFILES = ((150, 82), (130, 74), (110, 66), (96, 58))

Image.MAX_IMAGE_PIXELS = MAX_IMAGE_PIXELS
warnings.simplefilter("error", Image.DecompressionBombWarning)


class ConversionError(Exception):
    """A customer-safe conversion failure."""


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Combine JPG, PNG, or WebP images into one bounded PDF."
    )
    parser.add_argument("images", nargs="+", help="Input images in page order")
    parser.add_argument("--output", required=True, help="Output PDF path")
    return parser.parse_args()


def is_within(path: Path, root: Path) -> bool:
    return path == root or root in path.parents


def resolve_inputs(raw_paths: list[str], workspace: Path) -> list[Path]:
    if len(raw_paths) > MAX_IMAGES:
        raise ConversionError(f"too many images: {len(raw_paths)} (maximum {MAX_IMAGES})")

    resolved: list[Path] = []
    seen: set[Path] = set()
    total_bytes = 0
    for raw in raw_paths:
        candidate = Path(raw)
        if not candidate.is_absolute():
            candidate = workspace / candidate
        try:
            candidate = candidate.resolve(strict=True)
        except OSError as exc:
            raise ConversionError(f"input file not found: {raw}") from exc
        if not is_within(candidate, workspace):
            raise ConversionError(f"input is outside the current workspace: {raw}")
        if not candidate.is_file():
            raise ConversionError(f"input is not a regular file: {raw}")
        if candidate in seen:
            raise ConversionError(f"duplicate input image: {raw}")

        size = candidate.stat().st_size
        if size <= 0:
            raise ConversionError(f"input image is empty: {raw}")
        if size > MAX_INPUT_BYTES:
            raise ConversionError(f"input image exceeds 25 MiB: {raw}")
        total_bytes += size
        if total_bytes > MAX_TOTAL_INPUT_BYTES:
            raise ConversionError("combined input exceeds 250 MiB")

        seen.add(candidate)
        resolved.append(candidate)
    return resolved


def resolve_output(raw_path: str, workspace: Path) -> Path:
    output = Path(raw_path)
    if not output.is_absolute():
        output = workspace / output
    if output.suffix.lower() != ".pdf":
        raise ConversionError("output filename must end in .pdf")

    parent = output.parent.resolve(strict=True)
    output = parent / output.name
    if not is_within(output, workspace):
        raise ConversionError("output is outside the current workspace")
    relative_parts = output.relative_to(workspace).parts
    if relative_parts and relative_parts[0] == ".uploads":
        raise ConversionError("output must not be written inside .uploads")
    if output.exists() and output.is_symlink():
        raise ConversionError("output path must not be a symlink")
    return output


def verify_image(path: Path) -> None:
    try:
        with Image.open(path) as image:
            image.verify()
            if image.format not in SUPPORTED_FORMATS:
                raise ConversionError(
                    f"unsupported image format for {path.name}: {image.format or 'unknown'}"
                )
    except ConversionError:
        raise
    except Exception as exc:
        raise ConversionError(f"invalid or corrupt image: {path.name}") from exc


def flatten_to_rgb(image: Image.Image) -> Image.Image:
    if image.mode == "RGB":
        return image.copy()
    if image.mode in {"RGBA", "LA"} or "transparency" in image.info:
        rgba = image.convert("RGBA")
        background = Image.new("RGBA", rgba.size, "white")
        background.alpha_composite(rgba)
        return background.convert("RGB")
    return image.convert("RGB")


def make_page(path: Path, dpi: int) -> Image.Image:
    try:
        with Image.open(path) as source:
            source.seek(0)
            normalized = ImageOps.exif_transpose(source)
            rgb = flatten_to_rgb(normalized)
    except Exception as exc:
        raise ConversionError(f"failed to decode image: {path.name}") from exc

    portrait = rgb.height >= rgb.width
    a4_inches = (8.2677, 11.6929) if portrait else (11.6929, 8.2677)
    page_size = tuple(int(round(value * dpi)) for value in a4_inches)
    margin = max(1, int(round(MARGIN_POINTS * dpi / 72)))
    available = (page_size[0] - 2 * margin, page_size[1] - 2 * margin)
    rgb.thumbnail(available, Image.Resampling.LANCZOS)

    page = Image.new("RGB", page_size, "white")
    offset = ((page_size[0] - rgb.width) // 2, (page_size[1] - rgb.height) // 2)
    page.paste(rgb, offset)
    rgb.close()
    return page


def write_pdf(inputs: list[Path], output: Path) -> tuple[int, int]:
    for path in inputs:
        verify_image(path)

    last_size = 0
    for dpi, quality in RENDER_PROFILES:
        pages: list[Image.Image] = []
        temp_path: Path | None = None
        try:
            pages = [make_page(path, dpi) for path in inputs]
            with tempfile.NamedTemporaryFile(
                prefix=f".{output.stem}-", suffix=".pdf", dir=output.parent, delete=False
            ) as temp_file:
                temp_path = Path(temp_file.name)

            pages[0].save(
                temp_path,
                "PDF",
                save_all=True,
                append_images=pages[1:],
                resolution=dpi,
                quality=quality,
                optimize=True,
                title=output.stem,
            )
            last_size = temp_path.stat().st_size
            if last_size > MAX_OUTPUT_BYTES:
                temp_path.unlink(missing_ok=True)
                temp_path = None
                continue

            reader = PdfReader(str(temp_path))
            if len(reader.pages) != len(inputs):
                raise ConversionError(
                    f"PDF validation failed: expected {len(inputs)} pages, got {len(reader.pages)}"
                )
            with temp_path.open("rb") as result_file:
                if result_file.read(5) != b"%PDF-":
                    raise ConversionError("PDF validation failed: invalid file signature")

            os.replace(temp_path, output)
            temp_path = None
            return len(inputs), last_size
        finally:
            for page in pages:
                page.close()
            if temp_path is not None:
                temp_path.unlink(missing_ok=True)

    raise ConversionError(
        f"PDF remains larger than 24 MiB after safe compression ({last_size} bytes)"
    )


def main() -> int:
    try:
        args = parse_args()
        workspace = Path.cwd().resolve(strict=True)
        inputs = resolve_inputs(args.images, workspace)
        output = resolve_output(args.output, workspace)
        if output in inputs:
            raise ConversionError("output path must be different from every input image")
        page_count, byte_size = write_pdf(inputs, output)
        print(
            json.dumps(
                {
                    "status": "ok",
                    "output": str(output),
                    "page_count": page_count,
                    "byte_size": byte_size,
                },
                ensure_ascii=True,
                separators=(",", ":"),
            )
        )
        return 0
    except ConversionError as exc:
        print(f"images-to-pdf: {exc}", file=sys.stderr)
        return 2
    except Exception as exc:
        print(f"images-to-pdf: unexpected failure: {exc}", file=sys.stderr)
        return 3


if __name__ == "__main__":
    raise SystemExit(main())
