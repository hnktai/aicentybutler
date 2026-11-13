---
name: document-to-pdf
description: Create a polished PDF from plain text, a Word document, or a public web article. Use when the user asks to turn text, DOC/DOCX, or a news/article URL into PDF. Choose between a clean reading version and a page-faithful web print; do not use this for image-only input, which belongs to images-to-pdf.
---

# Document to PDF

This skill has three explicit modes. Use the deterministic `document-to-pdf` command and send the resulting file only after its JSON success result is returned.

## Choose a mode

- `text`: plain text or clean Markdown-like content becomes a Vietnamese-safe A4 PDF with a title, wrapped paragraphs, headings, and page numbers.
- `word`: `.doc` or `.docx` is converted by LibreOffice headless so existing tables, images, styles, headers, and page breaks are preserved.
- `web`: prints a public URL through an isolated Chromium renderer and keeps the page's print layout. Use this only when the user asks to preserve the web page appearance.

When a user provides a URL without choosing a layout, ask whether they want `ban doc sach` or `giu nguyen giao dien trang web`. For `ban doc sach`, call `web_fetch` first with `extractMode="markdown"`, write only the returned article content to a temporary workspace text file with `write_file(deliver=false)`, then use `text` and pass the source URL with `--source-url`.

When the customer sends plain text directly in chat, write exactly that requested content to a temporary UTF-8 `.txt` or `.md` file with `write_file(deliver=false)`, then run `text`. Do not ask the customer to upload a file merely to convert pasted text.

`Ban doc sach` prioritizes the article text, headings, lists, and source URL; decorative web imagery may be omitted. Use `giu nguyen giao dien trang web` when the customer wants the page's images and visual composition preserved.

## Commands

Run from the current workspace. Use a unique output filename outside `.uploads/`.

```bash
document-to-pdf text --input "article.md" --output "article.pdf" --title "Tieu de bai viet" --source-url "https://example.com/article"
document-to-pdf word --input ".uploads/report.docx" --output "report.pdf"
document-to-pdf web --url "https://example.com/article" --output "article-print.pdf"
```

Rules:

1. Use only the text, Word path, or URL supplied by the customer/current conversation. Do not scan other workspaces or upload folders.
2. Preserve Vietnamese text and the requested title. For web clean mode, keep the article title and source URL when available.
3. Treat success only as exit code 0 with JSON containing `status`, `output`, `page_count`, and `byte_size`. Then call `send_file` exactly once with that output path.
4. If conversion fails, report the specific reason and do not send a partial file. Never install dependencies during a customer turn.

Limits: text input 1 MiB, Word input 20 MiB, URL length 2048 characters, output 24 MiB. Only `http` and `https` public URLs are accepted for web mode; private/loopback hosts and redirects are blocked by the isolated renderer.
