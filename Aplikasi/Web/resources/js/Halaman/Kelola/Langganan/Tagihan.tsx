import { router } from '@inertiajs/react';
import { useState } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Tombol from '@/Komponen/Formulir/Tombol';
import HasilPembayaran from '@/Komponen/Langganan/Bayar/HasilPembayaran';
import LayarBerhasil from '@/Komponen/Langganan/Bayar/LayarBerhasil';
import MemeriksaPembayaran from '@/Komponen/Langganan/Bayar/MemeriksaPembayaran';
import PenunjukTagihan from '@/Komponen/Langganan/PenunjukTagihan';
import PanelBayarSnap from '@/Komponen/Langganan/PanelBayarSnap';
import PanelTransferManual, { type RekeningTujuan } from '@/Komponen/Langganan/PanelTransferManual';
import RincianTagihan from '@/Komponen/Langganan/RincianTagihan';
import RiwayatPembayaranTagihan from '@/Komponen/Langganan/RiwayatPembayaranTagihan';
import TombolBayarOnline from '@/Komponen/Langganan/TombolBayarOnline';
import { Card } from '@/Komponen/Ui/card';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/Komponen/Ui/alert-dialog';
import { AmbilJson } from '@/Pustaka/PermintaanJson';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PembayaranLangganan, PilihanBayar, TagihanLangganan } from '@/Tipe/TagihanLangganan';

type PropsTagihan = {
    Tagihan: TagihanLangganan;
    Pembayaran: PembayaranLangganan[];
    BolehBayarOnline: boolean;
    /** D-159: cara bayar SNAP (halaman bayar Payoung) dan apakah DOKU Checkout juga tersedia. */
    PilihanBayar?: PilihanBayar[];
    BolehCheckout?: boolean;
    /** D-305: nama penyedia dari server (mis. "DOKU") untuk teks layar. */
    NamaPenyedia?: string;
    BolehBatalkan: boolean;
    BolehUnggahBukti?: boolean;
    RekeningTujuan?: RekeningTujuan[];
};

/** Detail tagihan dan pembayaran online: halaman bayar Payoung (DOKU SNAP) serta DOKU Checkout untuk kartu. */
export default function HalamanTagihanLangganan({
    Tagihan,
    Pembayaran,
    BolehBayarOnline,
    PilihanBayar = [],
    BolehCheckout = BolehBayarOnline,
    NamaPenyedia = 'penyedia pembayaran',
    BolehBatalkan,
    BolehUnggahBukti = false,
    RekeningTujuan = [],
}: PropsTagihan) {
    const [membatalkan, AturMembatalkan] = useState(false);
    const [konfirmasiBatal, AturKonfirmasiBatal] = useState(false);
    // Panel bayar (atau layar berhasil) jadi kolom kanan, rincian dan riwayat di kiri (D-332). Tanpa keduanya satu kolom.
    const adaPanelBayar = Tagihan.Status === 'Lunas' || BolehBayarOnline;

    const Batalkan = () =>
        router.post(
            `/kelola/langganan/tagihan/${Tagihan.Uuid}/batalkan`,
            {},
            { onStart: () => AturMembatalkan(true), onFinish: () => AturMembatalkan(false) },
        );

    return (
        <TataLetakAplikasi
            judul={`Tagihan ${Tagihan.Nomor}`}
            jejak={[{ label: 'Langganan', href: '/kelola/langganan' }]}
        >
            <AksiHalaman
                langkah={[]}
                lainnya={
                    BolehBatalkan
                        ? [
                              {
                                  label: 'Batalkan tagihan',
                                  saatKlik: () => AturKonfirmasiBatal(true),
                                  nonaktif: membatalkan,
                                  bahaya: true,
                              },
                          ]
                        : []
                }
            />
            <PenunjukTagihan status={Tagihan.Status} labelStatus={Tagihan.LabelStatus} />
            <div
                className={
                    adaPanelBayar
                        ? 'grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]'
                        : 'flex flex-col gap-4'
                }
            >
                {adaPanelBayar ? (
                    <div className="order-1 flex flex-col gap-4 lg:order-2">
                        {Tagihan.Status === 'Lunas' ? (
                            <LayarBerhasil
                                tagihan={Tagihan}
                                pembayaran={Pembayaran.find((baris) => baris.Keadaan === 'Berhasil') ?? null}
                            />
                        ) : null}
                        {BolehBayarOnline && Tagihan.Status !== 'Lunas' ? (
                            <AreaBayar
                                tagihan={Tagihan}
                                pembayaran={Pembayaran}
                                pilihan={PilihanBayar}
                                bolehCheckout={BolehCheckout}
                                namaPenyedia={NamaPenyedia}
                            />
                        ) : null}
                    </div>
                ) : null}
                <div className="order-2 flex min-w-0 flex-col gap-4 lg:order-1">
                    <RincianTagihan tagihan={Tagihan} />
                    {Tagihan.Manual &&
                    RekeningTujuan.length > 0 &&
                    (Tagihan.Status === 'Terbit' || Tagihan.Status === 'JatuhTempo') ? (
                        <PanelTransferManual
                            uuidTagihan={Tagihan.Uuid}
                            total={Tagihan.Total}
                            rekening={RekeningTujuan}
                            bolehUnggah={BolehUnggahBukti}
                        />
                    ) : null}
                    <RiwayatPembayaranTagihan pembayaran={Pembayaran} />
                </div>
            </div>
            {BolehBatalkan ? (
                <AlertDialog open={konfirmasiBatal} onOpenChange={AturKonfirmasiBatal}>
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>Batalkan tagihan {Tagihan.Nomor}?</AlertDialogTitle>
                            <AlertDialogDescription>Anda bisa membuat tagihan baru setelahnya.</AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel>Jangan batalkan</AlertDialogCancel>
                            <AlertDialogAction variant="destructive" onClick={Batalkan}>
                                Batalkan tagihan
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            ) : null}
        </TataLetakAplikasi>
    );
}

type PropsAreaBayar = {
    tagihan: TagihanLangganan;
    pembayaran: PembayaranLangganan[];
    pilihan: PilihanBayar[];
    bolehCheckout: boolean;
    namaPenyedia: string;
};

/** Pembayaran SNAP yang kode bayarnya masih berlaku, bila ada. */
function AmbilTampilanBerlaku(pembayaran: PembayaranLangganan | null) {
    const tampilan = pembayaran?.TampilanBayar ?? null;

    if (
        tampilan === null ||
        (tampilan.KedaluwarsaPada !== null && new Date(tampilan.KedaluwarsaPada).getTime() <= Date.now())
    ) {
        return null;
    }

    return tampilan;
}

/**
 * D-305: satu area bayar yang berganti menurut keadaan pembayaran terakhir. Kode bayar yang masih berlaku tampil
 * sebagai instruksi, kembali dari halaman DOKU tampil sebagai "memeriksa", hasil yang belum selesai (kedaluwarsa, belum
 * pasti, gagal) dijelaskan dulu sebelum pilihan cara bayar dibuka lagi, selain itu langsung pilihan cara bayar.
 */
function AreaBayar({ tagihan, pembayaran, pilihan, bolehCheckout, namaPenyedia }: PropsAreaBayar) {
    const [lihatPilihan, AturLihatPilihan] = useState(false);
    const [memeriksa, AturMemeriksa] = useState(false);
    const terbaru = pembayaran.find((baris) => baris.Metode === 'Gateway') ?? null;
    const tampilan = AmbilTampilanBerlaku(terbaru);

    if (pilihan.length === 0) {
        return bolehCheckout ? <TombolBayarOnline uuidTagihan={tagihan.Uuid} namaPenyedia={namaPenyedia} /> : null;
    }

    if (tampilan !== null) {
        return (
            <PanelBayarSnap
                uuidTagihan={tagihan.Uuid}
                nomorTagihan={tagihan.Nomor}
                total={tagihan.Total}
                pilihan={pilihan}
                awal={tampilan}
                bolehCheckout={bolehCheckout}
                namaPenyedia={namaPenyedia}
            />
        );
    }

    if (terbaru !== null && terbaru.Keadaan === 'Menunggu') {
        return <MemeriksaPembayaran uuidTagihan={tagihan.Uuid} namaPenyedia={namaPenyedia} />;
    }

    const belumSelesai =
        terbaru !== null && ['Kedaluwarsa', 'TidakPasti', 'Gagal'].includes(terbaru.Keadaan) && !lihatPilihan;

    if (belumSelesai && terbaru !== null) {
        const keadaan = terbaru.Keadaan as 'Kedaluwarsa' | 'TidakPasti' | 'Gagal';

        return (
            <Card role="region" aria-label="Pembayaran tagihan" className="rounded-panel px-4 py-6 shadow-none">
                <HasilPembayaran keadaan={keadaan} kodeReferensi={terbaru.KodeReferensi}>
                    <Tombol onClick={() => AturLihatPilihan(true)}>Pilih cara bayar</Tombol>
                    {keadaan === 'TidakPasti' ? (
                        <Tombol
                            varian="sekunder"
                            memproses={memeriksa}
                            onClick={() => {
                                AturMemeriksa(true);
                                // Menanyakan status ke server (yang bertanya ke DOKU), lalu memuat ulang apa pun hasilnya.
                                void AmbilJson(`/kelola/langganan/tagihan/${tagihan.Uuid}/status-bayar`)
                                    .catch(() => undefined)
                                    .finally(() => router.reload({ onFinish: () => AturMemeriksa(false) }));
                            }}
                        >
                            Cek lagi
                        </Tombol>
                    ) : null}
                </HasilPembayaran>
            </Card>
        );
    }

    return (
        <PanelBayarSnap
            uuidTagihan={tagihan.Uuid}
            nomorTagihan={tagihan.Nomor}
            total={tagihan.Total}
            pilihan={pilihan}
            bolehCheckout={bolehCheckout}
            namaPenyedia={namaPenyedia}
        />
    );
}
