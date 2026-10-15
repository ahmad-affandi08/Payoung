import { Link } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import { useCallback, useRef, useState } from 'react';

import DaftarPesanObrolan from '@/Komponen/Dukungan/DaftarPesanObrolan';
import KotakPesanObrolan from '@/Komponen/Dukungan/KotakPesanObrolan';
import Tombol from '@/Komponen/Formulir/Tombol';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import {
    AmbilPenanda,
    GabungPesan,
    type KeadaanObrolanTenant,
    type LayananObrolan,
    type ObrolanTenant,
    type PesanObrolan,
} from '@/Pustaka/Obrolan';
import { AmbilJson, GalatPermintaan, KirimJson } from '@/Pustaka/PermintaanJson';

type PropsPanelObrolan = {
    /** Jalur halaman yang sedang dibuka; ikut dikirim sebagai konteks tiket. */
    halamanAsal: string;
};

/**
 * Obrolan langsung dengan tim dukungan di lembar Bantuan (PLT-12, D-163). Obrolan adalah tiket berkanal Obrolan:
 * bila ada petugas online, pesan muncul di inbox mereka dan dijawab waktu nyata (halaman bertanya ke server tiap
 * beberapa detik selagi ada obrolan); bila tidak, pesan tetap tercatat sebagai tiket dan dibalas sesuai janji respons
 * paket, dan layar menyatakannya terus terang. Polling hanya berjalan selagi panel ini terpasang (lembar terbuka).
 */
export default function PanelObrolan({ halamanAsal }: PropsPanelObrolan) {
    const [pesan, AturPesan] = useState<PesanObrolan[]>([]);
    const [obrolan, AturObrolan] = useState<Omit<ObrolanTenant, 'Pesan'> | null>(null);
    const [layanan, AturLayanan] = useState<LayananObrolan | null>(null);
    const [memproses, AturMemproses] = useState<'kirim' | 'akhiri' | null>(null);
    const [galat, AturGalat] = useState<string | null>(null);
    const penanda = useRef<string | null>(null);
    const uuidSaatIni = useRef<string | null>(null);

    const Terapkan = useCallback((keadaan: KeadaanObrolanTenant) => {
        AturLayanan(keadaan.Layanan);

        if (keadaan.Obrolan === null) {
            uuidSaatIni.current = null;
            penanda.current = null;
            AturObrolan(null);
            AturPesan([]);

            return;
        }

        const { Pesan: baru, ...meta } = keadaan.Obrolan;
        const berganti = uuidSaatIni.current !== meta.Uuid;
        uuidSaatIni.current = meta.Uuid;
        AturObrolan(meta);
        AturPesan((lama) => {
            const hasil = berganti ? baru : GabungPesan(lama, baru);
            penanda.current = AmbilPenanda(hasil);

            return hasil;
        });
    }, []);

    const kueri = useQuery({
        queryKey: KunciKueri.Obrolan,
        queryFn: async ({ signal }) => {
            const hasil = await AmbilJson<KeadaanObrolanTenant>(
                `/kelola/obrolan${penanda.current === null ? '' : `?setelah=${encodeURIComponent(penanda.current)}`}`,
                signal,
            );
            // Keadaan diterapkan di sini (bukan di efek): pembaruan percakapan terjadi saat jawaban server tiba.
            Terapkan(hasil);

            return hasil;
        },
        refetchInterval: (q) => (q.state.data?.Obrolan ? q.state.data.Layanan.SegarkanDetik * 1000 : false),
        refetchIntervalInBackground: false,
        staleTime: 0,
        gcTime: 0,
    });

    const Kirim = async (isi: string): Promise<boolean> => {
        AturMemproses('kirim');
        AturGalat(null);

        try {
            Terapkan(await KirimJson<KeadaanObrolanTenant>('/kelola/obrolan', { Isi: isi, HalamanAsal: halamanAsal }));
            void kueri.refetch();

            return true;
        } catch (kesalahan) {
            AturGalat(kesalahan instanceof GalatPermintaan ? kesalahan.message : 'Pesan belum terkirim. Coba lagi.');

            return false;
        } finally {
            AturMemproses(null);
        }
    };

    const Akhiri = async () => {
        AturMemproses('akhiri');
        AturGalat(null);

        try {
            Terapkan(await KirimJson<KeadaanObrolanTenant>('/kelola/obrolan/akhiri'));
        } catch (kesalahan) {
            AturGalat(
                kesalahan instanceof GalatPermintaan ? kesalahan.message : 'Obrolan belum bisa diakhiri. Coba lagi.',
            );
        } finally {
            AturMemproses(null);
        }
    };

    const keadaanLayanan = layanan ?? kueri.data?.Layanan ?? null;

    return (
        <section
            aria-label="Obrolan dengan tim dukungan"
            className="flex flex-col gap-3 rounded-panel border border-garis bg-permukaan p-3"
        >
            <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-label font-semibold text-teks-sekunder">Obrolan dengan tim dukungan</h3>
                {keadaanLayanan === null ? null : (
                    <LabelStatus
                        jenis={keadaanLayanan.Buka ? 'sukses' : 'netral'}
                        teks={keadaanLayanan.Buka ? 'Tim online' : 'Tim belum online'}
                    />
                )}
            </div>

            {kueri.isPending ? <p className="text-isi text-teks-sekunder">Memuat obrolan...</p> : null}
            {kueri.isError && layanan === null ? (
                <p role="alert" className="text-isi text-bahaya">
                    Obrolan belum bisa dimuat. Periksa koneksi lalu buka Bantuan lagi.
                </p>
            ) : null}
            {keadaanLayanan === null ? null : (
                <p className="text-keterangan text-teks-sekunder">{keadaanLayanan.Pesan}</p>
            )}
            {galat === null ? null : <Pemberitahuan jenis="bahaya">{galat}</Pemberitahuan>}

            {obrolan === null ? null : (
                <>
                    <p className="text-keterangan text-teks-sekunder">
                        Obrolan {obrolan.Nomor} |{' '}
                        {obrolan.Petugas === null ? 'menunggu petugas' : `dengan ${obrolan.Petugas}`}
                    </p>
                    <DaftarPesanObrolan
                        pesan={pesan}
                        sebagai="Pelapor"
                        className="max-h-72 min-h-24 rounded-panel bg-latar p-2"
                    />
                </>
            )}

            {keadaanLayanan === null ? null : (
                <KotakPesanObrolan
                    maksimal={keadaanLayanan.MaksPanjangPesan}
                    memproses={memproses === 'kirim'}
                    placeholder={obrolan === null ? 'Ceritakan kendala Anda...' : 'Tulis balasan...'}
                    labelKirim={obrolan === null ? 'Mulai obrolan' : 'Kirim pesan'}
                    saatKirim={Kirim}
                />
            )}

            {obrolan === null ? null : (
                <div className="flex flex-wrap items-center gap-3 border-t border-garis pt-3">
                    <Link
                        href={`/kelola/bantuan/${obrolan.Uuid}`}
                        className="text-keterangan font-semibold text-brand underline"
                    >
                        Lihat sebagai tiket
                    </Link>
                    <div className="ml-auto">
                        <Tombol varian="sekunder" onClick={() => void Akhiri()} memproses={memproses === 'akhiri'}>
                            Akhiri obrolan
                        </Tombol>
                    </div>
                </div>
            )}
        </section>
    );
}
