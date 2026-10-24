import type { Human, Config } from '@vladmandic/human';

import { KeSidikWajah, NilaiWajah, type KotakWajah, type PenilaianWajah } from '@/Fitur/Absensi/SidikWajah';

/**
 * Pembungkus model wajah di browser (pustaka Human, MIT) untuk absensi web (F-18 bagian 4, D-37, D-186). Dimuat hanya
 * di halaman absen (impor dinamis), model dari `/model-wajah/` milik server sendiri, tanpa layanan pihak ketiga.
 * Keputusan cocok-tidaknya wajah dibuat server; di sini hanya deteksi, skor wajah asli, dan sidik wajah.
 *
 * Kecepatan (D-186): mesin disiapkan **begitu halaman terbuka** (bukan saat tombol diketuk) dan dipanaskan sekali
 * dengan gambar contoh, supaya bingkai kamera pertama tidak menunggu kompilasi shader. Model disimpan Human di
 * IndexedDB, jadi kunjungan berikutnya hanya membaca dari perangkat.
 */
const KONFIGURASI: Partial<Config> = {
    backend: 'humangl',
    async: true,
    warmup: 'face',
    cacheSensitivity: 0,
    cacheModels: true,
    debug: false,
    face: {
        enabled: true,
        detector: { enabled: true, rotation: false, maxDetected: 2, return: false },
        mesh: { enabled: true },
        iris: { enabled: false },
        description: { enabled: true },
        emotion: { enabled: false },
        antispoof: { enabled: true },
        liveness: { enabled: true },
        attention: { enabled: false },
    },
    body: { enabled: false },
    hand: { enabled: false },
    object: { enabled: false },
    gesture: { enabled: false },
    segmentation: { enabled: false },
};

/** Jumlah model yang dimuat (blazeface, facemesh, faceres, antispoof, liveness); dasar persen muat. */
const JUMLAH_MODEL = 5;

export type TahapMesin = 'belum' | 'memuat' | 'memanaskan' | 'siap' | 'galat';

export type StatusMesin = { Tahap: TahapMesin; Persen: number };

export type HasilPindai = {
    Penilaian: PenilaianWajah;
    /** Sidik wajah (bilangan bulat) bila model deskriptor dijalankan dan wajah lolos. */
    Sidik: number[] | null;
};

let mesin: Promise<Human> | null = null;
let status: StatusMesin = { Tahap: 'belum', Persen: 0 };
const pendengar = new Set<() => void>();

function AturStatus(baru: StatusMesin): void {
    if (baru.Tahap === status.Tahap && baru.Persen === status.Persen) {
        return;
    }

    status = baru;
    pendengar.forEach((p) => p());
}

export function AmbilStatusMesin(): StatusMesin {
    return status;
}

export function LanggananStatusMesin(pendengarBaru: () => void): () => void {
    pendengar.add(pendengarBaru);

    return () => {
        pendengar.delete(pendengarBaru);
    };
}

/**
 * Menyiapkan mesin sekali per halaman: unduh & muat model, lalu panaskan. Dipanggil lagi = menunggu janji yang sama;
 * bila gagal, panggilan berikutnya mencoba dari awal (jaringan putus tidak mengunci halaman sampai dimuat ulang).
 */
export function MuatMesinWajah(alamatModel: string): Promise<Human> {
    mesin ??= (async () => {
        AturStatus({ Tahap: 'memuat', Persen: 0 });

        try {
            const { Human: KelasHuman } = await import('@vladmandic/human');
            const human = new KelasHuman({ ...KONFIGURASI, modelBasePath: `${alamatModel.replace(/\/$/, '')}/` });
            const pantau = setInterval(() => {
                const dimuat = human.models.stats().numLoadedModels;
                AturStatus({ Tahap: 'memuat', Persen: Math.min(85, Math.round((dimuat / JUMLAH_MODEL) * 85)) });
            }, 150);

            try {
                await human.load();
            } finally {
                clearInterval(pantau);
            }

            AturStatus({ Tahap: 'memanaskan', Persen: 85 });
            await human.warmup().catch(() => undefined);
            AturStatus({ Tahap: 'siap', Persen: 100 });

            return human;
        } catch (galat) {
            mesin = null;
            AturStatus({ Tahap: 'galat', Persen: 0 });

            throw galat;
        }
    })();

    return mesin;
}

/**
 * Satu kali deteksi dari video kamera. [denganSidik] menjalankan model deskriptor (model terberat); bingkai tanpa
 * itu hanya menilai keberadaan, posisi, dan keaslian wajah sehingga umpan balik di layar tetap cepat.
 */
export async function PindaiWajah(human: Human, video: HTMLVideoElement, denganSidik: boolean): Promise<HasilPindai> {
    const hasil = await human.detect(video, { face: { description: { enabled: denganSidik } } });
    const wajah = hasil.face.map((f) => ({
        ...(f.real === undefined ? {} : { real: f.real }),
        ...(f.live === undefined ? {} : { live: f.live }),
        ...(f.embedding === undefined ? {} : { embedding: f.embedding }),
        box: f.box satisfies KotakWajah,
    }));
    const penilaian = NilaiWajah(wajah, {
        ukuranVideo: { lebar: video.videoWidth, tinggi: video.videoHeight },
        butuhSidik: denganSidik,
    });
    const deskriptor = hasil.face[0]?.embedding;

    return {
        Penilaian: penilaian,
        Sidik: denganSidik && penilaian.Lolos && deskriptor ? KeSidikWajah(deskriptor) : null,
    };
}

/** Swafoto JPEG base64 (tanpa awalan data URL) maksimal 480 px, sesuai batas ukuran swafoto server. */
export function AmbilSwafoto(video: HTMLVideoElement): string {
    const skala = Math.min(1, 480 / Math.max(video.videoWidth, video.videoHeight, 1));
    const kanvas = document.createElement('canvas');
    kanvas.width = Math.round(video.videoWidth * skala);
    kanvas.height = Math.round(video.videoHeight * skala);
    kanvas.getContext('2d')?.drawImage(video, 0, 0, kanvas.width, kanvas.height);

    return kanvas.toDataURL('image/jpeg', 0.7).replace(/^data:image\/jpeg;base64,/, '');
}
