#!/bin/bash

# Tether Laravel - Startup Script
# This script handles port conflicts and starts Docker services safely

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to check if a port is in use
check_port() {
    local port=$1
    if lsof -i ":$port" >/dev/null 2>&1 || ss -tln 2>/dev/null | grep -q ":$port "; then
        return 0  # Port is in use
    else
        return 1  # Port is free
    fi
}

# Function to display port status
display_port_status() {
    echo -e "${BLUE}Checking port availability...${NC}"
    echo ""

    local issues=0

    # Check PostgreSQL port
    if check_port 5432; then
        echo -e "${RED}✗${NC} Port 5432 (PostgreSQL) is in use"
        lsof -i :5432 2>/dev/null | head -2 || ss -tlnp 2>/dev/null | grep :5432 || true
        issues=$((issues + 1))
    else
        echo -e "${GREEN}✓${NC} Port 5432 (PostgreSQL) is available"
    fi

    # Check Redis port
    if check_port 6379; then
        echo -e "${RED}✗${NC} Port 6379 (Redis) is in use"
        issues=$((issues + 1))
    else
        echo -e "${GREEN}✓${NC} Port 6379 (Redis) is available"
    fi

    # Check Nginx port
    if check_port 8000; then
        echo -e "${RED}✗${NC} Port 8000 (Nginx) is in use"
        issues=$((issues + 1))
    else
        echo -e "${GREEN}✓${NC} Port 8000 (Nginx) is available"
    fi

    echo ""
    return $issues
}

# Function to stop host PostgreSQL
stop_host_postgres() {
    echo -e "${YELLOW}Stopping PostgreSQL on host machine...${NC}"

    if systemctl is-active --quiet postgresql 2>/dev/null; then
        sudo systemctl stop postgresql
        echo -e "${GREEN}✓${NC} PostgreSQL service stopped"
    elif systemctl is-active --quiet postgresql@* 2>/dev/null; then
        sudo systemctl stop 'postgresql@*'
        echo -e "${GREEN}✓${NC} PostgreSQL service stopped"
    else
        echo -e "${YELLOW}ℹ${NC}  PostgreSQL service not found via systemctl"
    fi
}

# Main script
echo ""
echo "╔════════════════════════════════════════╗"
echo "║   Tether Laravel - Startup Manager    ║"
echo "╚════════════════════════════════════════╝"
echo ""

# Display current status
if ! display_port_status; then
    echo -e "${YELLOW}⚠ Port conflicts detected!${NC}"
    echo ""
    echo "Options:"
    echo "  1) Stop host PostgreSQL and continue"
    echo "  2) Clean up and retry"
    echo "  3) Use alternative ports (5433, 6380)"
    echo "  4) Exit and fix manually"
    echo ""
    read -p "Choose an option (1-4): " choice

    case $choice in
        1)
            stop_host_postgres
            echo ""
            echo -e "${BLUE}Cleaning up Docker resources...${NC}"
            docker-compose down --remove-orphans 2>/dev/null || true
            echo ""
            echo -e "${BLUE}Starting services...${NC}"
            docker-compose up -d
            ;;
        2)
            echo -e "${BLUE}Cleaning up Docker resources...${NC}"
            docker-compose down --remove-orphans 2>/dev/null || true
            echo ""
            echo -e "${BLUE}Retrying startup...${NC}"
            if check_port 5432; then
                echo -e "${RED}Error: Port 5432 still in use. Please stop the conflicting service.${NC}"
                exit 1
            fi
            docker-compose up -d
            ;;
        3)
            echo -e "${BLUE}Using alternative ports...${NC}"
            if [ -f "docker-compose.alt.yml" ]; then
                docker-compose -f docker-compose.alt.yml down --remove-orphans 2>/dev/null || true
                docker-compose -f docker-compose.alt.yml up -d
                echo -e "${GREEN}Services started with alternative ports:${NC}"
                echo "  - PostgreSQL: 5433"
                echo "  - Redis: 6380"
                echo "  - Nginx: 8000"
            else
                echo -e "${RED}Error: docker-compose.alt.yml not found${NC}"
                exit 1
            fi
            ;;
        4)
            echo "Exiting. Please resolve port conflicts manually."
            exit 0
            ;;
        *)
            echo -e "${RED}Invalid option${NC}"
            exit 1
            ;;
    esac
else
    echo -e "${GREEN}All ports available!${NC}"
    echo ""
    echo -e "${BLUE}Cleaning up Docker resources...${NC}"
    docker-compose down --remove-orphans 2>/dev/null || true
    echo ""
    echo -e "${BLUE}Starting services...${NC}"
    docker-compose up -d
fi

echo ""
echo -e "${GREEN}✓ Startup complete!${NC}"
echo ""
echo "Container status:"
docker-compose ps
echo ""
echo "Useful commands:"
echo "  make logs      - View all logs"
echo "  make bash      - Enter app container"
echo "  make down      - Stop all services"
echo "  make help      - Show all available commands"
echo ""
