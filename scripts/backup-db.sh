# Cấu hình Rclone kết nối Cloudflare R2 S3-Compatible (/root/.config/rclone/rclone.conf):
mkdir -p /root/.config/rclone
cat << 'EOF' > /root/.config/rclone/rclone.conf
[r2_storage]
type = s3
provider = Cloudflare
access_key_id = YOUR_R2_ACCESS_KEY_ID
secret_access_key = YOUR_R2_SECRET_ACCESS_KEY
endpoint = https://YOUR_CLOUDFLARE_ACCOUNT_ID.r2.cloudflarestorage.com
acl = private
EOF
chmod 600 /root/.config/rclone/rclone.conf
