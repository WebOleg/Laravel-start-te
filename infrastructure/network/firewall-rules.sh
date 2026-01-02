#!/bin/bash
# Tether Firewall Rules
# THR-176: Configure private networking
#
# Run on each server to configure iptables

set -e

PRIVATE_NETWORK="10.0.1.0/24"

# Detect server role based on hostname or argument
ROLE=${1:-"unknown"}

echo "=== Configuring firewall for: $ROLE ==="

# Flush existing rules
iptables -F
iptables -X

# Default policies
iptables -P INPUT DROP
iptables -P FORWARD DROP
iptables -P OUTPUT ACCEPT

# Allow loopback
iptables -A INPUT -i lo -j ACCEPT

# Allow established connections
iptables -A INPUT -m state --state ESTABLISHED,RELATED -j ACCEPT

# Allow SSH from anywhere (for management)
iptables -A INPUT -p tcp --dport 22 -j ACCEPT

case $ROLE in
  "loadbalancer")
    echo "Configuring Load Balancer rules..."
    # Public HTTP/HTTPS
    iptables -A INPUT -p tcp --dport 80 -j ACCEPT
    iptables -A INPUT -p tcp --dport 443 -j ACCEPT
    ;;

  "api")
    echo "Configuring API Node rules..."
    # Only from private network
    iptables -A INPUT -s $PRIVATE_NETWORK -p tcp --dport 9000 -j ACCEPT
    ;;

  "worker")
    echo "Configuring Worker Node rules..."
    # No inbound needed, only outbound
    ;;

  "redis")
    echo "Configuring Redis rules..."
    # Only from private network
    iptables -A INPUT -s $PRIVATE_NETWORK -p tcp --dport 6379 -j ACCEPT
    ;;

  "postgres")
    echo "Configuring PostgreSQL rules..."
    # Only from private network
    iptables -A INPUT -s $PRIVATE_NETWORK -p tcp --dport 5432 -j ACCEPT
    iptables -A INPUT -s $PRIVATE_NETWORK -p tcp --dport 6432 -j ACCEPT
    ;;

  "minio")
    echo "Configuring MinIO rules..."
    # Only from private network
    iptables -A INPUT -s $PRIVATE_NETWORK -p tcp --dport 9000 -j ACCEPT
    iptables -A INPUT -s $PRIVATE_NETWORK -p tcp --dport 9001 -j ACCEPT
    ;;

  *)
    echo "Unknown role: $ROLE"
    echo "Usage: $0 [loadbalancer|api|worker|redis|postgres|minio]"
    exit 1
    ;;
esac

# Save rules
if command -v netfilter-persistent &> /dev/null; then
    netfilter-persistent save
elif command -v iptables-save &> /dev/null; then
    iptables-save > /etc/iptables/rules.v4
fi

echo "=== Firewall configured ==="
iptables -L -n
