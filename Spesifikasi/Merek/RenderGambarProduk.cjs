// Ekspor SVG gambar produk demo ke PNG 256 px dengan Chromium. Pakai: NODE_PATH=<node_modules> node RenderGambarProduk.cjs <berkas.svg...>
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROMIUM ?? '/opt/pw-browsers/chromium' });
  const p = await b.newPage({ viewport: { width: 256, height: 256 } });
  for (const berkas of process.argv.slice(2)) {
    await p.setContent(`<html><body style="margin:0">${fs.readFileSync(berkas, 'utf8')}</body></html>`);
    await p.screenshot({ path: path.join(path.dirname(berkas), '..', path.basename(berkas).replace(/\.svg$/, '.png')) });
  }
  await b.close();
})();
