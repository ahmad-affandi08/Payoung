/**
 * Tur produk interaktif back-office (PLT-12). Tur = daftar langkah yang menyorot elemen bertanda `data-tur="..."` di
 * halaman dan menjelaskannya. Isi langkah ada di sini; server hanya mencatat tur mana yang sudah dilihat pengguna
 * (`KodeTurProduk`). Tur yang isinya berubah materiil diberi kode versi baru (`dashboard.v2`) supaya ditawarkan ulang.
 */
export type LangkahTur = {
    Kunci: string;
    Judul: string;
    Isi: string;
    /** Nama `data-tur` yang disorot; yang pertama terlihat dipakai. Kosong = langkah di tengah layar tanpa sorotan. */
    Target?: string[];
    /** true = langkah dilewati bila tidak ada target yang terlihat (mis. fitur yang tidak ada di edisi atau halaman ini). */
    Opsional?: boolean;
};

export type DefinisiTur = {
    Kode: string;
    Nama: string;
    Langkah: LangkahTur[];
};

export type LangkahAktif = LangkahTur;

export const KodeTurDashboard = 'dashboard.v1';

export const TurDashboard: DefinisiTur = {
    Kode: KodeTurDashboard,
    Nama: 'Tur keliling dashboard',
    Langkah: [
        {
            Kunci: 'sambutan',
            Judul: 'Selamat datang di Payoung',
            Isi: 'Kami tunjukkan bagian yang paling sering dipakai. Hanya sekitar satu menit, dan bisa dilewati kapan saja.',
        },
        {
            Kunci: 'menu',
            Judul: 'Menu samping',
            Isi: 'Semua fitur ada di sini, dikelompokkan menurut pekerjaan sehari-hari: penjualan, stok, pembelian, dan laporan. Di HP, buka lewat tombol menu di kiri atas.',
            Target: ['menu-samping', 'tombol-menu'],
        },
        {
            Kunci: 'pencarian',
            Judul: 'Pencarian cepat',
            Isi: 'Cari halaman atau data dari mana saja. Di komputer, tekan Ctrl+K.',
            Target: ['pencarian-cepat'],
        },
        {
            Kunci: 'notifikasi',
            Judul: 'Notifikasi',
            Isi: 'Kabar penting seperti stok menipis, void besar, atau pesanan online masuk muncul di sini. Jenis kabar yang ingin dibisukan bisa diatur di halamannya.',
            Target: ['notifikasi'],
        },
        {
            Kunci: 'bantuan',
            Judul: 'Bantuan',
            Isi: 'Butuh panduan untuk halaman yang sedang dibuka? Tekan Bantuan. Tur ini juga bisa diulang dari sana.',
            Target: ['bantuan'],
            Opsional: true,
        },
        {
            Kunci: 'siap',
            Judul: 'Siap mulai',
            Isi: 'Itu saja. Selamat bekerja!',
        },
    ],
};

/** Elemen pertama yang terlihat di antara nama `data-tur` yang diberikan (urutan nama = urutan prioritas). */
export function CariTarget(nama: readonly string[] | undefined, akar: ParentNode = document): HTMLElement | null {
    for (const satu of nama ?? []) {
        for (const elemen of Array.from(akar.querySelectorAll<HTMLElement>(`[data-tur="${satu}"]`))) {
            const kotak = elemen.getBoundingClientRect();

            if (kotak.width > 0 && kotak.height > 0) {
                return elemen;
            }
        }
    }

    return null;
}

/**
 * Langkah yang benar-benar ditampilkan: langkah opsional yang targetnya tidak terlihat dibuang. Langkah tidak opsional
 * yang targetnya tidak terlihat tetap ditampilkan di tengah layar (isinya masih berguna tanpa sorotan).
 */
export function SusunLangkahAktif(
    tur: DefinisiTur,
    cari: (nama: readonly string[] | undefined) => HTMLElement | null = CariTarget,
): LangkahAktif[] {
    return tur.Langkah.filter((langkah) => langkah.Opsional !== true || cari(langkah.Target) !== null);
}
