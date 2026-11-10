/* eslint-disable */
// Zips ./build as <slug>/… (the folder WordPress installs into) into
// ./<slug>.zip, then removes ./build.
const fs = require('fs-extra');
const path = require('path');
const archiver = require('archiver');

const root = process.cwd();
const buildDir = path.join(root, 'build');
const slug = 'abandoned-cart-recovery-for-woocommerce-pro';
const zipName = slug + '.zip';
const outputPath = path.join(root, zipName);

function formatBytes(bytes) {
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + ' kB';
  return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}

function formatTime(ms) {
  return ms < 1000 ? ms + 'ms' : (ms / 1000).toFixed(2) + 's';
}

function createZip() {
  return new Promise((resolve, reject) => {
    const output = fs.createWriteStream(outputPath);
    const archive = archiver('zip', { zlib: { level: 9 } });
    let files = 0;

    archive.on('entry', (entry) => {
      if (!entry.stats.isDirectory()) files++;
    });
    output.on('close', () => resolve({ files, size: archive.pointer() }));
    archive.on('warning', (err) => (err.code === 'ENOENT' ? null : reject(err)));
    archive.on('error', reject);

    archive.pipe(output);
    archive.directory(buildDir + '/', slug);
    archive.finalize();
  });
}

(async () => {
  try {
    if (!(await fs.pathExists(buildDir))) {
      throw new Error('No ./build folder. Run "npm run plugin:prepare" first.');
    }

    const start = Date.now();
    const col1 = 40;
    const col2 = 10;
    console.log('');
    console.log('📦 creating zip...');
    await fs.remove(outputPath);
    const { files, size } = await createZip();
    await fs.remove(buildDir);

    console.log('   ' + zipName);
    console.log(`   ${'files'.padEnd(col1)} ${String(files).padStart(col2)}`);
    console.log(`   ${'compressed'.padEnd(col1)} ${formatBytes(size).padStart(col2)}`);
    console.log('');
    console.log(`⚡ zipped in ${formatTime(Date.now() - start)}`);
    console.log('');
  } catch (err) {
    console.error('❌ Error:', err.message || err);
    process.exit(1);
  }
})();
