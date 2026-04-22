/**
 * Copia o site estático para dist/ (pronto para deploy em GitHub Pages, FTP, etc.)
 */
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const dist = path.join(root, 'dist');

const arquivosIgnoredEntries = new Set([
  'Backgroud 02.cdr',
  'color',
  'content',
  'Logotipo Mazani.cdr',
  'Mangueira',
  'META-INF',
  'Midia 02.cdr',
  'Midia 04.cdr',
  'Paleta de cor.cdr',
  'previews',
  'styles',
]);

function copyDir(src, dest, shouldIgnore) {
  fs.mkdirSync(dest, { recursive: true });
  for (const ent of fs.readdirSync(src, { withFileTypes: true })) {
    const from = path.join(src, ent.name);
    const to = path.join(dest, ent.name);
    if (typeof shouldIgnore === 'function' && shouldIgnore(from)) continue;
    if (ent.isDirectory()) copyDir(from, to, shouldIgnore);
    else fs.copyFileSync(from, to);
  }
}

fs.rmSync(dist, { recursive: true, force: true });
fs.mkdirSync(dist, { recursive: true });

fs.copyFileSync(path.join(root, 'index.html'), path.join(dist, 'index.html'));
fs.copyFileSync(path.join(root, 'internal.html'), path.join(dist, 'internal.html'));
fs.copyFileSync(path.join(root, 'projeto.html'), path.join(dist, 'projeto.html'));

for (const dir of ['logo', 'fotos', 'arquivos', 'api']) {
  const p = path.join(root, dir);
  if (!fs.existsSync(p)) continue;
  if (dir !== 'arquivos') {
    copyDir(p, path.join(dist, dir));
    continue;
  }
  copyDir(p, path.join(dist, dir), function shouldIgnoreArquivos(entryPath) {
    const relativePath = path.relative(p, entryPath);
    const topLevelEntry = relativePath.split(path.sep)[0];
    return arquivosIgnoredEntries.has(topLevelEntry);
  });
}

console.log('Build concluído: pasta dist/');
console.log('  - index.html');
console.log('  - internal.html');
console.log('  - projeto.html');
console.log('  - logo/');
console.log('  - fotos/');
console.log('  - arquivos/ (sem pastas de design e fontes auxiliares)');
console.log('  - api/ (integracao com banco)');
