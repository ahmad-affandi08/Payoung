/**
 * Logika murni wizard "Tambah produk" (F-03, D-128): daftar langkah per jenis, pemetaan kunci galat server ke
 * langkah, dan validasi klien per langkah. Tidak ada React di sini supaya mudah dites. Server tetap penentu;
 * aturan di bawah hanya cermin dari `SimpanProdukPermintaan` dan `AturanProduk`.
 */
import { PeriksaBarcode, PolaSku } from '@/Komponen/Katalog/BantuanKatalog';
import { PeriksaBarisHarga } from '@/Komponen/Katalog/TabelHargaBertingkat';
import { CekDesimalPositif } from '@/Pustaka/MasukanJumlah';
import type { AturanJenisProduk, FormProduk, JenisProduk, OpsiSatuan } from '@/Tipe/Katalog';

export type KunciLangkah = 'JenisNama' | 'Satuan' | 'HargaStok' | 'Varian' | 'Pajak' | 'Tinjau';

export type LangkahProduk = { Kunci: KunciLangkah; Label: string; Keterangan: string };

/** Urutan baku semua langkah; daftar langkah sebuah jenis selalu subhimpunan berurutan dari sini. */
export const urutanLangkahProduk: KunciLangkah[] = ['JenisNama', 'Satuan', 'HargaStok', 'Varian', 'Pajak', 'Tinjau'];

/**
 * Jenis yang tidak memakai langkah "Satuan & barcode": Jasa (tanpa barang), Resep (menu dibuat saat dipesan, stok
 * mengurangi bahannya), dan IndukVarian (barcode serta harga milik tiap varian). Satuan dasarnya tetap terisi
 * otomatis dari bawaan server (pcs) dan bisa diubah lewat Ubah produk.
 */
const jenisTanpaLangkahSatuan: JenisProduk[] = ['Jasa', 'Resep', 'IndukVarian'];

export type OpsiSusunLangkah = {
    /** Langkah yang dipaksa tampil, misal karena server menolak isian di dalamnya. */
    paksa?: KunciLangkah[];
};

function LabelHargaStok(aturan: AturanJenisProduk | undefined): string {
    if (!aturan || (aturan.BisaDijual && aturan.PunyaStok)) {
        return 'Harga & stok';
    }

    return aturan.BisaDijual ? 'Harga' : 'Stok';
}

function BuatLangkah(kunci: KunciLangkah, aturan: AturanJenisProduk | undefined): LangkahProduk {
    switch (kunci) {
        case 'JenisNama':
            return { Kunci: kunci, Label: 'Jenis & nama', Keterangan: 'Pilih jenis produk, lalu beri nama.' };
        case 'Satuan':
            return {
                Kunci: kunci,
                Label: 'Satuan & barcode',
                Keterangan: 'Satuan terkecil, satuan lain bila ada (pak, dus), dan barcode untuk dipindai kasir.',
            };
        case 'HargaStok':
            return {
                Kunci: kunci,
                Label: LabelHargaStok(aturan),
                Keterangan: aturan?.BisaDijual
                    ? 'Harga jual di kasir. Bila barangnya sudah ada di toko, isi stok sekarang.'
                    : 'Cara stok bahan ini dilacak, dan stok sekarang bila sudah ada.',
            };
        case 'Varian':
            return {
                Kunci: kunci,
                Label: 'Varian',
                Keterangan:
                    'Tentukan atribut seperti ukuran atau warna. Produk bervarian tidak punya harga sendiri: varian dibuat setelah produk disimpan, dan harga diisi per varian.',
            };
        case 'Pajak':
            return {
                Kunci: kunci,
                Label: 'Pajak & tampilan',
                Keterangan: 'Pajak produk dan di mana produk ini ditampilkan.',
            };
        case 'Tinjau':
            return {
                Kunci: kunci,
                Label: 'Tinjau & simpan',
                Keterangan: 'Periksa ringkasan di bawah. Ubah bagian yang perlu, lalu simpan.',
            };
    }
}

function CekLangkahBerlaku(kunci: KunciLangkah, aturan: AturanJenisProduk | undefined): boolean {
    switch (kunci) {
        case 'Satuan':
            return aturan !== undefined && !jenisTanpaLangkahSatuan.includes(aturan.Nilai);
        case 'HargaStok':
            return aturan !== undefined && (aturan.BisaDijual || aturan.PunyaStok);
        case 'Varian':
            return aturan?.Nilai === 'IndukVarian';
        default:
            return true;
    }
}

/**
 * Daftar langkah wizard untuk satu jenis produk (aturan dari server, `AturanJenisProduk`):
 * - Satuan & barcode: kecuali Jasa, Resep, dan Induk varian.
 * - Harga/stok: bila produk bisa dijual (harga jual) atau punya stok (pelacakan, stok awal).
 * - Varian: hanya Induk varian.
 */
export function SusunLangkahProduk(
    aturan: AturanJenisProduk | undefined,
    { paksa = [] }: OpsiSusunLangkah = {},
): LangkahProduk[] {
    return urutanLangkahProduk
        .filter((kunci) => CekLangkahBerlaku(kunci, aturan) || paksa.includes(kunci))
        .map((kunci) => BuatLangkah(kunci, aturan));
}

/** Langkah `kunci` bila ada di daftar; kalau sudah hilang (jenis berganti), langkah terdekat sebelumnya. */
export function CariLangkahTerdekat(langkah: LangkahProduk[], kunci: KunciLangkah): KunciLangkah {
    if (langkah.some((item) => item.Kunci === kunci)) {
        return kunci;
    }

    const indeksAsal = urutanLangkahProduk.indexOf(kunci);
    const sebelum = langkah.filter((item) => urutanLangkahProduk.indexOf(item.Kunci) < indeksAsal);

    return (sebelum[sebelum.length - 1] ?? langkah[0])?.Kunci ?? 'JenisNama';
}

const kunciJenisNama = ['Nama', 'NamaStruk', 'Sku', 'Jenis', 'UuidKategori', 'Merek'];
const kunciHargaStok = [
    'Pelacakan',
    'MasaGaransiBulan',
    'LokasiProduksi',
    'GolonganObat',
    'ObatWajibApotek',
    'Prekursor',
    'HargaTerbuka',
];
const kunciPajak = [
    'UuidKelompokPajak',
    'HargaTermasukPajak',
    'BolehMinus',
    'TampilDiPos',
    'TampilOnline',
    'Favorit',
    'DurasiMenit',
    'KodeBarangJasaCoretax',
    'KodeUnitCoretax',
];

/**
 * Langkah yang memuat isian dari kunci galat server (`Nama`, `Satuan.0.Barcode.1`, `StokAwal.Jumlah`, ...).
 * Galat harga awal satuan (`Satuan.{i}.HargaAwal…`) tampil di langkah harga, bukan langkah satuan.
 * Null = kunci tidak terikat isian mana pun (misal `Umum`, `Uuid`).
 */
export function PetakanGalatKeLangkah(kunci: string): KunciLangkah | null {
    if (/^Satuan\.\d+\.HargaAwal/.test(kunci)) {
        return 'HargaStok';
    }

    if (kunci === 'UuidSatuanDasar' || kunci === 'Satuan' || kunci.startsWith('Satuan.')) {
        return 'Satuan';
    }

    if (kunci === 'AtributVarian' || kunci.startsWith('AtributVarian.')) {
        return 'Varian';
    }

    if (kunci === 'StokAwal' || kunci.startsWith('StokAwal.') || kunci.startsWith('PaketSesi')) {
        return 'HargaStok';
    }

    if (kunciJenisNama.includes(kunci)) {
        return 'JenisNama';
    }

    if (kunciHargaStok.includes(kunci)) {
        return 'HargaStok';
    }

    return kunciPajak.includes(kunci) ? 'Pajak' : null;
}

/** Langkah-langkah yang memuat galat (urut baku), untuk penanda "perlu diperbaiki" dan memaksa langkah tampil. */
export function HitungLangkahBergalat(galat: Record<string, string | undefined>): KunciLangkah[] {
    const ada = new Set<KunciLangkah>();

    for (const [kunci, pesan] of Object.entries(galat)) {
        const langkah = pesan ? PetakanGalatKeLangkah(kunci) : null;

        if (langkah !== null) {
            ada.add(langkah);
        }
    }

    return urutanLangkahProduk.filter((kunci) => ada.has(kunci));
}

/** Galat yang tampil di satu langkah (kunci tak terpetakan tidak termasuk). */
export function AmbilGalatLangkah(
    galat: Record<string, string | undefined>,
    kunci: KunciLangkah,
): Record<string, string | undefined> {
    return Object.fromEntries(
        Object.entries(galat).filter(([isi, pesan]) => Boolean(pesan) && PetakanGalatKeLangkah(isi) === kunci),
    );
}

export type KonteksPeriksaLangkah = {
    data: FormProduk;
    aturan: AturanJenisProduk | undefined;
    opsiSatuan: OpsiSatuan[];
    izinUbahHarga: boolean;
    /** Isian "Jual sebagai paket sesi" tampil (Jasa di sektor layanan dengan fitur paket sesi). */
    bolehPaketSesi: boolean;
    /** Isian "Stok sekarang" tampil. */
    bolehStokAwal: boolean;
};

const polaKuantitas = /^\d{1,14}(\.\d{1,4})?$/;
const polaHargaBeli = /^\d{1,13}(\.\d{1,6})?$/;
const polaJumlahSesi = /^[1-9]\d{0,3}$/;

function PeriksaJenisNama({ data, aturan }: KonteksPeriksaLangkah): Record<string, string> {
    const galat: Record<string, string> = {};

    if (data.Nama.trim() === '') {
        galat.Nama = 'Isi nama produk dulu.';
    } else if (data.Nama.length > 150) {
        galat.Nama = 'Nama produk maksimal 150 karakter.';
    }

    if (aturan === undefined) {
        galat.Jenis = 'Pilih jenis produk dulu.';
    }

    if (data.NamaStruk.length > 40) {
        galat.NamaStruk = 'Nama di struk maksimal 40 karakter.';
    }

    if (data.Sku.trim() !== '' && !PolaSku.test(data.Sku.trim())) {
        galat.Sku =
            'SKU 1–64 karakter: huruf, angka, titik, garis bawah, strip, atau garis miring. Awali dengan huruf atau angka.';
    }

    if (data.Merek.length > 60) {
        galat.Merek = 'Merek maksimal 60 karakter.';
    }

    return galat;
}

function PeriksaSatuan({ data }: KonteksPeriksaLangkah): Record<string, string> {
    const galat: Record<string, string> = {};

    if (data.UuidSatuanDasar === '') {
        galat.UuidSatuanDasar = 'Pilih satuan dasar dulu.';
    }

    const semuaBarcode = data.Satuan.flatMap((baris, i) =>
        baris.Barcode.map((barcode, n) => ({ barcode, kunci: `Satuan.${String(i)}.Barcode.${String(n)}` })),
    );

    data.Satuan.forEach((baris, i) => {
        const awalan = `Satuan.${String(i)}`;

        if (baris.UuidSatuan === '') {
            galat[`${awalan}.UuidSatuan`] = 'Pilih satuan.';
        } else if (data.Satuan.some((lain, j) => j < i && lain.UuidSatuan === baris.UuidSatuan)) {
            galat[`${awalan}.UuidSatuan`] = 'Satuan ini sudah dipakai di baris lain.';
        }

        if (i > 0 && (!polaKuantitas.test(baris.KonversiKeDasar) || !CekDesimalPositif(baris.KonversiKeDasar))) {
            galat[`${awalan}.KonversiKeDasar`] = 'Isi jumlah lebih dari 0, maksimal 4 angka desimal.';
        }
    });

    for (const { barcode, kunci } of semuaBarcode) {
        const lain = semuaBarcode.filter((item) => item.kunci !== kunci).map((item) => item.barcode);
        const pesan = PeriksaBarcode(barcode, lain);

        if (pesan !== null) {
            galat[kunci] = pesan;
        }
    }

    return galat;
}

function PeriksaHargaStok(konteks: KonteksPeriksaLangkah): Record<string, string> {
    const { data, aturan, opsiSatuan, izinUbahHarga, bolehPaketSesi, bolehStokAwal } = konteks;
    const galat: Record<string, string> = {};
    const dijual = (aturan?.BisaDijual ?? false) && aturan?.Nilai !== 'IndukVarian';

    if (dijual) {
        data.Satuan.forEach((baris, i) => {
            const awalan = `Satuan.${String(i)}.HargaAwal`;
            const opsi = opsiSatuan.find((item) => item.Uuid === baris.UuidSatuan);
            const hasil = PeriksaBarisHarga(baris.HargaAwal, {
                wajibDasar: true,
                bolehDesimal: opsi?.BolehDesimal ?? false,
            });

            for (const [indeks, pesan] of Object.entries(hasil.perBaris)) {
                if (pesan.JumlahMinimum !== undefined) {
                    galat[`${awalan}.${indeks}.JumlahMinimum`] = pesan.JumlahMinimum;
                }

                if (pesan.Harga !== undefined) {
                    galat[`${awalan}.${indeks}.Harga`] = pesan.Harga;
                }
            }

            if (hasil.umum !== null) {
                galat[awalan] = hasil.umum;
            }
        });

        const indeksDasar = data.Satuan.findIndex((baris) => baris.UuidSatuan === data.UuidSatuanDasar);
        const hargaDasarKosong = indeksDasar >= 0 && (data.Satuan[indeksDasar]?.HargaAwal.length ?? 0) === 0;
        const kunciDasar = `Satuan.${String(indeksDasar)}.HargaAwal`;

        // Harga jual wajib diisi (0 untuk gratis) kecuali kasir mengetik harga sendiri (HargaTerbuka).
        if (indeksDasar >= 0 && izinUbahHarga && !(data.HargaTerbuka ?? false) && hargaDasarKosong) {
            galat[kunciDasar] = 'Isi harga jual. Tulis 0 bila gratis.';
        }
    }

    if (bolehPaketSesi && data.PaketSesi) {
        if (!polaJumlahSesi.test(data.PaketSesi.JumlahSesi)) {
            galat['PaketSesi.JumlahSesi'] = 'Isi jumlah sesi, misal 10.';
        }

        const masa = data.PaketSesi.MasaBerlakuHari;

        if (masa !== '' && (!/^[1-9]\d{0,3}$/.test(masa) || Number(masa) > 3650)) {
            galat['PaketSesi.MasaBerlakuHari'] = 'Isi 1 sampai 3650 hari, atau kosongkan.';
        }
    }

    const garansi = data.MasaGaransiBulan ?? null;

    if (data.Pelacakan === 'Seri' && garansi !== null && (garansi < 1 || garansi > 240)) {
        galat.MasaGaransiBulan = 'Isi 1 sampai 240 bulan, atau kosongkan.';
    }

    if (data.Pelacakan === 'Seri' && opsiSatuan.find((item) => item.Uuid === data.UuidSatuanDasar)?.BolehDesimal) {
        galat.Pelacakan = 'Nomor seri butuh satuan dasar tanpa desimal, misal pcs atau unit.';
    }

    const stok = data.StokAwal ?? null;

    if (bolehStokAwal && stok !== null && stok.Jumlah.trim() !== '') {
        if (!polaKuantitas.test(stok.Jumlah)) {
            galat['StokAwal.Jumlah'] = 'Isi jumlah stok berupa angka, maksimal 4 angka desimal. Misal 24 atau 2.5.';
        }

        if (stok.UuidGudang === '') {
            galat['StokAwal.UuidGudang'] = 'Pilih lokasi stok.';
        }

        if (stok.HargaBeli !== '' && !polaHargaBeli.test(stok.HargaBeli)) {
            galat['StokAwal.HargaBeli'] = 'Harga beli berupa angka, misal 12500.';
        }
    }

    return galat;
}

function PeriksaVarian({ data }: KonteksPeriksaLangkah): Record<string, string> {
    const galat: Record<string, string> = {};

    if (data.AtributVarian.length > 3) {
        galat.AtributVarian = 'Maksimal 3 atribut varian.';
    }

    data.AtributVarian.forEach((atribut, i) => {
        const nama = atribut.Nama.trim();
        const nilai = atribut.Nilai.map((isi) => isi.trim()).filter((isi) => isi !== '');

        if (nama === '') {
            galat[`AtributVarian.${String(i)}.Nama`] = 'Isi nama atribut, misal Ukuran.';
        } else if (nama.length > 30) {
            galat[`AtributVarian.${String(i)}.Nama`] = 'Nama atribut maksimal 30 karakter.';
        } else if (
            data.AtributVarian.some((lain, j) => j < i && lain.Nama.trim().toLowerCase() === nama.toLowerCase())
        ) {
            galat[`AtributVarian.${String(i)}.Nama`] = 'Nama atribut tidak boleh sama.';
        }

        if (nilai.length === 0) {
            galat[`AtributVarian.${String(i)}.Nilai`] = 'Isi minimal 1 nilai, misal M atau L.';
        } else if (nilai.length > 20) {
            galat[`AtributVarian.${String(i)}.Nilai`] = 'Maksimal 20 nilai per atribut.';
        } else if (nilai.some((isi) => isi.length > 40)) {
            galat[`AtributVarian.${String(i)}.Nilai`] = 'Nilai atribut maksimal 40 karakter.';
        }
    });

    return galat;
}

function PeriksaPajak({ data, aturan }: KonteksPeriksaLangkah): Record<string, string> {
    const galat: Record<string, string> = {};
    const wajibPajak = (aturan?.BisaDijual ?? false) || aturan?.Nilai === 'IndukVarian';

    if (wajibPajak && (data.UuidKelompokPajak ?? '') === '') {
        galat.UuidKelompokPajak = 'Pilih kelompok pajak agar pajak di struk benar.';
    }

    if ((data.KodeBarangJasaCoretax ?? '') !== '' && !/^\d{6}$/.test(data.KodeBarangJasaCoretax ?? '')) {
        galat.KodeBarangJasaCoretax = 'Kode barang/jasa Coretax terdiri dari 6 digit.';
    }

    if ((data.KodeUnitCoretax ?? '') !== '' && !/^UM\.\d{4}$/.test(data.KodeUnitCoretax ?? '')) {
        galat.KodeUnitCoretax = 'Format kode satuan Coretax: UM.0021.';
    }

    const durasi = data.DurasiMenit ?? null;

    if (durasi !== null && (durasi < 5 || durasi > 720)) {
        galat.DurasiMenit = 'Isi 5 sampai 720 menit, atau kosongkan.';
    }

    return galat;
}

/**
 * Galat klien satu langkah, kuncinya sama dengan kunci galat server (`Satuan.0.Barcode.1`, `StokAwal.Jumlah`) supaya
 * isian yang sama menampilkannya. Kosong = langkah boleh dilanjutkan. Langkah Tinjau tidak punya isian sendiri.
 */
export function PeriksaLangkahProduk(kunci: KunciLangkah, konteks: KonteksPeriksaLangkah): Record<string, string> {
    switch (kunci) {
        case 'JenisNama':
            return PeriksaJenisNama(konteks);
        case 'Satuan':
            return PeriksaSatuan(konteks);
        case 'HargaStok':
            return PeriksaHargaStok(konteks);
        case 'Varian':
            return PeriksaVarian(konteks);
        case 'Pajak':
            return PeriksaPajak(konteks);
        case 'Tinjau':
            return {};
    }
}

/**
 * Isi yang dikirim saat menyimpan produk baru: harga hanya untuk produk yang dijual (isian sisa dari jenis
 * sebelumnya dibuang), dan stok sekarang hanya bila diisi serta berlaku untuk jenis ini.
 */
export function SiapkanIsianProdukBaru(
    isi: FormProduk,
    { dijual, bolehStokAwal }: { dijual: boolean; bolehStokAwal: boolean },
): FormProduk {
    return {
        ...isi,
        Satuan: dijual ? isi.Satuan : isi.Satuan.map((baris) => ({ ...baris, HargaAwal: [] })),
        StokAwal: bolehStokAwal && isi.StokAwal && isi.StokAwal.Jumlah.trim() !== '' ? isi.StokAwal : null,
    };
}
