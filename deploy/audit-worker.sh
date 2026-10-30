#!/usr/bin/env bash
set -euo pipefail

task_app_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
task_php="${AUDIT_WORKER_PHP:-/opt/alt/php84/usr/bin/php}"
task_slot="${1:-}"
case "$task_slot" in
    general) task_queue="machine,default"; task_lock="worker.lock" ;;
    ai-[1-9]|ai-1[0-5]) task_queue="machine,ai,verify,default"; task_lock="worker-${task_slot}.lock" ;;
    verify) task_queue="machine,verify,ai,default"; task_lock="worker-verify.lock" ;;
    references) task_queue="references,default"; task_lock="worker-references.lock" ;;
    *) printf 'Usage: audit-worker.sh general|ai-1..ai-15|verify|references\n' >&2; exit 2 ;;
esac

cd -- "$task_app_dir"
exec /usr/bin/flock -n "$task_app_dir/storage/framework/$task_lock" \
    "$task_php" -d memory_limit=512M "$task_app_dir/artisan" \
    queue:work database --queue="$task_queue" --sleep=1 --tries=3 \
    --timeout=230 --memory=384 --max-time=300 --stop-when-empty
