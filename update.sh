#!/usr/bin/env bash
# Run in the website directory. Never creates a nested project directory.
set -euo pipefail
umask 022
ROOT=$(pwd -P)
REPOSITORY=${CLOUD_DESK_REPOSITORY:-https://github.com/fastcat920/Cloud-Server.git}
BRANCH=${CLOUD_DESK_BRANCH:-main}
TEMPORARY=$(mktemp -d "${TMPDIR:-/tmp}/cloud-desk-update.XXXXXX")
STANDALONE_SCRIPT=''
cleanup() {
    if [ -n "$STANDALONE_SCRIPT" ] && [ -f "$STANDALONE_SCRIPT" ] && [ ! -f "$ROOT/update.sh" ]; then
        cp "$STANDALONE_SCRIPT" "$ROOT/update.sh"
    fi
    rm -rf -- "$TEMPORARY"
}
trap cleanup EXIT
fail() { printf '%s\n' "$*" >&2; exit 1; }
command -v git >/dev/null 2>&1 || fail '请先安装 Git。'
command -v tar >/dev/null 2>&1 || fail '请先安装 tar。'
[ "$ROOT" != '/' ] && [ "$ROOT" != "$HOME" ] || fail '请先进入网站目录，不要在系统根目录或用户主目录执行。'
git_here() { git -c "safe.directory=$ROOT" -C "$ROOT" "$@"; }
backup() {
    local directory parent name
    parent=$(dirname "$ROOT")
    name=$(basename "$ROOT")
    directory="$parent/.cloud-desk-backups"
    mkdir -p "$directory"
    chmod 700 "$directory"
    BACKUP="$directory/$name-$(date +%Y%m%d-%H%M%S)-$$.tar.gz"
    tar --exclude='./.git' --exclude='./_core/data/sessions' -czf "$BACKUP" -C "$ROOT" .
    chmod 600 "$BACKUP"
    printf '升级前备份：%s\n' "$BACKUP"
}
prepare_data() {
    mkdir -p "$ROOT/_core/data"
    if [ "$(id -u)" = 0 ] && id www >/dev/null 2>&1; then
        chown www:www "$ROOT/_core/data"
    fi
    chmod 700 "$ROOT/_core/data"
}
# Do not attach the application to an unrelated repository or a parent repository.
if [ -d "$ROOT/.git" ]; then
    [ "$(git_here rev-parse --show-toplevel)" = "$ROOT" ] || fail '当前目录不是仓库根目录。'
    [ "$(git_here symbolic-ref --short HEAD)" = "$BRANCH" ] || fail "请先切换到 $BRANCH 分支。"
    [ -z "$(git_here status --porcelain --untracked-files=no)" ] || fail '检测到本地代码修改，已停止更新。请先保存或提交修改，不会强制覆盖。'
    [ -f "$ROOT/_core/Store.php" ] && grep -q 'namespace CloudDesk' "$ROOT/_core/Store.php" || fail '此目录不是原生 v2 项目。请先按文档迁移旧版。'
    origin=$(git_here remote get-url origin)
    if [ "$origin" != "$REPOSITORY" ] && [ "$origin" != 'git@github.com:fastcat920/Cloud-Server.git' ]; then
        fail 'origin 与预期仓库不一致，已停止更新。'
    fi
    git_here fetch origin "$BRANCH"
    git_here merge-base --is-ancestor HEAD FETCH_HEAD || fail '本地与远端历史已分叉，已停止更新，不会重置本地提交。'
    if [ "$(git_here rev-parse HEAD)" = "$(git_here rev-parse FETCH_HEAD)" ]; then
        prepare_data
        printf '当前目录已是最新版本：%s\n' "$ROOT"
        exit 0
    fi
    backup
    # A first-time download of this script may be untracked in an older checkout.
    if [ -f "$ROOT/update.sh" ] && ! git_here ls-files --error-unmatch update.sh >/dev/null 2>&1; then
        STANDALONE_SCRIPT="$TEMPORARY/update.sh"
        cp "$ROOT/update.sh" "$STANDALONE_SCRIPT"
        rm "$ROOT/update.sh"
    fi
    git_here merge --ff-only FETCH_HEAD
elif [ -e "$ROOT/.git" ]; then
    fail '当前目录使用特殊 Git 工作区布局，请直接使用 git pull --ff-only 更新。'
else
    contents=$(find "$ROOT" -mindepth 1 -maxdepth 1 ! -name update.sh ! -name .DS_Store -print -quit)
    if [ -n "$contents" ]; then
        [ -f "$ROOT/index.php" ] && [ -f "$ROOT/_core/Store.php" ] && grep -q 'namespace CloudDesk' "$ROOT/_core/Store.php" || fail '当前目录非空且不是原生 v2 解压安装，已停止。不会覆盖其他网站或旧版 _app 数据。'
        backup
    fi
    git clone --branch "$BRANCH" --single-branch "$REPOSITORY" "$TEMPORARY/source"
    # Reject symlink destinations for code; ignored _core/data is never copied.
    while IFS= read -r path; do
        current="$ROOT"
        IFS='/' read -r -a segments <<< "$path"
        for segment in "${segments[@]}"; do
            current="$current/$segment"
            [ ! -L "$current" ] || fail "代码位置存在符号链接，已停止：$path"
        done
    done < <(git -C "$TEMPORARY/source" ls-files)
    if [ -f "$ROOT/update.sh" ]; then
        STANDALONE_SCRIPT="$TEMPORARY/original-update.sh"
        cp "$ROOT/update.sh" "$STANDALONE_SCRIPT"
        rm "$ROOT/update.sh"
    fi
    cp -R "$TEMPORARY/source/." "$ROOT/"
fi
prepare_data
printf '\n完成。网站目录保持：%s\n首次访问域名创建账号；已有原生 v2 账号和数据保持不变。\n以后在同一目录执行：bash update.sh\n' "$ROOT"
