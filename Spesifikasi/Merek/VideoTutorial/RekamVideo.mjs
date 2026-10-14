// Merekam satu motion tutorial (HTML) menjadi MP4 1920x1080 lengkap dengan audio, bingkai demi bingkai (deterministik).
// Butuh: Playwright (Chromium) dan ffmpeg. Jalankan dari akar repo:
//   NODE_PATH=$(npm root -g) node Spesifikasi/Merek/VideoTutorial/RekamVideo.mjs [berkas.html] [keluaran.mp4] [fps]
// Bawaan: CaraDaftar30Detik.html → CaraDaftar30Detik.mp4, 30 fps.
// Path ffmpeg bisa diatur lewat variabel lingkungan FFMPEG.
import { spawn } from 'node:child_process';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { basename, dirname, join, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const folderIni = dirname(fileURLToPath(import.meta.url));
const pathHalaman = resolve(folderIni, process.argv[2] ?? 'CaraDaftar30Detik.html');
const pathKeluaran = resolve(process.argv[3] ?? pathHalaman.replace(/\.html$/i, '.mp4'));
const fps = Number(process.argv[4] ?? 30);
const alamatHalaman = pathToFileURL(pathHalaman).href + '?rekam=1';

async function RekamVideo() {
  const peramban = await chromium.launch();
  const halaman = await peramban.newPage({ viewport: { width: 1920, height: 1080 } });
  await halaman.goto(alamatHalaman);
  await halaman.waitForFunction(() => document.body.dataset.siap === '1', null, { timeout: 60000 });
  const hurufSiap = await halaman.evaluate(() => document.fonts.check('800 40px "Atkinson Hyperlegible Next"'));
  if (!hurufSiap) throw new Error('Font Atkinson Hyperlegible belum termuat; rekaman dibatalkan.');

  const folderSementara = mkdtempSync(join(tmpdir(), 'rekam-payoung-'));
  const pathAudio = join(folderSementara, 'Audio.wav');
  writeFileSync(pathAudio, Buffer.from(await halaman.evaluate(() => window.EksporWav()), 'base64'));

  const durasiDetik = await halaman.evaluate(() => window.DurasiDetik);
  const jumlahBingkai = Math.round(durasiDetik * fps);

  const ffmpeg = spawn(process.env.FFMPEG ?? 'ffmpeg', [
    '-y', '-loglevel', 'error',
    '-f', 'image2pipe', '-framerate', String(fps), '-i', '-',
    '-i', pathAudio,
    '-map', '0:v', '-map', '1:a',
    '-c:v', 'libx264', '-preset', 'slow', '-crf', '16', '-pix_fmt', 'yuv420p',
    '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11', '-ar', '44100',
    '-c:a', 'aac', '-b:a', '192k', '-shortest', '-movflags', '+faststart',
    pathKeluaran,
  ], { stdio: ['pipe', 'inherit', 'inherit'] });
  const selesai = new Promise((lanjut, gagal) => ffmpeg.on('close', (kode) => (kode === 0 ? lanjut() : gagal(new Error(`ffmpeg keluar dengan kode ${kode}`)))));

  for (let urutan = 0; urutan < jumlahBingkai; urutan += 1) {
    await halaman.evaluate((detik) => window.AturWaktu(detik), urutan / fps);
    const gambar = await halaman.screenshot({ type: 'png' });
    if (!ffmpeg.stdin.write(gambar)) await new Promise((lanjut) => ffmpeg.stdin.once('drain', lanjut));
    if (urutan % fps === 0) process.stdout.write(`\rMerekam ${basename(pathHalaman)} ${Math.round(urutan / fps)}/${durasiDetik} detik`);
  }
  ffmpeg.stdin.end();
  await selesai;
  await peramban.close();
  rmSync(folderSementara, { recursive: true, force: true });
  process.stdout.write(`\nSelesai: ${pathKeluaran}\n`);
}

await RekamVideo();
