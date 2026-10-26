import { useForm } from '@inertiajs/react';
import { useId, useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import CangkangWizard from '@/Komponen/Formulir/Wizard/CangkangWizard';
import { useAlurWizard } from '@/Komponen/Formulir/Wizard/useAlurWizard';
import RingkasanGalatFormulir from '@/Komponen/PanduanAwal/RingkasanGalatFormulir';
import {
    AmbilGalatLangkahOutlet,
    HitungLangkahOutletBergalat,
    PetakanGalatOutletKeLangkah,
    PeriksaLangkahOutlet,
    SusunLangkahOutlet,
    urutanLangkahOutlet,
    type KunciLangkahOutlet,
} from '@/Komponen/Organisasi/LangkahOutlet';
import RingkasanOutlet from '@/Komponen/Organisasi/RingkasanOutlet';
import { Card, CardContent, CardHeader, CardTitle } from '@/Komponen/Ui/card';
import { Field, FieldDescription, FieldLabel, FieldLegend, FieldSet } from '@/Komponen/Ui/field';
import { Switch } from '@/Komponen/Ui/switch';
import PemilihJam from '@/Komponen/Tanggal/PemilihJam';
import { SaranKodeOutlet } from '@/Pustaka/SaranKode';
import { PakaiSektor } from '@/Pustaka/Sektor';
import type { Kota, Pilihan } from '@/Tipe/Organisasi';

export type IsianOutlet = {
    Nama: string;
    Kode: string;
    Merek: string;
    Alamat: string;
    /** D-177: dicetak di kepala struk bila saklar Telepon outlet menyala. */
    Telepon: string;
    KodeKota: string;
    ZonaWaktu: string;
    JamTutupBuku: string;
    Pkp: boolean;
    Nitku: string;
    PungutPbjt: boolean;
    /** Modul Salesman bagian 3: outlet ini kendaraan kanvas salesman (lokasi stok Toko = bak kendaraan). */
    Kanvas: boolean;
    NomorKendaraan: string;
};

type PropsFormOutlet = {
    awal: IsianOutlet;
    /** Null = tambah outlet baru; selain itu Uuid outlet yang diubah. */
    uuid: string | null;
    kodeTerkunci?: boolean;
    merek: Pilihan[];
    kota: Kota[];
    saatBatal?: () => void;
};

const pilihanZonaWaktu: Pilihan[] = [
    { Nilai: 'WIB', Label: 'WIB (UTC+7)' },
    { Nilai: 'WITA', Label: 'WITA (UTC+8)' },
    { Nilai: 'WIT', Label: 'WIT (UTC+9)' },
];

/** Isian outlet (F-02 langkah 1): identitas, kota & zona waktu, jam tutup buku, profil pajak dasar. */
export default function FormOutlet({ awal, uuid, kodeTerkunci = false, merek, kota, saatBatal }: PropsFormOutlet) {
    const formulir = useForm<IsianOutlet>(awal);
    // D-48: pengaturan kanvas (kendaraan salesman) hanya untuk usaha grosir/distribusi atau outlet yang sudah kanvas.
    const tampilKanvas = PakaiSektor(['WHS', 'RTL-BLD']) || awal.Kanvas;
    // D-183: PBJT makanan & minuman hanya bermakna bagi usaha F&B (atau outlet yang sudah memungutnya).
    // PBJT (UU HKPD) atas makanan/minuman dan jasa kesenian & hiburan (spa, pusat kebugaran).
    const tampilPbjt = PakaiSektor(['FNB', 'SVC-SLN', 'SVC-FIT']) || awal.PungutPbjt;
    const idKanvas = useId();
    const pilihanKota = kota.map((baris) => ({
        Nilai: baris.Kode,
        Label: `${baris.Nama}${baris.NamaProvinsi ? `, ${baris.NamaProvinsi}` : ''} (${baris.ZonaWaktu})`,
    }));

    // Tambah outlet berupa wizard bertahap (kode terkunci setelah bertransaksi, jadi layak ditinjau); Profil outlet
    // (ubah) tetap satu halaman.
    const bertahap = uuid === null;
    // Outlet baru: kode disarankan dari nama selama belum diketik sendiri.
    const [kodeDiketik, AturKodeDiketik] = useState(uuid !== null);
    const [percobaan, AturPercobaan] = useState<KunciLangkahOutlet[]>([]);
    const galatServer = formulir.errors as Record<string, string | undefined>;
    const perluMerek = merek.length > 1;
    const langkah = SusunLangkahOutlet(tampilKanvas);
    const galatKlien: Record<string, string> = Object.assign(
        {},
        ...percobaan.map((kunci) => PeriksaLangkahOutlet(kunci, formulir.data, perluMerek)),
    );
    const galat: Record<string, string | undefined> = bertahap ? { ...galatServer, ...galatKlien } : galatServer;

    const PeriksaLangkah = (kunci: KunciLangkahOutlet): boolean => {
        AturPercobaan((lama) => (lama.includes(kunci) ? lama : [...lama, kunci]));
        const valid = Object.keys(PeriksaLangkahOutlet(kunci, formulir.data, perluMerek)).length === 0;

        if (valid) {
            // Langkah ini sudah diperbaiki pengguna: galat server lamanya tidak perlu terus tampil.
            const kunciServer = Object.keys(galatServer).filter((isi) => PetakanGalatOutletKeLangkah(isi) === kunci);

            if (kunciServer.length > 0) {
                formulir.clearErrors(...(kunciServer as never[]));
            }
        }

        return valid;
    };
    const kontrol = useAlurWizard(langkah, PeriksaLangkah, { awal: 'Identitas', urutan: urutanLangkahOutlet });

    const PilihKota = (kode: string) => {
        const terpilih = kota.find((baris) => baris.Kode === kode);
        formulir.setData({
            ...formulir.data,
            KodeKota: kode,
            ZonaWaktu: terpilih?.ZonaWaktu ?? formulir.data.ZonaWaktu,
        });
    };

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        if (uuid !== null) {
            formulir.put(`/kelola/outlet/${uuid}`, { preserveScroll: true });
        }
    };

    /** Tambah outlet: semua langkah diperiksa ulang; galat server (misal kode kembar) membuka langkah yang memuatnya. */
    const SimpanBaru = () => {
        const langkahSalah = langkah.find((item) => item.Kunci !== 'Tinjau' && !PeriksaLangkah(item.Kunci));

        if (langkahSalah) {
            kontrol.Paksa(langkahSalah.Kunci);

            return;
        }

        formulir.post('/kelola/outlet', {
            preserveScroll: true,
            onError: (galatBaru: Record<string, string | undefined>) => {
                const [pertama] = HitungLangkahOutletBergalat(galatBaru);

                if (pertama) {
                    kontrol.Paksa(pertama);
                }
            },
        });
    };

    const bidangIdentitas = (
        <>
            <BidangTeks
                label="Nama outlet"
                nilai={formulir.data.Nama}
                saatBerubah={(nilai) =>
                    kodeDiketik
                        ? formulir.setData('Nama', nilai)
                        : formulir.setData({ ...formulir.data, Nama: nilai, Kode: SaranKodeOutlet(nilai) })
                }
                galat={galat.Nama}
                maxLength={150}
                required
            />
            <BidangTeks
                label="Kode outlet"
                nilai={formulir.data.Kode}
                saatBerubah={(nilai) => {
                    AturKodeDiketik(true);
                    formulir.setData('Kode', nilai.toUpperCase());
                }}
                galat={galat.Kode}
                keterangan={
                    kodeTerkunci
                        ? 'Kode dikunci karena outlet sudah bertransaksi.'
                        : !kodeDiketik && formulir.data.Kode !== ''
                          ? 'Disarankan dari nama outlet. Boleh diganti. Tidak bisa diubah setelah outlet bertransaksi.'
                          : '3–5 karakter untuk nomor dokumen, misal JKT1. Tidak bisa diubah setelah outlet bertransaksi.'
                }
                maxLength={5}
                disabled={kodeTerkunci}
                kode
                required
            />
            {/* D-76: merek hanya ditanya bila tenant punya lebih dari satu; selain itu terisi otomatis. */}
            {merek.length > 1 ? (
                <BidangPilihan
                    label="Merek"
                    nilai={formulir.data.Merek}
                    opsi={merek}
                    saatBerubah={(nilai) => formulir.setData('Merek', nilai)}
                    galat={galat.Merek}
                    required
                />
            ) : null}
        </>
    );
    const bidangLokasi = (
        <>
            <BidangTeks
                label="Alamat (opsional)"
                nilai={formulir.data.Alamat}
                saatBerubah={(nilai) => formulir.setData('Alamat', nilai)}
                galat={galat.Alamat}
                maxLength={500}
            />
            <BidangTeks
                label="Telepon (opsional)"
                nilai={formulir.data.Telepon}
                saatBerubah={(nilai) => formulir.setData('Telepon', nilai)}
                galat={galat.Telepon}
                keterangan="Dicetak di kepala struk bersama alamat. Misal 0271-123456 atau 0812-3456-7890."
                inputMode="tel"
                maxLength={30}
            />
            <BidangPilihan
                label="Kabupaten/kota"
                nilai={formulir.data.KodeKota}
                opsi={pilihanKota}
                saatBerubah={PilihKota}
                galat={galat.KodeKota}
                kosong={kota.length === 0 ? 'Data wilayah belum tersedia' : 'Pilih kabupaten/kota'}
            />
            <BidangPilihan
                label="Zona waktu"
                nilai={formulir.data.ZonaWaktu}
                opsi={pilihanZonaWaktu}
                saatBerubah={(nilai) => formulir.setData('ZonaWaktu', nilai)}
                galat={galat.ZonaWaktu}
                required
            />
            <PemilihJam
                label="Jam tutup buku"
                nilai={formulir.data.JamTutupBuku}
                saatBerubah={(nilai) => formulir.setData('JamTutupBuku', nilai)}
                galat={galat.JamTutupBuku}
                keterangan="Transaksi sebelum jam ini masuk tanggal bisnis kemarin. Kafe yang tutup 02.00 memakai 04:00."
                contoh="04:00"
                langkahMenit={30}
                required
            />
        </>
    );
    const isiPajak = (
        <>
            <FieldDescription className="m-0 text-keterangan text-teks-sekunder">
                Disimpan untuk perhitungan pajak di menu Produk. Tarif mengikuti data resmi, bukan diisi di sini.
            </FieldDescription>
            <KotakCentang
                label="Usaha berstatus PKP (memungut PPN)"
                nilai={formulir.data.Pkp}
                saatBerubah={(nilai) => formulir.setData('Pkp', nilai)}
            />
            {formulir.data.Pkp ? (
                <div className="max-w-sm">
                    <BidangTeks
                        label="NITKU outlet (opsional)"
                        nilai={formulir.data.Nitku}
                        saatBerubah={(nilai) => formulir.setData('Nitku', nilai.replace(/\D/g, ''))}
                        galat={galat.Nitku}
                        keterangan="Nomor Identitas Tempat Kegiatan Usaha, 22 angka."
                        inputMode="numeric"
                        maxLength={22}
                        kode
                    />
                </div>
            ) : null}
            {tampilPbjt ? (
                <KotakCentang
                    label="Memungut PBJT makanan & minuman (pajak daerah)"
                    nilai={formulir.data.PungutPbjt}
                    saatBerubah={(nilai) => formulir.setData('PungutPbjt', nilai)}
                />
            ) : null}
        </>
    );
    const isiKanvas = (
        <>
            <Field orientation="horizontal" className="min-h-10 items-center">
                <Switch
                    id={idKanvas}
                    checked={formulir.data.Kanvas}
                    onCheckedChange={(nilai) => formulir.setData('Kanvas', nilai)}
                />
                <FieldLabel htmlFor={idKanvas} className="text-isi font-normal text-teks-utama">
                    Outlet kanvas (kendaraan salesman)
                </FieldLabel>
            </Field>
            <FieldDescription className="m-0 text-keterangan text-teks-sekunder">
                Lokasi stok Toko outlet ini adalah bak kendaraan. Muat & bongkar lewat transfer stok, rekap hariannya di
                menu Grosir › Kanvas.
            </FieldDescription>
            {formulir.data.Kanvas ? (
                <div className="max-w-sm">
                    <BidangTeks
                        label="Nomor kendaraan (opsional)"
                        nilai={formulir.data.NomorKendaraan}
                        saatBerubah={(nilai) => formulir.setData('NomorKendaraan', nilai.toUpperCase())}
                        galat={galat.NomorKendaraan}
                        keterangan="Plat nomor, misal AD 1234 XY."
                        maxLength={20}
                        kode
                    />
                </div>
            ) : null}
        </>
    );

    if (bertahap) {
        return (
            <CangkangWizard
                kontrol={kontrol}
                kunciTinjau="Tinjau"
                labelNavigasi="Langkah tambah outlet"
                teksSimpan="Tambah outlet"
                bergalat={HitungLangkahOutletBergalat(galat)}
                pesanGalat={<RingkasanGalatFormulir galat={AmbilGalatLangkahOutlet(galat, kontrol.aktif)} />}
                saatSimpan={SimpanBaru}
                memproses={formulir.processing}
                bolehSimpan
                tautanBatal="/kelola/outlet"
                panel={{
                    Identitas: <div className="grid grid-cols-1 gap-4 md:grid-cols-2">{bidangIdentitas}</div>,
                    Lokasi: <div className="grid grid-cols-1 gap-4 md:grid-cols-2">{bidangLokasi}</div>,
                    Pajak: <div className="flex flex-col gap-2">{isiPajak}</div>,
                    Kanvas: <div className="flex flex-col gap-2">{isiKanvas}</div>,
                    Tinjau: (
                        <RingkasanOutlet
                            isian={formulir.data}
                            langkah={langkah}
                            merek={merek}
                            kota={kota}
                            saatUbah={(kunci) => kontrol.Lompat(kunci)}
                        />
                    ),
                }}
            />
        );
    }

    return (
        <Card className="gap-4 rounded-panel py-6 shadow-none">
            <CardHeader className="px-6">
                <CardTitle className="text-subjudul font-semibold text-teks-utama">
                    <h2>Profil outlet</h2>
                </CardTitle>
            </CardHeader>
            <CardContent className="px-6">
                <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        {bidangIdentitas}
                        {bidangLokasi}
                    </div>
                    <FieldSet className="gap-2 border-t border-garis pt-4">
                        <FieldLegend variant="label" className="mb-0 text-label font-semibold text-teks-utama">
                            Profil pajak dasar
                        </FieldLegend>
                        {isiPajak}
                    </FieldSet>
                    {tampilKanvas ? (
                        <FieldSet className="gap-2 border-t border-garis pt-4">
                            <FieldLegend variant="label" className="mb-0 text-label font-semibold text-teks-utama">
                                Kanvas
                            </FieldLegend>
                            {isiKanvas}
                        </FieldSet>
                    ) : null}
                    <div className="flex flex-wrap gap-2">
                        <Tombol type="submit" memproses={formulir.processing}>
                            Simpan outlet
                        </Tombol>
                        {saatBatal ? (
                            <Tombol varian="sekunder" onClick={saatBatal}>
                                Batal
                            </Tombol>
                        ) : null}
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}
