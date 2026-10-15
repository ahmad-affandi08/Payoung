import { Link } from '@inertiajs/react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { useCallback, useEffect, useRef, useState } from 'react';

import DaftarPesanObrolan from '@/Komponen/Dukungan/DaftarPesanObrolan';
import KotakPesanObrolan from '@/Komponen/Dukungan/KotakPesanObrolan';
import Tombol from '@/Komponen/Formulir/Tombol';
import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import Panel from '@/Komponen/Kelola/Panel';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import {
    AmbilPenanda,
    FormatLamaMenunggu,
    GabungPesan,
    HitungMenunggu,
    SusunJudulTab,
    type PesanObrolan,
} from '@/Pustaka/Obrolan';
import { AmbilJson, GalatPermintaan, KirimJson } from '@/Pustaka/PermintaanJson';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';

export type BarisObrolan = {
    Uuid: string;
    Nomor: string;
    Judul: string;
    NamaTenant: string;
    NamaPelapor: string;
    Status: string;
    LabelStatus: string;
    IdPenanggungJawab: number | null;
    Petugas: string | null;
    MenungguBalasan: boolean;
    Pratinjau: string;
    PesanTerakhirPada: string;
};

export type KeadaanInbox = { Siap: boolean; PetugasOnline: number; Obrolan: BarisObrolan[] };

type PercakapanObrolan = {
    Uuid: string;
    Nomor: string;
    Status: string;
    LabelStatus: string;
    BisaDibalas: boolean;
    Pesan: PesanObrolan[];
};

type PropsObrolanKonsol = {
    Awal: KeadaanInbox;
    BolehTangani: boolean;
    SegarkanDetik: number;
    MaksPanjangPesan: number;
};

/** Detak kehadiran: inbox yang terbuka memberi tahu server tiap 20 detik bahwa petugas masih ada. */
const JEDA_DETAK_MS = 20_000;

function useSekarang(jedaMs: number): number {
    const [sekarang, AturSekarang] = useState(() => Date.now());

    useEffect(() => {
        const penghitung = window.setInterval(() => AturSekarang(Date.now()), jedaMs);

        return () => window.clearInterval(penghitung);
    }, [jedaMs]);

    return sekarang;
}

function PercakapanTerpilih({
    uuid,
    bolehTangani,
    segarkanDetik,
    maksPanjangPesan,
    saatKembali,
    saatBerubah,
}: {
    uuid: string;
    bolehTangani: boolean;
    segarkanDetik: number;
    maksPanjangPesan: number;
    saatKembali: () => void;
    saatBerubah: () => void;
}) {
    const [pesan, AturPesan] = useState<PesanObrolan[]>([]);
    const [meta, AturMeta] = useState<Omit<PercakapanObrolan, 'Pesan'> | null>(null);
    const [memproses, AturMemproses] = useState<'kirim' | 'ambil' | 'selesai' | null>(null);
    const [galat, AturGalat] = useState<string | null>(null);
    const penanda = useRef<string | null>(null);

    const Terapkan = useCallback((percakapan: PercakapanObrolan) => {
        const { Pesan: baru, ...sisa } = percakapan;
        AturMeta(sisa);
        AturPesan((lama) => {
            const hasil = GabungPesan(lama, baru);
            penanda.current = AmbilPenanda(hasil);

            return hasil;
        });
    }, []);

    const kueri = useQuery({
        queryKey: KunciKueri.ObrolanPesan(uuid),
        queryFn: async ({ signal }) => {
            const hasil = await AmbilJson<PercakapanObrolan>(
                `/dukungan/obrolan/${uuid}/pesan${penanda.current === null ? '' : `?setelah=${encodeURIComponent(penanda.current)}`}`,
                signal,
            );
            // Diterapkan saat jawaban server tiba (bukan di efek) supaya pesan baru langsung tergabung.
            Terapkan(hasil);

            return hasil;
        },
        refetchInterval: segarkanDetik * 1000,
        refetchIntervalInBackground: false,
        staleTime: 0,
        gcTime: 0,
    });

    const Jalankan = async (jenis: 'kirim' | 'ambil' | 'selesai', alamat: string, isi?: string): Promise<boolean> => {
        AturMemproses(jenis);
        AturGalat(null);

        try {
            Terapkan(await KirimJson<PercakapanObrolan>(alamat, isi === undefined ? {} : { Isi: isi }));
            saatBerubah();

            return true;
        } catch (kesalahan) {
            AturGalat(kesalahan instanceof GalatPermintaan ? kesalahan.message : 'Aksi belum berhasil. Coba lagi.');

            return false;
        } finally {
            AturMemproses(null);
        }
    };

    return (
        <div className="flex min-h-0 flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <Tombol varian="sekunder" onClick={saatKembali}>
                    Kembali ke daftar
                </Tombol>
                {meta === null ? null : (
                    <>
                        <span className="font-mono text-label text-teks-utama">{meta.Nomor}</span>
                        <LabelStatus jenis={meta.BisaDibalas ? 'netral' : 'sukses'} teks={meta.LabelStatus} />
                        <Link
                            href={`/dukungan/tiket/${meta.Uuid}`}
                            className="text-keterangan font-semibold text-brand underline"
                        >
                            Buka sebagai tiket
                        </Link>
                    </>
                )}
            </div>
            {galat === null ? null : <Pemberitahuan jenis="bahaya">{galat}</Pemberitahuan>}
            {kueri.isError && meta === null ? (
                <p role="alert" className="text-isi text-bahaya">
                    Percakapan belum bisa dimuat. Pilih obrolan lain atau muat ulang halaman.
                </p>
            ) : null}
            <DaftarPesanObrolan pesan={pesan} sebagai="Petugas" className="h-96 rounded-panel bg-latar p-2" />
            {bolehTangani && meta?.BisaDibalas === true ? (
                <>
                    <KotakPesanObrolan
                        maksimal={maksPanjangPesan}
                        memproses={memproses === 'kirim'}
                        placeholder="Tulis balasan untuk pelapor..."
                        labelKirim="Kirim balasan"
                        saatKirim={(isi) => Jalankan('kirim', `/dukungan/obrolan/${uuid}/pesan`, isi)}
                    />
                    <div className="flex flex-wrap items-center gap-2 border-t border-garis pt-3">
                        <Tombol
                            varian="sekunder"
                            onClick={() => void Jalankan('ambil', `/dukungan/obrolan/${uuid}/ambil`)}
                            memproses={memproses === 'ambil'}
                        >
                            Ambil obrolan ini
                        </Tombol>
                        <Tombol
                            varian="sekunder"
                            onClick={() => void Jalankan('selesai', `/dukungan/obrolan/${uuid}/selesai`)}
                            memproses={memproses === 'selesai'}
                        >
                            Selesaikan obrolan
                        </Tombol>
                    </div>
                </>
            ) : meta !== null && !meta.BisaDibalas ? (
                <p className="text-keterangan text-teks-sekunder">
                    Obrolan ini sudah {meta.LabelStatus.toLowerCase()} dan tidak bisa dibalas dari inbox.
                </p>
            ) : null}
        </div>
    );
}

/**
 * Inbox obrolan langsung petugas dukungan (PLT-12, D-163). Petugas menyalakan "Siap menerima obrolan" supaya tenant
 * melihat tim online; selama inbox terbuka halaman berdetak tiap 20 detik, dan yang tertutup otomatis dianggap offline.
 * Daftar menempatkan obrolan yang paling lama menunggu di atas, dan judul tab memuat jumlah yang menunggu.
 */
export default function ObrolanKonsol({ Awal, BolehTangani, SegarkanDetik, MaksPanjangPesan }: PropsObrolanKonsol) {
    const [siap, AturSiap] = useState(Awal.Siap);
    const [dipilih, AturDipilih] = useState<string | null>(null);
    const [galat, AturGalat] = useState<string | null>(null);
    const [mengubahSiap, AturMengubahSiap] = useState(false);
    const sekarang = useSekarang(30_000);

    const inbox = useQuery({
        queryKey: KunciKueri.ObrolanInbox,
        queryFn: ({ signal }) => AmbilJson<KeadaanInbox>('/dukungan/obrolan/daftar', signal),
        initialData: Awal,
        refetchInterval: SegarkanDetik * 1000,
        refetchIntervalInBackground: true,
        placeholderData: keepPreviousData,
    });
    const keadaan = inbox.data;
    const menunggu = HitungMenunggu(keadaan.Obrolan);

    useEffect(() => {
        const judulAsli = document.title.replace(/^\(\d+\)\s/, '');
        document.title = SusunJudulTab(judulAsli, menunggu);

        return () => {
            document.title = judulAsli;
        };
    }, [menunggu]);

    useEffect(() => {
        if (!siap) {
            return undefined;
        }

        const penghitung = window.setInterval(() => {
            void KirimJson<{ Siap: boolean }>('/dukungan/obrolan/kehadiran', {}).then(
                (hasil) => AturSiap(hasil.Siap),
                () => undefined,
            );
        }, JEDA_DETAK_MS);

        return () => window.clearInterval(penghitung);
    }, [siap]);

    const UbahKesiapan = async (nilai: boolean) => {
        AturMengubahSiap(true);
        AturGalat(null);

        try {
            AturSiap((await KirimJson<{ Siap: boolean }>('/dukungan/obrolan/kehadiran', { Siap: nilai })).Siap);
            void inbox.refetch();
        } catch (kesalahan) {
            AturGalat(kesalahan instanceof GalatPermintaan ? kesalahan.message : 'Kesiapan belum berubah. Coba lagi.');
        } finally {
            AturMengubahSiap(false);
        }
    };

    return (
        <TataLetakPengelola judul="Obrolan langsung">
            <AksiHalaman
                keterangan={`${String(keadaan.PetugasOnline)} petugas siap | ${String(menunggu)} obrolan menunggu balasan`}
            >
                <Link href="/dukungan/tiket" className="text-label font-semibold text-brand underline">
                    Antrean tiket
                </Link>
                {BolehTangani ? (
                    siap ? (
                        <Tombol varian="sekunder" onClick={() => void UbahKesiapan(false)} memproses={mengubahSiap}>
                            Berhenti menerima obrolan
                        </Tombol>
                    ) : (
                        <Tombol onClick={() => void UbahKesiapan(true)} memproses={mengubahSiap}>
                            Siap menerima obrolan
                        </Tombol>
                    )
                ) : null}
            </AksiHalaman>
            {galat === null ? null : <Pemberitahuan jenis="bahaya">{galat}</Pemberitahuan>}
            {BolehTangani && !siap ? (
                <Pemberitahuan jenis="info">
                    Tenant baru melihat tim online setelah ada petugas yang menekan "Siap menerima obrolan" dan inbox
                    ini tetap terbuka. Obrolan yang masuk saat tidak ada petugas siap tetap menjadi tiket biasa di
                    antrean.
                </Pemberitahuan>
            ) : null}
            <div className="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
                <div className={dipilih === null ? 'block' : 'hidden lg:block'}>
                    <Panel judul="Obrolan terbuka" tingkat="h2">
                        {keadaan.Obrolan.length === 0 ? (
                            <p className="text-isi text-teks-sekunder">
                                Belum ada obrolan. Obrolan baru dari tenant akan muncul di sini otomatis.
                            </p>
                        ) : (
                            <ul className="flex flex-col divide-y divide-garis" aria-label="Daftar obrolan terbuka">
                                {keadaan.Obrolan.map((baris) => (
                                    <li key={baris.Uuid}>
                                        <button
                                            type="button"
                                            onClick={() => AturDipilih(baris.Uuid)}
                                            aria-current={dipilih === baris.Uuid ? 'true' : undefined}
                                            className={`flex w-full flex-col gap-1 px-2 py-3 text-left outline-none focus-visible:ring-2 focus-visible:ring-brand pointer-coarse:min-h-11 ${
                                                dipilih === baris.Uuid ? 'bg-brand-lembut' : 'hover:bg-permukaan-redup'
                                            }`}
                                        >
                                            <span className="flex flex-wrap items-center gap-2">
                                                <span className="text-isi font-semibold text-teks-utama">
                                                    {baris.NamaTenant}
                                                </span>
                                                {baris.MenungguBalasan ? (
                                                    <LabelStatus
                                                        jenis="peringatan"
                                                        teks={`Menunggu ${FormatLamaMenunggu(baris.PesanTerakhirPada, sekarang)}`}
                                                    />
                                                ) : (
                                                    <LabelStatus jenis="netral" teks="Sudah dibalas" />
                                                )}
                                            </span>
                                            <span className="text-keterangan text-teks-sekunder">
                                                {baris.NamaPelapor} |{' '}
                                                {baris.Petugas === null ? 'belum ada petugas' : baris.Petugas}
                                            </span>
                                            <span className="break-words text-keterangan text-teks-sekunder">
                                                {baris.Pratinjau}
                                            </span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>
                <div className={dipilih === null ? 'hidden lg:block' : 'block'}>
                    <Panel judul="Percakapan" tingkat="h2">
                        {dipilih === null ? (
                            <p className="text-isi text-teks-sekunder">
                                Pilih satu obrolan di daftar untuk membaca dan membalasnya.
                            </p>
                        ) : (
                            <PercakapanTerpilih
                                key={dipilih}
                                uuid={dipilih}
                                bolehTangani={BolehTangani}
                                segarkanDetik={SegarkanDetik}
                                maksPanjangPesan={MaksPanjangPesan}
                                saatKembali={() => AturDipilih(null)}
                                saatBerubah={() => void inbox.refetch()}
                            />
                        )}
                    </Panel>
                </div>
            </div>
        </TataLetakPengelola>
    );
}
