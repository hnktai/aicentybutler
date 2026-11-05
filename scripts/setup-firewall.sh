#!/usr/bin/env bash
set -euo pipefail

echo ">> [FIREWALL] Áp dụng quy tắc iptables DOCKER-USER..."

# Flush custom rules
iptables -F DOCKER-USER 2>/dev/null || true

# 1. Allow established and related packets
iptables -A DOCKER-USER -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT

# 2. Allow intra-network traffic within aicenty network (172.27.0.0/16)
iptables -A DOCKER-USER -s 172.27.0.0/16 -d 172.27.0.0/16 -j ACCEPT

# 3. Block aicenty containers from accessing other docker networks (172.16.0.0/12)
iptables -A DOCKER-USER -s 172.27.0.0/16 -d 172.16.0.0/12 -j DROP

# 4. Block RFC 1918 private ranges to prevent internal SSRF
iptables -A DOCKER-USER -s 172.27.0.0/16 -d 10.0.0.0/8 -j DROP
iptables -A DOCKER-USER -s 172.27.0.0/16 -d 192.168.0.0/16 -j DROP

# 5. Block direct probe to other services ports on the host
iptables -A DOCKER-USER -s 172.27.0.0/16 -p tcp -m multiport --dports 18890,5433,5434,8069,8070,8073,8074 -j DROP

# 6. Default accept everything else (public internet for AI APIs)
iptables -A DOCKER-USER -j RETURN

echo ">> [FIREWALL] Danh sách quy tắc hiện tại:"
iptables -L DOCKER-USER -n -v --line-numbers
