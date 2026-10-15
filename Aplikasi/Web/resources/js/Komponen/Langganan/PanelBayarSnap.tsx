import { router } from '@inertiajs/react';
import { ChevronRightIcon, ClockIcon } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import LogoBayar from '@/Komponen/Langganan/LogoBayar';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/Komponen/Ui/accordion';
import { Card } from '@/Komponen/Ui/card';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { AmbilPetunjukBayar } from '@/Pustaka/PetunjukBayar';
import { AmbilJson, KirimJson } from '@/Pustaka/PermintaanJson';
import { NamaBerkasQr, UnduhGambarQr } from '@/Pustaka/UnduhQr';
import type { PembayaranLangganan, PilihanBayar, TampilanBayar } from '@/Tipe/TagihanLangganan';

type PropsPanelBayarSnap = {
    uuidTagihan: string;
    nomorTagihan: string;
    /** Total tagihan (string desimal). */
    total: string;
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

const KunciPilihan = (baris: PilihanBayar) => `${baris.Metode}|${baris.Kanal ?? ''}`;

/**
 * D-159: halaman bayar milik Payoung untuk tagihan langganan, dengan DOKU SNAP di belakangnya. Tenant memilih QRIS,
 * dompet digital, atau virtual account (per bank); QR, nomor VA, atau tombol ke aplikasi dompet tampil di sini, bukan di
 * halaman DOKU. Susunannya mengikuti halaman bayar yang sudah dikenal pembeli: ringkasan total di atas, pilihan berlogo,
 * lalu layar bayar dengan hitung mundur dan petunjuk langkah.
 *
 * Lunasnya tagihan **tidak pernah** diputuskan di peramban: halaman hanya menanyakan status ke server (tiap beberapa detik
 * dan saat "Cek status pembayaran" ditekan), dan server menanyakannya ke DOKU. Saat server menjawab lunas (atau pembayaran
 * sudah tak menunggu), halaman dimuat ulang menurut keadaan server.
 */
export default function PanelBayarSnap({
    uuidTagihan,
    nomorTagihan,
    total,
    pilihan,
    awal = null,
    jedaPolling = 6000,
}: PropsPanelBayarSnap) {
    const [aktif, AturAktif] = useState<TampilanBayar | null>(awal);
    const [memproses, AturMemproses] = useState<string | null>(null);
    const [galat, AturGalat] = useState<string | null>(null);

    const Pilih = async (baris: PilihanBayar) => {
        AturGalat(null);
        AturMemproses(KunciPilihan(baris));

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
        return (
            <LayarBayar
                tampilan={aktif}
                uuidTagihan={uuidTagihan}
                nomorTagihan={nomorTagihan}
                jedaPolling={jedaPolling}
            />
        );
    }

    const qris = pilihan.filter((baris) => baris.Metode === 'Qris');
    const dompet = pilihan.filter((baris) => baris.Metode === 'Dana' || baris.Metode === 'ShopeePay');
    const bank = pilihan.filter((baris) => baris.Metode === 'VirtualAccount');

    return (
        <Kerangka nomorTagihan={nomorTagihan} total={total}>
            {galat ? (
                <Pemberitahuan jenis="bahaya" judul="Pembayaran gagal dimulai">
                    {galat}
                </Pemberitahuan>
            ) : null}
            <p className="text-isi text-teks-sekunder">
                Pilih cara bayar. Kode bayar berlaku 60 menit dan tagihan lunas otomatis begitu pembayaran masuk.
            </p>
            {qris.length > 0 ? (
                <Bagian judul="QRIS">
                    {qris.map((baris) => (
                        <BarisPilihan
                            key={KunciPilihan(baris)}
                            baris={baris}
                            keterangan="Bayar dari GoPay, OVO, DANA, ShopeePay, atau m-banking"
                            memproses={memproses === KunciPilihan(baris)}
                            dikunci={memproses !== null}
                            saatPilih={() => void Pilih(baris)}
                        />
                    ))}
                </Bagian>
            ) : null}
            {dompet.length > 0 ? (
                <Bagian judul="Dompet digital">
                    {dompet.map((baris) => (
                        <BarisPilihan
                            key={KunciPilihan(baris)}
                            baris={baris}
                            keterangan="Selesaikan di aplikasi dompet digital"
                            memproses={memproses === KunciPilihan(baris)}
                            dikunci={memproses !== null}
                            saatPilih={() => void Pilih(baris)}
                        />
                    ))}
                </Bagian>
            ) : null}
            {bank.length > 0 ? (
                <Bagian judul="Virtual account">
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                        {bank.map((baris) => (
                            <UbinBank
                                key={KunciPilihan(baris)}
                                baris={baris}
                                memproses={memproses === KunciPilihan(baris)}
                                dikunci={memproses !== null}
                                saatPilih={() => void Pilih(baris)}
                            />
                        ))}
                    </div>
                </Bagian>
            ) : null}
        </Kerangka>
    );
}

function Kerangka({
    nomorTagihan,
    total,
    kanan,
    children,
}: {
    nomorTagihan: string;
    total: string;
    kanan?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Card
            role="region"
            className="gap-0 overflow-hidden rounded-panel p-0 shadow-none"
            aria-label="Pembayaran tagihan"
        >
            <div className="flex flex-wrap items-center justify-between gap-3 bg-brand-gelap px-4 py-4 text-brand-gelap-teks">
                <div className="flex flex-col">
                    <span className="text-keterangan">Total pembayaran</span>
                    <span className="text-judul font-semibold tabular-nums text-brand-teks">{FormatRupiah(total)}</span>
                </div>
                <div className="flex flex-col items-start gap-1 sm:items-end">
                    <span className="text-keterangan">Tagihan {nomorTagihan}</span>
                    {kanan}
                </div>
            </div>
            <div className="flex flex-col gap-4 px-4 py-4">{children}</div>
        </Card>
    );
}

function Bagian({ judul, children }: { judul: string; children: ReactNode }) {
    return (
        <section className="flex flex-col gap-2">
            <h3 className="text-label font-semibold text-teks-utama">{judul}</h3>
            {children}
        </section>
    );
}

const kelasPilihan =
    'cursor-pointer rounded-panel border border-garis bg-permukaan text-left transition-colors hover:border-brand hover:bg-brand-lembut focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60';

function BarisPilihan({
    baris,
    keterangan,
    memproses,
    dikunci,
    saatPilih,
}: {
    baris: PilihanBayar;
    keterangan: string;
    memproses: boolean;
    dikunci: boolean;
    saatPilih: () => void;
}) {
    return (
        <button
            type="button"
            className={`${kelasPilihan} flex min-h-14 items-center gap-3 px-3 py-2 pointer-coarse:min-h-16`}
            disabled={dikunci}
            aria-busy={memproses || undefined}
            onClick={saatPilih}
        >
            <LogoBayar metode={baris.Metode} teks={baris.Kanal ?? baris.Label} />
            <span className="flex min-w-0 flex-1 flex-col">
                <span className="text-label font-semibold text-teks-utama">{baris.Label}</span>
                <span className="text-keterangan text-teks-sekunder">{memproses ? 'Memproses…' : keterangan}</span>
            </span>
            <ChevronRightIcon aria-hidden="true" className="size-4 shrink-0 text-teks-sekunder" />
        </button>
    );
}

function UbinBank({
    baris,
    memproses,
    dikunci,
    saatPilih,
}: {
    baris: PilihanBayar;
    memproses: boolean;
    dikunci: boolean;
    saatPilih: () => void;
}) {
    const nama = baris.Label.replace(/^Virtual account\s+/i, '');

    return (
        <button
            type="button"
            className={`${kelasPilihan} flex min-h-24 flex-col items-center justify-center gap-1 px-2 py-3 text-center pointer-coarse:min-h-28`}
            disabled={dikunci}
            aria-busy={memproses || undefined}
            aria-label={`Virtual account ${nama}`}
            onClick={saatPilih}
        >
            <LogoBayar metode={baris.Metode} teks={baris.Kanal ?? baris.Label} className="h-12 w-20" />
            <span className="text-label font-semibold text-teks-utama">{memproses ? 'Memproses…' : nama}</span>
        </button>
    );
}

type PropsLayarBayar = { tampilan: TampilanBayar; uuidTagihan: string; nomorTagihan: string; jedaPolling: number };

function LayarBayar({ tampilan, uuidTagihan, nomorTagihan, jedaPolling }: PropsLayarBayar) {
    const [sekarang, AturSekarang] = useState(() => Date.now());
    const [tersalin, AturTersalin] = useState<string | null>(null);
    const [memeriksa, AturMemeriksa] = useState(false);
    const [mengunduh, AturMengunduh] = useState(false);
    const [kabar, AturKabar] = useState<{ jenis: 'info' | 'bahaya'; teks: string } | null>(null);

    useEffect(() => {
        const detik = window.setInterval(() => AturSekarang(Date.now()), 1000);

        return () => window.clearInterval(detik);
    }, []);

    const CekStatus = async (diam: boolean): Promise<void> => {
        try {
            const hasil = await AmbilJson<{ Lunas: boolean; MasihMenunggu: boolean }>(
                `/kelola/langganan/tagihan/${uuidTagihan}/status-bayar`,
            );

            if (hasil.Lunas || !hasil.MasihMenunggu) {
                router.reload();

                return;
            }

            if (!diam) {
                AturKabar({ jenis: 'info', teks: 'Pembayaran belum kami terima. Coba cek lagi sebentar lagi.' });
            }
        } catch {
            if (!diam) {
                AturKabar({ jenis: 'bahaya', teks: 'Status belum bisa diperiksa. Periksa koneksi lalu coba lagi.' });
            }
        }
    };

    useEffect(() => {
        const putaran = window.setInterval(() => void CekStatus(true), jedaPolling);

        return () => window.clearInterval(putaran);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- CekStatus hanya bergantung pada uuidTagihan.
    }, [uuidTagihan, jedaPolling]);

    const Periksa = async () => {
        AturKabar(null);
        AturMemeriksa(true);
        await CekStatus(false);
        AturMemeriksa(false);
    };

    const Salin = async (kunci: string, teks: string) => {
        try {
            await navigator.clipboard.writeText(teks);
            AturTersalin(kunci);
            window.setTimeout(() => AturTersalin((lama) => (lama === kunci ? null : lama)), 2500);
        } catch {
            AturTersalin(null);
        }
    };

    const Unduh = async () => {
        if (tampilan.QrSvg === null) {
            return;
        }

        AturKabar(null);
        AturMengunduh(true);

        try {
            await UnduhGambarQr(tampilan.QrSvg, NamaBerkasQr(nomorTagihan));
        } catch (kegagalan) {
            AturKabar({ jenis: 'bahaya', teks: kegagalan instanceof Error ? kegagalan.message : 'QR gagal diunduh.' });
        } finally {
            AturMengunduh(false);
        }
    };

    const sisa = FormatSisaWaktu(tampilan.KedaluwarsaPada, sekarang);
    const petunjuk = AmbilPetunjukBayar(tampilan.Metode, tampilan.Label);

    return (
        <Kerangka
            nomorTagihan={nomorTagihan}
            total={tampilan.Jumlah}
            kanan={
                <span
                    role="timer"
                    className="inline-flex items-center gap-1 rounded-full bg-brand-gelap-sorot px-3 py-1 text-label font-semibold tabular-nums text-brand-teks"
                >
                    <ClockIcon aria-hidden="true" className="size-4" />
                    {sisa !== null ? `Selesaikan dalam ${sisa}` : 'Batas bayar lewat'}
                </span>
            }
        >
            <div className="flex items-center gap-3">
                <LogoBayar metode={tampilan.Metode} teks={tampilan.NamaBank ?? tampilan.Label} />
                <h3 className="text-subjudul font-semibold text-teks-utama">{tampilan.Label}</h3>
            </div>

            {tampilan.Tampilan === 'Qr' && tampilan.QrSvg !== null ? (
                <div className="flex flex-col items-center gap-3 rounded-panel border border-garis bg-permukaan-redup px-4 py-5">
                    <div className="flex flex-col items-center gap-2 rounded-panel border border-garis bg-permukaan p-3">
                        <LogoBayar metode="Qris" teks="QRIS" className="h-8" />
                        <img
                            src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(tampilan.QrSvg)}`}
                            alt="Kode QRIS tagihan langganan"
                            className="size-60 sm:size-64"
                        />
                        <span className="text-label font-semibold tabular-nums text-teks-utama">
                            {FormatRupiah(tampilan.Jumlah)}
                        </span>
                    </div>
                    <div className="flex flex-wrap justify-center gap-2">
                        <Tombol memproses={mengunduh} onClick={() => void Unduh()}>
                            Unduh QR
                        </Tombol>
                        <Tombol varian="sekunder" memproses={memeriksa} onClick={() => void Periksa()}>
                            Cek status pembayaran
                        </Tombol>
                    </div>
                    <p className="text-center text-keterangan text-teks-sekunder">
                        Kamera tidak bisa memindai layar? Unduh QR, lalu pilih dari galeri di aplikasi pembayaran Anda.
                    </p>
                </div>
            ) : null}

            {tampilan.Tampilan === 'NomorVa' && tampilan.NomorVa !== null ? (
                <div className="flex flex-col gap-3">
                    <dl className="flex flex-col divide-y divide-garis rounded-panel border border-garis">
                        <BarisSalin
                            label={`Nomor virtual account ${tampilan.NamaBank ?? ''}`.trim()}
                            nilai={tampilan.NomorVa}
                            tampil={tampilan.NomorVa}
                            besar
                            tersalin={tersalin === 'va'}
                            saatSalin={() => void Salin('va', tampilan.NomorVa ?? '')}
                            namaTombol="Salin nomor"
                        />
                        <BarisSalin
                            label="Jumlah yang harus ditransfer"
                            nilai={tampilan.Jumlah}
                            tampil={FormatRupiah(tampilan.Jumlah)}
                            tersalin={tersalin === 'jumlah'}
                            saatSalin={() => void Salin('jumlah', String(Math.trunc(Number(tampilan.Jumlah))))}
                            namaTombol="Salin jumlah"
                        />
                    </dl>
                    <div>
                        <Tombol varian="sekunder" memproses={memeriksa} onClick={() => void Periksa()}>
                            Cek status pembayaran
                        </Tombol>
                    </div>
                </div>
            ) : null}

            {tampilan.Tampilan === 'Alihkan' && tampilan.UrlAlih !== null ? (
                <div className="flex flex-col gap-3">
                    <div className="flex flex-wrap gap-2">
                        <Tombol onClick={() => window.location.assign(tampilan.UrlAlih ?? '')}>
                            Lanjut ke {tampilan.Label}
                        </Tombol>
                        <Tombol varian="sekunder" memproses={memeriksa} onClick={() => void Periksa()}>
                            Cek status pembayaran
                        </Tombol>
                    </div>
                </div>
            ) : null}

            {kabar ? (
                <Pemberitahuan jenis={kabar.jenis} judul={kabar.jenis === 'info' ? 'Belum terbayar' : 'Gagal'}>
                    {kabar.teks}
                </Pemberitahuan>
            ) : null}

            <section className="flex flex-col gap-1">
                <h3 className="text-label font-semibold text-teks-utama">Cara membayar</h3>
                <Accordion type="single" collapsible defaultValue="petunjuk-0">
                    {petunjuk.map((butir, indeks) => (
                        <AccordionItem key={butir.Judul} value={`petunjuk-${String(indeks)}`}>
                            <AccordionTrigger className="text-label font-semibold text-teks-utama">
                                {butir.Judul}
                            </AccordionTrigger>
                            <AccordionContent>
                                <ol className="ml-5 flex list-decimal flex-col gap-1 text-isi text-teks-sekunder">
                                    {butir.Langkah.map((langkah) => (
                                        <li key={langkah}>{langkah}</li>
                                    ))}
                                </ol>
                            </AccordionContent>
                        </AccordionItem>
                    ))}
                </Accordion>
            </section>

            <p className="text-keterangan text-teks-sekunder" role="status">
                {sisa !== null
                    ? 'Halaman ini berganti otomatis begitu pembayaran terkonfirmasi.'
                    : 'Batas bayar sudah lewat. Muat ulang halaman untuk memilih cara bayar lain.'}
            </p>
        </Kerangka>
    );
}

function BarisSalin({
    label,
    nilai,
    tampil,
    besar = false,
    tersalin,
    saatSalin,
    namaTombol,
}: {
    label: string;
    nilai: string;
    tampil: string;
    besar?: boolean;
    tersalin: boolean;
    saatSalin: () => void;
    namaTombol: string;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-2 px-3 py-3" data-nilai={nilai}>
            <div className="flex min-w-0 flex-col">
                <dt className="text-keterangan text-teks-sekunder">{label}</dt>
                <dd
                    className={
                        besar
                            ? 'font-mono text-judul font-semibold tabular-nums break-all text-teks-utama'
                            : 'text-subjudul font-semibold tabular-nums text-teks-utama'
                    }
                >
                    {tampil}
                </dd>
            </div>
            <Tombol varian="sekunder" onClick={saatSalin}>
                {tersalin ? 'Tersalin' : namaTombol}
            </Tombol>
        </div>
    );
}
