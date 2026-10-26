import { router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import GrupCentang from '@/Komponen/Formulir/GrupCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import { AmbilGalatUmum, KerangkaForm } from '@/Komponen/Pengelola/Tenant/FormTindakan';
import { Card } from '@/Komponen/Ui/card';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';

type KelebihanKapasitas = { Outlet: number; Pengguna: number; Sku: number; Perangkat: number };

type KapasitasPaket = {
    Batas: { Outlet: number | null; Pengguna: number | null; Sku: number | null; PerangkatPerOutlet: number | null };
    Pakai: { Outlet: number; Pengguna: number; Sku: number; PerangkatMaksimal: number };
    Lebih: KelebihanKapasitas;
    Ada: boolean;
};

export type PilihanTurunPaket = {
    Kode: string;
    Nama: string;
    Gratis: boolean;
    HargaBulanan: string;
    HargaTahunan: string;
    Kapasitas: KapasitasPaket;
};

export type DataTurunPaket = {
    Menunggu: {
        Uuid: string;
        KodePaket: string;
        NamaPaket: string;
        Gratis: boolean;
        BerlakuPada: string;
        Kelebihan: KelebihanKapasitas | null;
    } | null;
    Bisa: boolean;
    Alasan: string | null;
    Pilihan: PilihanTurunPaket[];
    Outlet: { Uuid: string; Nama: string; JumlahPerangkat: number }[];
    Anggota: { Uuid: string; Nama: string; Email: string | null; Pemilik: boolean }[];
    /** Langganan ditangguhkan karena tunggakan: pindah ke paket Gratis seketika. */
    PindahGratis: { Kapasitas: KapasitasPaket } | null;
};

/**
 * BR-P04.4: turun paket mandiri. Berlaku pada perpanjangan berikutnya (atau saat periode berakhir untuk Gratis);
 * bila paket tujuan lebih kecil dari pemakaian, Owner memilih outlet dan pengguna yang tetap aktif, sisanya diarsipkan
 * tanpa menghapus data.
 */
export default function PanelTurunPaket({ data }: { data: DataTurunPaket }) {
    const [terbuka, AturTerbuka] = useState(false);

    if (data.Menunggu !== null) {
        const menunggu = data.Menunggu;

        return (
            <Pemberitahuan jenis="info" judul={`Turun ke paket ${menunggu.NamaPaket} dijadwalkan`}>
                <p>
                    {menunggu.Gratis
                        ? `Langganan turun ke paket Gratis pada ${FormatTanggal(menunggu.BerlakuPada)}.`
                        : `Perpanjangan berikutnya (setelah ${FormatTanggal(menunggu.BerlakuPada)}) ditagih dengan paket ${menunggu.NamaPaket}.`}{' '}
                    Sampai saat itu paket Anda tidak berubah dan data tidak dihapus.
                </p>
                <div className="mt-2">
                    <Tombol
                        varian="sekunder"
                        onClick={() =>
                            router.post(
                                `/kelola/langganan/turun-paket/${menunggu.Uuid}/batalkan`,
                                {},
                                { preserveScroll: true },
                            )
                        }
                    >
                        Batalkan turun paket
                    </Tombol>
                </div>
            </Pemberitahuan>
        );
    }

    if (data.PindahGratis !== null) {
        return (
            <Card className="flex flex-col gap-2 rounded-panel p-4 shadow-none">
                <h2 className="text-subjudul font-semibold text-teks-utama">Pindah ke paket Gratis</h2>
                <p className="text-isi text-teks-sekunder">
                    Langganan Anda ditangguhkan. Pindah ke paket Gratis supaya kasir terbuka lagi sekarang dalam batas
                    paket Gratis. Tagihan yang belum dibayar dibatalkan dan data Anda tidak dihapus.
                </p>
                <div>
                    <Tombol varian="sekunder" onClick={() => AturTerbuka(true)}>
                        Pindah ke paket Gratis
                    </Tombol>
                </div>
                {terbuka ? (
                    <FormTurunPaket
                        data={data}
                        kapasitasTetap={data.PindahGratis.Kapasitas}
                        urlKirim="/kelola/langganan/pindah-gratis"
                        saatSelesai={() => AturTerbuka(false)}
                    />
                ) : null}
            </Card>
        );
    }

    if (!data.Bisa) {
        return data.Alasan ? <p className="text-keterangan text-teks-sekunder">{data.Alasan}</p> : null;
    }

    return (
        <Card className="flex flex-col gap-2 rounded-panel p-4 shadow-none">
            <h2 className="text-subjudul font-semibold text-teks-utama">Turun paket</h2>
            <p className="text-isi text-teks-sekunder">
                Pindah ke paket yang lebih murah mulai perpanjangan berikutnya. Periode yang sudah dibayar tetap
                berjalan penuh dan data Anda tidak dihapus.
            </p>
            <div>
                <Tombol varian="sekunder" onClick={() => AturTerbuka(true)}>
                    Turun paket
                </Tombol>
            </div>
            {terbuka ? <FormTurunPaket data={data} saatSelesai={() => AturTerbuka(false)} /> : null}
        </Card>
    );
}

function Ringkas(kapasitas: KapasitasPaket): string[] {
    const hasil: string[] = [];

    if (kapasitas.Lebih.Outlet > 0 && kapasitas.Batas.Outlet !== null) {
        hasil.push(`Outlet aktif ${String(kapasitas.Pakai.Outlet)} dari batas ${String(kapasitas.Batas.Outlet)}`);
    }

    if (kapasitas.Lebih.Pengguna > 0 && kapasitas.Batas.Pengguna !== null) {
        hasil.push(`Pengguna ${String(kapasitas.Pakai.Pengguna)} dari batas ${String(kapasitas.Batas.Pengguna)}`);
    }

    if (kapasitas.Lebih.Sku > 0 && kapasitas.Batas.Sku !== null) {
        hasil.push(
            `Produk ${String(kapasitas.Pakai.Sku)} dari batas ${String(kapasitas.Batas.Sku)}: produk tetap ada, tetapi tidak bisa menambah produk baru`,
        );
    }

    if (kapasitas.Lebih.Perangkat > 0 && kapasitas.Batas.PerangkatPerOutlet !== null) {
        hasil.push(
            `Perangkat di satu outlet ${String(kapasitas.Pakai.PerangkatMaksimal)} dari batas ${String(kapasitas.Batas.PerangkatPerOutlet)}: cabut perangkat yang tidak dipakai`,
        );
    }

    return hasil;
}

type PropsFormTurunPaket = {
    data: DataTurunPaket;
    saatSelesai: () => void;
    /** Pindah ke Gratis seketika: paket tujuan tetap, kapasitasnya sudah diketahui. */
    kapasitasTetap?: KapasitasPaket;
    urlKirim?: string;
};

function FormTurunPaket({
    data,
    saatSelesai,
    kapasitasTetap,
    urlKirim = '/kelola/langganan/turun-paket',
}: PropsFormTurunPaket) {
    const formulir = useForm<{ KodePaket: string; UuidOutletTetap: string[]; UuidAnggotaTetap: string[] }>({
        KodePaket: data.Pilihan[0]?.Kode ?? '',
        UuidOutletTetap: [],
        UuidAnggotaTetap: [],
    });
    const pilihan = data.Pilihan.find((item) => item.Kode === formulir.data.KodePaket);
    const kapasitas = kapasitasTetap ?? pilihan?.Kapasitas;
    const seketika = kapasitasTetap !== undefined;
    const pilihOutlet = kapasitas !== undefined && kapasitas.Lebih.Outlet > 0;
    const pilihAnggota = kapasitas !== undefined && kapasitas.Lebih.Pengguna > 0;
    const galat = formulir.errors as Partial<Record<string, string>>;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.transform((nilai) => ({
            ...nilai,
            UuidOutletTetap: pilihOutlet ? nilai.UuidOutletTetap : [],
            UuidAnggotaTetap: pilihAnggota ? nilai.UuidAnggotaTetap : [],
        }));
        formulir.post(urlKirim, { preserveScroll: true, onSuccess: saatSelesai });
    };

    return (
        <KerangkaForm
            judul={seketika ? 'Pindah ke paket Gratis' : 'Turun paket'}
            keterangan={
                <p>
                    {seketika
                        ? 'Berlaku seketika. '
                        : 'Berlaku mulai perpanjangan berikutnya. Periode yang sudah dibayar tetap berjalan. '}
                    Outlet dan pengguna yang tidak dipilih diarsipkan (bukan dihapus) dan bisa dipulihkan bila Anda naik
                    paket lagi.
                </p>
            }
            labelKirim={seketika ? 'Pindah ke Gratis sekarang' : 'Jadwalkan turun paket'}
            memproses={formulir.processing}
            galatUmum={AmbilGalatUmum(formulir.errors) ?? galat.KodePaket}
            saatKirim={Kirim}
            saatBatal={saatSelesai}
        >
            {seketika ? null : (
                <BidangPilihan
                    label="Paket tujuan"
                    nilai={formulir.data.KodePaket}
                    opsi={data.Pilihan.map((item) => ({
                        Nilai: item.Kode,
                        Label: item.Gratis
                            ? `${item.Nama} (gratis)`
                            : `${item.Nama} (${FormatRupiah(item.HargaBulanan)}/bulan)`,
                    }))}
                    saatBerubah={(nilai) =>
                        formulir.setData({ KodePaket: nilai, UuidOutletTetap: [], UuidAnggotaTetap: [] })
                    }
                    required
                />
            )}
            {kapasitas !== undefined && kapasitas.Ada ? (
                <Pemberitahuan jenis="peringatan" judul="Pemakaian Anda melebihi batas paket tujuan">
                    <ul className="list-disc pl-5">
                        {Ringkas(kapasitas).map((baris) => (
                            <li key={baris}>{baris}</li>
                        ))}
                    </ul>
                </Pemberitahuan>
            ) : null}
            {pilihOutlet ? (
                <GrupCentang
                    legenda={`Outlet yang tetap aktif (maksimal ${String(kapasitas.Batas.Outlet)})`}
                    opsi={data.Outlet.map((outlet) => ({ nilai: outlet.Uuid, label: outlet.Nama }))}
                    terpilih={formulir.data.UuidOutletTetap}
                    saatBerubah={(terpilih) => formulir.setData('UuidOutletTetap', terpilih)}
                    galat={galat.UuidOutletTetap}
                    required
                />
            ) : null}
            {pilihAnggota ? (
                <GrupCentang
                    legenda={`Pengguna yang tetap aktif (maksimal ${String(kapasitas.Batas.Pengguna)}, termasuk Pemilik)`}
                    opsi={data.Anggota.filter((anggota) => !anggota.Pemilik).map((anggota) => ({
                        nilai: anggota.Uuid,
                        label: anggota.Email === null ? anggota.Nama : `${anggota.Nama} | ${anggota.Email}`,
                    }))}
                    terpilih={formulir.data.UuidAnggotaTetap}
                    saatBerubah={(terpilih) => formulir.setData('UuidAnggotaTetap', terpilih)}
                    galat={galat.UuidAnggotaTetap}
                />
            ) : null}
        </KerangkaForm>
    );
}
