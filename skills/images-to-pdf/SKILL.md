---
name: images-to-pdf
description: Combine customer-supplied JPG, PNG, or WebP images into one PDF and return it as a file attachment. Use for requests such as gop anh thanh PDF, ghep hinh thanh PDF, chuyen anh sang PDF, or make a PDF from photos. Do not use for extracting or editing an existing PDF.
---

# Images to PDF

Create the PDF with the bundled deterministic script. Do not rewrite this conversion logic in the conversation.

## Workflow

1. Use only image paths explicitly attached in the current request or referenced in the immediately preceding conversation. Never scan unrelated upload folders or other users' files.
2. Preserve the customer's stated order. If no order is stated, use chronological send order. Ask one short clarification only when the intended image set or order is genuinely ambiguous.
3. Choose a simple `.pdf` filename in the current workspace. Do not write the output inside `.uploads/`.
4. Run the installed converter command from the current workspace:

```bash
images-to-pdf --output "anh-gia-dinh.pdf" ".uploads/image-1.jpg" ".uploads/image-2.png"
```

5. Treat success only as a zero exit code with JSON containing the output path, page count, and byte size. Then call `send_file` with that output path exactly once.
6. If conversion fails, report the specific failure briefly. Do not install packages at runtime, do not claim the PDF was created, and do not send a partial file.

## Fixed Behavior

- One image becomes one page.
- Page orientation follows the image; content is fit without cropping on a white A4 page.
- EXIF rotation and transparent backgrounds are normalized automatically.
- Accepted inputs: JPEG, PNG, and WebP.
- Maximum: 30 images, 25 MiB per input, 250 MiB combined input, and 24 MiB output.
- This skill performs no OCR and uploads nothing outside the existing chat delivery path.
