import { CheckIcon, ChevronLeftIcon, ChevronRightIcon, PauseIcon, PlayIcon } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
    useSyncExternalStore,
    type KeyboardEvent,
    type PointerEvent,
} from 'react';

import PanggungSpesimen from '@/Komponen/Situs/PanggungSpesimen';
import { SPESIMEN } from '@/Komponen/Situs/SpesimenSitus';
import TombolSitus from '@/Komponen/Situs/TombolSitus';
import { cn } from '@/Komponen/Ui/utils';
import JudulHalaman from '@/Komponen/Umpan/JudulHalaman';
import type { BagianSitus, SorotanHero } from '@/Tipe/Situs';

import { GambarBagian } from './KepalaBagian';

type Props = { bagian: Extract<BagianSitus, { Jenis: 'HeroGeser' }>; utama: boolean };

/** Selang pergantian otomatis (permintaan pemilik produk: 5 detik). */
export const SELANG_OTOMATIS_MS = 5000;

/** Jarak geser jari minimum (px) supaya dianggap menggeser, bukan menyentuh. */
const AMBANG_GESER_PX = 48;

const KUERI_KURANGI_GERAK = '(prefers-reduced-motion: reduce)';

function LanggananKurangiGerak(saatUbah: () => void): () => void {
    if (typeof window.matchMedia !== 'function') {
        return () => undefined;
    }

    const kueri = window.matchMedia(KUERI_KURANGI_GERAK);
    kueri.addEventListener('change', saatUbah);

    return () => kueri.removeEventListener('change', saatUbah);
}

function AmbilKurangiGerak(): boolean {
    return typeof window.matchMedia === 'function' && window.matchMedia(KUERI_KURANGI_GERAK).matches;
}

/** Pengguna meminta gerakan dikurangi: tidak ada pergantian otomatis dan tidak ada transisi (PRD §17.6.4). */
function useKurangiGerak(): boolean {
    return useSyncExternalStore(LanggananKurangiGerak, AmbilKurangiGerak, () => false);
}

function PanggungVisual({ sorotan, prioritas, dimuat }: { sorotan: SorotanHero; prioritas: boolean; dimuat: boolean }) {
    const Spesimen = !sorotan.Gambar && sorotan.Spesimen ? SPESIMEN[sorotan.Spesimen] : null;

    return (
        <PanggungSpesimen warna="teal" tetap className="p-3 sm:p-6">
            {!dimuat ? null : sorotan.Gambar ? (
                <GambarBagian
                    gambar={sorotan.Gambar}
                    prioritas={prioritas}
                    className="max-h-full w-auto max-w-full rounded-kontrol object-contain"
                />
            ) : Spesimen ? (
                <Spesimen prioritas={prioritas} penuhTinggi className="max-w-none" />
            ) : null}
        </PanggungSpesimen>
    );
}

/**
 * Pembuka beranda berbentuk carousel (permintaan pemilik produk, menggantikan hero tunggal). Tiap sorotan = label,
 * judul, satu kalimat, tombol, dan tangkapan layar asli aplikasi.
 *
 * - **Tanpa pergeseran tata letak:** semua sorotan menumpuk di satu sel grid sehingga tingginya mengikuti sorotan
 *   terpanjang; yang tidak aktif `invisible` (keluar dari urutan Tab dan pembaca layar) lalu memudar masuk.
 * - **Isi tersedia tanpa JS:** teks seluruh sorotan ada di DOM, dan server mengirim sorotan pertama sebagai HTML
 *   (`resources/views/situs/kerangka.blade.php`) sebelum bundel dimuat. Gambar sorotan lain baru dimuat saat
 *   giliran sorotan itu hampir tiba, supaya halaman tidak menarik lima gambar sekaligus.
 * - **Gerakan terbatas:** pergantian otomatis berjalan sekali putaran lalu berhenti (bukan berulang tanpa henti),
 *   terjeda saat disentuh kursor atau difokus, berhenti selamanya begitu pengunjung memilih sendiri, dan mati total
 *   di `prefers-reduced-motion: reduce`. Tombol Jeda/Putar tersedia (WCAG 2.2.2).
 * - Panah, titik, geser jari di HP, dan tombol panah papan ketik. Judul sorotan pertama adalah `<h1>`.
 */
export default function BagianHeroGeser({ bagian, utama }: Props) {
    const daftar = bagian.Sorotan;
    const jumlah = daftar.length;
    const kurangiGerak = useKurangiGerak();
    const [aktif, AturAktif] = useState(0);
    const [berhenti, AturBerhenti] = useState(false);
    const [tertahan, AturTertahan] = useState(false);
    const [dimuat, AturDimuat] = useState<number[]>([0, 1]);
    const awalGeser = useRef<{ x: number; y: number } | null>(null);

    const otomatisJalan = jumlah > 1 && !kurangiGerak && !berhenti;

    const Tampilkan = useCallback(
        (indeks: number) => {
            const tujuan = ((indeks % jumlah) + jumlah) % jumlah;
            AturAktif(tujuan);
            AturDimuat((lama) => [...new Set([...lama, tujuan, (tujuan + 1) % jumlah])]);
        },
        [jumlah],
    );

    /** Pilihan pengunjung sendiri mengakhiri pergantian otomatis. */
    const Pilih = (indeks: number) => {
        AturBerhenti(true);
        Tampilkan(indeks);
    };

    useEffect(() => {
        if (!otomatisJalan || tertahan) {
            return;
        }

        const pewaktu = window.setTimeout(() => {
            if (aktif + 1 >= jumlah) {
                // Putaran pertama selesai: kembali ke awal lalu berhenti.
                AturBerhenti(true);
            }

            Tampilkan(aktif + 1);
        }, SELANG_OTOMATIS_MS);

        return () => window.clearTimeout(pewaktu);
    }, [otomatisJalan, tertahan, aktif, jumlah, Tampilkan]);

    const SaatTekan = (p: KeyboardEvent<HTMLDivElement>) => {
        if (p.key === 'ArrowRight') {
            p.preventDefault();
            Pilih(aktif + 1);
        } else if (p.key === 'ArrowLeft') {
            p.preventDefault();
            Pilih(aktif - 1);
        }
    };

    const SaatSentuhMulai = (p: PointerEvent<HTMLDivElement>) => {
        awalGeser.current = p.pointerType === 'mouse' ? null : { x: p.clientX, y: p.clientY };
    };

    const SaatSentuhSelesai = (p: PointerEvent<HTMLDivElement>) => {
        const awal = awalGeser.current;
        awalGeser.current = null;

        if (!awal) {
            return;
        }

        const selisihX = p.clientX - awal.x;
        const selisihY = p.clientY - awal.y;

        if (Math.abs(selisihX) >= AMBANG_GESER_PX && Math.abs(selisihX) > Math.abs(selisihY)) {
            Pilih(aktif + (selisihX < 0 ? 1 : -1));
        }
    };

    return (
        <section
            aria-roledescription="carousel"
            aria-label="Sorotan Payoung"
            className="bg-brand-gelap"
            onKeyDown={SaatTekan}
            onMouseEnter={() => AturTertahan(true)}
            onMouseLeave={() => AturTertahan(false)}
            onFocusCapture={() => AturTertahan(true)}
            onBlurCapture={(p) => {
                if (!p.currentTarget.contains(p.relatedTarget as Node | null)) {
                    AturTertahan(false);
                }
            }}
        >
            <div className="mx-auto max-w-6xl px-4 pt-12 pb-10 sm:pt-20 sm:pb-12">
                <div
                    className="grid touch-pan-y"
                    aria-live={otomatisJalan ? 'off' : 'polite'}
                    onPointerDown={SaatSentuhMulai}
                    onPointerUp={SaatSentuhSelesai}
                    onPointerCancel={() => {
                        awalGeser.current = null;
                    }}
                >
                    {daftar.map((s, i) => {
                        const judulHalaman = i === 0 && utama;
                        const terlihat = i === aktif;

                        return (
                            <div
                                key={`${s.Label}-${i}`}
                                role="group"
                                aria-roledescription="slide"
                                aria-label={`${i + 1} dari ${jumlah}`}
                                className={cn(
                                    'col-start-1 row-start-1 grid items-center gap-8 lg:grid-cols-[0.9fr_1.2fr] lg:gap-10',
                                    'transition-[opacity,visibility] duration-300 motion-reduce:transition-none',
                                    terlihat ? 'visible opacity-100' : 'invisible opacity-0',
                                )}
                            >
                                <div className="flex min-w-0 flex-col items-start gap-5">
                                    <p className="rounded-full bg-aksen px-3 py-1 text-label font-semibold text-teks-utama">
                                        {s.Label}
                                    </p>
                                    {judulHalaman ? (
                                        <JudulHalaman skala="sorotan" className="text-permukaan">
                                            {s.Judul}
                                        </JudulHalaman>
                                    ) : (
                                        <h2 className="text-sorotan-hp font-bold text-permukaan sm:text-sorotan">
                                            {s.Judul}
                                        </h2>
                                    )}
                                    {s.Teks ? (
                                        <p className="text-pengantar max-w-xl whitespace-pre-line text-brand-gelap-teks">
                                            {s.Teks}
                                        </p>
                                    ) : null}
                                    {s.TombolUtama || s.TombolKedua ? (
                                        <div className="flex w-full flex-col gap-3 sm:w-auto sm:flex-row">
                                            {s.TombolUtama ? (
                                                <TombolSitus href={s.TombolUtama.Tautan} ukuran="besar" varian="aksen">
                                                    {s.TombolUtama.Label}
                                                </TombolSitus>
                                            ) : null}
                                            {s.TombolKedua ? (
                                                <TombolSitus
                                                    href={s.TombolKedua.Tautan}
                                                    ukuran="besar"
                                                    varian="garis-terang"
                                                >
                                                    {s.TombolKedua.Label}
                                                </TombolSitus>
                                            ) : null}
                                        </div>
                                    ) : null}
                                </div>
                                <PanggungVisual sorotan={s} prioritas={i === 0 && utama} dimuat={dimuat.includes(i)} />
                            </div>
                        );
                    })}
                </div>

                {jumlah > 1 ? (
                    <div className="mt-6 flex items-center gap-1" role="group" aria-label="Kendali sorotan">
                        <button
                            type="button"
                            onClick={() => Pilih(aktif - 1)}
                            aria-label="Sorotan sebelumnya"
                            className="inline-flex size-11 items-center justify-center rounded-full border border-brand-gelap-gulir text-permukaan hover:bg-brand-gelap-sorot focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            <ChevronLeftIcon className="size-5" aria-hidden />
                        </button>
                        <div className="flex items-center" role="group" aria-label="Pilih sorotan">
                            {daftar.map((s, i) => (
                                <button
                                    key={`titik-${s.Label}-${i}`}
                                    type="button"
                                    onClick={() => Pilih(i)}
                                    aria-label={`Sorotan ${i + 1} dari ${jumlah}: ${s.Label}`}
                                    aria-current={i === aktif ? 'true' : undefined}
                                    className="group inline-flex h-11 min-w-7 items-center justify-center focus-visible:outline-none"
                                >
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'h-2 rounded-full transition-[width,background-color] duration-200 motion-reduce:transition-none group-focus-visible:ring-[3px] group-focus-visible:ring-ring/50',
                                            i === aktif
                                                ? 'w-6 bg-aksen'
                                                : 'w-2 bg-brand-gelap-gulir group-hover:bg-brand-gelap-teks',
                                        )}
                                    />
                                </button>
                            ))}
                        </div>
                        <button
                            type="button"
                            onClick={() => Pilih(aktif + 1)}
                            aria-label="Sorotan berikutnya"
                            className="inline-flex size-11 items-center justify-center rounded-full border border-brand-gelap-gulir text-permukaan hover:bg-brand-gelap-sorot focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            <ChevronRightIcon className="size-5" aria-hidden />
                        </button>
                        {kurangiGerak ? null : (
                            <button
                                type="button"
                                onClick={() => AturBerhenti((b) => !b)}
                                aria-label={otomatisJalan ? 'Jeda pergantian otomatis' : 'Putar pergantian otomatis'}
                                className="ml-1 inline-flex size-11 items-center justify-center rounded-full text-brand-gelap-teks hover:bg-brand-gelap-sorot hover:text-permukaan focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                {otomatisJalan ? (
                                    <PauseIcon className="size-5" aria-hidden />
                                ) : (
                                    <PlayIcon className="size-5" aria-hidden />
                                )}
                            </button>
                        )}
                    </div>
                ) : null}

                {(bagian.Poin ?? []).length > 0 || bagian.Catatan ? (
                    <div className="mt-8 flex flex-col gap-3 border-t border-brand-gelap-garis pt-6 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-6">
                        {(bagian.Poin ?? []).map((p) => (
                            <p key={p.Teks} className="flex items-center gap-2 text-label font-semibold text-permukaan">
                                <CheckIcon aria-hidden strokeWidth={2.5} className="size-4 shrink-0 text-aksen" />
                                {p.Teks}
                            </p>
                        ))}
                        {bagian.Catatan ? (
                            <p className="text-label text-brand-gelap-teks sm:ml-auto">{bagian.Catatan}</p>
                        ) : null}
                    </div>
                ) : null}
            </div>
        </section>
    );
}
