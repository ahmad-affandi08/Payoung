/**
 * F-03 (BR-03.1): pindai barcode produk dengan kamera perangkat (laptop, HP, tablet), tanpa pemindai USB.
 *
 * Mesin deteksi: `BarcodeDetector` bawaan peramban bila ada (Chrome/Edge, cepat dan hemat baterai); selain itu
 * pustaka ZXing yang dimuat malas (iPhone/Safari dan Firefox), supaya halaman produk tidak ikut berat bagi
 * yang tidak memakai kamera. Kamera hanya menyala selama pemindaian dan dimatikan di `Berhenti()`.
 */

/** Jenis barcode 1D yang dipakai barang eceran dan buatan sendiri (EAN/UPC, Code 128/39, ITF). QR tidak dipakai produk. */
export const FORMAT_BARCODE_PRODUK = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'itf'] as const;

type PendeteksiBarcode = { detect: (sumber: CanvasImageSource) => Promise<{ rawValue: string }[]> };
type KelasPendeteksiBarcode = new (opsi: { formats: string[] }) => PendeteksiBarcode;

export type PemindaiAktif = {
    /** Matikan kamera dan hentikan pembacaan. Aman dipanggil berulang. */
    Berhenti: () => void;
    /** Kamera yang sedang dipakai (untuk menandai pilihan di daftar kamera). */
    IdPerangkat: string | null;
};

export type OpsiPemindaian = {
    video: HTMLVideoElement;
    /** Kosong = kamera belakang bila ada. */
    idPerangkat?: string | null;
    saatTerbaca: (nilai: string) => void;
};

/** Kamera hanya tersedia di halaman aman (https atau localhost) pada peramban yang punya `getUserMedia`. */
export function KameraTersedia(): boolean {
    return typeof navigator !== 'undefined' && typeof navigator.mediaDevices?.getUserMedia === 'function';
}

/** Pesan galat kamera dalam bahasa sehari-hari beserta apa yang bisa dilakukan pengguna. */
export function PesanGalatKamera(galat: unknown): string {
    const nama = galat instanceof Error || galat instanceof DOMException ? galat.name : '';

    switch (nama) {
        case 'NotAllowedError':
        case 'SecurityError':
            return 'Izin kamera ditolak. Izinkan kamera untuk situs ini di pengaturan peramban, lalu coba lagi.';
        case 'NotSupportedError':
        case 'TypeError':
            return 'Peramban ini tidak mengizinkan kamera di halaman ini (kamera butuh alamat https). Ketik barcode atau pakai pemindai USB.';
        case 'NotFoundError':
        case 'OverconstrainedError':
            return 'Kamera tidak ditemukan di perangkat ini. Ketik barcode atau pakai pemindai USB.';
        case 'NotReadableError':
        case 'AbortError':
            return 'Kamera sedang dipakai aplikasi lain. Tutup aplikasi itu, lalu coba lagi.';
        default:
            return 'Kamera tidak bisa dibuka. Ketik barcode atau pakai pemindai USB.';
    }
}

/** Daftar kamera video. Nama (label) baru terisi setelah izin kamera diberikan. */
export async function DaftarKamera(): Promise<MediaDeviceInfo[]> {
    if (!KameraTersedia() || typeof navigator.mediaDevices.enumerateDevices !== 'function') {
        return [];
    }

    try {
        return (await navigator.mediaDevices.enumerateDevices()).filter((perangkat) => perangkat.kind === 'videoinput');
    } catch {
        return [];
    }
}

function AmbilKelasPendeteksi(): KelasPendeteksiBarcode | null {
    return (globalThis as { BarcodeDetector?: KelasPendeteksiBarcode }).BarcodeDetector ?? null;
}

/** Buat `BarcodeDetector` yang mendukung jenis barcode produk; null bila peramban/mesinnya tidak mendukung. */
async function BuatPendeteksiBawaan(): Promise<PendeteksiBarcode | null> {
    const kelas = AmbilKelasPendeteksi();

    if (kelas === null) {
        return null;
    }

    try {
        const didukung = await (
            kelas as unknown as { getSupportedFormats?: () => Promise<string[]> }
        ).getSupportedFormats?.();
        const format = didukung ? FORMAT_BARCODE_PRODUK.filter((item) => didukung.includes(item)) : [];

        // Mesin tanpa EAN-13 (mis. sebagian Chrome desktop Linux) tidak berguna untuk barang eceran: pakai ZXing.
        return format.includes('ean_13') ? new kelas({ formats: format }) : null;
    } catch {
        return null;
    }
}

/**
 * Nyalakan kamera pada [video] dan panggil `saatTerbaca` untuk setiap barcode yang terbaca (pemanggil yang
 * memutuskan berhenti). Melempar galat kamera (`PesanGalatKamera`) bila kamera tidak bisa dibuka.
 */
export async function MulaiPemindaian({ video, idPerangkat, saatTerbaca }: OpsiPemindaian): Promise<PemindaiAktif> {
    if (!KameraTersedia()) {
        throw new DOMException('Kamera tidak tersedia di halaman ini', 'NotSupportedError');
    }

    const stream = await navigator.mediaDevices.getUserMedia({
        audio: false,
        video: idPerangkat
            ? { deviceId: { exact: idPerangkat }, width: { ideal: 1280 }, height: { ideal: 720 } }
            : { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
    });
    let berhenti = false;
    let HentikanMesin: () => void = () => undefined;

    const Berhenti = () => {
        if (berhenti) {
            return;
        }

        berhenti = true;
        HentikanMesin();
        stream.getTracks().forEach((jalur) => jalur.stop());
        video.srcObject = null;
    };

    try {
        video.srcObject = stream;
        video.setAttribute('playsinline', 'true');
        video.muted = true;
        await video.play();

        const pendeteksi = await BuatPendeteksiBawaan();

        if (pendeteksi !== null) {
            let jadwal = 0;
            const Baca = async () => {
                try {
                    const hasil = video.readyState >= 2 ? await pendeteksi.detect(video) : [];
                    const nilai = hasil[0]?.rawValue.trim();

                    if (!berhenti && nilai) {
                        saatTerbaca(nilai);
                    }
                } catch {
                    // Satu bingkai gagal dibaca: coba bingkai berikutnya.
                }

                if (!berhenti) {
                    jadwal = window.setTimeout(() => void Baca(), 150);
                }
            };

            HentikanMesin = () => window.clearTimeout(jadwal);
            void Baca();
        } else {
            const { BarcodeFormat, BrowserMultiFormatReader, DecodeHintType } = await import('@zxing/library');
            const petunjuk = new Map<number, unknown>([
                [
                    DecodeHintType.POSSIBLE_FORMATS,
                    [
                        BarcodeFormat.EAN_13,
                        BarcodeFormat.EAN_8,
                        BarcodeFormat.UPC_A,
                        BarcodeFormat.UPC_E,
                        BarcodeFormat.CODE_128,
                        BarcodeFormat.CODE_39,
                        BarcodeFormat.ITF,
                    ],
                ],
                [DecodeHintType.TRY_HARDER, true],
            ]);
            const pembaca = new BrowserMultiFormatReader(petunjuk as Map<never, never>, 150);

            HentikanMesin = () => pembaca.reset();

            if (berhenti) {
                HentikanMesin();
            } else {
                // Bingkai tanpa barcode menghasilkan `NotFoundException` pada argumen kedua: wajar, diabaikan.
                void pembaca.decodeFromStream(stream, video, (hasil) => {
                    const nilai = hasil?.getText().trim();

                    if (!berhenti && nilai) {
                        saatTerbaca(nilai);
                    }
                });
            }
        }
    } catch (galat) {
        Berhenti();

        throw galat;
    }

    return { Berhenti, IdPerangkat: stream.getVideoTracks()[0]?.getSettings().deviceId ?? null };
}
