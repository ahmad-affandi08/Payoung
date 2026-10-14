import { useQuery } from '@tanstack/react-query';

import Panel from '@/Komponen/Kelola/Panel';
import { TautanUnduh } from '@/Komponen/Laporan/NavigasiTab';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import { BuatQueryLaporan } from '@/Pustaka/Laporan';
import type { RingkasanFakturPajak } from '@/Tipe/Laporan';

const alamatRingkas = '/kelola/laporan/pajak/faktur-keluaran';

async function AmbilRingkasan(query: Record<string, string>, sinyal: AbortSignal): Promise<RingkasanFakturPajak> {
    const respons = await fetch(`${alamatRingkas}?${BuatQueryLaporan(query)}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal: sinyal,
    });

    if (!respons.ok) {
        throw new Error(`Ringkasan Faktur Pajak gagal dimuat (${String(respons.status)})`);
    }

    return (await respons.json()) as RingkasanFakturPajak;
}

/**
 * Panel ekspor Faktur Pajak Keluaran ke Coretax (PRD v3.12): memeriksa faktur penjualan grosir pada periode saring,
 * menjelaskan yang belum bisa diekspor, lalu menyediakan unduhan XML impor massal untuk faktur yang lolos.
 */
export default function PanelFakturPajakCoretax({ query }: { query: Record<string, string> }) {
    const kueri = useQuery({
        queryKey: KunciKueri.FakturPajakCoretax(query),
        queryFn: ({ signal }) => AmbilRingkasan(query, signal),
        staleTime: 30_000,
    });
    const data = kueri.data;

    return (
        <Panel
            judul="Faktur Pajak Keluaran (Coretax)"
            keterangan="Dari faktur penjualan grosir dan Faktur Pajak per transaksi kasir pada periode ini. Unduh XML lalu impor di Coretax; nomor Faktur Pajak diisi Coretax, bukan Payoung."
            aksi={
                data?.BisaDiekspor ? (
                    <TautanUnduh
                        alamat={`${alamatRingkas}/ekspor`}
                        query={query}
                        label={`Unduh XML (${String(data.JumlahSiap)} faktur)`}
                    />
                ) : null
            }
        >
            {kueri.isPending ? (
                <p role="status" className="text-isi text-teks-sekunder">
                    Memeriksa faktur…
                </p>
            ) : null}

            {kueri.isError ? (
                <Pemberitahuan jenis="bahaya">Ringkasan Faktur Pajak gagal dimuat. Muat ulang halaman.</Pemberitahuan>
            ) : null}

            {data ? (
                <>
                    {data.MasalahUmum.map((m) => (
                        <Pemberitahuan key={m} jenis="bahaya" judul="Belum bisa diekspor">
                            {m}
                        </Pemberitahuan>
                    ))}

                    <p className="text-isi text-teks-utama">
                        {data.JumlahDiperiksa === 0
                            ? 'Tidak ada faktur penjualan grosir atau Faktur Pajak kasir pada periode ini.'
                            : `${String(data.JumlahSiap)} dari ${String(data.JumlahDiperiksa)} faktur siap diekspor. DPP ${FormatRupiah(data.TotalDpp)}, PPN ${FormatRupiah(data.TotalPpn)}.`}
                    </p>

                    {data.MasalahFaktur.length > 0 ? (
                        <Pemberitahuan jenis="peringatan" judul="Faktur yang dilewati">
                            <ul className="flex list-disc flex-col gap-1 pl-5">
                                {data.MasalahFaktur.map((f) => (
                                    <li key={f.Nomor}>
                                        <span className="font-mono">{f.Nomor}</span>: {f.Alasan.join(' ')}
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

                    {data.Selisih.length > 0 ? (
                        <Pemberitahuan jenis="info" judul="Selisih pembulatan dengan faktur internal">
                            Coretax membulatkan DPP dan PPN per baris, jadi angkanya bisa berbeda beberapa sen dari
                            faktur di Payoung (yang dibulatkan per dokumen). Pembukuan tidak diubah; cocokkan saat
                            rekonsiliasi SPT Masa PPN.
                            <ul className="mt-1 flex list-disc flex-col gap-1 pl-5">
                                {data.Selisih.map((s) => (
                                    <li key={s.Nomor}>
                                        <span className="font-mono">{s.Nomor}</span>: DPP {FormatRupiah(s.SelisihDpp)},
                                        PPN {FormatRupiah(s.SelisihPpn)}
                                    </li>
                                ))}
                            </ul>
                        </Pemberitahuan>
                    ) : null}

                    <p className="text-keterangan text-teks-sekunder">
                        Format mengikuti contoh berkas impor DJP dan belum dicocokkan dengan validasi resmi Coretax:
                        coba impor satu faktur dulu sebelum mengunggah seluruh periode.
                    </p>
                </>
            ) : null}
        </Panel>
    );
}
