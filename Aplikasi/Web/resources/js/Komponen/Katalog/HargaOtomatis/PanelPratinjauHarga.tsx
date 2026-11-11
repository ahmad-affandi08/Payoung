import { useEffect, useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import { cn } from '@/Komponen/Ui/utils';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatPerubahanPersen } from '@/Pustaka/HargaOtomatis';
import { KirimJson } from '@/Pustaka/PermintaanJson';
import type {
    ContohHargaOtomatis,
    MasukanHargaOtomatis,
    PratinjauHargaOtomatis,
    StatusContohHargaOtomatis,
} from '@/Tipe/Katalog';

const Alamat = '/kelola/produk/harga-otomatis/pratinjau';

/** Jeda sesudah ketikan terakhir sebelum server dimintai pratinjau, supaya mengetik tidak membanjiri server. */
const JedaMs = 500;

type Keadaan = { status: 'diam' | 'memuat' | 'siap' | 'galat'; data: PratinjauHargaOtomatis | null; pesan: string };

const Lencana: Record<StatusContohHargaOtomatis, { Teks: string; Kelas: string }> = {
    Berubah: { Teks: '', Kelas: 'text-teks-utama' },
    Menunggu: { Teks: 'Menunggu persetujuan', Kelas: 'bg-peringatan-lembut text-peringatan' },
    Tetap: { Teks: 'Tetap', Kelas: 'text-teks-sekunder' },
    Ditahan: { Teks: 'Tetap, harga beli turun', Kelas: 'text-teks-sekunder' },
};

type PropsPanelPratinjauHarga = {
    /** Isian layar yang sudah lengkap, atau null bila belum bisa dihitung. */
    masukan: MasukanHargaOtomatis | null;
    /** Saklar utama sudah menyala di server. */
    menyala: boolean;
};

function BarisContoh({ contoh }: { contoh: ContohHargaOtomatis }) {
    const lencana = Lencana[contoh.Status];

    return (
        <li className="flex flex-col gap-0.5 py-2.5">
            <span className="flex items-start justify-between gap-2">
                <span className="min-w-0 break-words text-isi font-semibold text-teks-utama">{contoh.Nama}</span>
                <span
                    className={cn(
                        'shrink-0 rounded-kontrol text-label font-semibold tabular-nums',
                        lencana.Kelas,
                        contoh.Status === 'Menunggu' ? 'px-2 py-0.5' : '',
                    )}
                >
                    {contoh.Status === 'Berubah' ? FormatPerubahanPersen(contoh.PerubahanPersen) : lencana.Teks}
                </span>
            </span>
            <span className="text-keterangan tabular-nums text-teks-sekunder">
                Beli {FormatRupiah(contoh.Modal)} | Jual {FormatRupiah(contoh.HargaLama)} menjadi{' '}
                <span className="font-semibold text-teks-utama">{FormatRupiah(contoh.HargaBaru)}</span>
            </span>
        </li>
    );
}

function Angka({ nilai, label, peringatan = false }: { nilai: number; label: string; peringatan?: boolean }) {
    return (
        <div
            className={cn('rounded-panel p-3', peringatan && nilai > 0 ? 'bg-peringatan-lembut' : 'bg-permukaan-redup')}
        >
            <p
                className={cn(
                    'text-judul font-semibold tabular-nums',
                    peringatan && nilai > 0 ? 'text-peringatan' : 'text-teks-utama',
                )}
            >
                {nilai.toLocaleString('id-ID')}
            </p>
            <p className="text-keterangan text-teks-sekunder">{label}</p>
        </div>
    );
}

/**
 * Pratinjau pengaturan Harga otomatis yang belum disimpan (D-268): berapa harga jual akan berubah, menunggu persetujuan,
 * atau tetap, dengan beberapa contoh produk. Dihitung server dari harga beli terakhir dengan aturan yang sama dengan
 * penerapan sungguhan, dan tidak menyimpan apa pun. Tiap ketikan menunggu jeda sebentar, dan hasil lama tetap tampil
 * (redup) sampai hasil baru datang.
 */
export default function PanelPratinjauHarga({ masukan, menyala }: PropsPanelPratinjauHarga) {
    const [keadaan, AturKeadaan] = useState<Keadaan>({ status: 'diam', data: null, pesan: '' });
    const [ulang, AturUlang] = useState(0);
    const kunci = masukan === null ? null : JSON.stringify(masukan);

    useEffect(() => {
        if (kunci === null) {
            return;
        }

        let dibatalkan = false;
        const waktu = setTimeout(() => {
            AturKeadaan((lama) => ({ ...lama, status: 'memuat' }));

            KirimJson<{ Data: PratinjauHargaOtomatis }>(Alamat, JSON.parse(kunci))
                .then((hasil) => {
                    if (!dibatalkan) {
                        AturKeadaan({ status: 'siap', data: hasil.Data, pesan: '' });
                    }
                })
                .catch((galat: unknown) => {
                    if (!dibatalkan) {
                        AturKeadaan((lama) => ({
                            status: 'galat',
                            data: lama.data,
                            pesan: galat instanceof Error ? galat.message : 'Pratinjau belum bisa dibuat. Coba lagi.',
                        }));
                    }
                });
        }, JedaMs);

        return () => {
            dibatalkan = true;
            clearTimeout(waktu);
        };
    }, [kunci, ulang]);

    const { status, data, pesan } = keadaan;
    const ringkasan = data?.Ringkasan;
    const dihitung = ringkasan === undefined ? 0 : ringkasan.Berubah + ringkasan.Menunggu + ringkasan.Tetap;

    return (
        <Panel
            judul="Pratinjau, kalau disimpan sekarang"
            keterangan={
                menyala
                    ? 'Hanya hitungan contoh dari harga beli terakhir. Belum ada harga yang diubah.'
                    : 'Harga otomatis belum menyala. Ini yang akan terjadi kalau Anda menyalakannya. Belum ada harga yang diubah.'
            }
        >
            <div
                aria-busy={status === 'memuat'}
                aria-live="polite"
                className={cn('flex flex-col gap-3', status === 'memuat' && data !== null ? 'opacity-60' : '')}
            >
                {masukan === null ? (
                    <p className="text-isi text-teks-sekunder">
                        Isi untung dan batas persetujuan dengan angka, lalu pratinjau muncul di sini.
                    </p>
                ) : null}

                {masukan !== null && data === null && status !== 'galat' ? (
                    <p className="text-isi text-teks-sekunder">Menghitung dari harga beli terakhir…</p>
                ) : null}

                {status === 'galat' ? (
                    <div role="alert" className="flex flex-col items-start gap-2">
                        <p className="text-isi font-semibold text-bahaya">{pesan}</p>
                        <Tombol varian="sekunder" onClick={() => AturUlang((n) => n + 1)}>
                            Coba lagi
                        </Tombol>
                    </div>
                ) : null}

                {ringkasan !== undefined && data !== null && masukan !== null ? (
                    dihitung === 0 ? (
                        <p className="text-isi text-teks-sekunder">
                            {ringkasan.TanpaHargaBeli > 0
                                ? 'Belum ada produk yang punya harga beli. Pratinjau muncul setelah ada penerimaan barang atau belanja stok.'
                                : 'Belum ada produk berstok yang bisa dihitung.'}
                        </p>
                    ) : (
                        <>
                            <div className="grid grid-cols-3 gap-2">
                                <Angka nilai={ringkasan.Berubah} label="harga akan berubah" />
                                <Angka nilai={ringkasan.Menunggu} label="menunggu persetujuan" peringatan />
                                <Angka nilai={ringkasan.Tetap} label="tetap sama" />
                            </div>
                            {ringkasan.TanpaHargaBeli > 0 ? (
                                <p className="text-keterangan text-teks-sekunder">
                                    {ringkasan.TanpaHargaBeli.toLocaleString('id-ID')} produk belum pernah dibeli, jadi
                                    belum dihitung.
                                </p>
                            ) : null}
                            <ul aria-label="Contoh perubahan harga" className="flex flex-col divide-y divide-garis">
                                {data.Contoh.map((contoh) => (
                                    <BarisContoh key={contoh.Uuid} contoh={contoh} />
                                ))}
                            </ul>
                            <p className="text-keterangan text-teks-sekunder">
                                Contoh yang tampil adalah produk dengan perubahan terbesar.
                            </p>
                        </>
                    )
                ) : null}
            </div>
        </Panel>
    );
}
