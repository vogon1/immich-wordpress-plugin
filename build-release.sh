#!/bin/bash

# Build & Release Script voor Gallery for Immich WordPress Plugin
# Dit script maakt een distributie ZIP file zoals de GitHub Actions release workflow

set -e  # Stop bij errors

PLUGIN_SLUG="gallery-for-immich"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "🔨 Building release for $PLUGIN_SLUG..."
echo ""

# 1. Install PHP dependencies (indien composer.json bestaat)
if [ -f composer.json ]; then
    echo "📦 Installing PHP dependencies..."
    composer install --no-dev --optimize-autoloader
    echo ""
fi

# 2. Install JS dependencies en build
if [ -f package.json ]; then
    echo "📦 Installing JS dependencies..."
    npm ci
    echo ""
    
    echo "🏗️  Building JS/CSS..."
    npm run build
    echo ""
fi

# 3. Maak clean build directory
echo "🧹 Creating clean build directory..."
rm -rf clean-build dist
mkdir -p clean-build/${PLUGIN_SLUG}
echo ""

# 4. Kopieer files volgens .distignore
echo "📋 Copying plugin files (excluding files from .distignore)..."
rsync -av --exclude-from=.distignore ./ clean-build/${PLUGIN_SLUG}/
echo ""

# 5. Maak ZIP
echo "📦 Creating ZIP file..."
cd clean-build
zip -r ../${PLUGIN_SLUG}.zip ${PLUGIN_SLUG}
cd ..
echo ""

# 6. Verplaats naar dist folder
mkdir -p dist
mv ${PLUGIN_SLUG}.zip dist/
echo ""

# 7. Cleanup
echo "🧹 Cleaning up temporary files..."
rm -rf clean-build
echo ""

echo "✅ Release ZIP created successfully!"
echo "📁 Location: dist/${PLUGIN_SLUG}.zip"
echo ""

# Toon ZIP inhoud
echo "📋 ZIP contents:"
unzip -l dist/${PLUGIN_SLUG}.zip | head -20
echo ""
echo "💡 To see full contents: unzip -l dist/${PLUGIN_SLUG}.zip"
