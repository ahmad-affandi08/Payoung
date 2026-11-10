import { Link, router, useForm } from '@inertiajs/react';
import { useId, type FormEvent } from 'react';

import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import { FieldError, FieldLegend, FieldSet } from '@/Komponen/Ui/field';
import { RadioGroup, RadioGroupItem } from '@/Komponen/Ui/radio-group';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { JelaskanTarifPajak } from '@/Pustaka/JelaskanTarifPajak';
import { FormatMasukanPersen, NormalisasiMasukanPersen } from '@/Pustaka/MasukanUang';
import { PakaiSektor } from '@/Pustaka/Sektor';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsPengaturanPajak } from '@/Tipe/Kasir';

const alamat = '/kelola/kasir/pajak';
const alamatProfilUsaha = '/kelola/pengaturan/profil-usaha';

/**
 * D-263: pajak dan biaya layanan per outlet (PBJT, biaya layanan, harga termasuk pajak). Dulu hanya bisa diubah lewat
 * langkah Pajak di Panduan awal. Menampilkan nilai yang tersimpan di outlet, perubahan berlaku untuk penjualan berikutnya
 * (penjualan lama menyimpan snapshot pengaturannya sendiri). Tarif selalu dari server (CLAUDE.md #12).
 */
export default function HalamanPengaturanPajak({
    UuidOutlet,
    OpsiOutlet,
    Pkp,
    Kota,
    Nilai,
    TarifPbjt,
    TarifPpn,
}: PropsPengaturanPajak) {
    const idLegendaHarga = useId();
    const formulir = useForm({ Outlet: UuidOutlet, ...Nilai });
    // D-183: PBJT makanan & minuman dan biaya layanan hanya untuk usaha F&B (atau yang sudah mengaturnya).
    const tampilPbjt = PakaiSektor(['FNB', 'SVC-SLN', 'SVC-FIT']) || formulir.data.PungutPbjt;
    const tampilBiayaLayanan = PakaiSektor(['FNB', 'SVC-SLN']) || formulir.data.BiayaLayananAktif;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.put(alamat, { preserveScroll: true });
    };

    return (
        <TataLetakAplikasi judul="Pajak & biaya layanan">
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <Panel>
                    <div className="max-w-md">
                        <BidangOutlet
                            nilai={UuidOutlet}
                            opsi={OpsiOutlet.map((o) => ({ Nilai: o.Uuid, Label: o.Nama }))}
                            saatBerubah={(nilai) => {
                                if (nilai !== UuidOutlet) {
                                    router.get(alamat, { outlet: nilai }, { preserveScroll: true });
                                }
                            }}
                            sembunyiBilaTunggal
                        />
                    </div>
                    <p className="text-keterangan text-teks-sekunder">
                        Pengaturan ini menentukan total setiap transaksi di outlet ini. Perubahan berlaku untuk
                        penjualan berikutnya, penjualan yang sudah tercatat tidak berubah. Kasir perlu sinkron dulu
                        supaya angka barunya terpakai.
                    </p>
                </Panel>

                <Panel judul="PPN">
                    <p className="text-isi text-teks-sekunder">
                        {Pkp
                            ? 'Usaha Anda PKP, jadi penjualan barang kena pajak dipungut PPN.'
                            : 'Usaha Anda bukan PKP, jadi tidak memungut PPN.'}{' '}
                        <Link href={alamatProfilUsaha} className="font-semibold text-brand underline">
                            Ubah status PKP di Profil usaha
                        </Link>
                        .
                    </p>
                    {Pkp && TarifPpn ? (
                        <p className="text-isi text-teks-utama">
                            Tarif PPN {JelaskanTarifPajak(TarifPpn)}
                            {TarifPpn.PengaliDppPembilang !== TarifPpn.PengaliDppPenyebut
                                ? `, dihitung dari DPP ${String(TarifPpn.PengaliDppPembilang)}/${String(TarifPpn.PengaliDppPenyebut)} harga jual`
                                : ''}
                            .
                        </p>
                    ) : null}
                    {Pkp && !TarifPpn ? (
                        <p className="text-isi text-teks-sekunder">
                            Tarif PPN belum tersedia. PPN belum dihitung sampai tarifnya tersedia.
                        </p>
                    ) : null}
                </Panel>

                {tampilPbjt ? (
                    <Panel judul="PBJT makanan & minuman (pajak daerah)">
                        <KotakCentang
                            label="Pungut PBJT di outlet ini"
                            nilai={formulir.data.PungutPbjt}
                            saatBerubah={(nilai) => formulir.setData('PungutPbjt', nilai)}
                        />
                        {formulir.errors.PungutPbjt ? (
                            <FieldError className="text-keterangan font-semibold">
                                {formulir.errors.PungutPbjt}
                            </FieldError>
                        ) : null}
                        {Kota === null ? (
                            <Pemberitahuan jenis="peringatan" judul="Kota outlet belum diisi">
                                Isi kota di{' '}
                                <Link href={alamatProfilUsaha} className="font-semibold text-brand underline">
                                    Profil usaha
                                </Link>
                                . Tarif PBJT mengikuti kota.
                            </Pemberitahuan>
                        ) : TarifPbjt ? (
                            <p className="text-isi text-teks-utama">
                                Tarif PBJT {Kota.Nama}: {JelaskanTarifPajak(TarifPbjt)}. Biaya layanan{' '}
                                {TarifPbjt.BiayaLayananMasukDpp ? 'ikut' : 'tidak ikut'} dikenai PBJT di kota ini.
                            </p>
                        ) : formulir.data.PungutPbjt ? (
                            <Pemberitahuan jenis="peringatan" judul="Tarif PBJT belum tersedia">
                                Tarif PBJT {Kota.Nama} belum tersedia. PBJT belum dihitung sampai tarifnya tersedia.
                            </Pemberitahuan>
                        ) : null}
                    </Panel>
                ) : null}

                {tampilBiayaLayanan ? (
                    <Panel judul="Biaya layanan">
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
                        ) : (
                            <p className="text-keterangan text-teks-sekunder">
                                Biaya layanan tidak ditambahkan ke total pelanggan.
                            </p>
                        )}
                    </Panel>
                ) : null}

                <Panel>
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
                                        Pelanggan membayar sesuai harga di menu. Pajak dihitung dari dalam harga.
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
                </Panel>

                <BilahAksiForm>
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan pengaturan pajak
                    </Tombol>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
