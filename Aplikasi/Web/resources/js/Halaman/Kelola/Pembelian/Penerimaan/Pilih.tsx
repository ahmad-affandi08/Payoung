import { Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Panel from '@/Komponen/Kelola/Panel';
import { AlamatPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import { AlamatTerimaDariPesanan } from '@/Komponen/Pembelian/SaranPesananTerbuka';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal } from '@/Pustaka/FormatWaktu';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PesananTerbukaDiterima, PropsPilihPenerimaan } from '@/Tipe/Pembelian';

/** Teks perkiraan tiba satu pesanan: terlambat, sesuai jadwal, atau belum ada perkiraan. */
export function TeksPerkiraanTiba(pesanan: PesananTerbukaDiterima): string {
    if (pesanan.PerkiraanTiba === null) {
        return pesanan.Terlambat ? 'Tanpa perkiraan tiba, sudah lama menunggu' : 'Tanpa perkiraan tiba';
    }

    return pesanan.HariTerlambat > 0
        ? `Perkiraan tiba ${FormatTanggal(pesanan.PerkiraanTiba)}, terlambat ${String(pesanan.HariTerlambat)} hari`
        : `Perkiraan tiba ${FormatTanggal(pesanan.PerkiraanTiba)}`;
}

/** Pesanan yang cocok dengan kata cari (nomor, pemasok, atau lokasi), tanpa membedakan huruf besar kecil. */
export function CariPesananTerbuka(daftar: PesananTerbukaDiterima[], kata: string): PesananTerbukaDiterima[] {
    const kunci = kata.trim().toLowerCase();

    if (kunci === '') {
        return daftar;
    }

    return daftar.filter((p) => `${p.Nomor} ${p.NamaPemasok} ${p.NamaGudang}`.toLowerCase().includes(kunci));
}

/**
 * Langkah pertama "Terima barang" (F-04): pilih pesanan pembelian yang barangnya datang, supaya form terisi dari sisa
 * barang pesanan dan status pesanan ikut berubah. Penerimaan tanpa pesanan tetap tersedia lewat tombol di bawah.
 * Halaman ini hanya tampil bila memang ada pesanan yang menunggu barang.
 */
export default function HalamanPilihPenerimaan({ PesananTerbuka }: PropsPilihPenerimaan) {
    const [kata, AturKata] = useState('');
    const tampil = useMemo(() => CariPesananTerbuka(PesananTerbuka, kata), [PesananTerbuka, kata]);

    return (
        <TataLetakAplikasi judul="Terima barang">
            <p className="max-w-3xl text-isi text-teks-sekunder">
                Pilih pesanan yang barangnya datang. Barisnya terisi dari sisa pesanan dan statusnya ikut berubah.
            </p>
            <Panel judul="Pesanan yang menunggu barang">
                <BidangTeks
                    label="Cari pesanan"
                    nilai={kata}
                    saatBerubah={AturKata}
                    keterangan="Ketik nomor pesanan, nama pemasok, atau lokasi."
                />
                {tampil.length === 0 ? (
                    <p className="text-isi text-teks-sekunder">Tidak ada pesanan yang cocok dengan pencarian.</p>
                ) : (
                    <ul className="flex flex-col divide-y divide-garis" aria-label="Pesanan yang menunggu barang">
                        {tampil.map((p) => (
                            <li
                                key={p.Uuid}
                                className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="flex min-w-0 flex-col gap-0.5">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="font-mono font-semibold break-words">{p.Nomor}</span>
                                        <LabelStatus
                                            jenis={p.Terlambat ? 'peringatan' : 'netral'}
                                            teks={p.Terlambat ? 'Terlambat' : p.LabelStatus}
                                        />
                                    </span>
                                    <span className="break-words">{p.NamaPemasok}</span>
                                    <span className="text-keterangan text-teks-sekunder">
                                        {p.NamaGudang} | {TeksPerkiraanTiba(p)} | sisa {FormatRupiah(p.NilaiSisa)} di{' '}
                                        {String(p.JumlahBarisSisa)} baris
                                    </span>
                                </div>
                                <Button asChild variant="outline" className="h-8 shrink-0 pointer-coarse:h-11">
                                    <Link href={AlamatTerimaDariPesanan(p.Uuid)}>Terima barang</Link>
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
            <Panel judul="Barang tidak dari pesanan?">
                <p className="text-isi text-teks-sekunder">
                    Untuk barang yang dibeli langsung atau dipesan lewat telepon, catat penerimaan tanpa pesanan. Untuk
                    belanja tunai di pasar, pakai Belanja stok.
                </p>
                <div className="flex flex-wrap gap-2">
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={`${AlamatPembelian}/penerimaan/buat?tanpa-pesanan=1`}>Terima tanpa pesanan</Link>
                    </Button>
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={`${AlamatPembelian}/belanja-stok`}>Belanja stok</Link>
                    </Button>
                </div>
            </Panel>
        </TataLetakAplikasi>
    );
}
