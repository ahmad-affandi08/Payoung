import { Link, useForm } from '@inertiajs/react';
import { useId, useRef, type FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import RingkasanGalatFormulir, { FokusGalatPertama } from '@/Komponen/PanduanAwal/RingkasanGalatFormulir';
import TataLetakPanduan from '@/Komponen/PanduanAwal/TataLetakPanduan';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import { Card } from '@/Komponen/Ui/card';
import { FieldError, FieldLegend, FieldSet } from '@/Komponen/Ui/field';
import { RadioGroup, RadioGroupItem } from '@/Komponen/Ui/radio-group';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatPersen } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import { PakaiSektor } from '@/Pustaka/Sektor';
import { FormatMasukanPersen, NormalisasiMasukanPersen } from '@/Pustaka/MasukanUang';
import { AlamatPanduan, type PropsPajak, type TarifTampil } from '@/Tipe/PanduanAwal';

type IsianPajak = PropsPajak['Nilai'];

const kolomKelompok: KolomTabel<PropsPajak['KelompokPajak'][number]>[] = [
    {
        id: 'Nama',
        accessorKey: 'Nama',
        header: 'Kelompok',
        meta: { label: 'Kelompok', prioritas: 'utama', wajib: true, kelasSel: 'break-words text-teks-utama' },
    },
    {
        id: 'Pajak',
        header: 'Pajak yang dikenakan',
        enableSorting: false,
        meta: { label: 'Pajak yang dikenakan', prioritas: 'penting', kelasSel: 'text-teks-sekunder' },
        cell: ({ row: { original: kelompok } }) =>
            kelompok.Pajak.length === 0 ? (
                'Tanpa pajak'
            ) : (
                <ul>
                    {kelompok.Pajak.map((pajak) => (
                        <li key={pajak.KodeJenisPajak}>
                            {pajak.NamaJenisPajak} | {pajak.LabelDasarPengenaan}
                        </li>
                    ))}
                </ul>
            ),
    },
];

/** "10.000000" berlaku "2024-01-01" → "10% berlaku mulai 1 Jan 2024 (Perda No. 1 Tahun 2024)". */
function JelaskanTarif(tarif: TarifTampil): string {
    const tanggal = /^\d{4}-\d{2}-\d{2}/.exec(tarif.BerlakuMulai)?.[0];
    const berlaku = tanggal ? ` berlaku mulai ${FormatTanggal(tanggal)}` : '';
    const dasarHukum = tarif.NomorDasarHukum ? ` (${tarif.NomorDasarHukum})` : '';

    return `${FormatPersen(tarif.Tarif)}%${berlaku}${dasarHukum}`;
}

/** Langkah 3 F-01: konfirmasi usulan pajak dari template, status PKP, dan tarif kota (tarif dari server, CLAUDE.md #12). */
export default function HalamanPajak({
    Progres,
    Pkp,
    Kota,
    Nilai,
    SudahDikonfirmasi,
    TarifPbjt,
    TarifPpn,
    KelompokPajak,
    AlasanUsulan,
}: PropsPajak) {
    const elemenFormulir = useRef<HTMLFormElement>(null);
    const idGalatPbjt = useId();
    const idLegendaHarga = useId();
    const formulir = useForm<IsianPajak>({ ...Nilai });
    // D-183: PBJT makanan & minuman dan biaya layanan hanya untuk usaha F&B (atau yang sudah mengaturnya).
    const tampilPbjt = PakaiSektor(['FNB', 'SVC-SLN', 'SVC-FIT']) || formulir.data.PungutPbjt;
    const tampilBiayaLayanan = PakaiSektor(['FNB', 'SVC-SLN']) || formulir.data.BiayaLayananAktif;
    const tautanProfil =
        Progres.Langkah.find((item) => item.Kunci === 'ProfilUsaha')?.Tautan ?? AlamatPanduan.ProfilUsaha;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(AlamatPanduan.Pajak, {
            preserveScroll: true,
            onError: () => FokusGalatPertama(elemenFormulir.current),
        });
    };

    return (
        <TataLetakPanduan progres={Progres} langkah="Pajak">
            {SudahDikonfirmasi ? (
                <Pemberitahuan jenis="info" judul="Pengaturan pajak sudah disimpan">
                    Ubah bila perlu, lalu simpan lagi.
                </Pemberitahuan>
            ) : AlasanUsulan.length > 0 ? (
                <Pemberitahuan jenis="info" judul="Usulan dari template dan data usaha Anda">
                    <ul className="list-disc pl-5">
                        {AlasanUsulan.map((alasan) => (
                            <li key={alasan}>{alasan}</li>
                        ))}
                    </ul>
                    <p className="mt-1">Periksa usulan ini, ubah bila perlu, lalu simpan.</p>
                </Pemberitahuan>
            ) : null}

            <Card className="p-4 sm:p-6 rounded-panel shadow-none">
                <form ref={elemenFormulir} onSubmit={Kirim} className="flex flex-col gap-6" noValidate>
                    <RingkasanGalatFormulir galat={formulir.errors} />

                    <section aria-labelledby="judul-ppn" className="flex flex-col gap-1">
                        <h2 id="judul-ppn" className="text-subjudul font-semibold text-teks-utama">
                            PPN
                        </h2>
                        <p className="text-isi text-teks-sekunder">
                            {Pkp
                                ? 'Usaha Anda PKP, jadi penjualan barang kena pajak dipungut PPN.'
                                : 'Usaha Anda bukan PKP, jadi tidak memungut PPN.'}{' '}
                            <Link href={tautanProfil} className="font-semibold text-brand underline">
                                Ubah status PKP di Profil usaha
                            </Link>
                            .
                        </p>
                        {Pkp && TarifPpn ? (
                            <p className="text-isi text-teks-utama">
                                Tarif PPN {JelaskanTarif(TarifPpn)}
                                {TarifPpn.PengaliDppPembilang !== TarifPpn.PengaliDppPenyebut
                                    ? `, dihitung dari DPP ${String(TarifPpn.PengaliDppPembilang)}/${String(TarifPpn.PengaliDppPenyebut)} harga jual`
                                    : ''}
                                .
                            </p>
                        ) : null}
                        {Pkp && !TarifPpn ? (
                            <p className="text-isi text-teks-sekunder">
                                Tarif PPN belum tersedia. Anda tetap bisa menyimpan; PPN belum dihitung sampai tarifnya
                                tersedia.
                            </p>
                        ) : null}
                    </section>

                    {tampilPbjt ? (
                        <section aria-labelledby="judul-pbjt" className="flex flex-col gap-2">
                            <h2 id="judul-pbjt" className="text-subjudul font-semibold text-teks-utama">
                                PBJT makanan & minuman (pajak daerah)
                            </h2>
                            <KotakCentang
                                label="Pungut PBJT di outlet ini"
                                nilai={formulir.data.PungutPbjt}
                                saatBerubah={(nilai) => formulir.setData('PungutPbjt', nilai)}
                            />
                            {formulir.errors.PungutPbjt ? (
                                <FieldError id={idGalatPbjt} className="text-keterangan font-semibold">
                                    {formulir.errors.PungutPbjt}
                                </FieldError>
                            ) : null}
                            {Kota === null ? (
                                <Pemberitahuan jenis="peringatan" judul="Kota outlet belum diisi">
                                    Isi kota di langkah{' '}
                                    <Link href={tautanProfil} className="font-semibold text-brand underline">
                                        Profil usaha
                                    </Link>
                                    . Tarif PBJT mengikuti kota.
                                </Pemberitahuan>
                            ) : TarifPbjt ? (
                                <p className="text-isi text-teks-utama">
                                    Tarif PBJT {Kota.Nama}: {JelaskanTarif(TarifPbjt)}. Biaya layanan{' '}
                                    {TarifPbjt.BiayaLayananMasukDpp ? 'ikut' : 'tidak ikut'} dikenai PBJT di kota ini.
                                </p>
                            ) : formulir.data.PungutPbjt ? (
                                <Pemberitahuan jenis="peringatan" judul="Tarif PBJT belum tersedia">
                                    Tarif PBJT {Kota.Nama} belum tersedia. Anda tetap bisa menyimpan; PBJT belum
                                    dihitung sampai tarifnya tersedia.
                                </Pemberitahuan>
                            ) : null}
                        </section>
                    ) : null}
                    {tampilBiayaLayanan ? (
                        <section aria-labelledby="judul-layanan" className="flex flex-col gap-2">
                            <h2 id="judul-layanan" className="text-subjudul font-semibold text-teks-utama">
                                Biaya layanan
                            </h2>
                            <KotakCentang
                                label="Kenakan biaya layanan"
                                nilai={formulir.data.BiayaLayananAktif}
                                saatBerubah={(nilai) => formulir.setData('BiayaLayananAktif', nilai)}
                            />
                            {formulir.data.BiayaLayananAktif ? (
                                <div className="max-w-xs">
                                    <BidangTeks
                                        label="Persentase biaya layanan"
                                        nilai={FormatMasukanPersen(formulir.data.PersenBiayaLayanan)}
                                        saatBerubah={(nilai) =>
                                            formulir.setData('PersenBiayaLayanan', NormalisasiMasukanPersen(nilai))
                                        }
                                        galat={formulir.errors.PersenBiayaLayanan}
                                        required
                                        keterangan="0 sampai 10 persen, misal 5 atau 7,5."
                                        inputMode="decimal"
                                        maxLength={5}
                                    />
                                </div>
                            ) : null}
                        </section>
                    ) : null}

                    <FieldSet className="gap-2">
                        <FieldLegend id={idLegendaHarga} className="mb-1 text-subjudul font-semibold text-teks-utama">
                            Harga jual di menu
                        </FieldLegend>
                        <RadioGroup
                            name="HargaTermasukPajak"
                            value={formulir.data.HargaTermasukPajak ? 'Termasuk' : 'Belum'}
                            onValueChange={(nilai) => formulir.setData('HargaTermasukPajak', nilai === 'Termasuk')}
                            aria-labelledby={idLegendaHarga}
                            className="gap-2"
                        >
                            <div className="flex min-h-10 items-start gap-2 text-isi text-teks-utama">
                                <RadioGroupItem id={`${idLegendaHarga}-termasuk`} value="Termasuk" className="mt-0.5" />
                                <label htmlFor={`${idLegendaHarga}-termasuk`} className="cursor-pointer">
                                    Sudah termasuk pajak
                                    <span className="block text-keterangan text-teks-sekunder">
                                        Pelanggan membayar sesuai harga di menu; pajak dihitung dari dalam harga.
                                    </span>
                                </label>
                            </div>
                            <div className="flex min-h-10 items-start gap-2 text-isi text-teks-utama">
                                <RadioGroupItem id={`${idLegendaHarga}-belum`} value="Belum" className="mt-0.5" />
                                <label htmlFor={`${idLegendaHarga}-belum`} className="cursor-pointer">
                                    Belum termasuk pajak
                                    <span className="block text-keterangan text-teks-sekunder">
                                        Pajak ditambahkan di atas harga menu saat pembayaran.
                                    </span>
                                </label>
                            </div>
                        </RadioGroup>
                        {formulir.errors.HargaTermasukPajak ? (
                            <FieldError className="text-keterangan font-semibold">
                                {formulir.errors.HargaTermasukPajak}
                            </FieldError>
                        ) : null}
                    </FieldSet>

                    <section aria-labelledby="judul-kelompok" className="flex flex-col gap-2">
                        <h2 id="judul-kelompok" className="text-subjudul font-semibold text-teks-utama">
                            Kelompok pajak dari template
                        </h2>
                        <TabelData
                            id="panduan-kelompok-pajak"
                            label="Kelompok pajak"
                            kolom={kolomKelompok}
                            sumber={{ mode: 'lokal', data: KelompokPajak }}
                            ambilIdBaris={(kelompok) => kelompok.Nama}
                            kosong={{
                                ilustrasi: true,
                                judul: 'Belum ada kelompok pajak. Terapkan template di langkah Jenis usaha & template untuk menyiapkannya.',
                            }}
                        />
                    </section>

                    <div>
                        <Tombol type="submit" memproses={formulir.processing}>
                            Simpan pengaturan pajak
                        </Tombol>
                    </div>
                </form>
            </Card>
        </TataLetakPanduan>
    );
}
