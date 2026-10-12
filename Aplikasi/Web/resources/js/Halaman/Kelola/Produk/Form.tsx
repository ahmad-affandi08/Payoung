import { Link, useForm } from '@inertiajs/react';
import { useId, useRef, useState, type FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { AmbilGalatBerawalan, CekAdaGalat } from '@/Komponen/Katalog/BantuanKatalog';
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

/** Kunci galat server per tab (untuk penanda "perlu diperbaiki" dan pindah tab otomatis). */
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
        'GolonganObat',
        'StokAwal.Jumlah',
        'StokAwal.HargaBeli',
        'StokAwal.UuidGudang',
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

type ModeFormulir = 'Sederhana' | 'Lengkap';

const kunciModeFormulir = 'Katalog.FormProduk.Mode';

/** Preferensi mode formulir per peramban (kenyamanan saja); bawaan Sederhana (D-23 B). */
function BacaModeFormulir(): ModeFormulir {
    try {
        return window.localStorage.getItem(kunciModeFormulir) === 'Lengkap' ? 'Lengkap' : 'Sederhana';
    } catch {
        return 'Sederhana';
    }
}

function SimpanModeFormulir(mode: ModeFormulir): void {
    try {
        window.localStorage.setItem(kunciModeFormulir, mode);
    } catch {
        // Penyimpanan peramban diblokir: pilihan hanya berlaku di halaman ini.
    }
}

/** Jenis yang ditawarkan di mode Sederhana, dengan contoh yang mudah dipahami. */
/** K-25: jenis yang boleh berharga terbuka (sama dengan `JenisProduk::CekBolehHargaTerbuka`). */
const jenisHargaTerbuka: JenisProduk[] = ['Stok', 'NonStok', 'Jasa', 'Resep'];

const jenisSederhana: Partial<Record<JenisProduk, string>> = {
    Stok: 'Barang yang dihitung stoknya, misal sabun atau minuman botol.',
    Resep: 'Menu dari bahan baku, misal kopi susu. Resep diisi setelah produk disimpan.',
    Jasa: 'Layanan tanpa stok, misal potong rambut, servis, atau paket perawatan.',
    NonStok: 'Barang yang dijual tanpa menghitung stok.',
};

/** Galat yang isiannya tampil di mode Sederhana; galat lain membuka formulir lengkap. */
export function CekGalatSederhana(kunci: string): boolean {
    return (
        ['Uuid', 'Nama', 'Jenis', 'UuidKategori'].includes(kunci) ||
        /^(Satuan\.\d+\.HargaAwal|PaketSesi|StokAwal)/.test(kunci)
    );
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

/** F-03 buat/ubah produk (DesainF03 E.3). Body JSON = `FormProduk`; galat server dipetakan ke isian dan tab. */
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
    const galat = formulir.errors as Record<string, string | undefined>;
    const elemenForm = useRef<HTMLFormElement>(null);
    const idForm = useId();
    const [tabAktif, AturTabAktif] = useState<KunciTab>('Umum');
    const [periksaHarga, AturPeriksaHarga] = useState(false);
    const aturan = Jenis.find((item) => item.Nilai === data.Jenis);
    // D-48: bagian khusus sektor hanya tampil untuk sektornya (atau bila produk sudah memakainya), supaya toko
    // kelontong tidak melihat isian apotek, reservasi, atau paket sesi.
    const sektorApotek = PakaiSektor(['RTL-PHR']);
    const sektorLayanan = PakaiSektor(['SVC']);
    const sektorMakanan = PakaiSektor(['FNB']);
    const induk = data.Jenis === 'IndukVarian';
    const bisaDijual = aturan?.BisaDijual ?? false;
    const satuanDasar = Satuan.find((item) => item.Uuid === data.UuidSatuanDasar);
    const batasPenuh = Mode === 'Buat' && (aturan?.DihitungBatasSku ?? false) && CekBatasPenuh(BatasSku);
    const bolehUbah = Izin.Kelola;
    const [modeFormulir, AturModeFormulir] = useState<ModeFormulir>(() =>
        Mode === 'Buat' ? BacaModeFormulir() : 'Lengkap',
    );
    const [periksaSederhana, AturPeriksaSederhana] = useState(false);
    const adaGalatLanjutan = Object.entries(galat).some(
        ([kunci, pesan]) => Boolean(pesan) && !CekGalatSederhana(kunci),
    );
    const sederhana = Mode === 'Buat' && modeFormulir === 'Sederhana' && !adaGalatLanjutan;
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
    const indeksDasar = data.Satuan.findIndex(
        (baris) => baris.Uuid === null && baris.UuidSatuan === data.UuidSatuanDasar,
    );
    const hargaDasar = indeksDasar < 0 ? '' : (data.Satuan[indeksDasar]?.HargaAwal[0]?.Harga ?? '');
    const kelompokPajak = KelompokPajak.find((item) => item.Uuid === data.UuidKelompokPajak);

    const GantiModeFormulir = (mode: ModeFormulir) => {
        AturModeFormulir(mode);
        SimpanModeFormulir(mode);
    };

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
            AtributVarian: nilai === 'IndukVarian' ? lama.AtributVarian : [],
            ...(lama.PaketSesi !== undefined && nilai !== 'Jasa' ? { PaketSesi: null } : {}),
        }));
    };

    /** Mode Sederhana: satu isian harga = harga dasar (mulai 1) satuan dasar; baris bertingkat lain dipertahankan. */
    const AturHargaDasar = (nilai: string) =>
        formulir.setData((lama) => ({
            ...lama,
            Satuan: lama.Satuan.map((baris, i) => {
                if (i !== indeksDasar) {
                    return baris;
                }

                return {
                    ...baris,
                    HargaAwal:
                        nilai === ''
                            ? baris.HargaAwal.slice(1)
                            : [
                                  { JumlahMinimum: baris.HargaAwal[0]?.JumlahMinimum ?? '1', Harga: nilai },
                                  ...baris.HargaAwal.slice(1),
                              ],
                };
            }),
        }));

    const AturPaketSesi = (nilai: { JumlahSesi: string; MasaBerlakuHari: string } | null) =>
        formulir.setData((lama) => ({ ...lama, PaketSesi: nilai }));

    const galatHargaSederhana =
        galat[`Satuan.${String(indeksDasar)}.HargaAwal.0.Harga`] ??
        galat[`Satuan.${String(indeksDasar)}.HargaAwal`] ??
        (periksaSederhana && bisaDijual && !induk && Izin.UbahHarga && hargaDasar === ''
            ? 'Isi harga jual. Tulis 0 bila gratis.'
            : undefined);
    const galatJumlahSesi =
        galat['PaketSesi.JumlahSesi'] ??
        (periksaSederhana && paketSesi !== null && !/^[1-9]\d{0,3}$/.test(paketSesi.JumlahSesi)
            ? 'Isi jumlah sesi, misal 10.'
            : undefined);

    const satuanBaru = data.Satuan.map((baris, indeks) => ({ baris, indeks })).filter(
        ({ baris }) => baris.Uuid === null,
    );

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

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturPeriksaSederhana(true);

        const hargaKosong = sederhana && bisaDijual && !induk && Izin.UbahHarga && hargaDasar === '';
        const sesiSalah = paketSesi !== null && bolehPaketSesi && !/^[1-9]\d{0,3}$/.test(paketSesi.JumlahSesi);

        if (hargaKosong || sesiSalah) {
            AturTabAktif('Umum');
            FokusGalatPertama(elemenForm.current);

            return;
        }

        const tabSalah = PeriksaLokal();

        if (tabSalah !== null && sederhana) {
            GantiModeFormulir('Lengkap');
        }

        if (tabSalah !== null) {
            AturPeriksaHarga(true);
            AturTabAktif(tabSalah);
            FokusGalatPertama(elemenForm.current);

            return;
        }

        const opsi = {
            preserveScroll: true,
            onError: (galatBaru: Record<string, string | undefined>) => {
                if (Object.keys(galatBaru).every((kunci) => CekGalatSederhana(kunci)) && sederhana) {
                    FokusGalatPertama(elemenForm.current);

                    return;
                }

                const tab = (['Umum', 'Satuan', 'Harga', 'Varian', 'Pajak'] as KunciTab[]).find((kunci) =>
                    CekGalatTab(galatBaru, kunci),
                );

                if (tab) {
                    AturTabAktif(tab);
                }

                FokusGalatPertama(elemenForm.current);
            },
        };

        if (Mode === 'Buat') {
            // Stok sekarang hanya dikirim bila diisi dan berlaku untuk jenis produk ini.
            formulir.transform((isi) => ({
                ...isi,
                StokAwal: bolehStokAwal && isi.StokAwal && isi.StokAwal.Jumlah.trim() !== '' ? isi.StokAwal : null,
            }));
            formulir.post('/kelola/produk', opsi);
        } else {
            formulir.put(`/kelola/produk/${data.Uuid}`, opsi);
        }
    };

    const tabDasar: ItemTab<KunciTab>[] = [
        { Kunci: 'Umum', Label: 'Umum' },
        { Kunci: 'Satuan', Label: 'Satuan & barcode' },
        { Kunci: 'Harga', Label: 'Harga' },
        ...(induk ? [{ Kunci: 'Varian' as const, Label: 'Varian' }] : []),
        { Kunci: 'Pajak', Label: 'Pajak & tampilan' },
    ];
    const daftarTab = tabDasar.map((item) => ({ ...item, AdaGalat: CekGalatTab(galat, item.Kunci) }));

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
                        galat={galatJumlahSesi}
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

    const pilihanJenisSederhana = Jenis.filter(
        (item) =>
            (jenisSederhana[item.Nilai] !== undefined && (item.Nilai !== 'Resep' || sektorMakanan)) ||
            item.Nilai === data.Jenis,
    ).map((item) => ({
        Nilai: item.Nilai,
        Label: item.Label,
        Keterangan: jenisSederhana[item.Nilai] ?? JelaskanJenis(item),
    }));

    const panelSederhana = (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="sm:col-span-2">
                <BidangTeks
                    label="Nama produk"
                    nilai={data.Nama}
                    saatBerubah={(nilai) => Atur('Nama', nilai)}
                    galat={galat.Nama}
                    maxLength={150}
                    required
                    autoFocus
                />
            </div>
            <div className="sm:col-span-2">
                <GrupRadio<JenisProduk>
                    legenda="Jenis produk"
                    nilai={data.Jenis}
                    opsi={pilihanJenisSederhana}
                    saatBerubah={GantiJenis}
                    galat={galat.Jenis}
                />
            </div>
            {bagianDurasi}
            {bagianGaransi}
            {bagianPaketSesi}
            {bagianStokAwal}
            {bisaDijual && !induk ? (
                <div className="flex flex-col gap-1">
                    <BidangUang
                        label={paketSesi !== null ? 'Harga paket' : 'Harga jual'}
                        nilai={hargaDasar}
                        saatBerubah={AturHargaDasar}
                        galat={galatHargaSederhana}
                        keterangan={
                            satuanDasar
                                ? `Per ${satuanDasar.Nama.toLowerCase()}. Harga bertingkat ada di formulir lengkap.`
                                : 'Harga bertingkat ada di formulir lengkap.'
                        }
                        disabled={!Izin.UbahHarga}
                        required={Izin.UbahHarga}
                    />
                    {!Izin.UbahHarga ? (
                        <p className="text-keterangan text-teks-sekunder">
                            Harga diisi oleh pengguna dengan izin produk.harga.ubah setelah produk disimpan.
                        </p>
                    ) : null}
                </div>
            ) : null}
            <BidangPilihan
                label="Kategori"
                nilai={data.UuidKategori ?? ''}
                kosong="Tanpa kategori"
                opsi={Kategori.map((item) => ({ Nilai: item.Uuid, Label: item.Jalur }))}
                saatBerubah={(nilai) => Atur('UuidKategori', nilai === '' ? null : nilai)}
                galat={galat.UuidKategori}
            />
            <p className="text-keterangan text-teks-sekunder sm:col-span-2">
                Otomatis: satuan {satuanDasar ? `${satuanDasar.Nama} (${satuanDasar.Simbol})` : 'dasar'}, pajak{' '}
                {kelompokPajak ? kelompokPajak.Nama : 'belum dipilih'},{' '}
                {data.TampilDiPos && bisaDijual ? 'tampil di kasir' : 'tidak tampil di kasir'}, SKU dibuat otomatis.
                Barcode, satuan lain, harga bertingkat, dan varian ada di formulir lengkap.
            </p>
        </div>
    );

    const panelUmum = (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="sm:col-span-2">
                <BidangTeks
                    label="Nama produk"
                    nilai={data.Nama}
                    saatBerubah={(nilai) => Atur('Nama', nilai)}
                    galat={galat.Nama}
                    maxLength={150}
                    required
                    autoFocus={Mode === 'Buat'}
                />
            </div>
            <BidangTeks
                label="Nama di struk (opsional)"
                nilai={data.NamaStruk}
                saatBerubah={(nilai) => Atur('NamaStruk', nilai)}
                galat={galat.NamaStruk}
                keterangan="Nama pendek untuk struk dan dapur. Kosongkan untuk memakai nama produk."
                maxLength={40}
            />
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
            <BidangPilihan
                label="Kategori"
                nilai={data.UuidKategori ?? ''}
                kosong="Tanpa kategori"
                opsi={Kategori.map((item) => ({ Nilai: item.Uuid, Label: item.Jalur }))}
                saatBerubah={(nilai) => Atur('UuidKategori', nilai === '' ? null : nilai)}
                galat={galat.UuidKategori}
            />
            <BidangTeks
                label="Merek (opsional)"
                nilai={data.Merek}
                saatBerubah={(nilai) => Atur('Merek', nilai)}
                galat={galat.Merek}
                maxLength={100}
            />
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
            {bagianDurasi}
            {bagianGaransi}
            {bagianPaketSesi}
            {bagianStokAwal}
            {aturan?.BolehPelacakan ? (
                <div className="sm:col-span-2">
                    <GrupRadio
                        legenda="Pelacakan stok"
                        nilai={data.Pelacakan}
                        opsi={opsiPelacakan}
                        saatBerubah={(nilai) => Atur('Pelacakan', nilai)}
                        galat={galat.Pelacakan}
                    />
                </div>
            ) : null}
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

                            return (
                                <section key={indeks} className="flex flex-col gap-2">
                                    <h3 className="text-label font-semibold text-teks-utama">
                                        Harga per {opsi ? `${opsi.Nama} (${simbol})` : 'satuan belum dipilih'}
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
                                        galatServer={AmbilGalatBerawalan(galat, `Satuan.${String(indeks)}.HargaAwal`)}
                                        tampilkanGalat={periksaHarga}
                                        disabled={!Izin.UbahHarga}
                                    />
                                    {galat[`Satuan.${String(indeks)}.HargaAwal`] ? (
                                        <p className="text-keterangan font-semibold text-bahaya">
                                            {galat[`Satuan.${String(indeks)}.HargaAwal`]}
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

    const panelPajak = (
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
                {jenisHargaTerbuka.includes(data.Jenis) ? (
                    <Field orientation="horizontal" className="min-h-10 items-center">
                        <Switch
                            id={`${idForm}-harga-terbuka`}
                            checked={data.HargaTerbuka ?? false}
                            onCheckedChange={(nilai) => Atur('HargaTerbuka', nilai)}
                        />
                        <FieldLabel
                            htmlFor={`${idForm}-harga-terbuka`}
                            className="text-isi font-normal text-teks-utama"
                        >
                            Harga diketik kasir saat menjual (misal barang lain-lain, jasa servis)
                        </FieldLabel>
                    </Field>
                ) : null}
            </FieldSet>
        </div>
    );

    return (
        <TataLetakAplikasi judul={Mode === 'Buat' ? 'Tambah produk' : 'Ubah produk'}>
            {Kepala ? <KepalaProduk kepala={Kepala} tabAktif="Ringkasan" /> : null}
            {!bolehUbah ? <PesanHanyaLihat izin="produk.kelola" objek="produk ini" /> : null}
            {batasPenuh ? (
                <Pemberitahuan jenis="peringatan" judul="Batas produk paket sudah tercapai">
                    {FormatBatas(BatasSku, 'produk')}. Arsipkan produk lain atau tingkatkan paket di menu Langganan
                    sebelum menambah produk jenis ini.
                </Pemberitahuan>
            ) : null}
            <form ref={elemenForm} onSubmit={Kirim} noValidate className="flex flex-col gap-4">
                <RingkasanGalatFormulir galat={galat} />
                <DaftarGalatServer
                    galat={galat}
                    kecuali={Object.keys(galat).filter((kunci) =>
                        (Object.keys(galatPerTab) as KunciTab[]).some((tab) => CekGalatTab({ [kunci]: 'x' }, tab)),
                    )}
                />
                {Mode === 'Buat' ? (
                    <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Tampilan formulir">
                        <Button
                            type="button"
                            variant={sederhana ? 'default' : 'outline'}
                            aria-pressed={sederhana}
                            className="h-8 pointer-coarse:h-11"
                            onClick={() => GantiModeFormulir('Sederhana')}
                            disabled={adaGalatLanjutan}
                        >
                            Formulir sederhana
                        </Button>
                        <Button
                            type="button"
                            variant={sederhana ? 'outline' : 'default'}
                            aria-pressed={!sederhana}
                            className="h-8 pointer-coarse:h-11"
                            onClick={() => GantiModeFormulir('Lengkap')}
                        >
                            Formulir lengkap
                        </Button>
                        {adaGalatLanjutan ? (
                            <span className="text-keterangan text-teks-sekunder">
                                Perbaiki isian yang ditandai di formulir lengkap.
                            </span>
                        ) : null}
                    </div>
                ) : null}
                {sederhana ? (
                    <Card className="gap-0 rounded-panel p-4 shadow-none">{panelSederhana}</Card>
                ) : (
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
                                Varian: (
                                    <div className="flex flex-col gap-3">
                                        <p className="text-isi text-teks-sekunder">
                                            Tentukan atribut dan nilainya di sini. Setelah produk disimpan, buat varian
                                            dari halaman produk: satu varian untuk setiap kombinasi.
                                        </p>
                                        <PenyuntingAtributVarian
                                            nilai={data.AtributVarian}
                                            saatBerubah={(nilai) => Atur('AtributVarian', nilai)}
                                            galat={AmbilGalatBerawalan(galat, 'AtributVarian')}
                                            disabled={!bolehUbah}
                                        />
                                        {galat.AtributVarian ? (
                                            <p className="text-keterangan font-semibold text-bahaya">
                                                {galat.AtributVarian}
                                            </p>
                                        ) : null}
                                    </div>
                                ),
                                Pajak: panelPajak,
                            }}
                        />
                    </Card>
                )}
                {satuanDasar === undefined && data.UuidSatuanDasar !== '' ? (
                    <p className="text-keterangan text-bahaya">
                        Satuan dasar tidak ditemukan. Pilih ulang satuan dasar.
                    </p>
                ) : null}
                <BilahAksiForm>
                    {bolehUbah ? (
                        <Tombol type="submit" memproses={formulir.processing} disabled={batasPenuh}>
                            {Mode === 'Buat' ? 'Simpan produk' : 'Simpan perubahan'}
                        </Tombol>
                    ) : null}
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={Mode === 'Buat' ? '/kelola/produk' : `/kelola/produk/${data.Uuid}`}>Batal</Link>
                    </Button>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
