#!/usr/bin/env node
/**
 * Copy Vendor Assets
 *
 * Automatically copies third-party library assets from node_modules to public/assets/vendor
 * This runs after npm install via the postinstall script.
 */

const fs = require('fs');
const path = require('path');

/**
 * Recursively copy directory
 */
function copyDir(src, dest) {
    // Create destination directory if it doesn't exist
    if (!fs.existsSync(dest)) {
        fs.mkdirSync(dest, { recursive: true });
    }

    // Read source directory
    const entries = fs.readdirSync(src, { withFileTypes: true });

    for (const entry of entries) {
        const srcPath = path.join(src, entry.name);
        const destPath = path.join(dest, entry.name);

        if (entry.isDirectory()) {
            copyDir(srcPath, destPath);
        } else {
            fs.copyFileSync(srcPath, destPath);
        }
    }
}

/**
 * Copy a single file
 */
function copyFile(src, dest) {
    const destDir = path.dirname(dest);
    if (!fs.existsSync(destDir)) {
        fs.mkdirSync(destDir, { recursive: true });
    }
    fs.copyFileSync(src, dest);
}

// Define assets to copy
const assets = [
    {
        name: 'Green Audio Player',
        from: 'node_modules/green-audio-player/dist',
        to: 'public/assets/vendor/green-audio-player'
    }
];

console.log('📦 Copying vendor assets to public/assets/vendor...\n');

let successCount = 0;
let errorCount = 0;

for (const asset of assets) {
    try {
        const srcPath = path.resolve(asset.from);
        const destPath = path.resolve(asset.to);

        // Check if source exists
        if (!fs.existsSync(srcPath)) {
            console.log(`⚠️  Skipping ${asset.name}: source not found (${asset.from})`);
            errorCount++;
            continue;
        }

        // Copy directory
        copyDir(srcPath, destPath);
        console.log(`✓ ${asset.name}: ${asset.from} → ${asset.to}`);
        successCount++;
    } catch (error) {
        console.error(`✗ ${asset.name}: ${error.message}`);
        errorCount++;
    }
}

console.log(`\n📊 Summary: ${successCount} copied, ${errorCount} failed\n`);

if (errorCount > 0) {
    console.log('⚠️  Some assets failed to copy. Plugin functionality may be limited.');
}

process.exit(0);
