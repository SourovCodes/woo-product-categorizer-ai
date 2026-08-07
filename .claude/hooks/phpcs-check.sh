#!/usr/bin/env bash
#
# PostToolUse hook: keep every PHP file Claude writes on the WooCommerce/WordPress
# coding standards.
#
# Runs phpcbf first to fix what can be fixed automatically, then phpcs. Anything left
# goes to stderr with exit code 2, which blocks the turn and hands the report back to
# Claude to fix. Exits 0 quietly for non-PHP files and when the toolchain is not
# installed, so a fresh clone is never wedged by a missing vendor/ directory.
#
set -uo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHPCS="$PLUGIN_DIR/vendor/bin/phpcs"
PHPCBF="$PLUGIN_DIR/vendor/bin/phpcbf"

command -v jq >/dev/null 2>&1 || exit 0

FILE_PATH="$(jq -r '.tool_input.file_path // empty' 2>/dev/null)"
[ -n "$FILE_PATH" ] || exit 0

# Only PHP, only inside this plugin, never vendored or generated code.
case "$FILE_PATH" in
	*.php) ;;
	*) exit 0 ;;
esac
case "$FILE_PATH" in
	"$PLUGIN_DIR"/*) ;;
	*) exit 0 ;;
esac
case "$FILE_PATH" in
	*/vendor/*|*/node_modules/*) exit 0 ;;
esac

[ -f "$FILE_PATH" ] || exit 0
[ -x "$PHPCS" ] || exit 0

cd "$PLUGIN_DIR" || exit 0

# phpcbf exits 1 when it successfully fixed something, so its status is not an error signal.
BEFORE_HASH="$(shasum -a 1 "$FILE_PATH" | cut -d' ' -f1)"
[ -x "$PHPCBF" ] && "$PHPCBF" -q "$FILE_PATH" >/dev/null 2>&1
AFTER_HASH="$(shasum -a 1 "$FILE_PATH" | cut -d' ' -f1)"

REPORT="$("$PHPCS" --report=full --no-colors "$FILE_PATH" 2>&1)"
STATUS=$?

if [ "$STATUS" -ne 0 ]; then
	{
		echo "Coding standard violations in $FILE_PATH — fix these before continuing:"
		echo
		echo "$REPORT"
		if [ "$BEFORE_HASH" != "$AFTER_HASH" ]; then
			echo
			echo "Note: phpcbf already reformatted this file. Re-read it before editing again."
		fi
	} >&2
	exit 2
fi

if [ "$BEFORE_HASH" != "$AFTER_HASH" ]; then
	echo "phpcbf auto-formatted $FILE_PATH to the WooCommerce coding standard. Re-read it before editing again." >&2
	exit 2
fi

exit 0
