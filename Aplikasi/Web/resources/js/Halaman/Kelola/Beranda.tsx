import { ChartNoAxesColumnIcon } from 'lucide-react';

import RingkasanPenagihanKartu from '@/Komponen/Invoice/RingkasanPenagihanKartu';
import PintasanSektor from '@/Komponen/Kelola/PintasanSektor';
import RingkasTindakan from '@/Komponen/Kelola/RingkasTindakan';
import DasborPemilik from '@/Komponen/Laporan/DasborPemilik';
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia } from '@/Komponen/Ui/empty';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { DasborPemilik as DataDasbor } from '@/Tipe/Laporan';
import type { RingkasanPenagihan } from '@/Tipe/Invoice';
import type { ButirTindakan } from '@/Tipe/Tindakan';

type PropsBeranda = { Dasbor?: DataDasbor | null; Tindakan?: ButirTindakan[]; Penagihan?: RingkasanPenagihan | null };

/**
 * Beranda back-office: kartu "Perlu tindakan" (D-23 C; D-24: termasuk persiapan toko yang dulu berupa checklist)
 * dan dasbor pemilik (F-14a) untuk pemegang izin laporan penjualan. Tanpa izin: beranda tanpa angka.
 */
export default function HalamanBerandaKelola({ Dasbor = null, Tindakan = [], Penagihan = null }: PropsBeranda) {
    return (
        <TataLetakAplikasi judul="Beranda">
            <RingkasTindakan butir={Tindakan} />
            <PintasanSektor />
            {Penagihan ? <RingkasanPenagihanKartu ringkasan={Penagihan} /> : null}
            {Dasbor ? <DasborPemilik data={Dasbor} /> : null}
            {!Dasbor && !Penagihan && Tindakan.length === 0 ? (
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
