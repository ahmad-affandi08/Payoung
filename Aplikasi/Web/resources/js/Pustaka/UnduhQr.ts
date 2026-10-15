/** Nama berkas unduhan QR: `QRIS-INV-2026-0042.png`; karakter di luar huruf, angka, dan tanda hubung diganti `-`. */
export function NamaBerkasQr(nomorTagihan: string): string {
    const bersih = nomorTagihan.replace(/[^A-Za-z0-9-]+/g, '-').replace(/^-+|-+$/g, '');

    return `QRIS-${bersih === '' ? 'tagihan' : bersih}.png`;
}

function MuatGambar(sumber: string): Promise<HTMLImageElement> {
    return new Promise((selesai, gagal) => {
        const gambar = new Image();
        gambar.onload = () => selesai(gambar);
        gambar.onerror = () => gagal(new Error('Gambar QR tidak bisa dibaca.'));
        gambar.src = sumber;
    });
}

/**
 * Mengunduh QR (SVG dari server) sebagai PNG 1024 px berlatar putih, supaya bisa dipilih dari galeri di aplikasi bank atau
 * dompet digital bila kamera tidak bisa memindai layar. Rasterisasi di peramban; tidak ada yang dikirim ke server.
 */
export async function UnduhGambarQr(svg: string, namaBerkas: string): Promise<void> {
    const gambar = await MuatGambar(`data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`);
    const sisi = 1024;
    const kanvas = document.createElement('canvas');
    kanvas.width = sisi;
    kanvas.height = sisi;
    const konteks = kanvas.getContext('2d');

    if (konteks === null) {
        throw new Error('Peramban tidak mendukung pengunduhan gambar.');
    }

    // Latar mengikuti token permukaan (putih); kata kunci `white` hanya cadangan bila token tidak terbaca.
    konteks.fillStyle =
        getComputedStyle(document.documentElement).getPropertyValue('--color-permukaan').trim() || 'white';
    konteks.fillRect(0, 0, sisi, sisi);
    konteks.drawImage(gambar, 0, 0, sisi, sisi);

    const blob = await new Promise<Blob | null>((selesai) => kanvas.toBlob(selesai, 'image/png'));

    if (blob === null) {
        throw new Error('Gambar QR gagal dibuat.');
    }

    const alamat = URL.createObjectURL(blob);
    const tautan = document.createElement('a');
    tautan.href = alamat;
    tautan.download = namaBerkas;
    document.body.append(tautan);
    tautan.click();
    tautan.remove();
    window.setTimeout(() => URL.revokeObjectURL(alamat), 1000);
}
