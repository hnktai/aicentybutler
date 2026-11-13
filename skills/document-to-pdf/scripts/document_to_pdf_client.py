#!/usr/bin/env python3
"""Workspace-safe client for the isolated document renderer service."""

from __future__ import annotations

import argparse
import base64
import json
import os
from pathlib import Path
import sys
import tempfile
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

from pypdf import PdfReader


MAX_TEXT_BYTES = 1 * 1024 * 1024
MAX_WORD_BYTES = 20 * 1024 * 1024
MAX_URL_CHARS = 2048
MAX_OUTPUT_BYTES = 24 * 1024 * 1024
RENDERER_URL = os.environ.get(
    "AICENTY_DOCUMENT_RENDERER_URL", "http://aicenty-document-renderer:8787"
).rstrip("/")
WEB_RENDERER_URL = os.environ.get(
    "AICENTY_WEB_PDF_RENDERER_URL", "http://aicenty-web-pdf-renderer:8787"
).rstrip("/")


class ClientError(Exception):
    """A customer-safe conversion failure."""


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Create a PDF from text, Word, or a URL.")
    sub = parser.add_subparsers(dest="mode", required=True)

    text = sub.add_parser("text", help="Render plain text or Markdown-like content")
    text.add_argument("--input", required=True)
    text.add_argument("--output", required=True)
    text.add_argument("--title", default="Tài liệu")
    text.add_argument("--source-url", default="")

    word = sub.add_parser("word", help="Convert a Word document with LibreOffice")
    word.add_argument("--input", required=True)
    word.add_argument("--output", required=True)

    web = sub.add_parser("web", help="Print a public web page")
    web.add_argument("--url", required=True)
    web.add_argument("--output", required=True)
    return parser.parse_args()


def is_within(path: Path, root: Path) -> bool:
    return path == root or root in path.parents


def resolve_workspace_path(raw: str, workspace: Path, *, output: bool) -> Path:
    candidate = Path(raw)
    if not candidate.is_absolute():
        candidate = workspace / candidate
    if output:
        if candidate.suffix.lower() != ".pdf":
            raise ClientError("output filename must end in .pdf")
        parent = candidate.parent.resolve(strict=True)
        candidate = parent / candidate.name
        if not is_within(candidate, workspace):
            raise ClientError("output is outside the current workspace")
        if candidate.relative_to(workspace).parts[:1] == (".uploads",):
            raise ClientError("output must not be written inside .uploads")
        if candidate.exists() and candidate.is_symlink():
            raise ClientError("output path must not be a symlink")
        return candidate

    try:
        resolved = candidate.resolve(strict=True)
    except OSError as exc:
        raise ClientError(f"input file not found: {raw}") from exc
    if not is_within(resolved, workspace) or not resolved.is_file():
        raise ClientError("input must be a regular file inside the current workspace")
    return resolved


def read_bounded(path: Path, limit: int) -> bytes:
    size = path.stat().st_size
    if size <= 0:
        raise ClientError(f"input file is empty: {path.name}")
    if size > limit:
        raise ClientError(f"input file exceeds the limit: {path.name}")
    return path.read_bytes()


def build_request(args: argparse.Namespace, workspace: Path) -> tuple[dict, Path]:
    output = resolve_workspace_path(args.output, workspace, output=True)
    if args.mode == "text":
        input_path = resolve_workspace_path(args.input, workspace, output=False)
        try:
            content = read_bounded(input_path, MAX_TEXT_BYTES).decode("utf-8")
        except UnicodeDecodeError as exc:
            raise ClientError("text input must be UTF-8") from exc
        title = str(args.title or "Tài liệu").strip()[:240]
        source_url = str(args.source_url or "").strip()
        if len(source_url) > MAX_URL_CHARS:
            raise ClientError("source URL is too long")
        return {
            "mode": "text",
            "title": title or "Tài liệu",
            "content": content,
            "source_url": source_url,
        }, output

    if args.mode == "word":
        input_path = resolve_workspace_path(args.input, workspace, output=False)
        suffix = input_path.suffix.lower()
        if suffix not in {".doc", ".docx"}:
            raise ClientError("Word mode accepts only .doc or .docx")
        return {
            "mode": "word",
            "filename": input_path.name,
            "content_base64": base64.b64encode(read_bounded(input_path, MAX_WORD_BYTES)).decode("ascii"),
        }, output

    url = str(args.url).strip()
    if len(url) > MAX_URL_CHARS or not url.startswith(("http://", "https://")):
        raise ClientError("web mode accepts an http or https URL up to 2048 characters")
    return {"mode": "web", "url": url}, output


def call_renderer(payload: dict) -> bytes:
    renderer_url = WEB_RENDERER_URL if payload.get("mode") == "web" else RENDERER_URL
    body = json.dumps(payload, ensure_ascii=True, separators=(",", ":")).encode("utf-8")
    request = Request(
        f"{renderer_url}/render",
        data=body,
        headers={"Content-Type": "application/json", "Accept": "application/pdf"},
        method="POST",
    )
    try:
        with urlopen(request, timeout=130) as response:
            data = response.read(MAX_OUTPUT_BYTES + 1)
            if response.status != 200:
                raise ClientError(f"renderer returned HTTP {response.status}")
            return data
    except HTTPError as exc:
        detail = exc.read(4096).decode("utf-8", "replace")
        raise ClientError(f"renderer rejected the request: {detail[:500]}") from exc
    except URLError as exc:
        raise ClientError(f"document renderer unavailable: {exc.reason}") from exc
    except TimeoutError as exc:
        raise ClientError("document renderer timed out") from exc


def write_and_validate(pdf: bytes, output: Path) -> tuple[int, int]:
    if len(pdf) <= 0 or len(pdf) > MAX_OUTPUT_BYTES or not pdf.startswith(b"%PDF-"):
        raise ClientError("renderer returned an invalid or oversized PDF")
    with tempfile.NamedTemporaryFile(prefix=f".{output.stem}-", suffix=".pdf", dir=output.parent, delete=False) as tmp:
        temp_path = Path(tmp.name)
        tmp.write(pdf)
    try:
        reader = PdfReader(str(temp_path))
        pages = len(reader.pages)
        if pages < 1:
            raise ClientError("PDF validation found no pages")
        os.replace(temp_path, output)
        return pages, len(pdf)
    finally:
        temp_path.unlink(missing_ok=True)


def main() -> int:
    try:
        args = parse_args()
        workspace = Path.cwd().resolve(strict=True)
        payload, output = build_request(args, workspace)
        page_count, byte_size = write_and_validate(call_renderer(payload), output)
        print(json.dumps({
            "status": "ok",
            "mode": args.mode,
            "output": str(output),
            "page_count": page_count,
            "byte_size": byte_size,
        }, ensure_ascii=True, separators=(",", ":")))
        return 0
    except ClientError as exc:
        print(f"document-to-pdf: {exc}", file=sys.stderr)
        return 2
    except Exception as exc:
        print(f"document-to-pdf: unexpected failure: {exc}", file=sys.stderr)
        return 3


if __name__ == "__main__":
    raise SystemExit(main())
