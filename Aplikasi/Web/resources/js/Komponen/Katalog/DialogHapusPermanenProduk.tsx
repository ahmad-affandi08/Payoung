import { useForm } from '@inertiajs/react';
import { useQuery } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { KunciKueri } from '@/Pustaka/KunciKueri';
import { LabelTabelRiwayat } from '@/Pustaka/LabelRiwayatProduk';
import type { PratinjauHapusPermanen } from '@/Tipe/Katalog';

const BARIS_DITAMPILKAN = 6;

async function AmbilPratinjau(uuid: string, sinyal: AbortSignal): Promise<PratinjauHapusPermanen> {
    const respons = await fetch(`/kelola/produk/${uuid}/hapus-permanen`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal: sinyal,
    });

    if (!respons.ok) {
        throw new Error(`Riwayat produk gagal dibaca (${String(respons.status)})`);
    }

    return (await respons.json()) as PratinjauHapusPermanen;
}

/**
 * D-218: dialog "Hapus permanen beserta riwayat" (khusus Pemilik). Menampilkan berapa baris riwayat yang ikut hilang,
 * lalu meminta nama produk diketik ulang. Server tetap memeriksa izin, nama, stok nol, dan pemakaian di produk lain.
 */
export default function DialogHapusPermanenProduk({
    uuid,
    nama,
    saatSelesai,
}: {
    uuid: string;
    nama: string;
    saatSelesai: () => void;
}) {
    const [semua, AturSemua] = useState(false);
    const formulir = useForm({ KonfirmasiNama: '' });
    const kueri = useQuery({
        queryKey: KunciKueri.PratinjauHapusProduk(uuid),
        queryFn: ({ signal }) => AmbilPratinjau(uuid, signal),
        staleTime: 0,
        gcTime: 0,
    });
    const pratinjau = kueri.data;
    const terhalang = pratinjau?.Penghalang ?? null;
    const siap = pratinjau !== undefined && terhalang === null && formulir.data.KonfirmasiNama.trim() === nama;
    const rincian = pratinjau?.Rincian ?? [];
    const tampil = semua ? rincian : rincian.slice(0, BARIS_DITAMPILKAN);
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.delete(`/kelola/produk/${uuid}/hapus-permanen`, { preserveScroll: true });
    };

    return (
        <DialogFormulir
            jenis="konfirmasi"
            judul={`Hapus permanen ${nama}?`}
            galatUmum={(formulir.errors as Partial<Record<string, string>>).Umum}
            saatTutup={saatSelesai}
            keterangan={
                <div className="flex flex-col gap-2">
                    <p>
                        Ini menghapus produk <strong>beserta seluruh riwayatnya</strong>: penjualan, pembelian, faktur,
                        retur, mutasi stok, dan resep miliknya. Tidak bisa dibatalkan.
                    </p>
                    <p>
                        Nota dan jurnal lama tetap ada, tetapi baris produk ini hilang dari nota itu, sehingga total
                        nota tidak lagi sama dengan jumlah barisnya dan laporan penjualan, pembelian, dan laba lama ikut
                        berubah. Bila produk hanya tidak dijual lagi, pilih Arsipkan.
                    </p>
                </div>
            }
        >
            <form onSubmit={Kirim} noValidate className="flex flex-col gap-4">
                {kueri.isPending ? <p className="text-isi text-teks-sekunder">Menghitung riwayat produk…</p> : null}
                {kueri.isError ? (
                    <Pemberitahuan jenis="bahaya">
                        Riwayat produk tidak bisa dimuat. Tutup dialog lalu coba lagi.
                    </Pemberitahuan>
                ) : null}
                {terhalang !== null ? <Pemberitahuan jenis="bahaya">{terhalang}</Pemberitahuan> : null}
                {pratinjau !== undefined ? (
                    <div className="flex flex-col gap-1" aria-live="polite">
                        <p className="text-isi font-semibold text-teks-utama">
                            {pratinjau.Total === 0
                                ? 'Produk ini belum punya riwayat.'
                                : `${pratinjau.Total.toLocaleString('id-ID')} baris riwayat di ${pratinjau.Tabel.toLocaleString('id-ID')} tabel akan dihapus:`}
                        </p>
                        <ul className="list-disc pl-5 text-isi text-teks-sekunder">
                            {tampil.map((baris) => (
                                <li key={baris.Tabel}>
                                    {baris.Jumlah.toLocaleString('id-ID')} {LabelTabelRiwayat(baris.Tabel)}
                                </li>
                            ))}
                        </ul>
                        {rincian.length > BARIS_DITAMPILKAN ? (
                            <Tombol varian="sekunder" onClick={() => AturSemua(!semua)}>
                                {semua
                                    ? 'Ringkas daftar'
                                    : `Lihat ${String(rincian.length - BARIS_DITAMPILKAN)} lainnya`}
                            </Tombol>
                        ) : null}
                    </div>
                ) : null}
                {pratinjau !== undefined && terhalang === null && pratinjau.Stok.Lokasi > 0 ? (
                    <Pemberitahuan jenis="peringatan">
                        Produk ini masih punya stok di {pratinjau.Stok.Lokasi.toLocaleString('id-ID')} lokasi. Sisa stok
                        bernilai {FormatRupiah(pratinjau.Stok.Nilai)} otomatis dicatat sebagai stok hilang (beban susut
                        di laba rugi) sebelum riwayatnya dihapus.
                    </Pemberitahuan>
                ) : null}
                {terhalang === null ? (
                    <BidangTeks
                        label={`Ketik nama produk "${nama}" untuk memastikan`}
                        nilai={formulir.data.KonfirmasiNama}
                        saatBerubah={(nilai) => formulir.setData('KonfirmasiNama', nilai)}
                        galat={formulir.errors.KonfirmasiNama}
                        autoComplete="off"
                        required
                    />
                ) : null}
                <div className="flex flex-wrap gap-2">
                    <Tombol type="submit" varian="bahaya" memproses={formulir.processing} disabled={!siap}>
                        Hapus permanen
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatSelesai}>
                        Batal
                    </Tombol>
                </div>
            </form>
        </DialogFormulir>
    );
}
