#!/bin/bash
set -e

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Detect Python interpreter
if [ -f "$SCRIPT_DIR/.venv/bin/python" ]; then
    PY="$SCRIPT_DIR/.venv/bin/python"
else
    PY="python3"
fi

# Verify Python is available
if ! command -v "$PY" &> /dev/null; then
    echo "Error: Python interpreter not found at $PY" >&2
    exit 1
fi

# Ensure the 'build' package is available
"$PY" -m pip install --quiet build

# Setup output directory (use temp location, then move to final)
STORAGE_DIR="$SCRIPT_DIR/../storage/app/dist"
TEMP_BUILD_DIR=$(mktemp -d)
trap "rm -rf $TEMP_BUILD_DIR" EXIT

# Build the wheel to the temporary location
cd "$SCRIPT_DIR"
"$PY" -m build --wheel --outdir "$TEMP_BUILD_DIR"

# Find the generated wheel and rename it to slayer_cli-latest.whl
# The wheel is named slayer_cli-<version>-py3-none-any.whl
WHEEL=$(ls "$TEMP_BUILD_DIR"/slayer_cli-*.whl | head -n 1)
if [ -z "$WHEEL" ]; then
    echo "Error: No wheel file found in $TEMP_BUILD_DIR" >&2
    exit 1
fi

# Rename in temp location
LATEST_TEMP="$TEMP_BUILD_DIR/slayer_cli-latest.whl"
mv "$WHEEL" "$LATEST_TEMP"

# Ensure the storage directory exists
mkdir -p "$STORAGE_DIR" 2>/dev/null || true

# Copy the wheel to the final location (using cp to handle permission issues)
# If the directory is owned by a different user, copy will work if the parent dir is writable
FINAL_WHEEL="$STORAGE_DIR/slayer_cli-latest.whl"
cp -f "$LATEST_TEMP" "$FINAL_WHEEL" 2>/dev/null || {
    # If copy fails, try removing the old file and copying again
    rm -f "$FINAL_WHEEL" 2>/dev/null || true
    cp "$LATEST_TEMP" "$FINAL_WHEEL"
}

echo "✓ Built and installed wheel: $FINAL_WHEEL"
