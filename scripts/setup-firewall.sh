#!/usr/bin/env bash
set -euo pipefail

network_name="${AICENTY_INTERNAL_NETWORK:-goclaw-aicenty_aicenty_internal_net}"
chain_name="AICENTY-BUTLER"

subnet="$(docker network inspect "$network_name" --format '{{(index .IPAM.Config 0).Subnet}}')"
gateway="$(docker network inspect "$network_name" --format '{{(index .IPAM.Config 0).Gateway}}')"

if [[ ! "$subnet" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}/[0-9]{1,2}$ ]]; then
  echo "invalid IPv4 subnet resolved for $network_name: $subnet" >&2
  exit 1
fi
if [[ ! "$gateway" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; then
  echo "invalid IPv4 gateway resolved for $network_name: $gateway" >&2
  exit 1
fi

echo ">> [FIREWALL] Applying isolated rules for $network_name ($subnet)..."

iptables -N "$chain_name" 2>/dev/null || true
iptables -F "$chain_name"

while iptables -C DOCKER-USER -s "$subnet" -j "$chain_name" 2>/dev/null; do
  iptables -D DOCKER-USER -s "$subnet" -j "$chain_name"
done
iptables -I DOCKER-USER 1 -s "$subnet" -j "$chain_name"

iptables -A "$chain_name" -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT
iptables -A "$chain_name" -d "$gateway/32" -j DROP
iptables -A "$chain_name" -d "$subnet" -j ACCEPT
iptables -A "$chain_name" -d 10.0.0.0/8 -j DROP
iptables -A "$chain_name" -d 172.16.0.0/12 -j DROP
iptables -A "$chain_name" -d 192.168.0.0/16 -j DROP
iptables -A "$chain_name" -d 169.254.0.0/16 -j DROP
iptables -A "$chain_name" -j RETURN

iptables -L DOCKER-USER -n -v --line-numbers
iptables -L "$chain_name" -n -v --line-numbers
