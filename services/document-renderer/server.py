#!/usr/bin/env python3
"""Isolated document renderer for text, Word, and public web PDFs."""

from __future__ import annotations

import html
import ipaddress
import json
import os
from pathlib import Path
import re
import select
import shutil
import signal
import socket
import socketserver
import subprocess
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit

from pypdf import PdfReader
from reportlab.lib.enums import TA_LEFT
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.platypus import KeepTogether, Paragraph, SimpleDocTemplate


MAX_REQUEST_BYTES = 32 * 1024 * 1024
MAX_TEXT_BYTES = 1 * 1024 * 1024
MAX_WORD_BYTES = 20 * 1024 * 1024
MAX_OUTPUT_BYTES = 24 * 1024 * 1024
MAX_URL_CHARS = 2048
MAX_PAGES = 1000
RENDER_TIMEOUT = 100
RENDER_SEMAPHORE = threading.BoundedSemaphore(2)
FONT_LOCK = threading.Lock()
FONTS_READY = False
ALLOWED_MODES = {
    item.strip()
    for item in os.environ.get("ALLOWED_MODES", "text,word,web").split(",")
    if item.strip()
}


class RenderError(Exception):
    """A renderer failure that is safe to expose to the caller."""


def error_message(message: str) -> bytes:
    return json.dumps({"status": "error", "error": message}, ensure_ascii=True).encode("utf-8")


def public_ip(address: str) -> bool:
    ip = ipaddress.ip_address(address)
    return not (ip.is_private or ip.is_loopback or ip.is_link_local or ip.is_multicast or ip.is_reserved or ip.is_unspecified)


def resolve_public(host: str, port: int) -> str:
    if not host or host.lower() in {"localhost", "localhost.localdomain"}:
        raise RenderError("private or loopback host is not allowed")
    try:
        ipaddress.ip_address(host)
        addresses = [host]
    except ValueError:
        try:
            addresses = sorted({item[4][0] for item in socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)})
        except OSError as exc:
            raise RenderError("web host could not be resolved") from exc
    if not addresses or any(not public_ip(address) for address in addresses):
        raise RenderError("web host resolves to a private or reserved address")
    return addresses[0]


def validate_web_url(raw_url: str) -> tuple[str, int]:
    if len(raw_url) > MAX_URL_CHARS:
        raise RenderError("URL is too long")
    parsed = urlsplit(raw_url)
    if parsed.scheme not in {"http", "https"} or not parsed.hostname or parsed.username or parsed.password:
        raise RenderError("only public http/https URLs without credentials are allowed")
    port = parsed.port or (443 if parsed.scheme == "https" else 80)
    if port not in {80, 443}:
        raise RenderError("only ports 80 and 443 are allowed")
    return resolve_public(parsed.hostname, port), port


def find_font(pattern: str) -> str:
    candidates = list(Path("/usr/share/fonts").rglob(pattern)) if Path("/usr/share/fonts").exists() else []
    if candidates:
        return str(candidates[0])
    raise RenderError(f"required font is missing: {pattern}")


def ensure_fonts() -> None:
    global FONTS_READY
    if FONTS_READY:
        return
    with FONT_LOCK:
        if FONTS_READY:
            return
        regular = find_font("NotoSans-Regular.ttf")
        bold = find_font("NotoSans-Bold.ttf")
        pdfmetrics.registerFont(TTFont("AicentyNoto", regular))
        pdfmetrics.registerFont(TTFont("AicentyNotoBold", bold))
        FONTS_READY = True


def inline_text(value: str) -> str:
    value = re.sub(r"!\[([^\]]*)\]\([^)]+\)", lambda match: f"[Hình: {match.group(1)}]" if match.group(1) else "", value)
    value = re.sub(r"\[([^\]]+)\]\([^)]+\)", r"\1", value)
    value = value.replace("`", "")
    escaped = html.escape(value, quote=False)
    escaped = re.sub(r"\*\*(.+?)\*\*", r"<b>\1</b>", escaped)
    escaped = re.sub(r"__(.+?)__", r"<b>\1</b>", escaped)
    return escaped


def text_story(content: str, title: str, source_url: str) -> list:
    if len(content.encode("utf-8")) > MAX_TEXT_BYTES:
        raise RenderError("text input exceeds 1 MiB")
    ensure_fonts()
    styles = getSampleStyleSheet()
    title_style = ParagraphStyle("AicentyTitle", parent=styles["Title"], fontName="AicentyNotoBold", fontSize=20, leading=25, textColor="#111827", alignment=TA_LEFT, spaceAfter=10)
    h1 = ParagraphStyle("AicentyH1", parent=styles["Heading1"], fontName="AicentyNotoBold", fontSize=15, leading=20, textColor="#111827", spaceBefore=12, spaceAfter=5, keepWithNext=True)
    h2 = ParagraphStyle("AicentyH2", parent=styles["Heading2"], fontName="AicentyNotoBold", fontSize=12, leading=16, textColor="#1f2937", spaceBefore=9, spaceAfter=3, keepWithNext=True)
    body = ParagraphStyle("AicentyBody", parent=styles["BodyText"], fontName="AicentyNoto", fontSize=10.5, leading=15, textColor="#111827", spaceAfter=7)
    bullet = ParagraphStyle("AicentyBullet", parent=body, leftIndent=14, firstLineIndent=-9, bulletIndent=0)
    meta = ParagraphStyle("AicentyMeta", parent=body, fontName="AicentyNoto", fontSize=8.5, leading=11, textColor="#6b7280", spaceAfter=12)

    story = [Paragraph(inline_text(title.strip()[:240] or "Tai lieu"), title_style)]
    if source_url:
        story.append(Paragraph(inline_text(f"Nguồn: {source_url[:MAX_URL_CHARS]}"), meta))

    blocks: list[tuple[str, str]] = []
    paragraph_lines: list[str] = []
    def flush() -> None:
        if paragraph_lines:
            blocks.append(("body", " ".join(line.strip() for line in paragraph_lines)))
            paragraph_lines.clear()

    for raw_line in content.replace("\r\n", "\n").replace("\r", "\n").split("\n"):
        line = raw_line.strip()
        if not line:
            flush()
            continue
        heading = re.match(r"^(#{1,6})\s+(.+)$", line)
        if heading:
            flush()
            blocks.append(("h1" if len(heading.group(1)) == 1 else "h2", heading.group(2)))
            continue
        list_item = re.match(r"^(?:[-*]|\d+[.)])\s+(.+)$", line)
        if list_item:
            flush()
            blocks.append(("bullet", list_item.group(1)))
            continue
        paragraph_lines.append(line)
    flush()

    if blocks and blocks[0][0] == "h1" and blocks[0][1].strip().casefold() == title.strip().casefold():
        blocks = blocks[1:]

    section: list = []
    def flush_section() -> None:
        if section:
            story.append(KeepTogether(list(section)))
            section.clear()

    for kind, value in blocks:
        if kind == "h2":
            flush_section()
            section.append(Paragraph(inline_text(value), h2))
        elif kind == "h1":
            flush_section()
            story.append(Paragraph(inline_text(value), h1))
        elif kind == "bullet":
            target = section if section else story
            target.append(Paragraph(f"&bull; {inline_text(value)}", bullet))
        else:
            target = section if section else story
            target.append(Paragraph(inline_text(value), body))
    flush_section()
    if not blocks:
        story.append(Paragraph("(Noi dung trong)", body))
    return story


def page_footer(canvas, doc) -> None:
    canvas.saveState()
    canvas.setFont("AicentyNoto", 8)
    canvas.setFillColorRGB(0.42, 0.46, 0.52)
    canvas.drawRightString(A4[0] - 18 * mm, 12 * mm, f"Trang {doc.page}")
    canvas.restoreState()


def render_text(content: str, title: str, source_url: str, output: Path) -> None:
    story = text_story(content, title, source_url)
    doc = SimpleDocTemplate(str(output), pagesize=A4, rightMargin=18 * mm, leftMargin=18 * mm, topMargin=18 * mm, bottomMargin=20 * mm, title=title[:240])
    doc.build(story, onFirstPage=page_footer, onLaterPages=page_footer)


def run_process(command: list[str], env: dict[str, str], timeout: int) -> tuple[str, str]:
    process = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, env=env, start_new_session=True)
    try:
        stdout, stderr = process.communicate(timeout=timeout)
    except subprocess.TimeoutExpired as exc:
        try:
            os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
        raise RenderError("renderer process timed out") from exc
    if process.returncode != 0:
        raise RenderError("renderer process failed")
    return stdout, stderr


def render_word(content: bytes, filename: str, output: Path) -> None:
    if len(content) > MAX_WORD_BYTES:
        raise RenderError("Word input exceeds 20 MiB")
    suffix = Path(filename).suffix.lower()
    if suffix not in {".doc", ".docx"}:
        raise RenderError("Word mode accepts only .doc or .docx")
    with tempfile.TemporaryDirectory(prefix="aicenty-word-") as temp:
        root = Path(temp)
        source = root / ("input" + suffix)
        outdir = root / "out"
        profile = root / "lo-profile"
        outdir.mkdir()
        source.write_bytes(content)
        profile.mkdir()
        env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "HOME": str(root), "TMPDIR": str(root), "LANG": "C.UTF-8", "SAL_USE_VCLPLUGIN": "svp", "SAL_DISABLE_OPENCL": "1"}
        command = ["soffice", "--headless", "--safe-mode", "--nologo", "--nodefault", "--norestore", "--nolockcheck", f"-env:UserInstallation=file://{profile}", "--convert-to", "pdf:writer_pdf_Export", "--outdir", str(outdir), str(source)]
        run_process(command, env, RENDER_TIMEOUT)
        pdfs = list(outdir.glob("*.pdf"))
        if len(pdfs) != 1:
            raise RenderError("LibreOffice did not produce exactly one PDF")
        output.write_bytes(pdfs[0].read_bytes())


class ValidatingProxy(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


class ProxyHandler(socketserver.StreamRequestHandler):
    MAX_HEADER = 64 * 1024
    MAX_TUNNEL_BYTES = 64 * 1024 * 1024

    def validate_target(self, host: str, port: int) -> str:
        return resolve_public(host, port)

    def handle(self) -> None:
        try:
            request_line = self.rfile.readline(self.MAX_HEADER)
            if not request_line:
                return
            headers: list[bytes] = []
            while True:
                line = self.rfile.readline(self.MAX_HEADER)
                if not line:
                    return
                if line in {b"\r\n", b"\n"}:
                    break
                headers.append(line)
            method, target, _ = request_line.decode("latin1").strip().split(" ", 2)
            if method == "CONNECT":
                host, _, port_raw = target.rpartition(":")
                port = int(port_raw or "443")
                ip = self.validate_target(host, port)
                remote = socket.create_connection((ip, port), timeout=10)
                self.wfile.write(b"HTTP/1.1 200 Connection Established\r\n\r\n")
                self.wfile.flush()
                self.tunnel(remote)
                return
            if method not in {"GET", "HEAD"}:
                self.wfile.write(b"HTTP/1.1 405 Method Not Allowed\r\nConnection: close\r\n\r\n")
                return
            parsed = urlsplit(target)
            port = parsed.port or (443 if parsed.scheme == "https" else 80)
            ip = self.validate_target(parsed.hostname or "", port)
            path = parsed.path or "/"
            if parsed.query:
                path += "?" + parsed.query
            remote = socket.create_connection((ip, port), timeout=10)
            with remote:
                forwarded = [f"{method} {path} HTTP/1.1\r\n".encode("latin1")]
                forwarded_headers = []
                for header in headers:
                    lower = header.lower()
                    if lower.startswith((b"proxy-", b"connection:", b"host:")):
                        continue
                    forwarded_headers.append(header)
                forwarded.append(f"Host: {parsed.hostname}\r\n".encode("latin1"))
                forwarded.extend(forwarded_headers)
                forwarded.append(b"Connection: close\r\n\r\n")
                remote.sendall(b"".join(forwarded))
                self.relay(remote, self.wfile)
        except Exception:
            try:
                self.wfile.write(b"HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\n\r\n")
            except Exception:
                pass

    def relay(self, source, dest) -> None:
        total = 0
        while total < self.MAX_TUNNEL_BYTES:
            chunk = source.recv(min(64 * 1024, self.MAX_TUNNEL_BYTES - total))
            if not chunk:
                break
            dest.write(chunk)
            dest.flush()
            total += len(chunk)

    def tunnel(self, remote) -> None:
        sockets = [self.connection, remote]
        total = 0
        with remote:
            while total < self.MAX_TUNNEL_BYTES:
                readable, _, exceptional = select.select(sockets, [], sockets, 30)
                if exceptional or not readable:
                    break
                for source in readable:
                    destination = remote if source is self.connection else self.connection
                    data = source.recv(min(64 * 1024, self.MAX_TUNNEL_BYTES - total))
                    if not data:
                        return
                    destination.sendall(data)
                    total += len(data)


def render_web(url: str, output: Path) -> None:
    validate_web_url(url)
    proxy = ValidatingProxy(("127.0.0.1", 0), ProxyHandler)
    thread = threading.Thread(target=proxy.serve_forever, daemon=True)
    thread.start()
    try:
        chromium = shutil.which("chromium-headless-shell") or shutil.which("chromium")
        if not chromium:
            raise RenderError("Chromium renderer is not installed")
        with tempfile.TemporaryDirectory(prefix="aicenty-web-") as temp:
            root = Path(temp)
            pdf_path = root / "web.pdf"
            env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "HOME": str(root), "TMPDIR": str(root), "LANG": "C.UTF-8"}
            command = [chromium, "--headless", "--no-sandbox", "--disable-gpu", "--disable-dev-shm-usage", "--disable-quic", "--disable-background-networking", "--disable-sync", "--disable-extensions", "--no-first-run", "--no-pdf-header-footer", "--virtual-time-budget=12000", f"--proxy-server=http://127.0.0.1:{proxy.server_address[1]}", "--proxy-bypass-list=<-loopback>", "--host-resolver-rules=MAP * ~NOTFOUND,EXCLUDE 127.0.0.1", f"--user-data-dir={root / 'profile'}", f"--print-to-pdf={pdf_path}", url]
            run_process(command, env, RENDER_TIMEOUT)
            if not pdf_path.exists():
                raise RenderError("Chromium did not produce a PDF")
            output.write_bytes(pdf_path.read_bytes())
    finally:
        proxy.shutdown()
        proxy.server_close()


def validate_output(path: Path) -> int:
    size = path.stat().st_size
    if size <= 0 or size > MAX_OUTPUT_BYTES or not path.read_bytes()[:5] == b"%PDF-":
        raise RenderError("renderer output is invalid or exceeds 24 MiB")
    pages = len(PdfReader(str(path)).pages)
    if pages < 1 or pages > MAX_PAGES:
        raise RenderError("renderer output has an invalid page count")
    return pages


def render(payload: dict, output: Path) -> int:
    mode = payload.get("mode")
    if mode not in ALLOWED_MODES:
        raise RenderError("document mode is disabled on this renderer")
    if mode == "text":
        render_text(str(payload.get("content", "")), str(payload.get("title", "Tài liệu")), str(payload.get("source_url", "")), output)
    elif mode == "word":
        try:
            content = __import__("base64").b64decode(payload["content_base64"], validate=True)
        except Exception as exc:
            raise RenderError("invalid Word payload") from exc
        render_word(content, str(payload.get("filename", "input.docx")), output)
    elif mode == "web":
        render_web(str(payload.get("url", "")), output)
    else:
        raise RenderError("unknown document mode")
    return validate_output(output)


class Handler(BaseHTTPRequestHandler):
    server_version = "AicentyDocumentRenderer/1.0"

    def log_message(self, _format, *_args):
        return

    def do_GET(self) -> None:
        if self.path != "/health":
            self.send_error(404)
            return
        body = json.dumps({"status": "ok", "renderer": "document", "modes": sorted(ALLOWED_MODES)}, ensure_ascii=True).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_POST(self) -> None:
        if self.path != "/render":
            self.send_error(404)
            return
        length = int(self.headers.get("Content-Length", "0"))
        if length <= 0 or length > MAX_REQUEST_BYTES:
            self.send_response(413)
            self.end_headers()
            return
        if not RENDER_SEMAPHORE.acquire(timeout=5):
            self.send_response(429)
            self.end_headers()
            return
        try:
            payload = json.loads(self.rfile.read(length))
            with tempfile.NamedTemporaryFile(prefix=".render-", suffix=".pdf", delete=False) as tmp:
                output = Path(tmp.name)
            try:
                pages = render(payload, output)
                data = output.read_bytes()
            finally:
                output.unlink(missing_ok=True)
            self.send_response(200)
            self.send_header("Content-Type", "application/pdf")
            self.send_header("Content-Length", str(len(data)))
            self.send_header("X-Page-Count", str(pages))
            self.end_headers()
            self.wfile.write(data)
        except RenderError as exc:
            body = error_message(str(exc))
            self.send_response(400)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        except Exception:
            body = error_message("document renderer failed")
            self.send_response(500)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        finally:
            RENDER_SEMAPHORE.release()


def main() -> None:
    if shutil.which("soffice") is None:
        raise SystemExit("LibreOffice is not installed")
    server = ThreadingHTTPServer(("0.0.0.0", int(os.environ.get("PORT", "8787"))), Handler)
    server.serve_forever()


if __name__ == "__main__":
    main()
