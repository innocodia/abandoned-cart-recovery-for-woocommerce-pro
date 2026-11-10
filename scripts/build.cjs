/* eslint-disable */
// Copies the plugin into ./build (minus package.json "buildExclude"), then
// generates a production-only Composer autoloader there: Pro's own classes,
// no dev tools (PHPCS etc.) in the shipped plugin.
const fs = require('fs-extra');
const path = require('path');
const micromatch = require('micromatch');
const { execSync } = require('child_process');

const root = process.cwd();
const buildDir = path.join(root, 'build');
const pkg = require(path.join(root, 'package.json'));
const patterns = pkg.buildExclude || [];

function formatTime(ms) {
  return ms < 1000 ? ms + 'ms' : (ms / 1000).toFixed(2) + 's';
}

function isExcluded(relativePath) {
  return micromatch.isMatch(relativePath.replace(/\\/g, '/'), patterns, { dot: true });
}

let filesCopied = 0;
let filesSkipped = 0;

async function copyRecursive(src, dest) {
  await fs.ensureDir(dest);

  for (const item of await fs.readdir(src)) {
    const srcPath = path.join(src, item);
    const relative = path.relative(root, srcPath);

    if (relative === 'build' || relative.startsWith('build' + path.sep)) continue;

    if (isExcluded(relative)) {
      filesSkipped++;
      continue;
    }

    const destPath = path.join(dest, item);
    if ((await fs.stat(srcPath)).isDirectory()) {
      await copyRecursive(srcPath, destPath);
    } else {
      await fs.copy(srcPath, destPath);
      filesCopied++;
    }
  }
}

async function run() {
  const start = Date.now();
  const col1 = 40;
  const col2 = 10;
  console.log('');

  await fs.remove(buildDir);

  console.log('📑 copying files...');
  await copyRecursive(root, buildDir);

  console.log('🧩 generating production autoloader...');
  execSync('composer dump-autoload --no-dev --classmap-authoritative --no-interaction --quiet', {
    cwd: buildDir,
    stdio: 'inherit',
  });
  // composer.json is only needed to generate the autoloader.
  await fs.remove(path.join(buildDir, 'composer.json'));

  console.log(`   ${'files copied'.padEnd(col1)} ${String(filesCopied).padStart(col2)}`);
  console.log(`   ${'files skipped'.padEnd(col1)} ${String(filesSkipped).padStart(col2)}`);
  console.log('');
  console.log(`⚡ build ready in ${formatTime(Date.now() - start)}`);
}

run().catch((err) => {
  console.error('❌ Error:', err.message || err);
  process.exit(1);
});
