import { ChartNoAxesColumnIcon } from 'lucide-react';

import RingkasanPenagihanKartu from '@/Komponen/Invoice/RingkasanPenagihanKartu';
import KartuAbsenSaya from '@/Komponen/Kelola/KartuAbsenSaya';
import PintasanSektor from '@/Komponen/Kelola/PintasanSektor';
import DasborPemilik from '@/Komponen/Laporan/DasborPemilik';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia } from '@/Komponen/Ui/empty';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { DasborPemilik as DataDasbor } from '@/Tipe/Laporan';
import type { RingkasanPenagihan } from '@/Tipe/Invoice';

type PropsBeranda = { Dasbor?: DataDasbor | null; Penagihan?: RingkasanPenagihan | null };

/**
 * Beranda back-office: ringkasan angka (dasbor pemilik F-14a untuk pemegang izin laporan penjualan) dan pintasan.
 * Hal yang perlu ditindaklanjuti (D-23 C, termasuk persiapan toko) hanya ada di Kotak Tindakan (`/kelola/tindakan`,
 * lencana jumlahnya di menu samping), bukan diulang di sini (D-247). Tanpa izin: beranda tanpa angka.
 */
export default function HalamanBerandaKelola({ Dasbor = null, Penagihan = null }: PropsBeranda) {
    return (
        <TataLetakAplikasi judul="Beranda">
            <KartuAbsenSaya />
            <PintasanSektor />
            {Penagihan ? <RingkasanPenagihanKartu ringkasan={Penagihan} /> : null}
            {Dasbor ? <DasborPemilik data={Dasbor} /> : null}
            {!Dasbor && !Penagihan ? (
                <Empty className="rounded-panel border border-garis bg-permukaan px-4 py-6 md:p-8">
                    <EmptyHeader>
                        <EmptyMedia variant="icon" aria-hidden="true">
                            <ChartNoAxesColumnIcon />
                        </EmptyMedia>
                        <EmptyDescription className="text-isi text-teks-sekunder">
                            Selamat bekerja. Ringkasan penjualan hanya tampil untuk pengguna yang punya izin melihat
                            laporan penjualan.
                        </EmptyDescription>
                    </EmptyHeader>
                </Empty>
            ) : null}
        </TataLetakAplikasi>
    );
}
