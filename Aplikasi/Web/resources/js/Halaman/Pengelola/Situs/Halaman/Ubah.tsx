import { Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarClock,
    Copy,
    Eye,
    EyeOff,
    ExternalLink,
    History,
    Layers,
    LayoutPanelLeft,
    MoreHorizontal,
    Monitor,
    Plus,
    Redo2,
    Settings2,
    Trash2,
    Undo2,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import { DaftarSaranTautan } from '@/Komponen/Pengelola/Situs/BidangTautan';
import { BuatNilaiKosong } from '@/Komponen/Pengelola/Situs/EditorBlok';
import DaftarBlok from '@/Komponen/Pengelola/Situs/EditorVisual/DaftarBlok';
import GaleriBlok from '@/Komponen/Pengelola/Situs/EditorVisual/GaleriBlok';
import InspektorBlok from '@/Komponen/Pengelola/Situs/EditorVisual/InspektorBlok';
import DialogJadwalTerbit from '@/Komponen/Pengelola/Situs/EditorVisual/DialogJadwalTerbit';
import DialogTambahBlok from '@/Komponen/Pengelola/Situs/EditorVisual/DialogTambahBlok';
import PanelPengaturanHalaman from '@/Komponen/Pengelola/Situs/EditorVisual/PanelPengaturanHalaman';
import PanelRiwayat, { type RevisiHalaman } from '@/Komponen/Pengelola/Situs/EditorVisual/PanelRiwayat';
import PanelPratinjau, { OPSI_PERANGKAT } from '@/Komponen/Pengelola/Situs/EditorVisual/PanelPratinjau';
import {
    BerinyaIdBlok,
    BuatIdBlok,
    KunciDraf,
    type BlokDraf,
    type Draf,
    type Perangkat,
} from '@/Komponen/Pengelola/Situs/EditorVisual/Tipe';
import { useRiwayat } from '@/Komponen/Pengelola/Situs/EditorVisual/useRiwayat';
import { useSimpanOtomatis } from '@/Komponen/Pengelola/Situs/EditorVisual/useSimpanOtomatis';
import { usePratinjauLangsung } from '@/Komponen/Pengelola/Situs/EditorVisual/usePratinjauLangsung';
import type { NilaiBlok, SkemaBlok } from '@/Komponen/Pengelola/Situs/Tipe';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';

import { AmbilStatusHalaman, type RingkasHalamanSitus } from './Daftar';

type HalamanUbah = RingkasHalamanSitus & {
    JudulSeo: string | null;
    DeskripsiSeo: string | null;
    UuidGambarOg: string | null;
    TampilDiSitemap: boolean;
    Bagian: NilaiBlok[];
    Bawaan: boolean;
};

type PropsUbah = {
    Halaman: HalamanUbah;
    LabelBlok: Record<string, string>;
    Skema: Record<string, SkemaBlok>;
    Ikon: string[];
    NamaSitus: string;
    AlamatSitus: string;
    UrlPratinjauEditor: string;
    Revisi: RevisiHalaman[];
    JadwalTerbitPada: string | null;
    Izin: { Kelola: boolean };
};

const LABEL_SIMPAN = {
    diam: '',
    menunggu: 'Perubahan belum disimpan…',
    menyimpan: 'Menyimpan draf…',
    tersimpan: 'Draf tersimpan',
    galat: 'Draf belum tersimpan',
} as const;

function Jam(waktu: Date | null): string {
    return waktu === null ? '' : waktu.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
}

/** Editor halaman situs: pratinjau langsung di samping daftar blok yang bisa diseret (D-21, D-63). */
export default function HalamanUbahHalamanSitus({
    Halaman: halaman,
    LabelBlok,
    Skema,
    Ikon,
    NamaSitus,
    AlamatSitus,
    UrlPratinjauEditor,
    Revisi,
    JadwalTerbitPada,
    Izin,
}: PropsUbah) {
    const url = `/situs/halaman/${halaman.Uuid}`;
    const awal = useMemo<Draf>(
        () => ({
            Slug: halaman.Slug,
            Judul: halaman.Judul,
            JudulSeo: halaman.JudulSeo ?? '',
            DeskripsiSeo: halaman.DeskripsiSeo ?? '',
            UuidGambarOg: halaman.UuidGambarOg,
            TampilDiSitemap: halaman.TampilDiSitemap,
            Bagian: BerinyaIdBlok(halaman.Bagian),
        }),
        // Muatan awal hanya dihitung sekali; sesudahnya editor memegang isi yang sedang disunting.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [],
    );
    const riwayat = useRiwayat<Draf>(awal);
    const draf = riwayat.nilai;
    const [kunciTersimpan, AturKunciTersimpan] = useState(() => KunciDraf(awal));
    const [adaPerubahanServer, AturAdaPerubahanServer] = useState(halaman.AdaPerubahan);
    const SaatTersimpan = useCallback((kunci: string, adaPerubahan: boolean) => {
        AturKunciTersimpan(kunci);
        AturAdaPerubahanServer(adaPerubahan);
    }, []);
    const simpan = useSimpanOtomatis({ draf, kunciTersimpan, url, aktif: Izin.Kelola, saatTersimpan: SaatTersimpan });
    const pratinjau = usePratinjauLangsung(url, draf);
    const galat = { ...pratinjau.galat, ...simpan.galat };
    const [terbukaId, AturTerbukaId] = useState<string | null>(null);
    const [perangkat, AturPerangkat] = useState<Perangkat>('komputer');
    const [tab, AturTab] = useState<'lapisan' | 'kanvas' | 'properti'>('kanvas');
    const [panelKiri, AturPanelKiri] = useState<'lapisan' | 'tambah'>('lapisan');
    const [dialogBlok, AturDialogBlok] = useState<{ sisipSetelah: number | null } | null>(null);
    const [panelHalaman, AturPanelHalaman] = useState(false);
    const [konfirmasi, AturKonfirmasi] = useState<'terbit' | 'hapus' | null>(null);
    const [memproses, AturMemproses] = useState(false);
    const [panelRiwayat, AturPanelRiwayat] = useState(false);
    const [dialogJadwal, AturDialogJadwal] = useState(false);
    const [galatJadwal, AturGalatJadwal] = useState<string | undefined>(undefined);
    const [terhapus, AturTerhapus] = useState<string | null>(null);
    const terpilihIndeks = draf.Bagian.findIndex((b) => b._id === terbukaId);
    const status = AmbilStatusHalaman({ ...halaman, AdaPerubahan: adaPerubahanServer || simpan.kotor });
    const jenisTersedia = Object.keys(LabelBlok);

    const Ubah = useCallback(
        (pembaruan: (lama: Draf) => Draf, gabung = false) => riwayat.ubah(pembaruan, gabung),
        // `riwayat.ubah` stabil; objek `riwayat` berganti tiap render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [riwayat.ubah],
    );

    // Peringatan bila halaman ditutup saat masih ada perubahan yang belum tersimpan.
    useEffect(() => {
        if (!simpan.kotor) {
            return;
        }

        const Cegah = (peristiwa: BeforeUnloadEvent) => {
            peristiwa.preventDefault();
        };
        window.addEventListener('beforeunload', Cegah);

        return () => window.removeEventListener('beforeunload', Cegah);
    }, [simpan.kotor]);

    // Pintasan papan ketik: Ctrl+S simpan, Ctrl+Z urungkan, Ctrl+Shift+Z / Ctrl+Y ulangi (di luar kolom ketik).
    useEffect(() => {
        const SaatTombol = (peristiwa: KeyboardEvent) => {
            if (!(peristiwa.ctrlKey || peristiwa.metaKey)) {
                return;
            }

            const tombol = peristiwa.key.toLowerCase();
            const dalamKolom = (peristiwa.target as HTMLElement | null)?.closest(
                'input, textarea, select, [contenteditable]',
            );

            if (tombol === 's') {
                peristiwa.preventDefault();
                void simpan.simpanSekarang();
            } else if (!dalamKolom && tombol === 'z') {
                peristiwa.preventDefault();

                if (peristiwa.shiftKey) {
                    riwayat.ulangi();
                } else {
                    riwayat.urungkan();
                }
            } else if (!dalamKolom && tombol === 'y') {
                peristiwa.preventDefault();
                riwayat.ulangi();
            }
        };
        window.addEventListener('keydown', SaatTombol);

        return () => window.removeEventListener('keydown', SaatTombol);
    }, [riwayat, simpan]);

    const PilihBlokDariPratinjau = useCallback(
        (indeks: number) => {
            const blok = draf.Bagian[indeks];

            if (blok) {
                AturTerbukaId(blok._id);
                AturTab('properti');
            }
        },
        [draf.Bagian],
    );

    const TambahBlok = (jenis: string, sisipSetelah: number | null | undefined = undefined) => {
        const skema = Skema[jenis];
        const sisip = sisipSetelah === undefined ? (dialogBlok?.sisipSetelah ?? null) : sisipSetelah;

        if (!skema) {
            return;
        }

        const baru: BlokDraf = { _id: BuatIdBlok(), Jenis: jenis, ...BuatNilaiKosong(skema) };
        Ubah((d) => {
            const posisi = sisip === null ? d.Bagian.length : sisip + 1;

            return { ...d, Bagian: [...d.Bagian.slice(0, posisi), baru, ...d.Bagian.slice(posisi)] };
        });
        AturTerbukaId(baru._id);
        AturDialogBlok(null);
        AturTab('properti');
    };

    const HapusBlok = (indeks: number) => {
        const blok = draf.Bagian[indeks];

        if (!blok) {
            return;
        }

        Ubah((d) => ({ ...d, Bagian: d.Bagian.filter((_, j) => j !== indeks) }));
        AturTerhapus(LabelBlok[String(blok.Jenis)] ?? 'Blok');

        if (terbukaId === blok._id) {
            AturTerbukaId(null);
        }
    };

    const GandakanBlok = (indeks: number) => {
        const blok = draf.Bagian[indeks];

        if (!blok) {
            return;
        }

        const salinan: BlokDraf = { ...(structuredClone(blok) as BlokDraf), _id: BuatIdBlok() };
        Ubah((d) => ({ ...d, Bagian: [...d.Bagian.slice(0, indeks + 1), salinan, ...d.Bagian.slice(indeks + 1)] }));
        AturTerbukaId(salinan._id);
    };

    const Kirim = (aksi: string, data: Record<string, boolean> = {}, metode: 'post' | 'delete' = 'post') => {
        const opsi = {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => {
                AturMemproses(false);
                AturKonfirmasi(null);
            },
        };

        if (metode === 'delete') {
            router.delete(url, opsi);
        } else {
            router.post(`${url}/${aksi}`, data, {
                ...opsi,
                onSuccess: () => {
                    if (aksi === 'terbitkan') {
                        AturAdaPerubahanServer(false);
                    }
                },
            });
        }
    };

    const Jadwalkan = async (iso: string | null) => {
        if (iso !== null && !(await simpan.simpanSekarang())) {
            return;
        }

        router.put(
            `${url}/jadwal`,
            { JadwalTerbitPada: iso },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: () => {
                    AturDialogJadwal(false);
                    AturGalatJadwal(undefined);
                },
                onError: (galatServer) => AturGalatJadwal(galatServer.JadwalTerbitPada ?? galatServer.Umum),
            },
        );
    };

    const Pulihkan = async (revisi: RevisiHalaman) => {
        if (!(await simpan.simpanSekarang())) {
            return;
        }

        router.post(
            `${url}/revisi/${revisi.Uuid}/pulihkan`,
            {},
            {
                // Pasang ulang editor dengan isi draf hasil pemulihan.
                preserveState: false,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
            },
        );
    };

    const MintaTerbit = async () => {
        if (await simpan.simpanSekarang()) {
            AturKonfirmasi('terbit');
        }
    };

    const BukaTabBaru = async () => {
        const jendela = window.open('', '_blank');
        await simpan.simpanSekarang();

        if (jendela) {
            jendela.location.href = `${url}/pratinjau`;
        }
    };

    const labelSimpan =
        simpan.status === 'tersimpan' && simpan.disimpanPada
            ? `${LABEL_SIMPAN.tersimpan} otomatis ${Jam(simpan.disimpanPada)}`
            : LABEL_SIMPAN[simpan.status];

    const jenisKiri = panelKiri;
    const blokTerpilih = terpilihIndeks >= 0 ? (draf.Bagian[terpilihIndeks] ?? null) : null;
    const terbitNonaktif = simpan.status === 'galat' || (!simpan.kotor && !adaPerubahanServer && halaman.Terbit);
    const KelasPanel = (nama: 'lapisan' | 'kanvas' | 'properti') => (tab === nama ? 'flex' : 'hidden');

    return (
        <TataLetakPengelola judul={draf.Judul === '' ? halaman.Judul : draf.Judul}>
            <DaftarSaranTautan />
            {konfirmasi === 'terbit' ? (
                <DialogKonfirmasi
                    judul={`Terbitkan ${draf.Judul}?`}
                    labelAksi="Terbitkan"
                    varian="utama"
                    memproses={memproses}
                    saatKonfirmasi={() => Kirim('terbitkan')}
                    saatBatal={() => AturKonfirmasi(null)}
                >
                    <p>Isi yang sedang Anda lihat di pratinjau langsung tampil ke pengunjung situs.</p>
                </DialogKonfirmasi>
            ) : null}
            {konfirmasi === 'hapus' ? (
                <DialogKonfirmasi
                    judul={`Hapus halaman ${halaman.Judul}?`}
                    labelAksi="Hapus halaman"
                    memproses={memproses}
                    saatKonfirmasi={() => Kirim('', {}, 'delete')}
                    saatBatal={() => AturKonfirmasi(null)}
                >
                    <p>
                        Alamat {halaman.Jalur} akan menampilkan "halaman tidak ditemukan". Tautan menu ke halaman ini
                        perlu diubah.
                    </p>
                </DialogKonfirmasi>
            ) : null}
            <DialogTambahBlok
                terbuka={dialogBlok !== null}
                saatTutup={() => AturDialogBlok(null)}
                jenis={jenisTersedia}
                label={LabelBlok}
                saatPilih={TambahBlok}
                keterangan={
                    dialogBlok?.sisipSetelah == null
                        ? 'Blok baru ditambahkan di bagian paling bawah halaman.'
                        : `Blok baru ditambahkan di bawah blok ${String(dialogBlok.sisipSetelah + 1)}.`
                }
            />
            <PanelRiwayat
                terbuka={panelRiwayat}
                saatTutup={() => AturPanelRiwayat(false)}
                revisi={Revisi}
                bolehUbah={Izin.Kelola}
                memproses={memproses}
                saatPulihkan={(r) => void Pulihkan(r)}
            />
            {dialogJadwal ? (
                <DialogJadwalTerbit
                    jadwalSaatIni={JadwalTerbitPada}
                    memproses={memproses}
                    galat={galatJadwal}
                    saatTutup={() => {
                        AturDialogJadwal(false);
                        AturGalatJadwal(undefined);
                    }}
                    saatSimpan={(iso) => void Jadwalkan(iso)}
                />
            ) : null}
            <PanelPengaturanHalaman
                terbuka={panelHalaman}
                saatTutup={() => AturPanelHalaman(false)}
                draf={draf}
                saatUbah={(bagian, gabung) => Ubah((d) => ({ ...d, ...bagian }), gabung ?? false)}
                galat={galat}
                bawaan={halaman.Bawaan}
                jalurAsli={AlamatSitus}
                namaSitus={NamaSitus}
                bolehUbah={Izin.Kelola}
            />

            <div
                data-studio
                className="fixed inset-0 z-40 flex flex-col bg-latar"
                role="region"
                aria-label="Studio halaman situs"
            >
                <header className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-garis bg-permukaan px-3 py-2">
                    <Link
                        href="/situs/halaman"
                        className="inline-flex h-9 items-center gap-1.5 rounded-kontrol px-2 text-label font-semibold text-teks-utama outline-none hover:bg-permukaan-sorot focus-visible:ring-2 focus-visible:ring-brand"
                    >
                        <ArrowLeft className="size-4" aria-hidden /> Semua halaman
                    </Link>
                    <div className="flex min-w-0 flex-col">
                        <span className="truncate text-isi font-semibold text-teks-utama">
                            {draf.Judul === '' ? halaman.Judul : draf.Judul}
                        </span>
                        <span className="truncate font-mono text-keterangan text-teks-sekunder">
                            {AlamatSitus.replace(/^https?:\/\//, '').replace(/\/$/, '')}
                            {draf.Slug === 'beranda' ? '' : `/${draf.Slug}`}
                        </span>
                    </div>
                    <LabelStatus jenis={status.jenis} teks={status.teks} />
                    <div
                        className="mx-auto flex gap-1 rounded-kontrol bg-permukaan-redup p-1"
                        role="group"
                        aria-label="Ukuran layar kanvas"
                    >
                        {OPSI_PERANGKAT.map(({ kunci, label, ikon: IkonPerangkat }) => (
                            <button
                                key={kunci}
                                type="button"
                                aria-pressed={perangkat === kunci}
                                onClick={() => AturPerangkat(kunci)}
                                className={`inline-flex h-8 items-center gap-1.5 rounded-kontrol px-2.5 text-label font-semibold ${
                                    perangkat === kunci
                                        ? 'bg-permukaan text-teks-utama shadow-sm'
                                        : 'text-teks-sekunder'
                                }`}
                            >
                                <IkonPerangkat className="size-4" aria-hidden />
                                <span className="hidden xl:inline">{label}</span>
                            </button>
                        ))}
                    </div>
                    <span
                        role="status"
                        aria-live="polite"
                        className={`text-keterangan font-medium ${
                            simpan.status === 'galat' ? 'text-bahaya' : 'text-teks-sekunder'
                        }`}
                    >
                        {labelSimpan}
                    </span>
                    {Izin.Kelola ? (
                        <div className="flex items-center gap-1">
                            <button
                                type="button"
                                aria-label="Urungkan (Ctrl+Z)"
                                title="Urungkan (Ctrl+Z)"
                                disabled={!riwayat.bisaUrungkan}
                                onClick={riwayat.urungkan}
                                className="inline-flex size-8 items-center justify-center rounded-kontrol text-teks-utama outline-none hover:bg-permukaan-sorot focus-visible:ring-2 focus-visible:ring-brand disabled:opacity-40 pointer-coarse:size-11"
                            >
                                <Undo2 className="size-4" aria-hidden />
                            </button>
                            <button
                                type="button"
                                aria-label="Ulangi (Ctrl+Y)"
                                title="Ulangi (Ctrl+Y)"
                                disabled={!riwayat.bisaUlangi}
                                onClick={riwayat.ulangi}
                                className="inline-flex size-8 items-center justify-center rounded-kontrol text-teks-utama outline-none hover:bg-permukaan-sorot focus-visible:ring-2 focus-visible:ring-brand disabled:opacity-40 pointer-coarse:size-11"
                            >
                                <Redo2 className="size-4" aria-hidden />
                            </button>
                        </div>
                    ) : null}
                    <Tombol varian="sekunder" onClick={() => AturPanelHalaman(true)}>
                        <Settings2 aria-hidden /> <span className="hidden md:inline">Pengaturan</span>
                    </Tombol>
                    <Tombol varian="sekunder" onClick={() => void BukaTabBaru()}>
                        <ExternalLink aria-hidden /> <span className="hidden md:inline">Tab baru</span>
                    </Tombol>
                    {Izin.Kelola ? (
                        <Tombol
                            memproses={memproses && konfirmasi === 'terbit'}
                            disabled={terbitNonaktif}
                            title={
                                simpan.status === 'galat'
                                    ? 'Perbaiki isian yang ditandai merah dulu'
                                    : terbitNonaktif
                                      ? 'Tidak ada perubahan untuk diterbitkan'
                                      : undefined
                            }
                            onClick={() => void MintaTerbit()}
                        >
                            Terbitkan
                        </Tombol>
                    ) : null}
                    {Izin.Kelola ? (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button
                                    type="button"
                                    aria-label="Aksi lain halaman"
                                    className="inline-flex size-8 items-center justify-center rounded-kontrol border border-garis-input text-teks-utama outline-none hover:bg-permukaan-sorot focus-visible:ring-2 focus-visible:ring-brand pointer-coarse:size-11"
                                >
                                    <MoreHorizontal className="size-4" aria-hidden />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem onSelect={() => AturPanelRiwayat(true)}>
                                    <History aria-hidden /> Riwayat versi
                                </DropdownMenuItem>
                                <DropdownMenuItem onSelect={() => AturDialogJadwal(true)}>
                                    <CalendarClock aria-hidden />{' '}
                                    {JadwalTerbitPada === null ? 'Jadwalkan terbit' : 'Ubah jadwal terbit'}
                                </DropdownMenuItem>
                                <DropdownMenuItem onSelect={() => Kirim('gandakan')}>
                                    <Copy aria-hidden /> Gandakan halaman
                                </DropdownMenuItem>
                                {halaman.Terbit && halaman.Slug !== 'beranda' ? (
                                    <DropdownMenuItem onSelect={() => Kirim('aktif', { Aktif: !halaman.Aktif })}>
                                        {halaman.Aktif ? <EyeOff aria-hidden /> : <Eye aria-hidden />}
                                        {halaman.Aktif ? 'Sembunyikan dari situs' : 'Tampilkan lagi'}
                                    </DropdownMenuItem>
                                ) : null}
                                {!halaman.Bawaan ? (
                                    <>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            variant="destructive"
                                            onSelect={() => AturKonfirmasi('hapus')}
                                        >
                                            <Trash2 aria-hidden /> Hapus halaman
                                        </DropdownMenuItem>
                                    </>
                                ) : null}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    ) : null}
                </header>
                {simpan.status === 'galat' && simpan.pesan ? (
                    <div className="px-3 pt-2">
                        <Pemberitahuan jenis="bahaya">{simpan.pesan}</Pemberitahuan>
                    </div>
                ) : null}
                {terhapus ? (
                    <div className="px-3 pt-2">
                        <Pemberitahuan jenis="info">
                            Blok {terhapus} dihapus.{' '}
                            <button
                                type="button"
                                className="font-semibold text-brand underline"
                                onClick={() => {
                                    riwayat.urungkan();
                                    AturTerhapus(null);
                                }}
                            >
                                Urungkan
                            </button>{' '}
                            <button
                                type="button"
                                className="text-teks-sekunder underline"
                                onClick={() => AturTerhapus(null)}
                            >
                                Tutup
                            </button>
                        </Pemberitahuan>
                    </div>
                ) : null}

                <div className="flex min-h-0 flex-1">
                    <aside
                        aria-label="Lapisan dan pustaka blok"
                        className={`${KelasPanel('lapisan')} min-h-0 w-full shrink-0 flex-col border-r border-garis bg-permukaan lg:flex lg:w-80`}
                    >
                        <div className="flex gap-1 border-b border-garis p-2" role="tablist" aria-label="Panel kiri">
                            {(
                                [
                                    ['lapisan', 'Lapisan', Layers],
                                    ['tambah', 'Tambah blok', Plus],
                                ] as const
                            ).map(([kunci, label, IkonTab]) => (
                                <button
                                    key={kunci}
                                    type="button"
                                    role="tab"
                                    aria-selected={jenisKiri === kunci}
                                    onClick={() => AturPanelKiri(kunci)}
                                    className={`inline-flex h-9 flex-1 items-center justify-center gap-1.5 rounded-kontrol text-label font-semibold ${
                                        jenisKiri === kunci
                                            ? 'bg-brand-lembut text-brand'
                                            : 'text-teks-sekunder hover:bg-permukaan-sorot'
                                    }`}
                                >
                                    <IkonTab className="size-4" aria-hidden /> {label}
                                </button>
                            ))}
                        </div>
                        <div className="min-h-0 flex-1 overflow-y-auto p-2">
                            {jenisKiri === 'tambah' ? (
                                <GaleriBlok
                                    jenis={jenisTersedia}
                                    label={LabelBlok}
                                    bolehUbah={Izin.Kelola}
                                    keterangan={
                                        terpilihIndeks >= 0
                                            ? `Blok baru masuk di bawah blok ${String(terpilihIndeks + 1)}.`
                                            : 'Blok baru masuk di bagian paling bawah halaman.'
                                    }
                                    saatPilih={(jenis) =>
                                        TambahBlok(jenis, terpilihIndeks >= 0 ? terpilihIndeks : null)
                                    }
                                />
                            ) : (
                                <div className="flex flex-col gap-2">
                                    {galat.Bagian ? <Pemberitahuan jenis="bahaya">{galat.Bagian}</Pemberitahuan> : null}
                                    {draf.Bagian.length === 0 ? (
                                        <div className="flex flex-col items-center gap-3 rounded-panel border border-dashed border-garis-input px-4 py-8 text-center">
                                            <LayoutPanelLeft className="size-8 text-teks-sekunder" aria-hidden />
                                            <p className="text-isi text-teks-sekunder">
                                                Halaman ini masih kosong. Mulai dengan blok pembuka.
                                            </p>
                                            {Izin.Kelola ? (
                                                <Tombol onClick={() => AturPanelKiri('tambah')}>
                                                    <Plus aria-hidden /> Tambah blok pertama
                                                </Tombol>
                                            ) : null}
                                        </div>
                                    ) : (
                                        <DaftarBlok
                                            tampilan="lapisan"
                                            bagian={draf.Bagian}
                                            skema={Skema}
                                            labelBlok={LabelBlok}
                                            ikon={Ikon}
                                            galat={galat}
                                            bolehUbah={Izin.Kelola}
                                            terbukaId={terbukaId}
                                            terpilihId={terbukaId}
                                            saatToggle={(id) => {
                                                AturTerbukaId(id);
                                                AturTab('properti');
                                            }}
                                            saatUbahBlok={(i, blok) =>
                                                Ubah(
                                                    (d) => ({
                                                        ...d,
                                                        Bagian: d.Bagian.map((x, j) => (j === i ? blok : x)),
                                                    }),
                                                    true,
                                                )
                                            }
                                            saatUrutUlang={(baru) => Ubah((d) => ({ ...d, Bagian: baru }))}
                                            saatGandakan={GandakanBlok}
                                            saatHapus={HapusBlok}
                                            saatSisipkan={(i) => AturDialogBlok({ sisipSetelah: i })}
                                        />
                                    )}
                                </div>
                            )}
                        </div>
                    </aside>

                    <main
                        aria-label="Kanvas halaman"
                        className={`${KelasPanel('kanvas')} min-h-0 min-w-0 flex-1 flex-col gap-2 bg-permukaan-redup p-3 lg:flex`}
                    >
                        <div className="min-h-0 flex-1">
                            <PanelPratinjau
                                urlBingkai={UrlPratinjauEditor}
                                halaman={pratinjau.halaman}
                                memuat={pratinjau.memuat}
                                terpilih={terpilihIndeks >= 0 ? terpilihIndeks : null}
                                perangkat={perangkat}
                                saatKlikBlok={PilihBlokDariPratinjau}
                            />
                        </div>
                        <p className="flex items-center gap-1.5 text-keterangan text-teks-sekunder">
                            <Monitor className="size-3.5 shrink-0" aria-hidden /> Klik bagian mana pun di kanvas untuk
                            menyuntingnya. Perubahan tersimpan otomatis sebagai draf. Pengunjung baru melihatnya setelah
                            Terbitkan.
                        </p>
                    </main>

                    <aside
                        aria-label="Properti blok"
                        className={`${KelasPanel('properti')} min-h-0 w-full shrink-0 flex-col border-l border-garis bg-permukaan lg:flex lg:w-96`}
                    >
                        <InspektorBlok
                            blok={blokTerpilih}
                            indeks={terpilihIndeks}
                            jumlah={draf.Bagian.length}
                            skema={blokTerpilih ? Skema[String(blokTerpilih.Jenis)] : undefined}
                            label={
                                blokTerpilih
                                    ? (LabelBlok[String(blokTerpilih.Jenis)] ?? String(blokTerpilih.Jenis))
                                    : ''
                            }
                            ikon={Ikon}
                            galat={galat}
                            bolehUbah={Izin.Kelola}
                            saatBerubah={(blok) =>
                                Ubah(
                                    (d) => ({
                                        ...d,
                                        Bagian: d.Bagian.map((x, j) => (j === terpilihIndeks ? blok : x)),
                                    }),
                                    true,
                                )
                            }
                            saatPindah={(arah) =>
                                Ubah((d) => ({
                                    ...d,
                                    Bagian: d.Bagian.map((_, j, semua) =>
                                        j === terpilihIndeks
                                            ? (semua[j + arah] as BlokDraf)
                                            : j === terpilihIndeks + arah
                                              ? (semua[terpilihIndeks] as BlokDraf)
                                              : (semua[j] as BlokDraf),
                                    ),
                                }))
                            }
                            saatGandakan={() => GandakanBlok(terpilihIndeks)}
                            saatHapus={() => HapusBlok(terpilihIndeks)}
                            saatSisipkan={() => AturDialogBlok({ sisipSetelah: terpilihIndeks })}
                            saatPengaturanHalaman={() => AturPanelHalaman(true)}
                        />
                    </aside>
                </div>

                <nav
                    aria-label="Panel studio"
                    className="flex gap-1 border-t border-garis bg-permukaan p-1 lg:hidden"
                    role="tablist"
                >
                    {(
                        [
                            ['lapisan', 'Lapisan'],
                            ['kanvas', 'Kanvas'],
                            ['properti', 'Properti'],
                        ] as const
                    ).map(([kunci, label]) => (
                        <button
                            key={kunci}
                            type="button"
                            role="tab"
                            aria-selected={tab === kunci}
                            onClick={() => AturTab(kunci)}
                            className={`h-10 flex-1 rounded-kontrol text-label font-semibold ${
                                tab === kunci ? 'bg-brand-lembut text-brand' : 'text-teks-sekunder'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </nav>
            </div>
        </TataLetakPengelola>
    );
}
