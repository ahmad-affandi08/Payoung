import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { DefinisiSaring, KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { DropdownMenuItem } from '@/Komponen/Ui/dropdown-menu';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import { RingkasIsiLaundry, type PengaturanLaundry, type PropsDaftarLaundry, type TiketLaundry } from '@/Tipe/Laundry';

const alamat = '/kelola/laundry';

const JenisLabelStatus: Record<string, 'netral' | 'sukses' | 'peringatan' | 'bahaya'> = {
    Diterima: 'netral',
    Dicuci: 'netral',
    Dikeringkan: 'netral',
    Disetrika: 'netral',
    Siap: 'peringatan',
    Diambil: 'sukses',
    Dibatalkan: 'bahaya',
};

const LabelAksiStatus: Record<string, string> = {
    Dicuci: 'Mulai dicuci',
    Dikeringkan: 'Masuk pengeringan',
    Disetrika: 'Masuk setrika',
    Siap: 'Tandai siap diambil',
    Diambil: 'Sudah diambil pelanggan',
};

const kolom: KolomTabel<TiketLaundry>[] = [
    {
        id: 'DibuatPada',
        accessorKey: 'DibuatPada',
        header: 'Diterima',
        meta: { label: 'Diterima', prioritas: 'utama', wajib: true },
        cell: ({ row: { original: t } }) => (
            <span className="flex flex-col gap-0.5">
                <span className="font-mono text-teks-utama">{t.Nomor}</span>
                <span className="text-keterangan whitespace-nowrap text-teks-sekunder tabular-nums">
                    {t.DibuatPada ? FormatTanggalWaktu(t.DibuatPada) : '-'}
                </span>
            </span>
        ),
    },
    {
        id: 'Pelanggan',
        header: 'Pelanggan',
        enableSorting: false,
        meta: { label: 'Pelanggan', prioritas: 'penting' },
        cell: ({ row: { original: t } }) => (
            <span className="flex flex-col gap-0.5 break-words">
                <span className="text-teks-utama">{t.NamaPelanggan}</span>
                {t.NoHp ? <span className="text-keterangan text-teks-sekunder tabular-nums">{t.NoHp}</span> : null}
            </span>
        ),
    },
    {
        id: 'Isi',
        header: 'Cucian',
        enableSorting: false,
        meta: { label: 'Cucian', prioritas: 'penting' },
        cell: ({ row: { original: t } }) => (
            <span className="flex flex-col gap-0.5 break-words">
                <span>
                    {t.JenisLayanan} | {RingkasIsiLaundry(t)}
                </span>
                {t.Parfum || t.Catatan ? (
                    <span className="text-keterangan text-teks-sekunder">
                        {[t.Parfum ? `Parfum ${t.Parfum}` : null, t.Catatan].filter(Boolean).join(' | ')}
                    </span>
                ) : null}
            </span>
        ),
    },
    {
        id: 'EstimasiSelesaiPada',
        accessorKey: 'EstimasiSelesaiPada',
        header: 'Perkiraan selesai',
        meta: { label: 'Perkiraan selesai', prioritas: 'penting' },
        cell: ({ row: { original: t } }) => (
            <span className="flex flex-col gap-0.5">
                <span className="whitespace-nowrap tabular-nums">{FormatTanggalWaktu(t.EstimasiSelesaiPada)}</span>
                {t.LewatEstimasi ? (
                    <span className="text-keterangan font-semibold text-bahaya">Lewat perkiraan</span>
                ) : null}
            </span>
        ),
    },
    {
        id: 'Status',
        header: 'Status',
        enableSorting: false,
        meta: { label: 'Status', prioritas: 'penting' },
        cell: ({ row: { original: t } }) => (
            <span className="flex flex-col items-start gap-1">
                <LabelStatus jenis={JenisLabelStatus[t.Status] ?? 'netral'} teks={t.LabelStatus} />
                {t.TerlambatDiambil ? (
                    <span className="text-keterangan font-semibold text-bahaya">Lama belum diambil</span>
                ) : null}
                {t.Status === 'Siap' && t.NotifikasiTerkirim ? (
                    <span className="text-keterangan text-teks-sekunder">Pelanggan sudah dikabari</span>
                ) : null}
            </span>
        ),
    },
    {
        id: 'Outlet',
        header: 'Outlet',
        enableSorting: false,
        meta: { label: 'Outlet', prioritas: 'rendah' },
        cell: ({ row }) => row.original.Outlet.Nama,
    },
];

const OpsiJam = [6, 12, 24, 36, 48, 72, 96, 120, 168].map((j) => ({
    Nilai: String(j),
    Label: j < 24 || j % 24 !== 0 ? `${String(j)} jam` : `${String(j / 24)} hari`,
}));

const OpsiHari = [2, 3, 5, 7, 14, 30].map((h) => ({ Nilai: String(h), Label: `${String(h)} hari` }));

/** Pengaturan laundry: aktif di kasir, durasi layanan, parfum, notifikasi, batas belum diambil. */
function DialogPengaturan({ pengaturan, saatTutup }: { pengaturan: PengaturanLaundry; saatTutup: () => void }) {
    const formulir = useForm<PengaturanLaundry>({ ...pengaturan });
    const [parfum, AturParfum] = useState(pengaturan.Parfum.join(', '));
    const d = formulir.data;
    const galat = formulir.errors as Record<string, string | undefined>;

    return (
        <DialogFormulir judul="Pengaturan laundry" lebar="lebar" saatTutup={saatTutup} galatUmum={galat.Umum}>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    formulir.put(`${alamat}/pengaturan`, { preserveScroll: true, onSuccess: saatTutup });
                }}
                className="grid gap-4 sm:grid-cols-2"
                noValidate
            >
                <div className="flex flex-col gap-3 sm:col-span-2">
                    <KotakCentang
                        label="Tampilkan isian laundry di aplikasi kasir"
                        nilai={d.Aktif}
                        saatBerubah={(nilai) => formulir.setData('Aktif', nilai)}
                    />
                    <KotakCentang
                        label="Kabari pelanggan lewat WhatsApp saat cucian siap"
                        nilai={d.NotifikasiSiap}
                        saatBerubah={(nilai) => formulir.setData('NotifikasiSiap', nilai)}
                    />
                </div>
                <BidangPilihan
                    label="Lama layanan reguler"
                    nilai={String(d.JamReguler)}
                    opsi={OpsiJam}
                    saatBerubah={(nilai) => formulir.setData('JamReguler', Number(nilai))}
                    galat={galat.JamReguler}
                />
                <BidangPilihan
                    label="Lama layanan express"
                    nilai={String(d.JamExpress)}
                    opsi={OpsiJam}
                    saatBerubah={(nilai) => formulir.setData('JamExpress', Number(nilai))}
                    galat={galat.JamExpress}
                />
                <BidangPilihan
                    label="Tandai lama belum diambil setelah"
                    nilai={String(d.HariBelumDiambil)}
                    opsi={OpsiHari}
                    saatBerubah={(nilai) => formulir.setData('HariBelumDiambil', Number(nilai))}
                    galat={galat.HariBelumDiambil}
                />
                <BidangTeks
                    label="Pilihan parfum"
                    nilai={parfum}
                    saatBerubah={(nilai) => {
                        AturParfum(nilai);
                        formulir.setData(
                            'Parfum',
                            nilai
                                .split(',')
                                .map((p) => p.trim())
                                .filter((p) => p !== ''),
                        );
                    }}
                    keterangan="Pisahkan dengan koma, misalnya: Lavender, Sakura, Tanpa parfum."
                    galat={galat.Parfum}
                    maxLength={1000}
                />
                <DialogFooter className="sm:col-span-2 sm:justify-start">
                    <Tombol type="submit" memproses={formulir.processing}>
                        Simpan pengaturan
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}

/**
 * Laundry (§9.9, SLS-09): cucian yang diterima kasir beserta status prosesnya. Status diubah dari sini atau dari
 * aplikasi kasir; saat "Siap" pelanggan dikabari lewat WhatsApp dan bisa melacak lewat QR di nota.
 */
export default function HalamanDaftarLaundry({ Tiket, OpsiStatus, OpsiOutlet, Pengaturan, Izin }: PropsDaftarLaundry) {
    const [dialog, AturDialog] = useState<'pengaturan' | null>(null);
    const UbahStatus = (t: TiketLaundry, status: string) =>
        router.post(`${alamat}/${t.Uuid}/status`, { Status: status }, { preserveScroll: true });
    const saring: DefinisiSaring[] = [
        { id: 'Tanggal', label: 'Tanggal terima', jenis: 'rentangTanggal' },
        {
            id: 'Status',
            label: 'Status',
            jenis: 'pilihanBanyak',
            opsi: OpsiStatus.map((o) => ({ nilai: o.Nilai, label: o.Label })),
        },
        {
            id: 'Terlambat',
            label: 'Perlu perhatian',
            jenis: 'pilihanBanyak',
            opsi: [
                { nilai: 'BelumSiap', label: 'Lewat perkiraan, belum siap' },
                { nilai: 'BelumDiambil', label: `Siap lebih dari ${String(Pengaturan.HariBelumDiambil)} hari` },
            ],
        },
        ...(OpsiOutlet.length > 1
            ? [
                  {
                      id: 'Outlet',
                      label: 'Outlet',
                      jenis: 'pilihan' as const,
                      opsi: OpsiOutlet.map((o) => ({ nilai: o.Uuid, label: o.Nama })),
                  },
              ]
            : []),
    ];

    return (
        <TataLetakAplikasi judul="Laundry">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Cucian dicatat kasir saat pelanggan menyerahkan pakaian. Ubah statusnya sesuai proses. Saat siap,
                pelanggan dikabari dan bisa melacak lewat QR di nota.
                {Pengaturan.Aktif ? null : ' Isian laundry di aplikasi kasir belum aktif (buka Pengaturan).'}
            </p>
            <AksiHalaman>
                {Izin.Pengaturan ? (
                    <Tombol varian="sekunder" onClick={() => AturDialog('pengaturan')}>
                        Pengaturan
                    </Tombol>
                ) : null}
            </AksiHalaman>
            <TabelData
                id="laundry"
                label="Daftar cucian"
                kolom={kolom}
                sumber={{ mode: 'server', alamat, awal: Tiket }}
                ambilIdBaris={(t) => t.Uuid}
                urutBawaan="-DibuatPada"
                cari="Cari nomor nota, nama, atau nomor HP"
                saring={saring}
                aksiBaris={(t: TiketLaundry) =>
                    t.StatusBerikutnya.length === 0 ? null : (
                        <>
                            {t.StatusBerikutnya.map((s) => (
                                <DropdownMenuItem key={s} onSelect={() => UbahStatus(t, s)}>
                                    {LabelAksiStatus[s] ?? s}
                                </DropdownMenuItem>
                            ))}
                        </>
                    )
                }
                kosong={{ ilustrasi: true, judul: 'Belum ada cucian.' }}
            />
            {dialog === 'pengaturan' ? (
                <DialogPengaturan pengaturan={Pengaturan} saatTutup={() => AturDialog(null)} />
            ) : null}
        </TataLetakAplikasi>
    );
}
