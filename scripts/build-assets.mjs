import { copyFileSync, mkdirSync, existsSync, readdirSync } from 'node:fs';
import { join, basename, resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');
const dest = (path) => join(root, 'public', 'assets', 'vendor', path);
function copy(source, destination) {
  const origin = join(root, 'node_modules', source);
  if (!existsSync(origin)) throw new Error('Missing dependency asset: ' + source);
  const output = dest(destination);
  mkdirSync(resolve(output, '..'), { recursive: true });
  copyFileSync(origin, output);
  process.stdout.write('bundled ' + destination + '\n');
}
function findFile(dir, file) {
  if (!existsSync(dir)) return null;
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) {
      const found = findFile(path, file);
      if (found) return found;
    } else if (entry.name === file) {
      return path;
    }
  }
  return null;
}
copy('@tabler/core/dist/css/tabler.rtl.min.css', 'tabler/tabler.rtl.min.css');
copy('@tabler/core/dist/js/tabler.min.js', 'tabler/tabler.min.js');
copy('apexcharts/dist/apexcharts.min.js', 'apexcharts/apexcharts.min.js');
copy('jquery/dist/jquery.min.js', 'jquery/jquery.min.js');
copy('persian-date/dist/persian-date.min.js', 'persian-date/persian-date.min.js');
copy('persian-datepicker/dist/css/persian-datepicker.min.css', 'persian-datepicker/persian-datepicker.min.css');
copy('persian-datepicker/dist/js/persian-datepicker.min.js', 'persian-datepicker/persian-datepicker.min.js');

const fontRoot = join(root, 'node_modules', 'vazirmatn', 'fonts');
for (const ext of ['woff2', 'ttf']) {
  for (const style of ['Regular', 'Bold']) {
    const filename = 'Vazirmatn-' + style + '.' + ext;
    const origin = findFile(fontRoot, filename);
    if (!origin) throw new Error('Font missing from installed vazirmatn: ' + filename);
    const output = ext === 'ttf' ? join(root, 'storage', 'fonts', filename) : dest('vazirmatn/' + filename);
    mkdirSync(resolve(output, '..'), { recursive: true });
    copyFileSync(origin, output);
    process.stdout.write('bundled ' + basename(output) + '\n');
  }
}
copy('vazirmatn/OFL.txt', 'vazirmatn/OFL.txt');
console.log('All runtime assets are self-hosted. No CDN is required.');
