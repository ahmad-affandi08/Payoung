import { Link, useForm } from '@inertiajs/react';
import { useId, useRef, useState, type FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { AmbilGalatBerawalan, CekAdaGalat, TahanBilaBarcodeTertunda } from '@/Komponen/Katalog/BantuanKatalog';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import DaftarTab, { type ItemTab } from '@/Komponen/Katalog/DaftarTab';
import PenyuntingAtributVarian from '@/Komponen/Katalog/PenyuntingAtributVarian';
import PenyuntingSatuanProduk, {
    GantiSatuanDasar,
    SiapkanSatuanDasar,
} from '@/Komponen/Katalog/PenyuntingSatuanProduk';
import GrupRadio from '@/Komponen/Katalog/GrupRadio';
import KalkulatorHja from '@/Komponen/Katalog/KalkulatorHja';
import KepalaProduk from '@/Komponen/Katalog/KepalaProduk';
import PesanHanyaLihat from '@/Komponen/Katalog/PesanHanyaLihat';
import TabelHargaBertingkat, { PeriksaBarisHarga } from '@/Komponen/Katalog/TabelHargaBertingkat';
import {
    AmbilGalatLangkah,
    HitungLangkahBergalat,
    PeriksaLangkahProduk,
    PetakanGalatKeLangkah,
    SiapkanIsianProdukBaru,
    SusunLangkahProduk,
    type KonteksPeriksaLangkah,
    type KunciLangkah,
} from '@/Komponen/Katalog/WizardProduk/LangkahProduk';
import RingkasanProduk from '@/Komponen/Katalog/WizardProduk/RingkasanProduk';
import { useAlurWizardProduk } from '@/Komponen/Katalog/WizardProduk/useAlurWizardProduk';
import WizardProduk from '@/Komponen/Katalog/WizardProduk/WizardProduk';
import RingkasanGalatFormulir, { FokusGalatPertama } from '@/Komponen/PanduanAwal/RingkasanGalatFormulir';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { Button } from '@/Komponen/Ui/button';
import { Card } from '@/Komponen/Ui/card';
import { Field, FieldContent, FieldDescription, FieldLabel, FieldLegend, FieldSet } from '@/Komponen/Ui/field';
import { Switch } from '@/Komponen/Ui/switch';
import { PakaiSektor } from '@/Pustaka/Sektor';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import {
    labelGolonganObat,
    type AturanJenisProduk,
    type FormProduk,
    type GolonganObat,
    type IsianStokAwalProduk,
    type JenisProduk,
    type PelacakanProduk,
    type PropsFormProduk,
    type TigaKeadaan,
} from '@/Tipe/Katalog';
import { CekBatasPenuh, FormatBatas } from '@/Tipe/Organisasi';

type KunciTab = 'Umum' | 'Satuan' | 'Harga' | 'Varian' | 'Pajak';

/** Kunci galat server per tab (untuk penanda "perlu diperbaiki" dan pindah tab otomatis) saat mengubah produk. */
const galatPerTab: Record<KunciTab, string[]> = {
    Umum: [
        'Nama',
        'NamaStruk',
        'Sku',
        'Jenis',
        'UuidKategori',
        'Merek',
        'UuidSatuanDasar',
        'Pelacakan',
        'DurasiMenit',
        'MasaGaransiBulan',
        'LokasiProduksi',
        'GolonganObat',
    ],
    Satuan: ['Satuan'],
    Harga: [],
    Varian: ['AtributVarian'],
    Pajak: [
        'UuidKelompokPajak',
        'HargaTermasukPajak',
        'BolehMinus',
        'TampilDiPos',
        'TampilOnline',
        'Favorit',
        'HargaTerbuka',
        'KodeBarangJasaCoretax',
        'KodeUnitCoretax',
    ],
};

/** Galat harga awal (`Satuan.{i}.HargaAwal…`) tampil di tab Harga, bukan tab Satuan. */
function CekGalatTab(galat: Record<string, string | undefined>, tab: KunciTab): boolean {
    const hargaAwal = Object.entries(galat).some(
        ([kunci, pesan]) => Boolean(pesan) && /^Satuan\.\d+\.HargaAwal/.test(kunci),
    );

    if (tab === 'Harga') {
        return hargaAwal;
    }

    if (tab === 'Satuan') {
        return Object.entries(galat).some(
            ([kunci, pesan]) =>
                Boolean(pesan) &&
                (kunci === 'Satuan' || kunci.startsWith('Satuan.')) &&
                !/^Satuan\.\d+\.HargaAwal/.test(kunci),
        );
    }

    return CekAdaGalat(galat, galatPerTab[tab]);
}

const opsiPelacakan: { Nilai: PelacakanProduk; Label: string; Keterangan: string }[] = [
    { Nilai: 'Tidak', Label: 'Tidak dilacak', Keterangan: 'Stok dihitung per jumlah saja.' },
    { Nilai: 'Batch', Label: 'Nomor batch', Keterangan: 'Untuk barang dengan tanggal kedaluwarsa.' },
    {
        Nilai: 'Seri',
        Label: 'Nomor seri',
        Keterangan:
            'Satu nomor per unit, misal ponsel. Satuan dasar harus bilangan bulat; jual saat stok kosong dimatikan.',
    },
];

/** K-25: jenis yang boleh berharga terbuka (sama dengan `JenisProduk::CekBolehHargaTerbuka`). */
const jenisHargaTerbuka: JenisProduk[] = ['Stok', 'NonStok', 'Jasa', 'Resep'];

/** Penjelasan jenis dengan contoh yang mudah dipahami (langkah "Jenis & nama" saat tambah produk). */
const penjelasanJenis: Record<JenisProduk, string> = {
    Stok: 'Barang yang dihitung stoknya, misal sabun atau minuman botol.',
    Resep: 'Menu dari bahan baku, misal kopi susu. Resep diisi setelah produk disimpan.',
    Jasa: 'Layanan tanpa stok, misal potong rambut, servis, atau paket perawatan.',
    NonStok: 'Barang yang dijual tanpa menghitung stok.',
    IndukVarian:
        'Satu produk dengan banyak pilihan, misal kaos dengan ukuran dan warna. Varian dibuat setelah disimpan.',
    Paket: 'Beberapa produk dijual sebagai satu paket berharga sendiri. Isi paket diatur setelah produk disimpan.',
    Produksi: 'Barang jadi dari bahan lain dan punya stok sendiri, misal beras pak 5 kg dari karung 25 kg.',
    BahanBaku: 'Bahan untuk resep atau produksi. Tidak dijual langsung di kasir.',
    Konsinyasi: 'Barang titipan penitip. Hutang ke penitip baru tercatat saat barang terjual.',
};

/** Jenis yang paling sering dipakai, ditaruh paling atas (Resep hanya di atas untuk sektor makanan & minuman). */
function UrutkanJenisProduk(jenis: AturanJenisProduk[], sektorMakanan: boolean): AturanJenisProduk[] {
    const umum: JenisProduk[] = ['Stok', ...(sektorMakanan ? (['Resep'] as const) : []), 'Jasa', 'NonStok'];

    return [
        ...umum.flatMap((nilai) => jenis.filter((item) => item.Nilai === nilai)),
        ...jenis.filter((item) => !umum.includes(item.Nilai)),
    ];
}

/** Keterangan singkat aturan jenis produk untuk pengguna. */
export function JelaskanJenis(aturan: AturanJenisProduk | undefined): string {
    if (!aturan) {
        return '';
    }

    if (aturan.Nilai === 'IndukVarian') {
        return 'Produk bervarian: dijual lewat varian (misal ukuran atau warna). Tidak dihitung ke batas produk paket.';
    }

    const bagian = [
        aturan.PunyaStok ? 'punya stok' : 'tanpa stok sendiri',
        aturan.BisaDijual ? 'dijual di kasir' : 'tidak dijual di kasir',
    ];

    if (aturan.BolehResep) {
        bagian.push('memakai resep');
    }

    if (aturan.BolehKomponen) {
        bagian.push('berisi produk lain');
    }

    return `${aturan.Label}: ${bagian.join(', ')}.`;
}

/**
 * F-03 buat/ubah produk (DesainF03 E.3). Body JSON = `FormProduk`; galat server dipetakan ke isian dan langkah/tab.
 * Tambah produk (D-128) berupa wizard bertahap yang memeriksa tiap langkah sebelum lanjut; ubah produk tetap
 * tab bebas.
 */
export default function HalamanFormProduk({
    Mode,
    Produk,
    Kepala,
    Kategori,
    Satuan,
    KelompokPajak,
    Jenis,
    JenisTerkunci,
    BatasSku,
    Pengaturan,
    Izin,
    FiturPaketSesi = false,
    StokAwal: OpsiStokAwal = null,
}: PropsFormProduk) {
    const lokasiStok = OpsiStokAwal?.Lokasi ?? [];
    const formulir = useForm<FormProduk>({
        ...Produk,
        Satuan: SiapkanSatuanDasar(Produk.Satuan, Produk.UuidSatuanDasar),
        ...(Mode === 'Buat' ? { PaketSesi: null } : {}),
        // Audit kemudahan pakai #11: satu lokasi stok = terpilih otomatis (aturan isi-otomatis v3.25).
        ...(Mode === 'Buat' && OpsiStokAwal
            ? {
                  StokAwal: {
                      Jumlah: '',
                      HargaBeli: '',
                      UuidGudang: lokasiStok.length === 1 ? (lokasiStok[0]?.Uuid ?? '') : '',
                  },
              }
            : {}),
    });
    const data = formulir.data;
    const galatServer = formulir.errors as Record<string, string | undefined>;
    const elemenForm = useRef<HTMLFormElement>(null);
    const idForm = useId();
    const [tabAktif, AturTabAktif] = useState<KunciTab>('Umum');
    const [periksaHarga, AturPeriksaHarga] = useState(false);
    // Wizard tambah produk: langkah yang sudah dicoba dilanjutkan; galatnya dihitung langsung dari isian.
    const [percobaan, AturPercobaan] = useState<KunciLangkah[]>([]);
    const aturan = Jenis.find((item) => item.Nilai === data.Jenis);
    // D-48: bagian khusus sektor hanya tampil untuk sektornya (atau bila produk sudah memakainya), supaya toko
    // kelontong tidak melihat isian apotek, reservasi, atau paket sesi.
    const sektorApotek = PakaiSektor(['RTL-PHR']);
    const sektorLayanan = PakaiSektor(['SVC']);
    const sektorMakanan = PakaiSektor(['FNB']);
    // D-183: nomor batch untuk barang bertanggal kedaluwarsa, nomor seri untuk perangkat; resep dan produksi untuk usaha
    // yang meracik atau memproduksi. Pilihan yang sudah dipakai produk ini tetap tampil supaya tidak hilang diam-diam.
    const sektorBatch = PakaiSektor(['RTL-PHR', 'FNB', 'RTL-GEN', 'RTL-FRS', 'RTL-PET', 'RTL-FLR']);
    const sektorSeri = PakaiSektor(['RTL-ELC', 'SVC-WRK', 'WHS']);
    const sektorResep = PakaiSektor(['FNB', 'RTL-FLR']);
    const sektorProduksi = PakaiSektor(['FNB', 'RTL-FLR', 'WHS', 'RTL-BLD', 'BIZ-MFG']);
    // Retail memecah kemasan (karung beras → pak 2,5 kg) lewat jenis Produksi; bahan baku tetap khusus sektor produksi.
    const sektorKemasanRetail = PakaiSektor(['RTL-GEN', 'RTL-FRS', 'RTL-FSH', 'RTL-ELC', 'RTL-PET']);
    // Barang titipan (konsinyasi) lazim di warung/kelontong, toko kue & kafe (titip jajanan), butik, dan distributor.
    const sektorKonsinyasi = PakaiSektor(['FNB', 'RTL', 'WHS']);
    const induk = data.Jenis === 'IndukVarian';
    const bisaDijual = aturan?.BisaDijual ?? false;
    const satuanDasar = Satuan.find((item) => item.Uuid === data.UuidSatuanDasar);
    const batasPenuh = Mode === 'Buat' && (aturan?.DihitungBatasSku ?? false) && CekBatasPenuh(BatasSku);
    const bolehUbah = Izin.Kelola;
    const paketSesi = data.PaketSesi ?? null;
    const bolehPaketSesi = Mode === 'Buat' && FiturPaketSesi && data.Jenis === 'Jasa' && sektorLayanan;
    const stokAwal = data.StokAwal ?? null;
    const bolehStokAwal =
        Mode === 'Buat' &&
        stokAwal !== null &&
        lokasiStok.length > 0 &&
        (aturan?.PunyaStok ?? false) &&
        data.Jenis !== 'Konsinyasi' &&
        data.Pelacakan === 'Tidak';
    // Tambah produk: galat klien (kunci sama dengan galat server) digabung ke galat server supaya isian yang sama
    // menampilkannya. Ubah produk hanya memakai galat server.
    const konteksPeriksa: KonteksPeriksaLangkah = {
        data,
        aturan,
        opsiSatuan: Satuan,
        izinUbahHarga: Izin.UbahHarga,
        bolehPaketSesi,
        bolehStokAwal,
    };
    const galatKlien: Record<string, string> =
        Mode === 'Buat'
            ? Object.assign({}, ...percobaan.map((kunci) => PeriksaLangkahProduk(kunci, konteksPeriksa)))
            : {};
    const galat: Record<string, string | undefined> = { ...galatServer, ...galatKlien };

    const PeriksaLangkah = (kunci: KunciLangkah): boolean => {
        AturPercobaan((lama) => (lama.includes(kunci) ? lama : [...lama, kunci]));
        const valid = Object.keys(PeriksaLangkahProduk(kunci, konteksPeriksa)).length === 0;

        if (valid) {
            // Langkah ini sudah diperbaiki pengguna: galat server lamanya tidak perlu terus tampil.
            const kunciServer = Object.keys(galatServer).filter((isi) => PetakanGalatKeLangkah(isi) === kunci);

            if (kunciServer.length > 0) {
                formulir.clearErrors(...(kunciServer as never[]));
            }
        }

        return valid;
    };
    const langkah = SusunLangkahProduk(aturan, { paksa: HitungLangkahBergalat(galatServer) });
    const kontrol = useAlurWizardProduk(langkah, PeriksaLangkah);

    const Atur = <K extends keyof FormProduk>(kunci: K, nilai: FormProduk[K]) =>
        formulir.setData((lama) => ({ ...lama, [kunci]: nilai }));

    const GantiJenis = (nilai: JenisProduk) => {
        const aturanBaru = Jenis.find((item) => item.Nilai === nilai);

        formulir.setData((lama) => ({
            ...lama,
            Jenis: nilai,
            Pelacakan: aturanBaru?.BolehPelacakan ? lama.Pelacakan : 'Tidak',
            TampilDiPos: aturanBaru?.BisaDijual || nilai === 'IndukVarian' ? lama.TampilDiPos : false,
            Favorit: aturanBaru?.BisaDijual || nilai === 'IndukVarian' ? lama.Favorit : false,
            HargaTerbuka: jenisHargaTerbuka.includes(nilai) ? (lama.HargaTerbuka ?? false) : false,
            AtributVarian: nilai === 'IndukVarian' ? lama.AtributVarian : [],
            ...(lama.PaketSesi !== undefined && nilai !== 'Jasa' ? { PaketSesi: null } : {}),
        }));
    };

    const AturPaketSesi = (nilai: { JumlahSesi: string; MasaBerlakuHari: string } | null) =>
        formulir.setData((lama) => ({ ...lama, PaketSesi: nilai }));

    const satuanBaru = data.Satuan.map((baris, indeks) => ({ baris, indeks })).filter(
        ({ baris }) => baris.Uuid === null,
    );

    /** Ubah produk: harga bertingkat yang salah ditahan di peramban dan tab Harga dibuka. */
    const PeriksaLokal = (): KunciTab | null => {
        if (bisaDijual && !induk) {
            const salah = satuanBaru.some(({ baris }) => {
                const opsi = Satuan.find((item) => item.Uuid === baris.UuidSatuan);
                const hasil = PeriksaBarisHarga(baris.HargaAwal, {
                    wajibDasar: true,
                    bolehDesimal: opsi?.BolehDesimal ?? false,
                });

                return Object.keys(hasil.perBaris).length > 0 || hasil.umum !== null;
            });

            if (salah) {
                return 'Harga';
            }
        }

        return null;
    };

    const KirimUbah = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        // Barcode yang diketik tetapi belum ditambahkan tidak boleh hilang diam-diam saat menyimpan.
        if (TahanBilaBarcodeTertunda(elemenForm.current)) {
            return;
        }

        const tabSalah = PeriksaLokal();

        if (tabSalah !== null) {
            AturPeriksaHarga(true);
            AturTabAktif(tabSalah);
            FokusGalatPertama(elemenForm.current);

            return;
        }

        formulir.put(`/kelola/produk/${data.Uuid}`, {
            preserveScroll: true,
            onError: (galatBaru: Record<string, string | undefined>) => {
                const tab = (['Umum', 'Satuan', 'Harga', 'Varian', 'Pajak'] as KunciTab[]).find((kunci) =>
                    CekGalatTab(galatBaru, kunci),
                );

                if (tab) {
                    AturTabAktif(tab);
                }

                FokusGalatPertama(elemenForm.current);
            },
        });
    };

    /** Tambah produk: semua langkah diperiksa ulang, lalu kirim. Galat server membuka langkah pertama yang memuatnya. */
    const SimpanBaru = () => {
        const langkahSalah = langkah.find((item) => item.Kunci !== 'Tinjau' && !PeriksaLangkah(item.Kunci));

        if (langkahSalah) {
            kontrol.Paksa(langkahSalah.Kunci);

            return;
        }

        formulir.transform((isi) => SiapkanIsianProdukBaru(isi, { dijual: bisaDijual && !induk, bolehStokAwal }));
        formulir.post('/kelola/produk', {
            preserveScroll: true,
            onError: (galatBaru: Record<string, string | undefined>) => {
                const [pertama] = HitungLangkahBergalat(galatBaru);

                if (pertama) {
                    kontrol.Paksa(pertama);
                }
            },
        });
    };

    const tabDasar: ItemTab<KunciTab>[] = [
        { Kunci: 'Umum', Label: 'Umum' },
        { Kunci: 'Satuan', Label: 'Satuan & barcode' },
        { Kunci: 'Harga', Label: 'Harga' },
        ...(induk ? [{ Kunci: 'Varian' as const, Label: 'Varian' }] : []),
        { Kunci: 'Pajak', Label: 'Pajak & tampilan' },
    ];
    const daftarTab = tabDasar.map((item) => ({ ...item, AdaGalat: CekGalatTab(galat, item.Kunci) }));

    const bidangNama = (
        <BidangTeks
            label="Nama produk"
            nilai={data.Nama}
            saatBerubah={(nilai) => Atur('Nama', nilai)}
            galat={galat.Nama}
            maxLength={150}
            required
        />
    );
    const bidangNamaStruk = (
        <BidangTeks
            label="Nama di struk (opsional)"
            nilai={data.NamaStruk}
            saatBerubah={(nilai) => Atur('NamaStruk', nilai)}
            galat={galat.NamaStruk}
            keterangan="Nama pendek untuk struk dan dapur. Kosongkan untuk memakai nama produk."
            maxLength={40}
        />
    );
    const bidangSku = (
        <BidangTeks
            label="SKU (opsional)"
            nilai={data.Sku}
            saatBerubah={(nilai) => Atur('Sku', nilai)}
            galat={galat.Sku}
            keterangan={
                Mode === 'Buat'
                    ? 'Kode unik produk. Kosongkan agar dibuat otomatis, misal PRD-000123.'
                    : 'Kode unik produk. Kosongkan untuk tetap memakai SKU yang sekarang.'
            }
            maxLength={64}
            kode
        />
    );
    const bidangKategori = (
        <BidangPilihan
            label="Kategori"
            nilai={data.UuidKategori ?? ''}
            kosong="Tanpa kategori"
            opsi={Kategori.map((item) => ({ Nilai: item.Uuid, Label: item.Jalur }))}
            saatBerubah={(nilai) => Atur('UuidKategori', nilai === '' ? null : nilai)}
            galat={galat.UuidKategori}
        />
    );
    const bidangMerek = (
        <BidangTeks
            label="Merek (opsional)"
            nilai={data.Merek}
            saatBerubah={(nilai) => Atur('Merek', nilai)}
            galat={galat.Merek}
            maxLength={100}
        />
    );
    const bidangSatuanDasar = (
        <div className="flex flex-col gap-1">
            <BidangPilihan
                label="Satuan dasar"
                nilai={data.UuidSatuanDasar}
                kosong="Pilih satuan"
                opsi={Satuan.map((item) => ({ Nilai: item.Uuid, Label: `${item.Nama} (${item.Simbol})` }))}
                saatBerubah={(nilai) =>
                    formulir.setData((lama) => ({
                        ...lama,
                        UuidSatuanDasar: nilai,
                        Satuan: GantiSatuanDasar(lama.Satuan, lama.UuidSatuanDasar, nilai),
                    }))
                }
                galat={galat.UuidSatuanDasar}
                required
            />
            <p className="text-keterangan text-teks-sekunder">
                Satuan terkecil untuk stok dan resep, misal pcs, gram, atau ml.
            </p>
        </div>
    );

    // F-07 mode service: durasi layanan jasa untuk slot reservasi.
    const bagianDurasi =
        data.Jenis === 'Jasa' && (sektorLayanan || Boolean(data.DurasiMenit)) ? (
            <BidangTeks
                label="Durasi layanan (menit, opsional)"
                nilai={data.DurasiMenit ? String(data.DurasiMenit) : ''}
                saatBerubah={(nilai) => {
                    const angka = nilai.replace(/\D/g, '');
                    Atur('DurasiMenit', angka === '' ? null : Number(angka));
                }}
                galat={galat.DurasiMenit}
                keterangan="Isi agar layanan ini bisa dipesan lewat reservasi (misal 45 untuk potong rambut)."
                inputMode="numeric"
                maxLength={3}
            />
        ) : null;
    // F-05h: masa garansi standar produk bernomor seri (tercetak di kartu garansi bersama nomor seri yang dijual).
    const bagianGaransi =
        data.Pelacakan === 'Seri' ? (
            <BidangTeks
                label="Masa garansi (bulan, opsional)"
                nilai={data.MasaGaransiBulan ? String(data.MasaGaransiBulan) : ''}
                saatBerubah={(nilai) => {
                    const angka = nilai.replace(/\D/g, '');
                    Atur('MasaGaransiBulan', angka === '' ? null : Number(angka));
                }}
                galat={galat.MasaGaransiBulan}
                keterangan="Isi agar struk memuat garansi sampai tanggal berapa (misal 12 untuk garansi setahun)."
                inputMode="numeric"
                maxLength={3}
            />
        ) : null;
    // BR-05.4: produk Resep dibuat di Dapur/Bar; bahan resepnya dikurangi dari lokasi stok itu saat terjual.
    const bagianLokasiProduksi =
        data.Jenis === 'Resep' ? (
            <div className="flex flex-col gap-1">
                <BidangPilihan
                    label="Lokasi produksi (opsional)"
                    nilai={data.LokasiProduksi ?? ''}
                    kosong="Toko (area jual)"
                    opsi={[
                        { Nilai: 'Dapur', Label: 'Dapur' },
                        { Nilai: 'Bar', Label: 'Bar' },
                    ]}
                    saatBerubah={(nilai) => Atur('LokasiProduksi', nilai === '' ? null : (nilai as 'Dapur' | 'Bar'))}
                    galat={galat.LokasiProduksi}
                />
                <p className="text-keterangan text-teks-sekunder">
                    Bahan resep dikurangi dari lokasi stok ini saat terjual. Bila outlet tidak punya lokasi itu, stok
                    toko yang dikurangi.
                </p>
            </div>
        ) : null;
    // Apotek (§9.5): golongan obat untuk barang ber-batch (obat wajib Batch & kedaluwarsa). Muncul begitu pelacakan
    // Batch dipilih, atau bila produk sudah bergolongan (supaya galat "wajib Batch" tetap terlihat).
    const golonganObat = data.GolonganObat ?? null;
    const bagianObat =
        aturan?.BolehPelacakan && (golonganObat !== null || (sektorApotek && data.Pelacakan === 'Batch')) ? (
            <div className="flex flex-col gap-3 rounded-kontrol border border-garis p-3 sm:col-span-2">
                <BidangPilihan
                    label="Golongan obat (apotek)"
                    nilai={golonganObat ?? ''}
                    kosong="Bukan obat"
                    opsi={(Object.keys(labelGolonganObat) as GolonganObat[]).map((g) => ({
                        Nilai: g,
                        Label: labelGolonganObat[g],
                    }))}
                    saatBerubah={(nilai) => {
                        const baru = nilai === '' ? null : (nilai as GolonganObat);
                        Atur('GolonganObat', baru);
                        if (baru !== 'Keras') Atur('ObatWajibApotek', false);
                        if (baru === null) Atur('Prekursor', false);
                    }}
                    galat={galat.GolonganObat}
                />
                {golonganObat === 'Keras' ? (
                    <KotakCentang
                        label="Obat Wajib Apotek (boleh diserahkan apoteker tanpa resep, tetap dicatat)"
                        nilai={data.ObatWajibApotek ?? false}
                        saatBerubah={(nilai) => Atur('ObatWajibApotek', nilai)}
                    />
                ) : null}
                {golonganObat !== null ? (
                    <KotakCentang
                        label="Prekursor farmasi"
                        nilai={data.Prekursor ?? false}
                        saatBerubah={(nilai) => Atur('Prekursor', nilai)}
                    />
                ) : null}
                <p className="text-keterangan text-teks-sekunder">
                    {golonganObat === null
                        ? 'Pilih golongan bila produk ini obat. Obat keras, psikotropika, dan narkotika hanya bisa dijual apoteker.'
                        : golonganObat === 'Bebas' || golonganObat === 'BebasTerbatas' || data.ObatWajibApotek
                          ? golonganObat === 'Keras'
                              ? 'Dijual tanpa resep oleh apoteker; penyerahannya tetap tercatat.'
                              : 'Dijual tanpa resep. Batch dipilih otomatis dari kedaluwarsa terdekat.'
                          : 'Wajib resep dokter dan hanya bisa dijual apoteker. Tercatat di Laporan apotek.'}
                </p>
            </div>
        ) : null;
    const opsiPelacakanTersedia = opsiPelacakan.filter(
        (opsi) =>
            opsi.Nilai === 'Tidak' ||
            opsi.Nilai === data.Pelacakan ||
            (opsi.Nilai === 'Batch' ? sektorBatch : sektorSeri),
    );
    const bagianPelacakan =
        aturan?.BolehPelacakan && (opsiPelacakanTersedia.length > 1 || data.Pelacakan !== 'Tidak') ? (
            <div className="sm:col-span-2">
                <GrupRadio
                    legenda="Pelacakan stok"
                    nilai={data.Pelacakan}
                    opsi={opsiPelacakanTersedia}
                    saatBerubah={(nilai) => Atur('Pelacakan', nilai)}
                    galat={galat.Pelacakan}
                />
            </div>
        ) : null;
    const bagianPaketSesi = bolehPaketSesi ? (
        <div className="flex flex-col gap-2 rounded-kontrol border border-garis p-3 sm:col-span-2">
            <KotakCentang
                label="Jual sebagai paket sesi"
                nilai={paketSesi !== null}
                saatBerubah={(pilih) => AturPaketSesi(pilih ? { JumlahSesi: '', MasaBerlakuHari: '' } : null)}
            />
            <p className="text-keterangan text-teks-sekunder">
                Pelanggan membayar di muka untuk beberapa kali layanan, misal 10 kali creambath. Sisa sesi tercatat di
                data pelanggan dan dipakai di kasir.
            </p>
            {paketSesi !== null ? (
                <div className="grid gap-4 sm:grid-cols-2">
                    <BidangTeks
                        label="Jumlah sesi"
                        nilai={paketSesi.JumlahSesi}
                        saatBerubah={(nilai) => AturPaketSesi({ ...paketSesi, JumlahSesi: nilai.replace(/\D/g, '') })}
                        galat={galat['PaketSesi.JumlahSesi']}
                        inputMode="numeric"
                        maxLength={4}
                        required
                    />
                    <BidangTeks
                        label="Masa berlaku (hari, opsional)"
                        nilai={paketSesi.MasaBerlakuHari}
                        saatBerubah={(nilai) =>
                            AturPaketSesi({ ...paketSesi, MasaBerlakuHari: nilai.replace(/\D/g, '') })
                        }
                        galat={galat['PaketSesi.MasaBerlakuHari']}
                        keterangan="Kosongkan bila sesi tidak kedaluwarsa."
                        inputMode="numeric"
                        maxLength={4}
                    />
                    <p className="text-keterangan text-teks-sekunder sm:col-span-2">
                        Semua layanan Jasa bisa ditukar dengan sesi paket ini. Batasi layanannya di menu Paket sesi.
                    </p>
                </div>
            ) : null}
        </div>
    ) : null;

    const AturStokAwal = (nilai: Partial<IsianStokAwalProduk>) =>
        formulir.setData((lama) => ({
            ...lama,
            StokAwal: { Jumlah: '', HargaBeli: '', UuidGudang: '', ...lama.StokAwal, ...nilai },
        }));
    const bagianStokAwal =
        bolehStokAwal && stokAwal !== null ? (
            <div className="flex flex-col gap-3 rounded-kontrol border border-garis p-3 sm:col-span-2">
                <p className="text-label font-semibold text-teks-utama">Stok sekarang (opsional)</p>
                <div className="grid gap-4 sm:grid-cols-2">
                    {lokasiStok.length > 1 ? (
                        <div className="sm:col-span-2">
                            <BidangPilihan
                                label="Lokasi stok"
                                nilai={stokAwal.UuidGudang}
                                kosong="Pilih lokasi stok"
                                opsi={lokasiStok.map((l) => ({
                                    Nilai: l.Uuid,
                                    Label: l.NamaOutlet ? `${l.NamaOutlet} | ${l.Nama}` : l.Nama,
                                }))}
                                saatBerubah={(nilai) => AturStokAwal({ UuidGudang: nilai })}
                                galat={galat['StokAwal.UuidGudang']}
                            />
                        </div>
                    ) : null}
                    <BidangTeks
                        label={satuanDasar ? `Jumlah stok (${satuanDasar.Nama.toLowerCase()})` : 'Jumlah stok'}
                        nilai={stokAwal.Jumlah}
                        saatBerubah={(nilai) => AturStokAwal({ Jumlah: nilai.replace(/[^\d.]/g, '') })}
                        galat={galat['StokAwal.Jumlah']}
                        inputMode="decimal"
                        maxLength={19}
                    />
                    <BidangUang
                        label="Harga beli per satuan"
                        nilai={stokAwal.HargaBeli}
                        saatBerubah={(nilai) => AturStokAwal({ HargaBeli: nilai })}
                        galat={galat['StokAwal.HargaBeli']}
                        keterangan="Modal per satuan untuk menghitung laba. Kosong = Rp 0."
                    />
                </div>
                <p className="text-keterangan text-teks-sekunder">
                    Diisi bila barangnya sudah ada di toko. Langsung tercatat sebagai stok awal
                    {lokasiStok.length === 1 ? ` di ${lokasiStok[0]?.Nama ?? ''}` : ''}; kosongkan bila belum ada stok.
                </p>
            </div>
        ) : null;

    const saklarHargaTerbuka = jenisHargaTerbuka.includes(data.Jenis) ? (
        <Field orientation="horizontal" className="min-h-10 items-center sm:col-span-2">
            <Switch
                id={`${idForm}-harga-terbuka`}
                checked={data.HargaTerbuka ?? false}
                onCheckedChange={(nilai) => Atur('HargaTerbuka', nilai)}
            />
            <FieldLabel htmlFor={`${idForm}-harga-terbuka`} className="text-isi font-normal text-teks-utama">
                Harga diketik kasir saat menjual (misal barang lain-lain, jasa servis)
            </FieldLabel>
        </Field>
    ) : null;

    /** Ubah produk: tab Umum. */
    const panelUmum = (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="sm:col-span-2">{bidangNama}</div>
            {bidangNamaStruk}
            {bidangSku}
            <div className="flex flex-col gap-1">
                {JenisTerkunci ? (
                    <BidangTeks
                        label="Jenis produk"
                        nilai={aturan?.Label ?? data.Jenis}
                        saatBerubah={() => undefined}
                        galat={galat.Jenis}
                        disabled
                    />
                ) : (
                    <BidangPilihan
                        label="Jenis produk"
                        nilai={data.Jenis}
                        opsi={Jenis.map((item) => ({ Nilai: item.Nilai, Label: item.Label }))}
                        saatBerubah={(nilai) => GantiJenis(nilai as JenisProduk)}
                        galat={galat.Jenis}
                        required
                    />
                )}
                <p className="text-keterangan text-teks-sekunder">
                    {JenisTerkunci
                        ? 'Jenis tidak bisa diubah karena produk sudah dipakai atau punya varian.'
                        : JelaskanJenis(aturan)}
                </p>
            </div>
            {bidangKategori}
            {bidangMerek}
            {bidangSatuanDasar}
            {bagianDurasi}
            {bagianGaransi}
            {bagianLokasiProduksi}
            {bagianPelacakan}
            {bagianObat}
        </div>
    );

    const panelHarga = (
        <div className="flex flex-col gap-4">
            {induk ? (
                <p className="text-isi text-teks-sekunder">
                    Produk bervarian tidak punya harga sendiri. Harga diisi per varian setelah varian dibuat.
                </p>
            ) : !bisaDijual ? (
                <p className="text-isi text-teks-sekunder">
                    {aturan?.Label ?? 'Jenis ini'} tidak dijual di kasir, jadi tidak perlu harga jual.
                </p>
            ) : (
                <>
                    {!Izin.UbahHarga ? <PesanHanyaLihat izin="produk.harga.ubah" objek="harga produk" /> : null}
                    {satuanBaru.length === 0 ? (
                        <p className="text-isi text-teks-sekunder">
                            Semua satuan sudah tersimpan. Ubah harga dan harga bertingkat di{' '}
                            <Link
                                href={`/kelola/produk/${data.Uuid}/harga`}
                                className="font-semibold text-brand underline"
                            >
                                halaman Harga produk
                            </Link>
                            .
                        </p>
                    ) : (
                        satuanBaru.map(({ baris, indeks }) => {
                            const opsi = Satuan.find((item) => item.Uuid === baris.UuidSatuan);
                            const simbol = opsi?.Simbol ?? 'satuan';
                            const kunciHarga = `Satuan.${String(indeks)}.HargaAwal`;

                            return (
                                <section key={indeks} className="flex flex-col gap-2">
                                    <h3 className="text-label font-semibold text-teks-utama">
                                        {paketSesi !== null ? 'Harga paket' : 'Harga'} per{' '}
                                        {opsi ? `${opsi.Nama} (${simbol})` : 'satuan belum dipilih'}
                                    </h3>
                                    <TabelHargaBertingkat
                                        judul={`Harga per ${simbol}`}
                                        baris={baris.HargaAwal}
                                        saatBerubah={(nilai) =>
                                            Atur(
                                                'Satuan',
                                                data.Satuan.map((item, i) =>
                                                    i === indeks ? { ...item, HargaAwal: nilai } : item,
                                                ),
                                            )
                                        }
                                        simbolSatuan={simbol}
                                        bolehDesimal={opsi?.BolehDesimal ?? false}
                                        wajibDasar
                                        galatServer={AmbilGalatBerawalan(galat, kunciHarga)}
                                        // Tambah produk menampilkan galat lewat kunci galat (tidak dari tabel sendiri).
                                        tampilkanGalat={Mode === 'Ubah' && periksaHarga}
                                        disabled={!Izin.UbahHarga}
                                    />
                                    {galat[kunciHarga] ? (
                                        <p
                                            data-galat-langkah=""
                                            tabIndex={-1}
                                            className="text-keterangan font-semibold text-bahaya outline-none"
                                        >
                                            {galat[kunciHarga]}
                                        </p>
                                    ) : null}
                                    {golonganObat !== null ? (
                                        <KalkulatorHja
                                            simbolSatuan={simbol}
                                            disabled={!Izin.UbahHarga}
                                            saatPakai={(harga) =>
                                                Atur(
                                                    'Satuan',
                                                    data.Satuan.map((item, i) =>
                                                        i !== indeks
                                                            ? item
                                                            : {
                                                                  ...item,
                                                                  HargaAwal:
                                                                      item.HargaAwal.length === 0
                                                                          ? [{ JumlahMinimum: '1', Harga: harga }]
                                                                          : item.HargaAwal.map((h, n) =>
                                                                                n === 0 ? { ...h, Harga: harga } : h,
                                                                            ),
                                                              },
                                                    ),
                                                )
                                            }
                                        />
                                    ) : null}
                                </section>
                            );
                        })
                    )}
                    <p className="text-keterangan text-teks-sekunder">
                        Harga bertingkat berlaku otomatis di kasir, misal 1–11 pcs Rp 5.000 dan mulai 12 pcs Rp 4.500.
                        Harga per outlet, kanal, atau waktu diatur di Daftar harga.
                    </p>
                </>
            )}
        </div>
    );

    const panelVarian = (
        <div className="flex flex-col gap-3">
            <p className="text-isi text-teks-sekunder">
                Tentukan atribut dan nilainya di sini. Setelah produk disimpan, buat varian dari halaman produk: satu
                varian untuk setiap kombinasi.
            </p>
            <PenyuntingAtributVarian
                nilai={data.AtributVarian}
                saatBerubah={(nilai) => Atur('AtributVarian', nilai)}
                galat={AmbilGalatBerawalan(galat, 'AtributVarian')}
                disabled={!bolehUbah}
            />
            {galat.AtributVarian ? (
                <p className="text-keterangan font-semibold text-bahaya">{galat.AtributVarian}</p>
            ) : null}
        </div>
    );

    /** Panel pajak & tampilan. Tambah produk memindahkan "harga diketik kasir" ke langkah harga dan memuat durasi jasa. */
    const BuatPanelPajak = (modeBaru: boolean) => (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="flex flex-col gap-1 sm:col-span-2">
                <BidangPilihan
                    label={bisaDijual || induk ? 'Kelompok pajak' : 'Kelompok pajak (opsional)'}
                    nilai={data.UuidKelompokPajak ?? ''}
                    kosong="Pilih kelompok pajak"
                    opsi={KelompokPajak.map((item) => ({
                        Nilai: item.Uuid,
                        Label: `${item.Nama} | ${item.LabelKategori}`,
                    }))}
                    saatBerubah={(nilai) => Atur('UuidKelompokPajak', nilai === '' ? null : nilai)}
                    galat={galat.UuidKelompokPajak}
                    required={bisaDijual || induk}
                />
                <p className="text-keterangan text-teks-sekunder">
                    Tarif diambil dari tabel tarif yang berlaku saat transaksi. Kelompok baru dibuat di menu Kelompok
                    pajak.
                </p>
            </div>
            <GrupRadio<TigaKeadaan>
                legenda="Harga sudah termasuk pajak?"
                nilai={data.HargaTermasukPajak}
                opsi={[
                    { Nilai: 'Ikut', Label: `Ikuti pengaturan outlet (${Pengaturan.HargaTermasukPajakOutlet})` },
                    { Nilai: 'Ya', Label: 'Ya, harga jual sudah termasuk pajak' },
                    { Nilai: 'Tidak', Label: 'Tidak, pajak ditambahkan di atas harga' },
                ]}
                saatBerubah={(nilai) => Atur('HargaTermasukPajak', nilai)}
                galat={galat.HargaTermasukPajak}
            />
            {aturan?.PunyaStok ? (
                <GrupRadio<TigaKeadaan>
                    legenda="Boleh dijual saat stok kosong?"
                    nilai={data.BolehMinus}
                    opsi={[
                        {
                            Nilai: 'Ikut',
                            Label: `Ikuti pengaturan usaha (${Pengaturan.StokBolehMinus ? 'boleh' : 'tidak boleh'})`,
                        },
                        { Nilai: 'Ya', Label: 'Boleh, stok bisa minus' },
                        { Nilai: 'Tidak', Label: 'Tidak boleh' },
                    ]}
                    saatBerubah={(nilai) => Atur('BolehMinus', nilai)}
                    galat={galat.BolehMinus}
                    disabled={data.Pelacakan === 'Seri'}
                />
            ) : null}
            {modeBaru ? bagianDurasi : null}
            <BidangTeks
                label="Kode barang/jasa Coretax (opsional)"
                nilai={data.KodeBarangJasaCoretax ?? ''}
                saatBerubah={(nilai) =>
                    Atur('KodeBarangJasaCoretax', nilai.replace(/\D/g, '') === '' ? null : nilai.replace(/\D/g, ''))
                }
                galat={galat.KodeBarangJasaCoretax}
                keterangan="6 digit dari daftar DJP, dipakai di Faktur Pajak. Kosong = kode umum 000000."
                inputMode="numeric"
                maxLength={6}
            />
            <BidangTeks
                label="Kode satuan Coretax (opsional)"
                nilai={data.KodeUnitCoretax ?? ''}
                saatBerubah={(nilai) =>
                    Atur('KodeUnitCoretax', nilai.trim() === '' ? null : nilai.trim().toUpperCase())
                }
                galat={galat.KodeUnitCoretax}
                keterangan="Format UM.0021 (pcs). Kosong = UM.0021."
                maxLength={10}
            />
            <FieldSet className="gap-1 sm:col-span-2">
                <FieldLegend variant="label" className="mb-1 text-label font-semibold text-teks-utama">
                    Tampilkan produk
                </FieldLegend>
                {bisaDijual || induk ? (
                    <Field orientation="horizontal" className="min-h-10 items-center">
                        <Switch
                            id={`${idForm}-tampil-pos`}
                            checked={data.TampilDiPos}
                            onCheckedChange={(nilai) =>
                                formulir.setData((lama) => ({
                                    ...lama,
                                    TampilDiPos: nilai,
                                    // Produk tersembunyi dari kasir tidak mungkin favorit (saklar Favorit ikut tersembunyi).
                                    Favorit: nilai ? lama.Favorit : false,
                                }))
                            }
                        />
                        <FieldLabel htmlFor={`${idForm}-tampil-pos`} className="text-isi font-normal text-teks-utama">
                            {induk ? 'Tampilkan sebagai grup varian di kasir' : 'Tampil di kasir (POS)'}
                        </FieldLabel>
                    </Field>
                ) : (
                    <p className="text-keterangan text-teks-sekunder">Jenis ini tidak tampil di kasir.</p>
                )}
                {(bisaDijual || induk) && data.TampilDiPos ? (
                    <Field orientation="horizontal" className="min-h-10 items-center">
                        <Switch
                            id={`${idForm}-favorit`}
                            checked={data.Favorit}
                            onCheckedChange={(nilai) => Atur('Favorit', nilai)}
                        />
                        <FieldContent>
                            <FieldLabel htmlFor={`${idForm}-favorit`} className="text-isi font-normal text-teks-utama">
                                Favorit di kasir
                            </FieldLabel>
                            <FieldDescription className="text-keterangan text-teks-sekunder">
                                Produk muncul di kategori Favorit di layar jual kasir.
                            </FieldDescription>
                        </FieldContent>
                    </Field>
                ) : null}
                <Field orientation="horizontal" className="min-h-10 items-center">
                    <Switch
                        id={`${idForm}-tampil-online`}
                        checked={data.TampilOnline}
                        onCheckedChange={(nilai) => Atur('TampilOnline', nilai)}
                    />
                    <FieldLabel htmlFor={`${idForm}-tampil-online`} className="text-isi font-normal text-teks-utama">
                        Tampil di toko online
                    </FieldLabel>
                </Field>
                {modeBaru ? null : saklarHargaTerbuka}
            </FieldSet>
        </div>
    );

    // Tambah produk (wizard).
    const CekJenisTersedia = (nilai: JenisProduk) =>
        nilai === data.Jenis ||
        (nilai === 'Resep'
            ? sektorResep
            : nilai === 'Produksi'
              ? sektorProduksi || sektorKemasanRetail
              : nilai === 'BahanBaku'
                ? sektorProduksi
                : nilai === 'Konsinyasi'
                  ? sektorKonsinyasi
                  : true);
    const pilihanJenis = UrutkanJenisProduk(Jenis, sektorMakanan)
        .filter((item) => CekJenisTersedia(item.Nilai))
        .map((item) => ({
            Nilai: item.Nilai,
            Label: item.Label,
            Keterangan: penjelasanJenis[item.Nilai],
        }));
    const panelJenisNama = (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="sm:col-span-2">{bidangNama}</div>
            <div className="sm:col-span-2">
                <GrupRadio<JenisProduk>
                    legenda="Jenis produk"
                    nilai={data.Jenis}
                    opsi={pilihanJenis}
                    saatBerubah={GantiJenis}
                    galat={galat.Jenis}
                />
            </div>
            {bidangKategori}
            {bidangMerek}
            {bidangNamaStruk}
            {bidangSku}
        </div>
    );
    const panelSatuanBaru = (
        <div className="flex flex-col gap-4">
            {bidangSatuanDasar}
            <PenyuntingSatuanProduk
                satuan={data.Satuan}
                uuidSatuanDasar={data.UuidSatuanDasar}
                opsiSatuan={Satuan}
                saatBerubah={(nilai) => Atur('Satuan', nilai)}
                galat={galat}
                disabled={!bolehUbah}
                petunjukSatuanDasar="Pilih satuan dasar di atas lebih dulu."
            />
        </div>
    );
    const panelHargaStok = (
        <div className="flex flex-col gap-4">
            {bagianPaketSesi}
            {bisaDijual && !induk ? (
                <>
                    {panelHarga}
                    {saklarHargaTerbuka}
                </>
            ) : null}
            {bagianPelacakan || bagianGaransi || bagianLokasiProduksi || bagianObat || bagianStokAwal ? (
                <div className="grid gap-4 sm:grid-cols-2">
                    {bagianPelacakan}
                    {bagianGaransi}
                    {bagianLokasiProduksi}
                    {bagianObat}
                    {bagianStokAwal}
                </div>
            ) : null}
        </div>
    );
    const panelTinjau = (
        <RingkasanProduk
            data={data}
            aturan={aturan}
            langkah={langkah}
            kategori={Kategori}
            opsiSatuan={Satuan}
            kelompokPajak={KelompokPajak}
            pengaturan={Pengaturan}
            lokasiStok={lokasiStok}
            bolehPaketSesi={bolehPaketSesi}
            bolehStokAwal={bolehStokAwal}
            saatUbah={(kunci) => kontrol.Lompat(kunci)}
        />
    );

    const pesanBatasPenuh = batasPenuh ? (
        <Pemberitahuan jenis="peringatan" judul="Batas produk paket sudah tercapai">
            {FormatBatas(BatasSku, 'produk')}. Arsipkan produk lain atau tingkatkan paket di menu Langganan sebelum
            menambah produk jenis ini.
        </Pemberitahuan>
    ) : null;
    const pesanSatuanHilang =
        satuanDasar === undefined && data.UuidSatuanDasar !== '' ? (
            <p className="text-keterangan text-bahaya">Satuan dasar tidak ditemukan. Pilih ulang satuan dasar.</p>
        ) : null;

    return (
        <TataLetakAplikasi judul={Mode === 'Buat' ? 'Tambah produk' : 'Ubah produk'}>
            {Kepala ? <KepalaProduk kepala={Kepala} tabAktif="Ringkasan" /> : null}
            {!bolehUbah ? <PesanHanyaLihat izin="produk.kelola" objek="produk ini" /> : null}
            {pesanBatasPenuh}
            {Mode === 'Buat' ? (
                <>
                    <WizardProduk
                        kontrol={kontrol}
                        panel={{
                            JenisNama: panelJenisNama,
                            Satuan: panelSatuanBaru,
                            HargaStok: panelHargaStok,
                            Varian: panelVarian,
                            Pajak: BuatPanelPajak(true),
                            Tinjau: panelTinjau,
                        }}
                        bergalat={HitungLangkahBergalat(galat)}
                        pesanGalat={
                            <>
                                <RingkasanGalatFormulir galat={AmbilGalatLangkah(galat, kontrol.aktif)} />
                                <DaftarGalatServer
                                    galat={galat}
                                    kecuali={Object.keys(galat).filter(
                                        (kunci) => PetakanGalatKeLangkah(kunci) !== null,
                                    )}
                                />
                            </>
                        }
                        saatSimpan={SimpanBaru}
                        memproses={formulir.processing}
                        bolehSimpan={bolehUbah}
                        simpanDitahan={batasPenuh}
                        tautanBatal="/kelola/produk"
                    />
                    {pesanSatuanHilang}
                </>
            ) : (
                <form ref={elemenForm} onSubmit={KirimUbah} noValidate className="flex flex-col gap-4">
                    <RingkasanGalatFormulir galat={galat} />
                    <DaftarGalatServer
                        galat={galat}
                        kecuali={Object.keys(galat).filter((kunci) =>
                            (Object.keys(galatPerTab) as KunciTab[]).some((tab) => CekGalatTab({ [kunci]: 'x' }, tab)),
                        )}
                    />
                    <Card className="gap-0 rounded-panel p-4 shadow-none">
                        <DaftarTab
                            label="Bagian formulir produk"
                            tab={daftarTab}
                            aktif={tabAktif}
                            saatPilih={AturTabAktif}
                            panel={{
                                Umum: panelUmum,
                                Satuan: (
                                    <PenyuntingSatuanProduk
                                        satuan={data.Satuan}
                                        uuidSatuanDasar={data.UuidSatuanDasar}
                                        opsiSatuan={Satuan}
                                        saatBerubah={(nilai) => Atur('Satuan', nilai)}
                                        galat={galat}
                                        disabled={!bolehUbah}
                                    />
                                ),
                                Harga: panelHarga,
                                Varian: panelVarian,
                                Pajak: BuatPanelPajak(false),
                            }}
                        />
                    </Card>
                    {pesanSatuanHilang}
                    <BilahAksiForm>
                        {bolehUbah ? (
                            <Tombol type="submit" memproses={formulir.processing}>
                                Simpan perubahan
                            </Tombol>
                        ) : null}
                        <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                            <Link href={`/kelola/produk/${data.Uuid}`}>Batal</Link>
                        </Button>
                    </BilahAksiForm>
                </form>
            )}
        </TataLetakAplikasi>
    );
}
