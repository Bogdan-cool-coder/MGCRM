#!/bin/bash
# ================================================================
# Vizion — первичная настройка сервера 185.36.144.215
# Запускать от root: sudo bash scripts/setup-server.sh
# ================================================================
set -euo pipefail

SERVER_IP="185.36.144.215"
DEV_DOMAIN="devizion.macroglobal.tech"
PROD_DOMAIN="vizion.macroglobal.tech"
DEV_FRONTEND_PORT=3030
PROD_FRONTEND_PORT=3031

echo "=== 1. Создание пользователей ==="
# skorpyone
id skorpyone &>/dev/null || {
    adduser skorpyone --gecos ""
    usermod -aG sudo skorpyone
    usermod -aG docker skorpyone
    mkdir -p /home/skorpyone/.ssh
    cp ~/.ssh/authorized_keys /home/skorpyone/.ssh/authorized_keys 2>/dev/null || true
    chown -R skorpyone:skorpyone /home/skorpyone/.ssh
    chmod 700 /home/skorpyone/.ssh
    chmod 600 /home/skorpyone/.ssh/authorized_keys
    echo "  [OK] skorpyone created"
}

# macro (CI/CD)
id macro &>/dev/null || {
    adduser macro --gecos ""
    usermod -aG docker macro
    usermod -aG skorpyone macro
    echo "  [OK] macro created"
    echo "  [!] Не забудь добавить SSH-ключ macro: ssh-keygen -t ed25519 -C 'macro-ci' -f /home/macro/.ssh/id_ed25519"
    echo "  [!] И добавить публичный ключ в GitHub Deploy Keys для vizion repo"
}

echo "=== 2. Swap ==="
if [ ! -f /swapfile ]; then
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    echo '/swapfile none swap sw 0 0' >> /etc/fstab
    echo "  [OK] 2G swap created"
else
    echo "  [SKIP] swap already exists"
fi

echo "=== 3. Nginx + Certbot ==="
dpkg -l | grep -q nginx || {
    apt update
    apt install -y nginx certbot python3-certbot-nginx
    echo "  [OK] nginx + certbot installed"
}

echo "=== 4. UFW ==="
ufw status | grep -q "Status: active" || {
    ufw allow OpenSSH
    ufw allow 'Nginx Full'
    ufw --force enable
    echo "  [OK] UFW enabled"
}

echo "=== 5. Host Nginx configs ==="

cat > /etc/nginx/sites-available/${DEV_DOMAIN} <<NGINX
server {
    listen 80;
    server_name ${DEV_DOMAIN};
    location / {
        proxy_pass http://127.0.0.1:${DEV_FRONTEND_PORT};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
NGINX

cat > /etc/nginx/sites-available/${PROD_DOMAIN} <<NGINX
server {
    listen 80;
    server_name ${PROD_DOMAIN};
    location / {
        proxy_pass http://127.0.0.1:${PROD_FRONTEND_PORT};
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
NGINX

# Enable sites
ln -sf /etc/nginx/sites-available/${DEV_DOMAIN} /etc/nginx/sites-enabled/
ln -sf /etc/nginx/sites-available/${PROD_DOMAIN} /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

nginx -t && systemctl reload nginx
echo "  [OK] Host nginx configured"

echo ""
echo "=== 6. Права для macro ==="
chmod -R 775 /home/skorpyone/vizion 2>/dev/null || true
find /home/skorpyone/vizion -type d -exec chmod g+s {} \; 2>/dev/null || true
echo "  [OK] Group permissions set"

echo ""
echo "========================================"
echo "  Настройка сервера завершена!"
echo "========================================"
echo ""
echo "Следующие шаги:"
echo "  1. DNS: A-записи ${DEV_DOMAIN} и ${PROD_DOMAIN} → ${SERVER_IP}"
echo "  2. SSH-ключ для macro (см. выше)"
echo "  3. Запустить bash scripts/setup-dev.sh (от skorpyone)"
echo "  4. Запустить bash scripts/setup-prod.sh (от skorpyone)"
echo "  5. SSL: certbot --nginx -d ${DEV_DOMAIN} -d ${PROD_DOMAIN}"
echo "  6. GitHub Secrets: NEW_SERVER_HOST, NEW_SERVER_USER, NEW_SERVER_SSH_KEY"
echo ""
