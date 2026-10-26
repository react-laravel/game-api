#!/bin/bash
set -euo pipefail
umask 077

runtime_archive="${1:?runtime archive required}"
runtime_sha="${2:?commit SHA required}"
[[ "$runtime_sha" =~ ^[0-9a-f]{40}$ ]] || { echo "Invalid commit SHA" >&2; exit 2; }
[[ -n "${B_GAME_API_DEPLOY_SSH_KEY:-}" && -n "${B_GAME_API_DEPLOY_KNOWN_HOSTS:-}" ]] || {
  echo "B Game api deployment secrets are missing." >&2
  exit 2
}

api_ssh_dir="$(mktemp -d "${RUNNER_TEMP:-/tmp}/game-api-ssh.XXXXXX")"
trap 'rm -rf "$api_ssh_dir"' EXIT
printf '%s\n' "$B_GAME_API_DEPLOY_SSH_KEY" > "$api_ssh_dir/id"
printf '%s\n' "$B_GAME_API_DEPLOY_KNOWN_HOSTS" > "$api_ssh_dir/known_hosts"
chmod 600 "$api_ssh_dir/id" "$api_ssh_dir/known_hosts"
unset B_GAME_API_DEPLOY_SSH_KEY B_GAME_API_DEPLOY_KNOWN_HOSTS

api_ssh_options=(
  -o BatchMode=yes
  -o IdentitiesOnly=yes
  -o StrictHostKeyChecking=yes
  -o UserKnownHostsFile="$api_ssh_dir/known_hosts"
  -o ConnectTimeout=15
  -o ServerAliveInterval=15
  -o ServerAliveCountMax=3
  -i "$api_ssh_dir/id"
)
api_ssh_target="game-api-deploy@154.21.197.138"

if [[ "${VALIDATE_ONLY:-false}" == true ]]; then
  ssh "${api_ssh_options[@]}" "$api_ssh_target" "validate $runtime_sha"
else
  test -f "$runtime_archive"
  ssh "${api_ssh_options[@]}" "$api_ssh_target" "receive $runtime_sha" < "$runtime_archive"
  ssh "${api_ssh_options[@]}" "$api_ssh_target" "publish $runtime_sha"
fi
