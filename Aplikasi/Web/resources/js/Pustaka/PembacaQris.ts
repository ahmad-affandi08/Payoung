/**
 * F-08 (D-189): membaca teks QR dari berkas gambar QRIS yang diunggah pemilik toko, di peramban (gambar tidak dikirim
 * ke pihak lain). `BarcodeDetector` bawaan peramban dipakai bila ada (Chrome/Edge/Safari baru); selain itu ZXing yang
 * dimuat malas. Gambar QRIS dari bank sering besar dengan logo di tengah, jadi dicoba pada beberapa ukuran.
 */

type PendeteksiBarcode = { detect: (sumber: ImageBitmapSource) => Promise<{ rawValue: string }[]> };
type KelasPendeteksiBarcode = new (opsi: { formats: string[] }) => PendeteksiBarcode;

/**
 * Sisi terpanjang (piksel) yang dicoba berturut-turut; 0 = ukuran asli. ZXing sangat peka pada ukuran modul QR (hasil
 * pengecilan gambar yang tidak pas membuat kode tidak terdeteksi), jadi gambar dicoba pada banyak ukuran. Diukur pada
 * QRIS 230 karakter dengan zona tenang lebar, berlogo, dan PNG transparan: setiap contoh terbaca dalam kurang dari
 * setengah detik, sedangkan satu ukuran saja gagal pada sebagian besar contoh.
 */
const UKURAN_COBA = [0, 1600, 1400, 1200, 1000, 900, 800, 700, 600, 500, 450, 400, 350, 300, 250, 200];

async function BukaGambar(berkas: Blob): Promise<ImageBitmap | null> {
    try {
        return await createImageBitmap(berkas);
    } catch {
        return null;
    }
}

function GambarKeKanvas(gambar: ImageBitmap, sisiMaksimal: number): HTMLCanvasElement {
    const skala = sisiMaksimal > 0 ? Math.min(1, sisiMaksimal / Math.max(gambar.width, gambar.height)) : 1;
    const kanvas = document.createElement('canvas');
    kanvas.width = Math.max(1, Math.round(gambar.width * skala));
    kanvas.height = Math.max(1, Math.round(gambar.height * skala));
    const konteks = kanvas.getContext('2d', { willReadFrequently: true });

    if (konteks) {
        // Latar putih: PNG transparan dengan QR hitam akan terbaca hitam-di-hitam tanpa ini.
        konteks.fillStyle = '#ffffff';
        konteks.fillRect(0, 0, kanvas.width, kanvas.height);
        konteks.drawImage(gambar, 0, 0, kanvas.width, kanvas.height);
    }

    return kanvas;
}

async function BacaDenganDetektor(kanvas: HTMLCanvasElement): Promise<string | null> {
    const Kelas = (globalThis as { BarcodeDetector?: KelasPendeteksiBarcode }).BarcodeDetector;

    if (Kelas === undefined) {
        return null;
    }

    try {
        const hasil = await new Kelas({ formats: ['qr_code'] }).detect(kanvas);

        return hasil[0]?.rawValue ?? null;
    } catch {
        return null;
    }
}

async function BacaDenganZxing(kanvas: HTMLCanvasElement): Promise<string | null> {
    const {
        BarcodeFormat,
        BinaryBitmap,
        DecodeHintType,
        GlobalHistogramBinarizer,
        HTMLCanvasElementLuminanceSource,
        HybridBinarizer,
        QRCodeReader,
    } = await import('@zxing/library');
    const petunjuk = new Map<number, unknown>([
        [DecodeHintType.POSSIBLE_FORMATS, [BarcodeFormat.QR_CODE]],
        [DecodeHintType.TRY_HARDER, true],
    ]) as Map<never, never>;
    let sumber: InstanceType<typeof HTMLCanvasElementLuminanceSource>;

    try {
        sumber = new HTMLCanvasElementLuminanceSource(kanvas);
    } catch {
        // Kanvas tidak bisa dibaca pikselnya (konteks 2D tidak tersedia): lewati mesin ini.
        return null;
    }

    // Dua cara membinerkan gambar: lokal (Hybrid) bagus untuk pencahayaan tak rata, global untuk gambar bersih.
    for (const Binarizer of [HybridBinarizer, GlobalHistogramBinarizer]) {
        try {
            return new QRCodeReader().decode(new BinaryBitmap(new Binarizer(sumber)), petunjuk).getText();
        } catch {
            // Tidak terdeteksi pada ukuran/binarisasi ini; coba berikutnya.
        }
    }

    return null;
}

/** Teks QR dalam [berkas], atau null bila tidak ada QR yang terbaca. */
export async function BacaQrDariBerkas(berkas: Blob): Promise<string | null> {
    const gambar = await BukaGambar(berkas);

    if (gambar === null) {
        return null;
    }

    try {
        for (const ukuran of UKURAN_COBA) {
            const kanvas = GambarKeKanvas(gambar, ukuran);
            const teks = (await BacaDenganDetektor(kanvas)) ?? (await BacaDenganZxing(kanvas));

            if (teks !== null && teks.trim() !== '') {
                return teks.trim();
            }
        }

        return null;
    } finally {
        gambar.close();
    }
}
