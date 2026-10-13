/**
 * Logika murni wizard "Tambah promo" (F-16c): daftar langkah, pemetaan kunci galat server ke langkah, validasi klien
 * per langkah, dan kalimat ringkasan. Tanpa React supaya mudah dites. Server (`SimpanPromo`) tetap penentu; aturan di
 * bawah mencerminkannya supaya galat tertangkap sebelum pengguna sampai di langkah Tinjau.
 */
import type { LangkahWizard } from '@/Komponen/Formulir/Wizard/TipeWizard';
import { FormatRupiah } from '@/Pustaka/Format';
import type {
    JenisAksiPromo,
    JenisKondisiPromo,
    JenisUlangTahunPromo,
    OpsiNilai,
    PeriodeBatasPelangganPromo,
} from '@/Tipe/Promo';

export type KunciLangkahPromo = 'JenisNama' | 'Potongan' | 'Barang' | 'Waktu' | 'Pelanggan' | 'Tinjau';

export type LangkahPromo = LangkahWizard<KunciLangkahPromo>;

export const urutanLangkahPromo: KunciLangkahPromo[] = [
    'JenisNama',
    'Potongan',
    'Barang',
    'Waktu',
    'Pelanggan',
    'Tinjau',
];

export type IsianPromo = {
    Kode: string;
    Nama: string;
    Prioritas: string;
    Eksklusif: boolean;
    WajibVoucher: boolean;
    TanggalMulai: string;
    TanggalSelesai: string;
    Kuota: string;
    Hari: string[];
    JamMulai: string;
    JamSelesai: string;
    Outlet: string[];
    Kanal: string[];
    Tier: string[];
    MinimalSubtotal: string;
    JenisKondisi: JenisKondisiPromo;
    UuidKondisi: string[];
    JumlahMinimal: string;
    JenisAksi: JenisAksiPromo;
    Persen: string;
    Jumlah: string;
    Harga: string;
    Beli: string;
    Gratis: string;
    PersenGratis: string;
    Pengali: string;
    UuidPemasok: string;
    PersenDanaPemasok: string;
    BatasPerTransaksi: string;
    MetodeBayar: string[];
    UlangTahun: JenisUlangTahunPromo | '';
    HariUlangTahun: string;
    TransaksiPertama: boolean;
    BatasPerPelanggan: string;
    PeriodeBatasPelanggan: PeriodeBatasPelangganPromo;
};

export const langkahPromo: LangkahPromo[] = [
    {
        Kunci: 'JenisNama',
        Label: 'Jenis & nama',
        Keterangan: 'Pilih jenis potongannya, lalu beri kode dan nama yang mudah dikenali kasir.',
    },
    {
        Kunci: 'Potongan',
        Label: 'Potongan',
        Keterangan: 'Isi besar potongan sesuai jenis yang dipilih.',
    },
    {
        Kunci: 'Barang',
        Label: 'Barang',
        Keterangan: 'Barang apa saja yang kena promo ini.',
    },
    {
        Kunci: 'Waktu',
        Label: 'Syarat & waktu',
        Keterangan:
            'Kapan promo berlaku, di outlet dan kanal mana, serta syarat belanjanya. Kosong berarti tanpa batas.',
    },
    {
        Kunci: 'Pelanggan',
        Label: 'Pembayaran & pelanggan',
        Keterangan: 'Syarat metode bayar dan pelanggan, serta pemasok yang ikut menanggung. Semua boleh dikosongkan.',
    },
    {
        Kunci: 'Tinjau',
        Label: 'Tinjau & simpan',
        Keterangan: 'Periksa ringkasan di bawah. Ubah bagian yang perlu, lalu simpan.',
    },
];

/** Jenis potongan yang menurunkan harga lewat isian "Pendanaan promo" (pemasok menanggung sebagian potongan). */
export const AdaPendanaanPemasok = (aksi: JenisAksiPromo): boolean =>
    aksi !== 'PoinBerlipat' && aksi !== 'GratisOngkir';

const kunciJenisNama = ['Kode', 'Nama', 'Prioritas', 'JenisAksi', 'Eksklusif', 'WajibVoucher'];
const kunciPotongan = ['Persen', 'Jumlah', 'Harga', 'Beli', 'Gratis', 'PersenGratis', 'Pengali', 'BatasPerTransaksi'];
const kunciBarang = ['JenisKondisi', 'UuidKondisi'];
const kunciWaktu = [
    'MinimalSubtotal',
    'Kuota',
    'TanggalMulai',
    'TanggalSelesai',
    'MulaiPada',
    'SelesaiPada',
    'JamMulai',
    'JamSelesai',
    'Hari',
    'Kanal',
    'Tier',
    'Outlet',
];
const kunciPelanggan = [
    'UuidPemasok',
    'PersenDanaPemasok',
    'MetodeBayar',
    'UlangTahun',
    'HariUlangTahun',
    'TransaksiPertama',
    'BatasPerPelanggan',
    'PeriodeBatasPelanggan',
];

/**
 * Langkah yang memuat isian dari kunci galat server. `JumlahMinimal` ada di dua tempat: isi satu bundel (langkah
 * Potongan) dan jumlah barang minimal (langkah Barang), jadi bergantung jenis potongan. Null = tidak terikat isian.
 */
export function PetakanGalatPromoKeLangkah(kunci: string, aksi: JenisAksiPromo): KunciLangkahPromo | null {
    if (kunci === 'JumlahMinimal') {
        return aksi === 'BundelHargaTetap' || aksi === 'BeliXGratisY' ? 'Potongan' : 'Barang';
    }

    if (kunciJenisNama.includes(kunci)) {
        return 'JenisNama';
    }

    if (kunciPotongan.includes(kunci)) {
        return 'Potongan';
    }

    if (kunciBarang.includes(kunci)) {
        return 'Barang';
    }

    if (kunciWaktu.includes(kunci)) {
        return 'Waktu';
    }

    return kunciPelanggan.includes(kunci) ? 'Pelanggan' : null;
}

/** Langkah-langkah yang memuat galat (urut baku), untuk penanda "perlu diperbaiki". */
export function HitungLangkahPromoBergalat(
    galat: Record<string, string | undefined>,
    aksi: JenisAksiPromo,
): KunciLangkahPromo[] {
    const ada = new Set<KunciLangkahPromo>();

    for (const [kunci, pesan] of Object.entries(galat)) {
        const langkah = pesan ? PetakanGalatPromoKeLangkah(kunci, aksi) : null;

        if (langkah !== null) {
            ada.add(langkah);
        }
    }

    return urutanLangkahPromo.filter((kunci) => ada.has(kunci));
}

/** Galat yang tampil di satu langkah (kunci tak terpetakan tidak termasuk). */
export function AmbilGalatLangkahPromo(
    galat: Record<string, string | undefined>,
    kunci: KunciLangkahPromo,
    aksi: JenisAksiPromo,
): Record<string, string | undefined> {
    return Object.fromEntries(
        Object.entries(galat).filter(
            ([isi, pesan]) => Boolean(pesan) && PetakanGalatPromoKeLangkah(isi, aksi) === kunci,
        ),
    );
}

const CekPolaDesimal = (nilai: string, maksDesimal: number): boolean =>
    new RegExp(`^\\d{1,14}(\\.\\d{1,${String(maksDesimal)}})?$`).test(nilai.trim());
const CekPolaBulat = (nilai: string): boolean => /^\d{1,6}$/.test(nilai.trim());
/** Lebih dari nol tanpa mengubah ke float: ada digit bukan nol. */
const LebihDariNol = (nilai: string, maksDesimal = 2): boolean =>
    CekPolaDesimal(nilai, maksDesimal) && /[1-9]/.test(nilai);
const DalamRentang = (nilai: string, minimum: number, maksimum: number): boolean =>
    CekPolaBulat(nilai) && Number(nilai) >= minimum && Number(nilai) <= maksimum;
const PersenValid = (nilai: string): boolean => LebihDariNol(nilai, 2) && Number(nilai) <= 100;

/**
 * Galat klien satu langkah, berkunci sama dengan galat server (`Persen`, `Beli`, `UuidKondisi`, ...) supaya isian yang
 * sama menampilkannya. Kosong = langkah boleh dilanjutkan. `Tinjau` tidak punya isian sendiri.
 */
export function PeriksaLangkahPromo(kunci: KunciLangkahPromo, isian: IsianPromo): Record<string, string> {
    const galat: Record<string, string> = {};
    const aksi = isian.JenisAksi;

    switch (kunci) {
        case 'JenisNama': {
            const kode = isian.Kode.trim();

            if (kode === '') {
                galat.Kode = 'Isi kode promo dulu.';
            } else if (!/^[A-Za-z0-9_-]{1,30}$/.test(kode)) {
                galat.Kode = 'Kode promo 1–30 huruf, angka, garis bawah, atau tanda hubung.';
            }

            if (isian.Nama.trim() === '') {
                galat.Nama = 'Isi nama promo dulu.';
            } else if (isian.Nama.trim().length > 100) {
                galat.Nama = 'Nama promo maksimal 100 karakter.';
            }

            if (isian.Prioritas.trim() !== '' && !DalamRentang(isian.Prioritas, 0, 999)) {
                galat.Prioritas = 'Prioritas 0–999.';
            }

            return galat;
        }
        case 'Potongan': {
            if ((aksi === 'DiskonPersenItem' || aksi === 'DiskonPersenPesanan') && !PersenValid(isian.Persen)) {
                galat.Persen = 'Persen diskon lebih dari 0 dan paling besar 100.';
            }

            if ((aksi === 'DiskonTetapItem' || aksi === 'DiskonTetapPesanan') && !LebihDariNol(isian.Jumlah)) {
                galat.Jumlah = 'Isi nilai potongan lebih dari Rp 0.';
            }

            if (aksi === 'HargaSpesial' && !CekPolaDesimal(isian.Harga, 2)) {
                galat.Harga = 'Isi harga spesial. Tulis 0 bila gratis.';
            }

            if (aksi === 'BundelHargaTetap') {
                if (!DalamRentang(isian.JumlahMinimal, 2, 99)) {
                    galat.JumlahMinimal = 'Isi bundel 2–99 barang.';
                }

                if (!LebihDariNol(isian.Harga)) {
                    galat.Harga = 'Isi harga bundel.';
                }
            }

            if (aksi === 'BeliXGratisY') {
                if (!DalamRentang(isian.Beli, 1, 99)) {
                    galat.Beli = 'Isi jumlah beli antara 1 dan 99.';
                }

                if (!DalamRentang(isian.Gratis, 1, 99)) {
                    galat.Gratis = 'Isi jumlah gratis antara 1 dan 99.';
                }

                if (!PersenValid(isian.PersenGratis)) {
                    galat.PersenGratis = 'Potongan barang gratis lebih dari 0 dan paling besar 100%.';
                }
            }

            if (aksi === 'PoinBerlipat') {
                const pengali = isian.Pengali.trim();

                if (!CekPolaDesimal(pengali, 1) || Number(pengali) <= 1 || Number(pengali) > 10) {
                    galat.Pengali = 'Pengali poin lebih dari 1 sampai 10, misal 2 atau 1,5.';
                }
            }

            if (aksi === 'GratisOngkir' && isian.Jumlah.trim() !== '' && !LebihDariNol(isian.Jumlah)) {
                galat.Jumlah =
                    'Batas potongan ongkir harus lebih dari Rp 0, atau kosongkan untuk gratis seluruh ongkir.';
            }

            if (
                (aksi === 'BeliXGratisY' || aksi === 'BundelHargaTetap') &&
                isian.BatasPerTransaksi.trim() !== '' &&
                !DalamRentang(isian.BatasPerTransaksi, 1, 999)
            ) {
                galat.BatasPerTransaksi = 'Batas per transaksi 1–999.';
            }

            return galat;
        }
        case 'Barang': {
            if (isian.JenisKondisi !== 'Semua' && isian.UuidKondisi.length === 0) {
                galat.UuidKondisi =
                    isian.JenisKondisi === 'Produk' ? 'Pilih minimal satu produk.' : 'Pilih minimal satu kategori.';
            }

            return galat;
        }
        case 'Waktu': {
            if (isian.Kuota.trim() !== '' && !DalamRentang(isian.Kuota, 1, 99_999_999)) {
                galat.Kuota = 'Kuota minimal 1, atau kosongkan untuk tanpa batas.';
            }

            // Tanggal selesai berlaku sampai akhir harinya, jadi tanggal yang sama dengan tanggal mulai sah.
            if (isian.TanggalMulai !== '' && isian.TanggalSelesai !== '' && isian.TanggalSelesai < isian.TanggalMulai) {
                galat.TanggalSelesai = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';
            }

            return galat;
        }
        case 'Pelanggan': {
            if (isian.UuidPemasok !== '') {
                const persen = isian.PersenDanaPemasok.trim();

                if (!CekPolaDesimal(persen, 2) || Number(persen) > 100) {
                    galat.PersenDanaPemasok = 'Bagian pemasok 0 sampai 100%.';
                }
            }

            if (isian.UlangTahun === 'Rentang' && !DalamRentang(isian.HariUlangTahun, 1, 30)) {
                galat.HariUlangTahun = 'Isi jarak 1–30 hari dari hari ulang tahun.';
            }

            if (isian.BatasPerPelanggan.trim() !== '' && !DalamRentang(isian.BatasPerPelanggan, 1, 999)) {
                galat.BatasPerPelanggan = 'Batas per pelanggan 1–999 kali.';
            }

            return galat;
        }
        case 'Tinjau':
            return galat;
    }
}

export type KonteksKalimatPromo = {
    namaProduk: Record<string, string>;
    opsiKategori: OpsiNilai[];
};

const TampilRupiah = (nilai: string): string => (CekPolaDesimal(nilai, 2) ? FormatRupiah(nilai) : `Rp ${nilai}`);
const TampilAngka = (nilai: string): string => nilai.replace('.', ',');

/** Kalimat potongan, misal "Diskon 10% per barang" atau "Beli 2 gratis 1". */
export function KalimatPotongan(isian: IsianPromo): string {
    switch (isian.JenisAksi) {
        case 'DiskonPersenItem':
            return `Diskon ${TampilAngka(isian.Persen)}% per barang`;
        case 'DiskonTetapItem':
            return `Potongan ${TampilRupiah(isian.Jumlah)} per barang`;
        case 'HargaSpesial':
            return `Harga spesial ${TampilRupiah(isian.Harga)} per barang`;
        case 'DiskonPersenPesanan':
            return `Diskon ${TampilAngka(isian.Persen)}% untuk seluruh pesanan`;
        case 'DiskonTetapPesanan':
            return `Potongan ${TampilRupiah(isian.Jumlah)} untuk seluruh pesanan`;
        case 'BeliXGratisY': {
            const gratis =
                Number(isian.PersenGratis) === 100
                    ? `gratis ${isian.Gratis}`
                    : `dapat ${isian.Gratis} barang diskon ${TampilAngka(isian.PersenGratis)}%`;

            return `Beli ${isian.Beli} ${gratis}`;
        }
        case 'BundelHargaTetap':
            return `${isian.JumlahMinimal} barang cukup ${TampilRupiah(isian.Harga)}`;
        case 'PoinBerlipat':
            return `Poin loyalti ${TampilAngka(isian.Pengali)} kali lipat`;
        case 'GratisOngkir':
            return isian.Jumlah.trim() === ''
                ? 'Gratis ongkir penuh'
                : `Subsidi ongkir sampai ${TampilRupiah(isian.Jumlah)}`;
    }
}

export function KalimatBarang(isian: IsianPromo, konteks: KonteksKalimatPromo): string {
    if (isian.JenisKondisi === 'Semua') {
        return 'semua barang';
    }

    if (isian.JenisKondisi === 'Produk') {
        return `produk ${isian.UuidKondisi.map((uuid) => konteks.namaProduk[uuid] ?? uuid).join(', ')}`;
    }

    return `kategori ${isian.UuidKondisi.map(
        (uuid) => konteks.opsiKategori.find((item) => item.Nilai === uuid)?.Label ?? uuid,
    ).join(', ')}`;
}

const namaHari = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
const FormatTanggal = (nilai: string): string => nilai.split('-').reverse().join('/');

/** "setiap hari", "Senin, Rabu", atau "Senin–Jumat" bila hari berurutan. */
export function KalimatHari(hari: string[]): string {
    const urut = hari.map(Number).sort((a, b) => a - b);

    if (urut.length === 0 || urut.length === 7) {
        return 'setiap hari';
    }

    const awal = urut[0] ?? 1;
    const akhir = urut[urut.length - 1] ?? 1;

    if (urut.length >= 3 && akhir - awal === urut.length - 1) {
        return `${namaHari[awal - 1] ?? ''}–${namaHari[akhir - 1] ?? ''}`;
    }

    return urut.map((nomor) => namaHari[nomor - 1] ?? '').join(', ');
}

export function KalimatPeriode(isian: IsianPromo): string {
    if (isian.TanggalMulai === '' && isian.TanggalSelesai === '') {
        return 'tanpa batas tanggal';
    }

    if (isian.TanggalMulai !== '' && isian.TanggalSelesai !== '') {
        return `${FormatTanggal(isian.TanggalMulai)} sampai ${FormatTanggal(isian.TanggalSelesai)}`;
    }

    return isian.TanggalMulai !== ''
        ? `mulai ${FormatTanggal(isian.TanggalMulai)}`
        : `sampai ${FormatTanggal(isian.TanggalSelesai)}`;
}

export function KalimatJam(isian: IsianPromo): string {
    if (isian.JamMulai === '' && isian.JamSelesai === '') {
        return 'sepanjang hari';
    }

    return `${isian.JamMulai === '' ? '00:00' : isian.JamMulai}–${isian.JamSelesai === '' ? '24:00' : isian.JamSelesai}`;
}

/** Satu kalimat utuh untuk bagian atas Tinjau: apa, untuk barang apa, dan kapan. */
export function KalimatRingkasPromo(isian: IsianPromo, konteks: KonteksKalimatPromo): string {
    const barang = isian.JenisKondisi === 'Semua' ? '' : ` khusus ${KalimatBarang(isian, konteks)}`;
    const jam = isian.JamMulai === '' && isian.JamSelesai === '' ? '' : ` pukul ${KalimatJam(isian)}`;

    return `${KalimatPotongan(isian)}${barang}, ${KalimatHari(isian.Hari)}${jam}, ${KalimatPeriode(isian)}.`;
}
