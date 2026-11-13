---
name: quangia-image-generation
description: Generate and deliver AI images when a customer asks to create, draw, design, or illustrate an image. Do not use for analyzing an existing image or combining supplied images into a PDF.
---

# Quangia Image Generation

Create the requested image with the native `create_image` tool, then deliver the resulting file in the current conversation with `send_file`.

## Request Handling

- Preserve the customer's subject, style, composition, aspect ratio, colors, and any exact visible text.
- Infer harmless defaults instead of asking unnecessary questions. Ask one concise question only when a missing detail would materially change the result.
- Generate one image unless the customer explicitly requests variants or multiple outputs.
- Use a square chat-friendly composition by default. Follow an explicit portrait, landscape, poster, banner, wallpaper, or social-media format request.
- Use the customer's language for visible text. Put exact required text in quotes inside the generation prompt.
- Send only the customer's request and any reference image they explicitly supplied to the image provider. Do not add unrelated profile, memory, contact, family, or workspace data to the generation prompt.

## Generate And Deliver

1. Call `create_image` with a concrete prompt that includes all confirmed constraints.
2. Wait for the tool to return successfully. Do not claim the image exists before a real output file is returned.
3. Verify that the returned path identifies a generated image file. Use PNG, JPEG, or WebP output suitable for the current channel and keep the delivered file under 20 MB.
4. Call `send_file` with the exact returned workspace path so the image is attached to the current customer conversation.
5. After successful delivery, reply briefly in the established Quangia tone. Do not expose provider, model, API, storage paths, or internal tool details.

## Failure Rules

- Retry at most once for a transient provider timeout or temporary network error.
- Do not retry a safety rejection, invalid request, quota error, or authentication error. Explain the limitation briefly and ask for a safe reformulation only when useful.
- If generation succeeds but attachment delivery fails, keep the valid output path and report the delivery failure honestly. Never invent a link or claim that the image was sent.
- Do not use `message` or `sessions_send` as a substitute for attaching the generated file in the current chat.
- Never create or send an image proactively without a customer request.
