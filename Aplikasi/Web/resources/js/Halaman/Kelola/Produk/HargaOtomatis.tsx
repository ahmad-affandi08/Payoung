import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import DialogAturanHargaOtomatis, {
    LabelPembulatan,
    RapikanPersen,
} from '@/Komponen/Katalog/DialogAturanHargaOtomatis';
import Panel from '@/Komponen/Kelola/Panel';
import TabelData from '@/Komponen/TabelData/TabelData';
import type { KolomTabel } from '@/Komponen/TabelData/Tipe';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { AturanHargaOtomatis, PropsHargaOtomatis, UsulanHargaOtomatis } from '@/Tipe/Katalog';

const Alamat = '/kelola/produk/harga-otomatis';

/** Persen bertanda untuk perubahan harga: "+9,1%" atau "-12,5%". */
function FormatPerubahan(nilai: string): string {
    const angka = RapikanPersen(nilai);

    return nilai.startsWith('-') ? `${angka}%` : `+${angka}%`;
}

/**
 * D-266 harga jual otomatis mengikuti harga beli terakhir (F-03 + F-04, paket Pro ke atas). Pemilik menyalakan fitur,
 * mengatur batas aman, menulis aturan untung per cakupan, dan memutuskan perubahan besar yang ditahan sebagai usulan.
 * Hanya produk berstok yang harganya tidak diketik kasir yang ikut, dan hanya harga dasar satuan jual utama yang berubah.
 */
export default function HalamanHargaOtomatis(props: PropsHargaOtomatis) {
    const { Pengaturan, Aturan, Usulan, Izin } = props;
    const { props: bersama } = usePage<PropsBersamaAplikasi>();
    const galat = bersama.errors;
    const [aktif, AturAktif] = useState(Pengaturan.Aktif);
    const [batas, AturBatas] = useState(Pengaturan.BatasPerubahanPersen.replace(/\.?0+$/, ''));
    const [memproses, AturMemproses] = useState(false);
    const [dialogAturan, AturDialogAturan] = useState<{ aturan: AturanHargaOtomatis | null } | null>(null);
    const [hapus, AturHapus] = useState<AturanHargaOtomatis | null>(null);
    const [konfirmasiJalankan, AturKonfirmasiJalankan] = useState(false);
    const [sedangMemutuskan, AturSedangMemutuskan] = useState<string | null>(null);
    const berubah = aktif !== Pengaturan.Aktif || batas !== Pengaturan.BatasPerubahanPersen.replace(/\.?0+$/, '');
    const bolehUbah = Izin.Ubah;

    const SimpanPengaturan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        router.put(
            `${Alamat}/pengaturan`,
            { Aktif: aktif, BatasPerubahanPersen: batas },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );
    };

    const Putuskan = (usulan: UsulanHargaOtomatis, aksi: 'terapkan' | 'tolak') =>
        router.post(
            `${Alamat}/usulan/${usulan.Uuid}/${aksi}`,
            {},
            {
                preserveScroll: true,
                onStart: () => AturSedangMemutuskan(usulan.Uuid),
                onFinish: () => AturSedangMemutuskan(null),
            },
        );

    const kolomAturan: KolomTabel<AturanHargaOtomatis>[] = [
        {
            id: 'Sasaran',
            accessorKey: 'Sasaran',
            header: 'Berlaku untuk',
            meta: { label: 'Berlaku untuk', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: a } }) => (
                <span>
                    <span className="font-semibold text-teks-utama">{a.Sasaran}</span>
                    <span className="block text-keterangan text-teks-sekunder">
                        {a.Cakupan === 'Semua' ? 'Aturan umum' : a.Cakupan === 'Kategori' ? 'Kategori' : 'Produk'}
                    </span>
                </span>
            ),
        },
        {
            id: 'PersenUntung',
            accessorKey: 'PersenUntung',
            header: 'Untung',
            meta: { label: 'Untung', angka: true, prioritas: 'utama' },
            cell: ({ row }) => `${RapikanPersen(row.original.PersenUntung)}%`,
        },
        {
            id: 'Pembulatan',
            accessorKey: 'Pembulatan',
            header: 'Pembulatan',
            meta: { label: 'Pembulatan', prioritas: 'penting' },
            cell: ({ row }) => LabelPembulatan(row.original.Pembulatan),
        },
        {
            id: 'Arah',
            accessorKey: 'Arah',
            header: 'Harga berubah',
            meta: { label: 'Harga berubah', prioritas: 'rendah' },
            cell: ({ row }) => (row.original.Arah === 'HanyaNaik' ? 'Hanya naik' : 'Naik dan turun'),
        },
        {
            id: 'Aktif',
            accessorKey: 'Aktif',
            header: 'Status',
            meta: { label: 'Status', prioritas: 'penting' },
            cell: ({ row }) => (
                <LabelStatus
                    jenis={row.original.Aktif ? 'sukses' : 'netral'}
                    teks={row.original.Aktif ? 'Aktif' : 'Nonaktif'}
                />
            ),
        },
        {
            id: 'Aksi',
            header: 'Aksi',
            enableSorting: false,
            meta: { label: 'Aksi', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: a } }) =>
                bolehUbah ? (
                    <span className="flex gap-2">
                        <Tombol varian="sekunder" onClick={() => AturDialogAturan({ aturan: a })}>
                            Ubah
                        </Tombol>
                        <Tombol varian="sekunder" onClick={() => AturHapus(a)}>
                            Hapus
                        </Tombol>
                    </span>
                ) : null,
        },
    ];

    const kolomUsulan: KolomTabel<UsulanHargaOtomatis>[] = [
        {
            id: 'Produk',
            accessorFn: (u) => u.Produk.Nama,
            header: 'Produk',
            meta: { label: 'Produk', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: u } }) => (
                <span>
                    <span className="font-semibold text-teks-utama">{u.Produk.Nama}</span>
                    {u.Produk.Sku ? (
                        <span className="block font-mono text-keterangan text-teks-sekunder">{u.Produk.Sku}</span>
                    ) : null}
                </span>
            ),
        },
        {
            id: 'HargaLama',
            accessorKey: 'HargaLama',
            header: 'Harga sekarang',
            meta: { label: 'Harga sekarang', angka: true, prioritas: 'penting' },
            cell: ({ row }) => FormatRupiah(row.original.HargaLama),
        },
        {
            id: 'HargaBaru',
            accessorKey: 'HargaBaru',
            header: 'Harga usulan',
            meta: { label: 'Harga usulan', angka: true, prioritas: 'utama' },
            cell: ({ row }) => FormatRupiah(row.original.HargaBaru),
        },
        {
            id: 'Perubahan',
            accessorKey: 'PerubahanPersen',
            header: 'Perubahan',
            meta: { label: 'Perubahan', angka: true, prioritas: 'utama' },
            cell: ({ row }) => FormatPerubahan(row.original.PerubahanPersen),
        },
        {
            id: 'Modal',
            accessorKey: 'Modal',
            header: 'Modal',
            meta: { label: 'Modal', angka: true, prioritas: 'rendah' },
            cell: ({ row }) => FormatRupiah(row.original.Modal),
        },
        {
            id: 'Aksi',
            header: 'Aksi',
            enableSorting: false,
            meta: { label: 'Aksi', prioritas: 'utama', wajib: true },
            cell: ({ row: { original: u } }) =>
                bolehUbah ? (
                    <span className="flex gap-2">
                        <Tombol memproses={sedangMemutuskan === u.Uuid} onClick={() => Putuskan(u, 'terapkan')}>
                            Terapkan
                        </Tombol>
                        <Tombol
                            varian="sekunder"
                            disabled={sedangMemutuskan === u.Uuid}
                            onClick={() => Putuskan(u, 'tolak')}
                        >
                            Tolak
                        </Tombol>
                    </span>
                ) : null,
        },
    ];

    return (
        <TataLetakAplikasi judul="Harga otomatis">
            <DaftarGalatServer galat={galat} kecuali={['Umum', 'BatasPerubahanPersen']} />
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Harga jual mengikuti harga beli terakhir. Setiap ada penerimaan barang atau belanja stok, harga jual
                dihitung ulang dari harga beli terakhir (setelah diskon, sebelum pajak dan ongkos kirim) ditambah untung
                sesuai aturan di bawah. Harga beli naik, harga jual naik. Harga beli turun, harga jual ikut turun,
                kecuali aturannya diatur hanya naik.
            </p>

            <form
                onSubmit={SimpanPengaturan}
                noValidate
                aria-label="Pengaturan harga otomatis"
                className="grid gap-4 lg:grid-cols-2 lg:items-start"
            >
                <Panel
                    judul="Harga otomatis"
                    idJudul="judul-saklar"
                    keterangan="Bila mati, harga jual tidak pernah berubah sendiri. Harga yang sudah berubah tidak dikembalikan."
                >
                    <KotakCentang
                        label="Harga jual mengikuti harga beli"
                        nilai={aktif}
                        saatBerubah={AturAktif}
                        disabled={!bolehUbah}
                    />
                </Panel>
                <Panel
                    judul="Batas aman"
                    idJudul="judul-batas"
                    keterangan="Perubahan harga yang lebih besar dari batas ini tidak diterapkan sendiri. Anda yang memutuskan lewat daftar usulan, supaya salah ketik harga beli tidak langsung mengubah harga di kasir."
                >
                    <BidangJumlah
                        label="Perubahan terbesar yang diterapkan sendiri"
                        nilai={batas}
                        saatBerubah={AturBatas}
                        desimal={2}
                        digitBulat={3}
                        akhiran="%"
                        galat={galat.BatasPerubahanPersen}
                        disabled={!bolehUbah}
                        required
                        keterangan="Isi 1 sampai 100. Bawaan 20%."
                    />
                </Panel>
                {bolehUbah ? (
                    <div className="lg:col-span-2">
                        <Tombol type="submit" memproses={memproses} disabled={!berubah}>
                            Simpan pengaturan
                        </Tombol>
                    </div>
                ) : null}
            </form>

            <Panel
                judul="Aturan untung"
                idJudul="judul-aturan"
                keterangan="Aturan yang paling khusus berlaku: produk, lalu kategori (termasuk subkategori), lalu semua produk."
                aksi={
                    bolehUbah ? (
                        <Tombol onClick={() => AturDialogAturan({ aturan: null })}>Tambah aturan</Tombol>
                    ) : undefined
                }
            >
                <TabelData
                    id="produk-harga-otomatis-aturan"
                    label="Aturan harga otomatis"
                    kolom={kolomAturan}
                    sumber={{ mode: 'lokal', data: Aturan }}
                    ambilIdBaris={(a) => a.Uuid}
                    kosong={{
                        judul: 'Belum ada aturan. Tambah aturan untung supaya harga jual bisa mengikuti harga beli.',
                        ilustrasi: true,
                    }}
                />
                {bolehUbah && Aturan.length > 0 ? (
                    <div className="mt-3">
                        <Tombol varian="sekunder" onClick={() => AturKonfirmasiJalankan(true)}>
                            Terapkan sekarang
                        </Tombol>
                        <p className="mt-1 text-keterangan text-teks-sekunder">
                            Menghitung ulang semua produk dari harga beli terakhirnya, tanpa menunggu penerimaan barang
                            berikutnya.
                        </p>
                    </div>
                ) : null}
            </Panel>

            {Usulan.length > 0 ? (
                <Panel
                    judul="Usulan menunggu keputusan"
                    idJudul="judul-usulan"
                    keterangan="Harga beli berubah cukup besar, jadi harga jual belum diubah. Terapkan bila harga belinya benar, tolak bila salah ketik."
                >
                    <TabelData
                        id="produk-harga-otomatis-usulan"
                        label="Usulan harga"
                        kolom={kolomUsulan}
                        sumber={{ mode: 'lokal', data: Usulan }}
                        ambilIdBaris={(u) => u.Uuid}
                        kosong={{ judul: 'Tidak ada usulan yang menunggu.', ilustrasi: true }}
                    />
                </Panel>
            ) : null}

            {dialogAturan !== null ? (
                <DialogAturanHargaOtomatis
                    aturan={dialogAturan.aturan}
                    opsi={props}
                    saatTutup={() => AturDialogAturan(null)}
                />
            ) : null}
            {hapus !== null ? (
                <DialogKonfirmasi
                    judul="Hapus aturan ini?"
                    labelAksi="Hapus aturan"
                    varian="bahaya"
                    saatBatal={() => AturHapus(null)}
                    saatKonfirmasi={() =>
                        router.delete(`${Alamat}/aturan/${hapus.Uuid}`, {
                            preserveScroll: true,
                            onSuccess: () => AturHapus(null),
                        })
                    }
                >
                    Produk dengan aturan {hapus.Sasaran} tidak lagi mengikuti harga beli. Harga yang sudah berubah tetap
                    seperti sekarang.
                </DialogKonfirmasi>
            ) : null}
            {konfirmasiJalankan ? (
                <DialogKonfirmasi
                    judul="Hitung ulang semua harga?"
                    labelAksi="Terapkan sekarang"
                    saatBatal={() => AturKonfirmasiJalankan(false)}
                    saatKonfirmasi={() =>
                        router.post(
                            `${Alamat}/jalankan`,
                            {},
                            { preserveScroll: true, onSuccess: () => AturKonfirmasiJalankan(false) },
                        )
                    }
                >
                    Harga jual semua produk berstok yang punya aturan dihitung ulang dari harga beli terakhirnya.
                    Perubahan besar tetap menunggu keputusan Anda di daftar usulan. Hasilnya muncul di notifikasi.
                </DialogKonfirmasi>
            ) : null}
        </TataLetakAplikasi>
    );
}
