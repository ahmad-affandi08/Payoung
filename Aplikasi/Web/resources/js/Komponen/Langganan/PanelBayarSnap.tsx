import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { AmbilJson, KirimJson } from '@/Pustaka/PermintaanJson';
import type { PembayaranLangganan, PilihanBayar, TampilanBayar } from '@/Tipe/TagihanLangganan';

type PropsPanelBayarSnap = {
    uuidTagihan: string;
    pilihan: PilihanBayar[];
    /** Pembayaran SNAP yang masih menunggu dan berlaku (dari server); bila ada, langsung ditampilkan. */
    awal?: TampilanBayar | null;
    /** Jeda pertanyaan status ke server (ms). */
    jedaPolling?: number;
};

/** Sisa waktu "mm:ss" menuju batas bayar; null bila batasnya tidak ada atau sudah lewat. */
export function FormatSisaWaktu(sampai: string | null, sekarang: number): string | null {
    if (sampai === null) {
        return null;
    }

    const selisih = Math.floor((new Date(sampai).getTime() - sekarang) / 1000);

    if (Number.isNaN(selisih) || selisih <= 0) {
        return null;
    }

    const menit = Math.floor(selisih / 60);

    return `${String(menit).padStart(2, '0')}:${String(selisih % 60).padStart(2, '0')}`;
}

/**
 * D-159: halaman bayar milik Payoung untuk tagihan langganan, dengan DOKU SNAP di belakangnya. Tenant memilih QRIS,
 * virtual account (per bank), atau e-wallet; QR, nomor VA, atau tombol lanjut ke aplikasi dompet tampil di sini, bukan di
 * halaman DOKU.
 *
 * Lunasnya tagihan **tidak pernah** diputuskan di peramban: halaman hanya menanyakan status ke server tiap beberapa
 * detik, dan server menanyakannya ke DOKU. Saat server menjawab lunas (atau pembayaran sudah tak menunggu), halaman
 * dimuat ulang menurut keadaan server.
 */
export default function PanelBayarSnap({ uuidTagihan, pilihan, awal = null, jedaPolling = 6000 }: PropsPanelBayarSnap) {
    const [aktif, AturAktif] = useState<TampilanBayar | null>(awal);
    const [memproses, AturMemproses] = useState<string | null>(null);
    const [galat, AturGalat] = useState<string | null>(null);

    const Pilih = async (baris: PilihanBayar) => {
        AturGalat(null);
        AturMemproses(`${baris.Metode}|${baris.Kanal ?? ''}`);

        try {
            const hasil = await KirimJson<{ Pembayaran: PembayaranLangganan }>(
                `/kelola/langganan/tagihan/${uuidTagihan}/bayar-online`,
                { Metode: baris.Metode, Kanal: baris.Kanal },
            );

            if (hasil.Pembayaran.TampilanBayar) {
                AturAktif(hasil.Pembayaran.TampilanBayar);
            } else {
                router.reload();
            }
        } catch (kegagalan) {
            AturGalat(kegagalan instanceof Error ? kegagalan.message : 'Pembayaran tidak bisa dimulai.');
        } finally {
            AturMemproses(null);
        }
    };

    if (aktif !== null) {
        return <LayarBayar tampilan={aktif} uuidTagihan={uuidTagihan} jedaPolling={jedaPolling} />;
    }

    const bank = pilihan.filter((baris) => baris.Metode === 'VirtualAccount');
    const lain = pilihan.filter((baris) => baris.Metode !== 'VirtualAccount');

    return (
        <Panel
            judul="Pilih cara bayar"
            keterangan="Kode bayar berlaku 60 menit. Tagihan lunas otomatis begitu pembayaran masuk."
        >
            {galat ? (
                <Pemberitahuan jenis="bahaya" judul="Pembayaran gagal dimulai">
                    {galat}
                </Pemberitahuan>
            ) : null}
            <div className="grid gap-2 sm:grid-cols-2">
                {lain.map((baris) => (
                    <Tombol
                        key={`${baris.Metode}|${baris.Kanal ?? ''}`}
                        varian="sekunder"
                        memproses={memproses === `${baris.Metode}|${baris.Kanal ?? ''}`}
                        disabled={memproses !== null}
                        onClick={() => void Pilih(baris)}
                    >
                        {baris.Label}
                    </Tombol>
                ))}
            </div>
            {bank.length > 0 ? (
                <div className="flex flex-col gap-2">
                    <h3 className="text-label font-semibold text-teks-utama">Virtual account</h3>
                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        {bank.map((baris) => (
                            <Tombol
                                key={`${baris.Metode}|${baris.Kanal ?? ''}`}
                                varian="sekunder"
                                memproses={memproses === `${baris.Metode}|${baris.Kanal ?? ''}`}
                                disabled={memproses !== null}
                                onClick={() => void Pilih(baris)}
                            >
                                {baris.Label.replace(/^Virtual account /, '')}
                            </Tombol>
                        ))}
                    </div>
                </div>
            ) : null}
        </Panel>
    );
}

type PropsLayarBayar = { tampilan: TampilanBayar; uuidTagihan: string; jedaPolling: number };

function LayarBayar({ tampilan, uuidTagihan, jedaPolling }: PropsLayarBayar) {
    const [sekarang, AturSekarang] = useState(() => Date.now());
    const [tersalin, AturTersalin] = useState(false);

    useEffect(() => {
        const detik = window.setInterval(() => AturSekarang(Date.now()), 1000);

        return () => window.clearInterval(detik);
    }, []);

    useEffect(() => {
        let berhenti = false;
        const Tanya = async () => {
            try {
                const hasil = await AmbilJson<{ Lunas: boolean; MasihMenunggu: boolean }>(
                    `/kelola/langganan/tagihan/${uuidTagihan}/status-bayar`,
                );

                if (!berhenti && (hasil.Lunas || !hasil.MasihMenunggu)) {
                    router.reload();
                }
            } catch {
                // Gagal sesaat (jaringan): dicoba lagi pada putaran berikutnya.
            }
        };
        const putaran = window.setInterval(() => void Tanya(), jedaPolling);

        return () => {
            berhenti = true;
            window.clearInterval(putaran);
        };
    }, [uuidTagihan, jedaPolling]);

    const sisa = FormatSisaWaktu(tampilan.KedaluwarsaPada, sekarang);

    const Salin = async (teks: string) => {
        try {
            await navigator.clipboard.writeText(teks);
            AturTersalin(true);
        } catch {
            AturTersalin(false);
        }
    };

    return (
        <Panel judul={`Bayar dengan ${tampilan.Label}`} keterangan={`Total ${FormatRupiah(tampilan.Jumlah)}`}>
            {tampilan.Tampilan === 'Qr' && tampilan.QrSvg !== null ? (
                <div className="flex flex-col items-center gap-3">
                    <img
                        src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(tampilan.QrSvg)}`}
                        alt="Kode QRIS tagihan langganan"
                        className="size-64 rounded-panel border border-garis bg-permukaan p-2"
                    />
                    <p className="text-isi text-teks-sekunder">
                        Buka aplikasi bank atau dompet digital Anda, pilih Bayar QRIS, lalu pindai kode ini.
                    </p>
                </div>
            ) : null}
            {tampilan.Tampilan === 'NomorVa' && tampilan.NomorVa !== null ? (
                <div className="flex flex-col gap-2">
                    <p className="text-isi text-teks-sekunder">
                        Transfer ke nomor virtual account {tampilan.NamaBank ?? ''} berikut lewat ATM, m-banking, atau
                        internet banking.
                    </p>
                    <p className="font-mono text-judul font-semibold tabular-nums text-teks-utama">
                        {tampilan.NomorVa}
                    </p>
                    <div>
                        <Tombol varian="sekunder" onClick={() => void Salin(tampilan.NomorVa ?? '')}>
                            {tersalin ? 'Nomor tersalin' : 'Salin nomor'}
                        </Tombol>
                    </div>
                </div>
            ) : null}
            {tampilan.Tampilan === 'Alihkan' && tampilan.UrlAlih !== null ? (
                <div className="flex flex-col gap-2">
                    <p className="text-isi text-teks-sekunder">
                        Lanjutkan di aplikasi {tampilan.Label} untuk menyelesaikan pembayaran. Setelah selesai, kembali
                        ke halaman ini.
                    </p>
                    <div>
                        <Tombol onClick={() => window.location.assign(tampilan.UrlAlih ?? '')}>
                            Lanjut ke {tampilan.Label}
                        </Tombol>
                    </div>
                </div>
            ) : null}
            <p className="text-keterangan text-teks-sekunder" role="status">
                {sisa !== null
                    ? `Berlaku ${sisa} lagi. Halaman ini otomatis berganti begitu pembayaran terkonfirmasi.`
                    : 'Batas bayar sudah lewat. Muat ulang halaman untuk memilih cara bayar lain.'}
            </p>
        </Panel>
    );
}
