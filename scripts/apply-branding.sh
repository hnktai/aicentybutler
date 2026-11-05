#!/bin/bash
# ==============================================================================
# AICENTY BUTLER: FUTURISTIC LUXURY TECH BRANDING SUITE
# ==============================================================================
set -e

BRANDING_DIR="/var/www/aicenty/butler-branding"
mkdir -p "$BRANDING_DIR/assets" "$BRANDING_DIR/branding-assets"

echo ">> [BRANDING] 1. Tải Logo chính thức Aicenty..."
curl -sSL -o "$BRANDING_DIR/logo.png" "https://aicenty.com/aicenty-logo.png"
cp "$BRANDING_DIR/logo.png" "$BRANDING_DIR/branding-assets/logo.png"

echo ">> [BRANDING] 2. Tải Favicon từ Google Drive..."
DRIVE_FAVICON_ID="14Oa2h33tWc1EB0PGMvqRjFg870yDS6lL"
curl -sSL -o "$BRANDING_DIR/favicon.png" "https://drive.usercontent.google.com/download?id=${DRIVE_FAVICON_ID}&export=download"
cp "$BRANDING_DIR/favicon.png" "$BRANDING_DIR/favicon.ico"
cp "$BRANDING_DIR/favicon.png" "$BRANDING_DIR/apple-touch-icon.png"
cp "$BRANDING_DIR/favicon.png" "$BRANDING_DIR/branding-assets/favicon.svg"

echo ">> [BRANDING] 3. Chuyển đổi Theme GoClaw sang Deep Black & Electric Blue Glow..."
cat << 'EOF' > "$BRANDING_DIR/branding-assets/theme-luxury.css"
:root {
  --background: #05070B;
  --foreground: #F8FAFC;
  --card: #0B0F17;
  --card-foreground: #F8FAFC;
  --popover: #0B0F17;
  --popover-foreground: #F8FAFC;
  --primary: #00D2FF;
  --primary-foreground: #000000;
  --secondary: #1E293B;
  --secondary-foreground: #F8FAFC;
  --muted: #0F172A;
  --muted-foreground: #94A3B8;
  --accent: #0070F3;
  --accent-foreground: #FFFFFF;
  --border: #1E293B;
  --ring: #00D2FF;
  --glow-electric: 0 0 20px rgba(0, 210, 255, 0.45);
}
body {
  background-color: #05070B !important;
  color: #F8FAFC !important;
}
.electric-glow {
  box-shadow: 0 0 25px rgba(0, 210, 255, 0.45) !important;
}
EOF

echo ">> [BRANDING] Hoàn tất thiết lập bộ nhận diện Futuristic Luxury Tech!"
