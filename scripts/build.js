/**
 * Copia o site estático para dist/ (pronto para deploy em GitHub Pages, FTP, etc.)
 */
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const dist = path.join(root, 'dist');

function copyDir(src, dest) {
  fs.mkdirSync(dest, { recursive: true });
  for (const ent of fs.readdirSync(src, { withFileTypes: true })) {
    const from = path.join(src, ent.name);
    const to = path.join(dest, ent.name);
    if (ent.isDirectory()) copyDir(from, to);
    else fs.copyFileSync(from, to);
  }
}

fs.rmSync(dist, { recursive: true, force: true });
fs.mkdirSync(dist, { recursive: true });

fs.copyFileSync(path.join(root, 'index.html'), path.join(dist, 'index.html'));

for (const dir of ['logo', 'fotos']) {
  const p = path.join(root, dir);
  if (fs.existsSync(p)) copyDir(p, path.join(dist, dir));
}

console.log('Build concluído: pasta dist/');
console.log('  - index.html');
console.log('  - logo/');
console.log('  - fotos/');
