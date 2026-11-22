import { router } from '@inertiajs/react';
import {
    ChevronDownIcon,
    ClockIcon,
    CreditCardIcon,
    ExternalLinkIcon,
    LandmarkIcon,
    LockIcon,
    QrCodeIcon,
    WalletIcon,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import Tombol from '@/Komponen/Formulir/Tombol';
import HasilPembayaran from '@/Komponen/Langganan/Bayar/HasilPembayaran';
import LogoBayar from '@/Komponen/Langganan/LogoBayar';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/Komponen/Ui/accordion';
import { Card } from '@/Komponen/Ui/card';
import { cn } from '@/Komponen/Ui/utils';
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
    /** D-305: halaman bayar milik penyedia (kartu, QRIS, e-wallet) tersedia sebagai pilihan terakhir. */
    bolehCheckout?: boolean;
    /** Nama penyedia dari server untuk teks layar. Mengganti penyedia tidak menyentuh teks halaman ini. */
    namaPenyedia?: string;
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

const NamaBank = (baris: PilihanBayar) => baris.Label.replace(/^Virtual account\s+/i, '');

/** Bank yang paling sering dipakai, ditampilkan langsung. Bank lain ada di "Bank lain". */
const bankUtama = ['BCA', 'BRI', 'MANDIRI', 'BNI'];

/** Memisahkan bank populer (urutannya tetap) dari sisanya. */
export function PisahBank(bank: PilihanBayar[]): { utama: PilihanBayar[]; lain: PilihanBayar[] } {
    const utama = bankUtama.flatMap((nama) =>
        bank.filter((baris) => new RegExp(`\\b${nama}\\b`).test(NamaBank(baris).toUpperCase())),
    );

    return { utama, lain: bank.filter((baris) => !utama.includes(baris)) };
}

type KunciOpsi = 'transfer' | 'qris' | 'dompet' | 'doku';

type Opsi = {
    kunci: KunciOpsi;
    judul: string;
    keterangan: string;
    ikon: LucideIcon;
    /** Lencana di samping judul (hanya opsi pertama). */
    lencana?: string;
    keluarSitus?: boolean;
};

/**
 * D-305: layar pilih cara bayar tagihan langganan. Dua jalur nyata: transfer bank (halaman bayar Payoung lewat DOKU
 * SNAP) dan DOKU Checkout (QRIS, e-wallet, kartu di halaman DOKU). QRIS dan dompet digital SNAP hanya muncul bila
 * server menawarkannya, jadi layar tidak menjanjikan cara bayar yang belum aktif. Satu pilihan terpilih sejak awal
 * dan satu tombol utama yang labelnya mengikuti pilihan, tertempel di bawah layar HP.
 */
export default function PanelBayarSnap({
    uuidTagihan,
    nomorTagihan,
    total,
    pilihan,
    awal = null,
    jedaPolling = 6000,
    bolehCheckout = false,
    namaPenyedia = 'penyedia pembayaran',
}: PropsPanelBayarSnap) {
    const [aktif, AturAktif] = useState<TampilanBayar | null>(awal);
    const [memproses, AturMemproses] = useState<string | null>(null);
    const [galat, AturGalat] = useState<string | null>(null);

    const qris = pilihan.filter((baris) => baris.Metode === 'Qris');
    const dompet = pilihan.filter((baris) => baris.Metode === 'Dana' || baris.Metode === 'ShopeePay');
    const bank = pilihan.filter((baris) => baris.Metode === 'VirtualAccount');
    const { utama: bankPopuler, lain: bankLain } = PisahBank(bank);

    const opsi: Opsi[] = [];

    if (bank.length > 0) {
        opsi.push({
            kunci: 'transfer',
            judul: 'Transfer bank',
            keterangan:
                'Dapat nomor rekening khusus, bayar lewat m-banking, ATM, atau internet banking. Dicek otomatis.',
            ikon: LandmarkIcon,
        });
    }

    if (qris.length > 0) {
        opsi.unshift({
            kunci: 'qris',
            judul: 'QRIS',
            keterangan: 'Pindai dengan aplikasi bank atau e-wallet apa pun. Langsung terkonfirmasi.',
            ikon: QrCodeIcon,
        });
    }

    if (dompet.length > 0) {
        opsi.push({
            kunci: 'dompet',
            judul: 'Dompet digital',
            keterangan: 'Selesaikan di aplikasi dompet digital Anda.',
            ikon: WalletIcon,
        });
    }

    if (bolehCheckout) {
        const adaQrisSendiri = qris.length > 0 || dompet.length > 0;
        opsi.push({
            kunci: 'doku',
            judul: adaQrisSendiri ? 'Kartu kredit atau debit' : 'QRIS, e-wallet, atau kartu',
            keterangan: adaQrisSendiri
                ? `Anda pindah sebentar ke halaman pembayaran ${namaPenyedia}, lalu kembali ke sini.`
                : `GoPay, OVO, DANA, ShopeePay, QRIS, kartu kredit atau debit. Anda pindah sebentar ke halaman pembayaran ${namaPenyedia}, lalu kembali ke sini.`,
            ikon: adaQrisSendiri ? CreditCardIcon : QrCodeIcon,
            keluarSitus: true,
        });
    }

    const pertama = opsi[0];

    if (pertama !== undefined && (pertama.kunci === 'qris' || pertama.kunci === 'transfer')) {
        pertama.lencana = pertama.kunci === 'qris' ? 'Tercepat' : 'Paling mudah';
    }

    const [kunciAktif, AturKunciAktif] = useState<KunciOpsi | null>(null);
    const [bankDipilih, AturBankDipilih] = useState<string | null>(null);
    const [dompetDipilih, AturDompetDipilih] = useState<string | null>(null);
    const [lainTerbuka, AturLainTerbuka] = useState(false);

    // Pembayaran DOKU Checkout membuka halaman lain: tombol kembali peramban bisa memulihkan halaman ini dari cache
    // dalam keadaan "memproses".
    useEffect(() => {
        const Pulihkan = (kejadian: PageTransitionEvent) => {
            if (kejadian.persisted) {
                AturMemproses(null);
            }
        };
        window.addEventListener('pageshow', Pulihkan);

        return () => window.removeEventListener('pageshow', Pulihkan);
    }, []);

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

    const LanjutKeDoku = async () => {
        AturGalat(null);
        AturMemproses('doku');

        try {
            const hasil = await KirimJson<{ UrlBayar: string }>(
                `/kelola/langganan/tagihan/${uuidTagihan}/bayar-online`,
            );

            // Dibiarkan "memproses" sampai halaman berganti, supaya tidak terklik dua kali.
            window.location.assign(hasil.UrlBayar);
        } catch (kegagalan) {
            AturGalat(kegagalan instanceof Error ? kegagalan.message : 'Pembayaran online tidak bisa dimulai.');
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
                saatGanti={() => AturAktif(null)}
            />
        );
    }

    const kunciSaatIni: KunciOpsi | null = kunciAktif ?? opsi[0]?.kunci ?? null;
    const bankTerpilih =
        bank.find((baris) => KunciPilihan(baris) === bankDipilih) ?? bankPopuler[0] ?? bankLain[0] ?? null;
    const dompetTerpilih = dompet.find((baris) => KunciPilihan(baris) === dompetDipilih) ?? dompet[0] ?? null;

    const HitungTujuan = (): { label: string; jalankan: () => void } | null => {
        switch (kunciSaatIni) {
            case 'transfer':
                return bankTerpilih
                    ? { label: `Lanjut dengan ${NamaBank(bankTerpilih)}`, jalankan: () => void Pilih(bankTerpilih) }
                    : null;
            case 'qris':
                return qris[0]
                    ? { label: 'Tampilkan kode QRIS', jalankan: () => void Pilih(qris[0] as PilihanBayar) }
                    : null;
            case 'dompet':
                return dompetTerpilih
                    ? { label: `Lanjut dengan ${dompetTerpilih.Label}`, jalankan: () => void Pilih(dompetTerpilih) }
                    : null;
            case 'doku':
                return { label: `Lanjut ke halaman ${namaPenyedia}`, jalankan: () => void LanjutKeDoku() };
            default:
                return null;
        }
    };

    const tujuan = HitungTujuan();

    return (
        <>
            <Kerangka nomorTagihan={nomorTagihan} total={total}>
                {galat ? (
                    <Pemberitahuan jenis="bahaya" judul="Pembayaran gagal dimulai">
                        {galat}
                    </Pemberitahuan>
                ) : null}
                <div className="flex flex-col gap-1">
                    <h3 className="text-subjudul font-semibold text-teks-utama">Pilih cara bayar</h3>
                    <p className="text-isi text-teks-sekunder">
                        Kode bayar berlaku 60 menit dan tagihan lunas otomatis begitu pembayaran masuk.
                    </p>
                </div>
                <div
                    role="radiogroup"
                    aria-label="Cara bayar"
                    className="flex flex-col divide-y divide-garis overflow-hidden rounded-panel border border-garis"
                >
                    {opsi.map((baris) => {
                        const terpilih = baris.kunci === kunciSaatIni;
                        const Ikon = baris.ikon;

                        return (
                            <div
                                key={baris.kunci}
                                className={cn(
                                    terpilih
                                        ? 'bg-brand-lembut shadow-[inset_3px_0_0_var(--color-brand)]'
                                        : 'bg-permukaan',
                                )}
                            >
                                <button
                                    type="button"
                                    role="radio"
                                    aria-checked={terpilih}
                                    disabled={memproses !== null}
                                    className="flex w-full cursor-pointer items-start gap-3 px-4 py-3 text-left focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none focus-visible:ring-inset disabled:cursor-not-allowed disabled:opacity-60 pointer-coarse:py-4"
                                    onClick={() => AturKunciAktif(baris.kunci)}
                                >
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'mt-2 grid size-5 shrink-0 place-items-center rounded-full border-2 bg-permukaan',
                                            terpilih ? 'border-brand' : 'border-garis-input',
                                        )}
                                    >
                                        {terpilih ? <span className="size-2.5 rounded-full bg-brand" /> : null}
                                    </span>
                                    <span
                                        aria-hidden="true"
                                        className="grid size-10 shrink-0 place-items-center rounded-panel bg-brand-lembut text-brand"
                                    >
                                        <Ikon className="size-5" />
                                    </span>
                                    <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                        <span className="flex flex-wrap items-center gap-2 text-subjudul font-semibold text-teks-utama">
                                            {baris.judul}
                                            {baris.lencana ? (
                                                <span className="rounded-full bg-aksen px-2 text-keterangan font-semibold text-brand-gelap">
                                                    {baris.lencana}
                                                </span>
                                            ) : null}
                                            {baris.keluarSitus ? (
                                                <span className="inline-flex items-center gap-1 rounded-full bg-permukaan-sorot px-2 text-keterangan font-semibold text-teks-sekunder">
                                                    <ExternalLinkIcon aria-hidden="true" className="size-3" />
                                                    Halaman {namaPenyedia}
                                                </span>
                                            ) : null}
                                        </span>
                                        <span className="text-isi text-teks-sekunder">{baris.keterangan}</span>
                                    </span>
                                </button>
                                {terpilih && baris.kunci === 'transfer' ? (
                                    <div className="flex flex-col gap-2 px-4 pt-0 pb-4 sm:pl-[4.75rem]">
                                        <span className="text-keterangan text-teks-sekunder">Pilih bank Anda</span>
                                        <div
                                            role="radiogroup"
                                            aria-label="Bank"
                                            className="grid grid-cols-2 gap-2 sm:grid-cols-5"
                                        >
                                            {bankPopuler.map((bankIni) => (
                                                <UbinBank
                                                    key={KunciPilihan(bankIni)}
                                                    baris={bankIni}
                                                    terpilih={
                                                        bankTerpilih !== null &&
                                                        KunciPilihan(bankIni) === KunciPilihan(bankTerpilih)
                                                    }
                                                    dikunci={memproses !== null}
                                                    saatPilih={() => AturBankDipilih(KunciPilihan(bankIni))}
                                                />
                                            ))}
                                            {bankLain.length > 0 ? (
                                                <button
                                                    type="button"
                                                    aria-expanded={lainTerbuka}
                                                    className="inline-flex min-h-11 cursor-pointer items-center justify-center gap-1 rounded-panel border border-garis bg-permukaan px-2 text-label font-semibold text-brand hover:bg-brand-lembut focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none"
                                                    onClick={() => AturLainTerbuka((buka) => !buka)}
                                                >
                                                    Bank lain ({bankLain.length})
                                                    <ChevronDownIcon
                                                        aria-hidden="true"
                                                        className={cn(
                                                            'size-4 transition-transform',
                                                            lainTerbuka && 'rotate-180',
                                                        )}
                                                    />
                                                </button>
                                            ) : null}
                                        </div>
                                        {lainTerbuka ? (
                                            <div
                                                role="radiogroup"
                                                aria-label="Bank lain"
                                                className="grid grid-cols-2 gap-2 sm:grid-cols-5"
                                            >
                                                {bankLain.map((bankIni) => (
                                                    <UbinBank
                                                        key={KunciPilihan(bankIni)}
                                                        baris={bankIni}
                                                        terpilih={
                                                            bankTerpilih !== null &&
                                                            KunciPilihan(bankIni) === KunciPilihan(bankTerpilih)
                                                        }
                                                        dikunci={memproses !== null}
                                                        saatPilih={() => AturBankDipilih(KunciPilihan(bankIni))}
                                                    />
                                                ))}
                                            </div>
                                        ) : null}
                                        <span className="text-keterangan text-teks-sekunder">
                                            Bingung memilih? Pilih bank yang aplikasinya ada di HP Anda.
                                        </span>
                                    </div>
                                ) : null}
                                {terpilih && baris.kunci === 'dompet' ? (
                                    <div
                                        role="radiogroup"
                                        aria-label="Dompet digital"
                                        className="grid grid-cols-2 gap-2 px-4 pb-4 sm:grid-cols-5 sm:pl-[4.75rem]"
                                    >
                                        {dompet.map((dompetIni) => (
                                            <UbinBank
                                                key={KunciPilihan(dompetIni)}
                                                baris={dompetIni}
                                                terpilih={
                                                    dompetTerpilih !== null &&
                                                    KunciPilihan(dompetIni) === KunciPilihan(dompetTerpilih)
                                                }
                                                dikunci={memproses !== null}
                                                saatPilih={() => AturDompetDipilih(KunciPilihan(dompetIni))}
                                            />
                                        ))}
                                    </div>
                                ) : null}
                            </div>
                        );
                    })}
                </div>
                <p className="flex items-center gap-1 text-keterangan text-teks-sekunder">
                    <LockIcon aria-hidden="true" className="size-3.5" />
                    Pembayaran diproses {namaPenyedia} dan terenkripsi.
                </p>
            </Kerangka>
            <BilahAksiForm>
                <Tombol
                    ukuran="besar"
                    memproses={memproses !== null}
                    disabled={tujuan === null}
                    onClick={() => tujuan?.jalankan()}
                >
                    {tujuan?.label ?? 'Pilih cara bayar'}
                </Tombol>
            </BilahAksiForm>
        </>
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

function UbinBank({
    baris,
    terpilih,
    dikunci,
    saatPilih,
}: {
    baris: PilihanBayar;
    terpilih: boolean;
    dikunci: boolean;
    saatPilih: () => void;
}) {
    const nama = NamaBank(baris);

    return (
        <button
            type="button"
            role="radio"
            aria-checked={terpilih}
            aria-label={nama}
            disabled={dikunci}
            className={cn(
                'flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-panel border bg-permukaan px-2 py-2 text-label font-semibold text-teks-utama transition-colors hover:bg-brand-lembut focus-visible:ring-2 focus-visible:ring-brand focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60',
                terpilih ? 'border-2 border-brand' : 'border-garis',
            )}
            onClick={saatPilih}
        >
            <LogoBayar metode={baris.Metode} teks={baris.Kanal ?? baris.Label} className="h-6 w-10" />
            <span className="truncate">{nama}</span>
        </button>
    );
}

type PropsLayarBayar = {
    tampilan: TampilanBayar;
    uuidTagihan: string;
    nomorTagihan: string;
    jedaPolling: number;
    /** Kembali ke pilihan cara bayar (dipakai setelah batas bayar lewat). */
    saatGanti: () => void;
};

function LayarBayar({ tampilan, uuidTagihan, nomorTagihan, jedaPolling, saatGanti }: PropsLayarBayar) {
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
                            saatSalin={() => void Salin('jumlah', tampilan.Jumlah.replace(/\.\d+$/, ''))}
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

            {sisa !== null ? (
                <div role="status" className="flex items-center gap-3 rounded-panel bg-brand-lembut px-3 py-3">
                    <span className="denyut-bayar" aria-hidden="true">
                        <span />
                        <span />
                        <span />
                    </span>
                    <div className="flex flex-col">
                        <span className="text-label font-semibold text-teks-utama">Menunggu pembayaran</span>
                        <span className="text-keterangan text-teks-sekunder">
                            Dicek otomatis, boleh ditinggal. Halaman ini berganti sendiri begitu pembayaran
                            terkonfirmasi.
                        </span>
                    </div>
                </div>
            ) : (
                <HasilPembayaran keadaan="Kedaluwarsa">
                    <Tombol onClick={saatGanti}>Pilih cara bayar</Tombol>
                </HasilPembayaran>
            )}
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
