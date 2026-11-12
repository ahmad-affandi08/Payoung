import { useQuery } from '@tanstack/react-query';

import Panel from '@/Komponen/Kelola/Panel';
import { Skeleton } from '@/Komponen/Ui/skeleton';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import type { KalibrasiWajah } from '@/Tipe/Karyawan';

const ALAMAT = '/kelola/karyawan/absensi/kalibrasi-wajah';

/** Batas persentase percobaan ditolak yang dianggap tinggi (karyawan sah mungkin ikut tertolak). */
const BATAS_DITOLAK_TINGGI = 15;

async function AmbilKalibrasi(sinyal: AbortSignal): Promise<KalibrasiWajah> {
    const respons = await fetch(ALAMAT, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal: sinyal,
    });

    if (!respons.ok) {
        throw new Error(`Kalibrasi wajah gagal dimuat (${String(respons.status)})`);
    }

    return (await respons.json()) as KalibrasiWajah;
}

/** Angka desimal dari server ("0.60") ke tampilan Indonesia ("0,60"); "–" bila kosong. */
export function TulisDesimal(nilai: string | null): string {
    return nilai === null ? '–' : nilai.replace('.', ',');
}

export function SusunSaranKalibrasi(data: KalibrasiWajah): { jenis: 'info' | 'peringatan' | 'sukses'; teks: string } {
    const total = data.JumlahDiterima + data.JumlahDitolak;

    if (!data.CukupData) {
        return {
            jenis: 'info',
            teks: `Baru ${String(total)} percobaan absen dari HP dalam ${String(data.Hari)} hari. Kumpulkan minimal 50 sebelum menilai ambang.`,
        };
    }

    if (Number(data.PersenDitolak ?? '0') > BATAS_DITOLAK_TINGGI) {
        return {
            jenis: 'peringatan',
            teks: `${TulisDesimal(data.PersenDitolak)}% percobaan ditolak karena wajah tidak cocok. Bila yang ditolak ternyata karyawan sendiri (cahaya redup, kacamata, masker), atur ulang wajahnya agar mendaftar di tempat terang, atau minta tim teknis menurunkan ambang sedikit.`,
        };
    }

    return {
        jenis: 'sukses',
        teks: 'Sebaran wajar: hampir semua absen diterima dan penolakan sedikit. Ambang tidak perlu diubah.',
    };
}

/**
 * F-18 bagian 4 (D-37, K37): bahan kalibrasi ambang kemiripan wajah absensi web (`karyawan.kelola`). Sebaran
 * kemiripan absen diterima & percobaan ditolak per kelompok 0,05 selama 30 hari, ditambah ringkasan dan saran.
 */
export default function PanelKalibrasiWajah() {
    const kueri = useQuery({
        queryKey: KunciKueri.Karyawan.KalibrasiWajah(),
        queryFn: ({ signal }) => AmbilKalibrasi(signal),
    });
    const data = kueri.data;

    return (
        <Panel
            judul="Kalibrasi pencocokan wajah"
            keterangan="Seberapa mirip wajah saat absen dengan wajah terdaftar, 30 hari terakhir. Untuk menilai ambang terlalu longgar atau ketat."
        >
            {kueri.isPending ? (
                <div role="status" aria-label="Memuat kalibrasi wajah" className="flex flex-col gap-2">
                    <Skeleton className="h-4 w-48" />
                    <Skeleton className="h-24 w-full" />
                </div>
            ) : null}
            {kueri.isError ? (
                <Pemberitahuan jenis="bahaya">Data kalibrasi gagal dimuat. Muat ulang halaman.</Pemberitahuan>
            ) : null}
            {data ? <IsiKalibrasi data={data} /> : null}
        </Panel>
    );
}

function IsiKalibrasi({ data }: { data: KalibrasiWajah }) {
    const saran = SusunSaranKalibrasi(data);
    const terbesar = Math.max(1, ...data.Kelompok.map((k) => Math.max(k.Diterima, k.Ditolak)));
    const ringkasan: [string, string][] = [
        ['Ambang sekarang', TulisDesimal(data.Ambang)],
        ['Absen diterima', String(data.JumlahDiterima)],
        ['Percobaan ditolak', `${String(data.JumlahDitolak)} (${TulisDesimal(data.PersenDitolak)}%)`],
        ['Terendah diterima', TulisDesimal(data.TerendahDiterima)],
        ['Median diterima', TulisDesimal(data.MedianDiterima)],
        ['Tertinggi ditolak', TulisDesimal(data.TertinggiDitolak)],
    ];

    return (
        <div className="flex flex-col gap-4">
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                {ringkasan.map(([label, nilai]) => (
                    <div key={label} className="flex flex-col gap-0.5">
                        <dt className="text-keterangan text-teks-sekunder">{label}</dt>
                        <dd className="text-isi font-semibold text-teks-utama tabular-nums">{nilai}</dd>
                    </div>
                ))}
            </dl>
            <Pemberitahuan jenis={saran.jenis}>{saran.teks}</Pemberitahuan>
            <ul className="flex flex-col gap-1" aria-label="Sebaran kemiripan wajah per kelompok">
                {data.Kelompok.map((k) => {
                    const memuatAmbang = k.Dari <= data.Ambang && data.Ambang < k.Sampai;

                    return (
                        <li
                            key={k.Dari}
                            className="grid grid-cols-[6.5rem_1fr] items-center gap-2 text-keterangan sm:grid-cols-[7.5rem_1fr]"
                        >
                            <span className={memuatAmbang ? 'font-semibold text-teks-utama' : 'text-teks-sekunder'}>
                                {k.Dari === '0.00'
                                    ? `< ${TulisDesimal(k.Sampai)}`
                                    : `${TulisDesimal(k.Dari)}–${TulisDesimal(k.Sampai)}`}
                                {memuatAmbang ? ' | ambang' : ''}
                            </span>
                            <span className="flex flex-col gap-0.5">
                                <BarisBatang
                                    jumlah={k.Diterima}
                                    terbesar={terbesar}
                                    kelas="bg-sukses"
                                    label="diterima"
                                />
                                <BarisBatang jumlah={k.Ditolak} terbesar={terbesar} kelas="bg-bahaya" label="ditolak" />
                            </span>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

function BarisBatang({
    jumlah,
    terbesar,
    kelas,
    label,
}: {
    jumlah: number;
    terbesar: number;
    kelas: string;
    label: string;
}) {
    return (
        <span className="flex items-center gap-2">
            <span className="h-2 min-w-0 flex-1 rounded-full bg-permukaan-redup" aria-hidden="true">
                <span
                    className={`block h-2 rounded-full ${kelas}`}
                    style={{ width: `${String((jumlah / terbesar) * 100)}%` }}
                />
            </span>
            <span className="w-20 shrink-0 tabular-nums text-teks-sekunder">
                {jumlah} {label}
            </span>
        </span>
    );
}
