import { useQuery } from '@tanstack/react-query';

import Panel from '@/Komponen/Kelola/Panel';
import { TautanUnduh } from '@/Komponen/Laporan/NavigasiTab';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import { BuatQueryLaporan } from '@/Pustaka/Laporan';
import type { RingkasanNotaReturPajak } from '@/Tipe/Laporan';

const alamatRingkas = '/kelola/laporan/pajak/nota-retur';

async function AmbilRingkasan(query: Record<string, string>, sinyal: AbortSignal): Promise<RingkasanNotaReturPajak> {
    const respons = await fetch(`${alamatRingkas}?${BuatQueryLaporan(query)}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal: sinyal,
    });

    if (!respons.ok) {
        throw new Error(`Rekap nota retur gagal dimuat (${String(respons.status)})`);
    }

    return (await respons.json()) as RingkasanNotaReturPajak;
}

/**
 * Panel rekap nota retur pajak (PRD v4.08): retur grosir atas penyerahan yang sudah dibuatkan Faktur Pajak, dengan
 * nomor Faktur Pajak asal dan DPP/PPN per barang untuk dicatat di Coretax. Unduhannya CSV, bukan berkas impor.
 */
export default function PanelNotaReturPajak({ query }: { query: Record<string, string> }) {
    const kueri = useQuery({
        queryKey: KunciKueri.NotaReturPajak(query),
        queryFn: ({ signal }) => AmbilRingkasan(query, signal),
        staleTime: 30_000,
    });
    const data = kueri.data;

    return (
        <Panel
            judul="Nota retur pajak (Coretax)"
            keterangan="Dari retur grosir pada periode ini atas faktur yang sudah punya nomor Faktur Pajak. Bila pembeli PKP, pembeli yang membuat retur di Coretax dan Anda mengonfirmasinya. Bila bukan PKP, Anda yang mencatatnya di menu Retur Pajak Keluaran."
            aksi={
                data?.BisaDiekspor ? (
                    <TautanUnduh
                        alamat={`${alamatRingkas}/ekspor`}
                        query={query}
                        label={`Unduh CSV (${String(data.JumlahSiap)} retur)`}
                    />
                ) : null
            }
        >
            {kueri.isPending ? (
                <p role="status" className="text-isi text-teks-sekunder">
                    Memeriksa retur…
                </p>
            ) : null}

            {kueri.isError ? (
                <Pemberitahuan jenis="bahaya">Rekap nota retur gagal dimuat. Muat ulang halaman.</Pemberitahuan>
            ) : null}

            {data ? (
                <>
                    {data.MasalahUmum.map((m) => (
                        <Pemberitahuan key={m} jenis="bahaya" judul="Belum bisa direkap">
                            {m}
                        </Pemberitahuan>
                    ))}

                    <p className="text-isi text-teks-utama">
                        {data.JumlahDiperiksa === 0
                            ? 'Tidak ada retur grosir ber-PPN pada periode ini.'
                            : `${String(data.JumlahSiap)} dari ${String(data.JumlahDiperiksa)} retur siap. DPP ${FormatRupiah(data.TotalDpp)}, PPN dikurangkan ${FormatRupiah(data.TotalPpn)}.`}
                    </p>

                    {data.Retur.length > 0 ? (
                        <ul className="flex flex-col gap-1 text-isi text-teks-utama">
                            {data.Retur.map((r) => (
                                <li key={r.Nomor} className="flex flex-wrap gap-x-2">
                                    <span className="font-mono">{r.Nomor}</span>
                                    <span className="text-teks-sekunder">
                                        atas Faktur Pajak <span className="font-mono">{r.NomorFakturPajak}</span> |{' '}
                                        {r.Pembeli} | PPN {FormatRupiah(r.Ppn)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    ) : null}

                    {data.MasalahRetur.length > 0 ? (
                        <Pemberitahuan jenis="peringatan" judul="Retur yang belum bisa direkap">
                            <ul className="flex list-disc flex-col gap-1 pl-5">
                                {data.MasalahRetur.map((r) => (
                                    <li key={r.Nomor}>
                                        <span className="font-mono">{r.Nomor}</span>: {r.Alasan.join(' ')}
                                    </li>
                                ))}
                            </ul>
                        </Pemberitahuan>
                    ) : null}

                    {data.Peringatan.length > 0 ? (
                        <Pemberitahuan jenis="info" judul="Sebaiknya dilengkapi">
                            <ul className="flex list-disc flex-col gap-1 pl-5">
                                {data.Peringatan.map((p) => (
                                    <li key={p}>{p}</li>
                                ))}
                            </ul>
                        </Pemberitahuan>
                    ) : null}
                </>
            ) : null}
        </Panel>
    );
}
