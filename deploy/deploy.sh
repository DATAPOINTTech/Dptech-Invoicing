#!/bin/bash
# ─────────────────────────────────────────────────────────────
# DATAPOINT Invoicing — EC2 Deployment Script
# Run as ec2-user on Amazon Linux 2023
# Usage: bash deploy/deploy.sh
# ─────────────────────────────────────────────────────────────
set -e

APP_DIR="/home/ec2-user/dptech-invoicing"
VENV_DIR="/home/ec2-user/venv"
LOG_DIR="/var/log/dptech"
SERVICE="dptech"

echo "==> [1/8] System update & dependencies"
sudo dnf update -y
sudo dnf install -y git nginx python3.11 python3.11-pip postgresql15

echo "==> [2/8] Python virtual environment"
python3.11 -m venv "$VENV_DIR"
source "$VENV_DIR/bin/activate"

echo "==> [3/8] Install Python packages"
pip install --upgrade pip
pip install -r "$APP_DIR/requirements.txt"

echo "==> [4/8] Environment file"
if [ ! -f "$APP_DIR/.env" ]; then
    cp "$APP_DIR/.env.example" "$APP_DIR/.env"
    echo ""
    echo "  !! .env created from .env.example"
    echo "  !! Edit $APP_DIR/.env and set:"
    echo "       DATABASE_URL=postgresql://dptech:<password>@<rds-endpoint>:5432/dptech_db"
    echo "       SECRET_KEY=<run: python3 -c \"import secrets; print(secrets.token_urlsafe(50))\">"
    echo "       DB_SSL_REQUIRED=true"
    echo ""
    read -p "  Press ENTER after editing .env to continue..." _
fi

echo "==> [5/8] Run database migrations"
cd "$APP_DIR"
source "$VENV_DIR/bin/activate"
alembic upgrade head

echo "==> [6/8] Log directory"
sudo mkdir -p "$LOG_DIR"
sudo chown ec2-user:ec2-user "$LOG_DIR"

echo "==> [7/8] systemd service"
sudo cp "$APP_DIR/deploy/dptech.service" /etc/systemd/system/dptech.service
sudo systemctl daemon-reload
sudo systemctl enable "$SERVICE"
sudo systemctl restart "$SERVICE"
sudo systemctl status "$SERVICE" --no-pager

echo "==> [8/8] Nginx"
sudo cp "$APP_DIR/deploy/nginx.conf" /etc/nginx/conf.d/dptech.conf
sudo rm -f /etc/nginx/conf.d/default.conf
sudo nginx -t
sudo systemctl enable nginx
sudo systemctl restart nginx

echo ""
echo "✅  Deployment complete!"
echo "    App:   http://$(curl -s http://169.254.169.254/latest/meta-data/public-ipv4)"
echo "    Logs:  sudo journalctl -u dptech -f"
echo "    Nginx: sudo tail -f /var/log/nginx/access.log"
