#!/bin/bash
# Usage: ./bump-version.sh [major|minor|patch|x.y.z]
# Defaults to 'patch' if no argument given.
set -e

CURRENT=$(grep -o '"version": *"[^"]*"' composer.json | head -1 | grep -o '[0-9][0-9.]*')
if [[ -z "$CURRENT" ]]; then
    echo "Error: Could not read current version from composer.json"
    exit 1
fi

IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT"

case "${1:-patch}" in
    major) MAJOR=$((MAJOR + 1)); MINOR=0; PATCH=0 ;;
    minor) MINOR=$((MINOR + 1)); PATCH=0 ;;
    patch) PATCH=$((PATCH + 1)) ;;
    [0-9]*) IFS='.' read -r MAJOR MINOR PATCH <<< "$1" ;;
    *) echo "Usage: $0 [major|minor|patch|x.y.z]"; exit 1 ;;
esac

NEW="${MAJOR}.${MINOR}.${PATCH}"
echo "Bumping version: ${CURRENT} -> ${NEW}"

# -i takes no argument on GNU sed and a mandatory one on BSD/macOS sed, so pick
# per-platform rather than assuming a dev machine.
if sed --version >/dev/null 2>&1; then
    SED_INPLACE=(-i)
else
    SED_INPLACE=(-i '')
fi

sed "${SED_INPLACE[@]}" "s/\"version\": *\"${CURRENT}\"/\"version\": \"${NEW}\"/" composer.json
sed "${SED_INPLACE[@]}" "s/setup_version=\"${CURRENT}\"/setup_version=\"${NEW}\"/" etc/module.xml

echo "Updated composer.json and etc/module.xml to ${NEW}"
