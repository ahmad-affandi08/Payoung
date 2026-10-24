import { useForm, usePage } from '@inertiajs/react';
import { useMemo, useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import TabKatalog from '@/Komponen/Pengelola/TabKatalog';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';
import { IzinPengelola, PunyaIzin, type PropsBersamaPengelola } from '@/Tipe/Pengelola';

type Addon = {
    Kode: string;
    Nama: string;
    HargaBulanan: string;
    KunciFitur: string | null;
    TambahanBatas: Record<string, number>;
    Status: 'Aktif' | 'Diarsipkan';
};

type PropsAddon = { Addon: Addon[]; Fitur: { Kunci: string; Nama: string }[]; KolomBatas: string[] };

const labelBatas: Record<string, string> = {
    BatasOutlet: 'Outlet',
    BatasPerangkatPerOutlet: 'Perangkat per outlet',
    BatasPengguna: 'Pengguna',
    BatasSku: 'SKU',
    KuotaPesanWaBulanan: 'Pesan WA per bulan',
    BatasPenyimpananMb: 'Penyimpanan berkas (MB)',
    KuotaTransaksiBulanan: 'Transaksi penjualan per bulan',
    KuotaTransaksiHarian: 'Transaksi penjualan per hari',
};

function BuatKolom(namaFitur: Map<string, string>): KolomTabel<Addon>[] {
    return [
        {
            id: 'Nama',
            accessorKey: 'Nama',
            header: 'Add-on',
            meta: { label: 'Add-on', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: addon } }) => (
                <>
                    <span className="block font-semibold text-teks-utama">{addon.Nama}</span>
                    <span className="block font-mono text-keterangan font-normal text-teks-sekunder">{addon.Kode}</span>
                </>
            ),
        },
        {
            id: 'HargaBulanan',
            accessorKey: 'HargaBulanan',
            header: 'Harga/bulan',
            meta: { label: 'Harga/bulan', angka: true, prioritas: 'penting' },
            cell: ({ row }) => FormatRupiah(row.original.HargaBulanan),
        },
        {
            id: 'Memberi',
            header: 'Memberi',
            enableSorting: false,
            meta: { label: 'Memberi', prioritas: 'rendah', kelasSel: 'text-keterangan text-teks-sekunder' },
            cell: ({ row: { original: addon } }) => (
                <>
                    {addon.KunciFitur ? (
                        <span className="block">Fitur: {namaFitur.get(addon.KunciFitur) ?? addon.KunciFitur}</span>
                    ) : null}
                    {Object.entries(addon.TambahanBatas).map(([kolom, nilai]) => (
                        <span key={kolom} className="block">
                            +{nilai} {labelBatas[kolom] ?? kolom}
                        </span>
                    ))}
                </>
            ),
        },
        {
            id: 'Status',
            accessorKey: 'Status',
            header: 'Status',
            meta: { label: 'Status', prioritas: 'penting' },
            cell: ({ row }) => (
                <LabelStatus jenis={row.original.Status === 'Aktif' ? 'sukses' : 'netral'} teks={row.original.Status} />
            ),
        },
    ];
}

/** Add-on langganan: fitur dan/atau tambahan batas yang dibeli terpisah (P-04), TabelData D-16. */
export default function HalamanAddon({ Addon, Fitur, KolomBatas }: PropsAddon) {
    const { props } = usePage<PropsBersamaPengelola>();
    const bolehKelola = PunyaIzin(props.Pengguna, IzinPengelola.KatalogAddonKelola);
    const [sunting, AturSunting] = useState<Addon | 'baru' | null>(null);
    const kolom = useMemo(() => BuatKolom(new Map(Fitur.map((fitur) => [fitur.Kunci, fitur.Nama]))), [Fitur]);

    return (
        <TataLetakPengelola judul="Katalog">
            <TabKatalog />
            {sunting !== null ? (
                <FormAddon
                    key={sunting === 'baru' ? 'baru' : sunting.Kode}
                    addon={sunting === 'baru' ? null : sunting}
                    fitur={Fitur}
                    kolomBatas={KolomBatas}
                    saatSelesai={() => AturSunting(null)}
                />
            ) : null}
            <AksiHalaman>
                {bolehKelola && sunting === null ? (
                    <Tombol onClick={() => AturSunting('baru')}>Tambah add-on</Tombol>
                ) : null}
            </AksiHalaman>
            <TabelData
                id="pengelola-katalog-addon"
                label="Daftar add-on"
                kolom={kolom}
                sumber={{ mode: 'lokal', data: Addon }}
                ambilIdBaris={(addon) => addon.Kode}
                urutBawaan="Nama"
                cari="Cari nama atau kode add-on"
                saring={[
                    {
                        id: 'Status',
                        label: 'Status',
                        jenis: 'pilihanBanyak',
                        opsi: [
                            { nilai: 'Aktif', label: 'Aktif' },
                            { nilai: 'Diarsipkan', label: 'Diarsipkan' },
                        ],
                    },
                ]}
                {...(bolehKelola
                    ? {
                          aksiBaris: (addon: Addon) => (
                              <DropdownMenuItem onSelect={() => AturSunting(addon)}>Ubah add-on</DropdownMenuItem>
                          ),
                      }
                    : {})}
                kosong={{
                    ilustrasi: true,
                    judul: 'Belum ada add-on. Tambahkan add-on seperti outlet tambahan, self-order QR, atau kuota WhatsApp.',
                }}
            />
        </TataLetakPengelola>
    );
}

type PropsFormAddon = {
    addon: Addon | null;
    fitur: { Kunci: string; Nama: string }[];
    kolomBatas: string[];
    saatSelesai: () => void;
};

function FormAddon({ addon, fitur, kolomBatas, saatSelesai }: PropsFormAddon) {
    const formulir = useForm<{
        Kode: string;
        Nama: string;
        HargaBulanan: string;
        KunciFitur: string;
        TambahanBatas: Record<string, string>;
        Status: string;
    }>({
        Kode: addon?.Kode ?? '',
        Nama: addon?.Nama ?? '',
        HargaBulanan: addon?.HargaBulanan.replace(/\.00$/, '') ?? '',
        KunciFitur: addon?.KunciFitur ?? '',
        TambahanBatas: Object.fromEntries(
            kolomBatas.map((kolom) => [kolom, addon?.TambahanBatas[kolom] ? String(addon.TambahanBatas[kolom]) : '']),
        ),
        Status: addon?.Status ?? 'Aktif',
    });
    const galat = formulir.errors as Record<string, string | undefined>;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        const opsi = { preserveScroll: true, onSuccess: saatSelesai };

        if (addon === null) {
            formulir.post('/katalog/add-on', opsi);
        } else {
            formulir.put(`/katalog/add-on/${encodeURIComponent(addon.Kode)}`, opsi);
        }
    };

    return (
        <DialogFormulir
            judul={addon === null ? 'Tambah add-on' : `Ubah ${addon.Nama}`}
            saatTutup={saatSelesai}
            lebar="lebar"
            galatUmum={galat.Umum}
        >
            <form onSubmit={Kirim} className="grid gap-4 sm:grid-cols-2" noValidate>
                <BidangTeks
                    label="Kode"
                    kode
                    keterangan="Huruf besar, misal OUTLET_TAMBAHAN. Tidak bisa diubah."
                    nilai={formulir.data.Kode}
                    saatBerubah={(nilai) => formulir.setData('Kode', nilai.toUpperCase())}
                    galat={formulir.errors.Kode}
                    required
                    disabled={addon !== null}
                />
                <BidangTeks
                    label="Nama"
                    nilai={formulir.data.Nama}
                    saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                    galat={formulir.errors.Nama}
                    required
                />
                <BidangTeks
                    label="Harga per bulan (Rp)"
                    inputMode="decimal"
                    keterangan="Berlaku untuk tagihan berikutnya."
                    nilai={formulir.data.HargaBulanan}
                    saatBerubah={(nilai) => formulir.setData('HargaBulanan', nilai)}
                    galat={formulir.errors.HargaBulanan}
                    required
                />
                <BidangPilihan
                    label="Fitur yang diberikan"
                    nilai={formulir.data.KunciFitur}
                    kosong="Tidak ada (hanya tambahan batas)"
                    opsi={fitur.map((item) => ({ Nilai: item.Kunci, Label: item.Nama }))}
                    saatBerubah={(nilai) => formulir.setData('KunciFitur', nilai)}
                    galat={formulir.errors.KunciFitur}
                />
                <fieldset className="grid gap-3 sm:col-span-2 sm:grid-cols-3">
                    <legend className="mb-2 text-label font-semibold text-teks-utama">
                        Tambahan batas per unit (kosongkan bila tidak ada)
                    </legend>
                    {kolomBatas.map((kolom) => (
                        <BidangTeks
                            key={kolom}
                            label={labelBatas[kolom] ?? kolom}
                            inputMode="numeric"
                            nilai={formulir.data.TambahanBatas[kolom] ?? ''}
                            saatBerubah={(nilai) =>
                                formulir.setData('TambahanBatas', { ...formulir.data.TambahanBatas, [kolom]: nilai })
                            }
                            galat={galat[`TambahanBatas.${kolom}`]}
                        />
                    ))}
                </fieldset>
                {galat.TambahanBatas ? (
                    <p className="text-keterangan font-semibold text-bahaya sm:col-span-2">{galat.TambahanBatas}</p>
                ) : null}
                <BidangPilihan
                    label="Status"
                    nilai={formulir.data.Status}
                    opsi={[
                        { Nilai: 'Aktif', Label: 'Aktif (bisa dibeli)' },
                        { Nilai: 'Diarsipkan', Label: 'Diarsipkan (tidak dijual lagi)' },
                    ]}
                    saatBerubah={(nilai) => formulir.setData('Status', nilai)}
                    galat={formulir.errors.Status}
                    required
                />
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan add-on
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
