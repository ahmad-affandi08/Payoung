import { router } from '@inertiajs/react';
import { useState } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';

export type AddonDimiliki = {
    Uuid: string;
    Kode: string;
    Nama: string;
    Fitur: string | null;
    HargaBulanan: string;
    Jumlah: number;
    MulaiPada: string;
    SelesaiPada: string;
    Aktif: boolean;
    Berhenti: boolean;
};

export type AddonTersedia = {
    Kode: string;
    Nama: string;
    Fitur: string | null;
    HargaBulanan: string;
    BisaJumlah: boolean;
    HargaProrata: string | null;
};

export type DataAddonLangganan = {
    /** Alasan add-on belum bisa dibeli mandiri; null = boleh. */
    Alasan: string | null;
    Dimiliki: AddonDimiliki[];
    Tersedia: AddonTersedia[];
};

/**
 * D-49: add-on di halaman Langganan. Add-on yang dimiliki (aktif sampai kapan, diperpanjang otomatis atau berhenti)
 * dan add-on yang bisa dibeli. Beli = tagihan prorata sampai akhir periode; aktif setelah dibayar. Berhenti = tetap
 * aktif sampai akhir periode, tidak ditagih lagi. Harga dan jumlah tagihan dihitung server.
 */
export default function BagianAddon({ addon }: { addon: DataAddonLangganan }) {
    const [memproses, AturMemproses] = useState<string | null>(null);
    const [jumlah, AturJumlah] = useState<Record<string, string>>({});

    const Kirim = (kunci: string, alamat: string, data: Record<string, string | number> = {}) =>
        router.post(alamat, data, {
            preserveScroll: true,
            onStart: () => AturMemproses(kunci),
            onFinish: () => AturMemproses(null),
        });

    if (addon.Dimiliki.length === 0 && addon.Tersedia.length === 0) {
        return null;
    }

    return (
        <Panel
            judul="Add-on"
            keterangan="Tambahan fitur atau kapasitas tanpa ganti paket. Dibayar prorata sampai akhir periode, lalu ikut tagihan perpanjangan."
        >
            {addon.Dimiliki.length > 0 ? (
                <ul className="flex flex-col divide-y divide-garis" aria-label="Add-on Anda">
                    {addon.Dimiliki.map((baris) => (
                        <li key={baris.Uuid} className="flex flex-wrap items-center justify-between gap-3 py-3">
                            <div className="min-w-0">
                                <p className="font-semibold text-teks-utama">
                                    {baris.Nama}
                                    {baris.Jumlah > 1 ? ` × ${baris.Jumlah}` : ''}
                                </p>
                                <p className="text-keterangan text-teks-sekunder">
                                    {baris.Aktif
                                        ? baris.Berhenti
                                            ? `Aktif sampai ${FormatTanggal(baris.SelesaiPada)}, tidak diperpanjang`
                                            : `Aktif sampai ${FormatTanggal(baris.SelesaiPada)}, diperpanjang otomatis`
                                        : `Berakhir ${FormatTanggal(baris.SelesaiPada)}`}
                                    {' | '}
                                    {FormatRupiah(baris.HargaBulanan)}/bulan
                                </p>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <LabelStatus
                                    jenis={baris.Aktif ? (baris.Berhenti ? 'peringatan' : 'sukses') : 'netral'}
                                    teks={baris.Aktif ? (baris.Berhenti ? 'Berhenti' : 'Aktif') : 'Berakhir'}
                                />
                                {baris.Aktif ? (
                                    baris.Berhenti ? (
                                        <Tombol
                                            varian="sekunder"
                                            memproses={memproses === baris.Uuid}
                                            onClick={() =>
                                                Kirim(baris.Uuid, `/kelola/langganan/addon/${baris.Uuid}/lanjut`)
                                            }
                                        >
                                            Lanjutkan langganan
                                        </Tombol>
                                    ) : (
                                        <Tombol
                                            varian="sekunder"
                                            memproses={memproses === baris.Uuid}
                                            onClick={() =>
                                                Kirim(baris.Uuid, `/kelola/langganan/addon/${baris.Uuid}/berhenti`)
                                            }
                                        >
                                            Berhenti berlangganan
                                        </Tombol>
                                    )
                                ) : null}
                            </div>
                        </li>
                    ))}
                </ul>
            ) : null}
            {addon.Tersedia.length > 0 ? (
                <>
                    {addon.Alasan ? (
                        <Pemberitahuan jenis="info" judul="Add-on belum bisa dibeli sekarang">
                            {addon.Alasan}
                        </Pemberitahuan>
                    ) : null}
                    <ul className="flex flex-col divide-y divide-garis" aria-label="Add-on yang bisa dibeli">
                        {addon.Tersedia.map((baris) => (
                            <li key={baris.Kode} className="flex flex-wrap items-end justify-between gap-3 py-3">
                                <div className="min-w-0">
                                    <p className="font-semibold text-teks-utama">{baris.Nama}</p>
                                    <p className="text-keterangan text-teks-sekunder">
                                        {FormatRupiah(baris.HargaBulanan)}/bulan
                                        {baris.Fitur ? ` | membuka ${baris.Fitur}` : ''}
                                        {baris.HargaProrata
                                            ? ` | tagihan pertama ${FormatRupiah(baris.HargaProrata)} (prorata, belum termasuk pajak)`
                                            : ''}
                                    </p>
                                </div>
                                <div className="flex flex-wrap items-end gap-2">
                                    {baris.BisaJumlah ? (
                                        <div className="w-24">
                                            <BidangTeks
                                                label="Jumlah"
                                                nilai={jumlah[baris.Kode] ?? '1'}
                                                saatBerubah={(nilai) =>
                                                    AturJumlah({ ...jumlah, [baris.Kode]: nilai.replace(/\D/g, '') })
                                                }
                                                inputMode="numeric"
                                                maxLength={2}
                                            />
                                        </div>
                                    ) : null}
                                    <Tombol
                                        disabled={addon.Alasan !== null}
                                        memproses={memproses === baris.Kode}
                                        onClick={() =>
                                            Kirim(baris.Kode, '/kelola/langganan/addon/beli', {
                                                KodeAddon: baris.Kode,
                                                ...(baris.BisaJumlah
                                                    ? { Jumlah: Number(jumlah[baris.Kode] ?? '1') || 1 }
                                                    : {}),
                                            })
                                        }
                                    >
                                        Beli add-on
                                    </Tombol>
                                </div>
                            </li>
                        ))}
                    </ul>
                </>
            ) : null}
        </Panel>
    );
}
