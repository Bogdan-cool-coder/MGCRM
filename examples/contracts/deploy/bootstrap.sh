#!/usr/bin/env bash
# Первичный деплой MACRO Contracts на VPS. Идемпотентен.
#
# Что делает:
#  1) Генерирует/использует SSH-ключ для GitHub Actions
#  2) Устанавливает pub-ключ в authorized_keys на VPS
#  3) Сохраняет SSH_HOST/SSH_USER/SSH_PRIVATE_KEY в GitHub Secrets (через gh)
#  4) На VPS — генерирует deploy-key для git, регистрирует pub в репо
#  5) Клонирует репо в VPS_PATH через github-macro-contracts SSH alias
#  6) Заливает .env и Google service-account JSON на VPS
#  7) Запускает docker compose up -d --build

set -euo pipefail

VPS_HOST="${VPS_HOST:-153.80.193.132}"
VPS_USER="${VPS_USER:-root}"
VPS_PATH="${VPS_PATH:-/opt/macro-contracts}"
GITHUB_REPO="${GITHUB_REPO:-Bogdan-cool-coder/macro-contracts}"
SSH_KEY_PATH="${SSH_KEY_PATH:-$HOME/.ssh/macro_contracts_deploy}"
ENV_FILE="${ENV_FILE:-./.env}"
GOOGLE_SA_PATH="${GOOGLE_SA_PATH:-./deploy/secrets/google_sa.json}"

say() { echo -e "\033[1;36m[bootstrap]\033[0m $*"; }

# 1. Local SSH key
if [ ! -f "$SSH_KEY_PATH" ]; then
  say "Generating $SSH_KEY_PATH ..."
  ssh-keygen -t ed25519 -f "$SSH_KEY_PATH" -N "" -C "macro-contracts-deploy"
fi
PUB_KEY="$(cat "${SSH_KEY_PATH}.pub")"

# 2. Push pub key to VPS
say "Installing pub key on $VPS_USER@$VPS_HOST ..."
ssh -o StrictHostKeyChecking=accept-new "$VPS_USER@$VPS_HOST" \
  "mkdir -p ~/.ssh && chmod 700 ~/.ssh && grep -qxF '$PUB_KEY' ~/.ssh/authorized_keys 2>/dev/null || echo '$PUB_KEY' >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"

# 3. GitHub Secrets
if command -v gh >/dev/null 2>&1; then
  say "Setting GitHub Secrets..."
  gh secret set SSH_HOST -b "$VPS_HOST" -R "$GITHUB_REPO"
  gh secret set SSH_USER -b "$VPS_USER" -R "$GITHUB_REPO"
  gh secret set SSH_PRIVATE_KEY -b "$(cat "$SSH_KEY_PATH")" -R "$GITHUB_REPO"
else
  say "⚠ gh CLI не найден — задайте секреты SSH_HOST/SSH_USER/SSH_PRIVATE_KEY вручную в Settings → Secrets → Actions"
fi

# 4. VPS-side: deploy key for git
say "Setting up deploy key on VPS ..."
ssh -i "$SSH_KEY_PATH" "$VPS_USER@$VPS_HOST" bash <<'REMOTE'
set -euo pipefail
mkdir -p ~/.ssh
if [ ! -f ~/.ssh/macro_contracts_github ]; then
  ssh-keygen -t ed25519 -f ~/.ssh/macro_contracts_github -N "" -C "macro-contracts-deploy-key"
fi
if ! grep -q "Host github-macro-contracts" ~/.ssh/config 2>/dev/null; then
  cat >> ~/.ssh/config <<'EOF'

Host github-macro-contracts
  HostName github.com
  User git
  IdentityFile ~/.ssh/macro_contracts_github
  IdentitiesOnly yes
EOF
  chmod 600 ~/.ssh/config
fi
ssh-keyscan -t ed25519 github.com >> ~/.ssh/known_hosts 2>/dev/null
echo "=== DEPLOY KEY (зарегистрирован ниже, либо добавьте вручную в Settings → Deploy Keys) ==="
cat ~/.ssh/macro_contracts_github.pub
echo "==="
REMOTE

# 5. Register deploy key in GitHub
DEPLOY_PUB="$(ssh -i "$SSH_KEY_PATH" "$VPS_USER@$VPS_HOST" 'cat ~/.ssh/macro_contracts_github.pub')"
if command -v gh >/dev/null 2>&1; then
  say "Registering deploy key in $GITHUB_REPO ..."
  echo "$DEPLOY_PUB" | gh api "repos/$GITHUB_REPO/keys" \
    -f title="VPS deploy key" \
    -F read_only=true \
    -F key=@- || say "(возможно, уже зарегистрирован — это норм)"
fi

# 6. Clone / update repo
say "Cloning/updating repo on VPS at $VPS_PATH ..."
ssh -i "$SSH_KEY_PATH" "$VPS_USER@$VPS_HOST" bash <<REMOTE
set -euo pipefail
if [ ! -d "$VPS_PATH/.git" ]; then
  rm -rf "$VPS_PATH"
  git clone git@github-macro-contracts:$GITHUB_REPO.git "$VPS_PATH"
else
  cd "$VPS_PATH"
  git fetch --all
  git reset --hard origin/main
fi
mkdir -p "$VPS_PATH/deploy/secrets"
REMOTE

# 7. .env + Google service-account
if [ -f "$ENV_FILE" ]; then
  say "Uploading $ENV_FILE → VPS ..."
  scp -i "$SSH_KEY_PATH" "$ENV_FILE" "$VPS_USER@$VPS_HOST:$VPS_PATH/.env"
  ssh -i "$SSH_KEY_PATH" "$VPS_USER@$VPS_HOST" "chmod 600 $VPS_PATH/.env"
else
  say "⚠ ENV_FILE=$ENV_FILE не найден. Залейте .env вручную перед docker compose up."
fi

if [ -f "$GOOGLE_SA_PATH" ]; then
  say "Uploading Google service-account JSON ..."
  scp -i "$SSH_KEY_PATH" "$GOOGLE_SA_PATH" "$VPS_USER@$VPS_HOST:$VPS_PATH/deploy/secrets/google_sa.json"
  ssh -i "$SSH_KEY_PATH" "$VPS_USER@$VPS_HOST" "chmod 600 $VPS_PATH/deploy/secrets/google_sa.json"
else
  say "ℹ Google SA JSON ($GOOGLE_SA_PATH) не найден — это окей если Drive ещё не настроен."
fi

# 8. Build & up
say "docker compose up -d --build ..."
ssh -i "$SSH_KEY_PATH" "$VPS_USER@$VPS_HOST" "cd $VPS_PATH && docker compose up -d --build"

say "Готово!"
say ""
say "Полезные команды:"
say "  Логи API:   ssh -i $SSH_KEY_PATH $VPS_USER@$VPS_HOST 'cd $VPS_PATH && docker compose logs -f api'"
say "  Логи web:   ssh -i $SSH_KEY_PATH $VPS_USER@$VPS_HOST 'cd $VPS_PATH && docker compose logs -f web'"
say "  Рестарт:    ssh -i $SSH_KEY_PATH $VPS_USER@$VPS_HOST 'cd $VPS_PATH && docker compose restart'"
