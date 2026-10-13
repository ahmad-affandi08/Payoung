import { Link, router, useForm } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import TabSitus from '@/Komponen/Pengelola/Situs/TabSitus';
import TeksKaya from '@/Komponen/Situs/TeksKaya';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { Card } from '@/Komponen/Ui/card';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';

import type { RingkasArtikelBantuan } from './Daftar';

type ArtikelBantuanLengkap = RingkasArtikelBantuan & {
    Ringkasan: string | null;
    Isi: string;
    UrlVideo: string | null;
    Konteks: string[];
};

/** Satu jalur per baris di formulir → daftar jalur (baris kosong dibuang, spasi tepi dipangkas). */
export function UraiKonteks(teks: string): string[] {
    return teks
        .split('\n')
        .map((baris) => baris.trim())
        .filter((baris) => baris !== '');
}

const KETERANGAN_ISI =
    'Baris kosong = paragraf baru. "## " = subjudul, "- " = butir daftar, **tebal**, [teks](tautan). Tanpa HTML.';

/** Menulis & menerbitkan satu artikel Pusat Bantuan (PLT-12). Artikel terbit langsung berubah setelah disimpan. */
export default function HalamanUbahArtikelBantuan({
    Artikel: artikel,
    PilihanKategori,
    UrlArtikel,
    Izin,
}: {
    Artikel: ArtikelBantuanLengkap;
    PilihanKategori: string[];
    UrlArtikel: string;
    Izin: { Kelola: boolean };
}) {
    const awal = {
        Judul: artikel.Judul,
        Slug: artikel.Slug,
        Ringkasan: artikel.Ringkasan ?? '',
        Isi: artikel.Isi,
        Kategori: artikel.Kategori ?? '',
        UrlVideo: artikel.UrlVideo ?? '',
        /** Satu jalur per baris di formulir; dikirim sebagai daftar. */
        Konteks: artikel.Konteks.join('\n'),
        Urutan: String(artikel.Urutan),
    };
    const formulir = useForm(awal);
    const d = formulir.data;
    const galat = formulir.errors as Record<string, string | undefined>;
    const [konfirmasi, AturKonfirmasi] = useState<'terbit' | 'tarik' | 'hapus' | null>(null);
    const [memproses, AturMemproses] = useState(false);
    const terbit = artikel.Status === 'Terbit';
    const alamat = `/situs/bantuan/${artikel.Uuid}`;

    const Simpan = (p: FormEvent) => {
        p.preventDefault();
        formulir.transform((data) => ({
            ...data,
            Konteks: UraiKonteks(data.Konteks),
            Urutan: Number(data.Urutan) || 0,
        }));
        formulir.put(alamat, { preserveScroll: true, onSuccess: () => formulir.setDefaults() });
    };
    const Kirim = (akhiran: string, metode: 'post' | 'delete' = 'post') => {
        const opsi = {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => {
                AturMemproses(false);
                AturKonfirmasi(null);
            },
        };

        if (metode === 'delete') {
            router.delete(alamat, opsi);
        } else {
            router.post(`${alamat}/${akhiran}`, {}, opsi);
        }
    };

    return (
        <TataLetakPengelola
            judul={artikel.Judul}
            aksi={
                <div className="flex flex-wrap gap-2">
                    {terbit ? (
                        <a
                            href={UrlArtikel}
                            target="_blank"
                            rel="noopener"
                            className="inline-flex h-8 items-center gap-2 rounded-kontrol border border-garis-input px-3 text-isi font-medium text-teks-utama hover:bg-permukaan-sorot pointer-coarse:h-11"
                        >
                            Lihat di Pusat Bantuan <ExternalLink className="size-4" aria-hidden />
                        </a>
                    ) : null}
                    {Izin.Kelola ? (
                        terbit ? (
                            <Tombol varian="sekunder" onClick={() => AturKonfirmasi('tarik')}>
                                Tarik ke draf
                            </Tombol>
                        ) : (
                            <>
                                <Tombol varian="sekunder" onClick={() => AturKonfirmasi('hapus')}>
                                    Hapus
                                </Tombol>
                                <Tombol
                                    disabled={formulir.isDirty}
                                    title={formulir.isDirty ? 'Simpan dulu' : undefined}
                                    onClick={() => AturKonfirmasi('terbit')}
                                >
                                    Terbitkan
                                </Tombol>
                            </>
                        )
                    ) : null}
                </div>
            }
        >
            <TabSitus />
            <Link href="/situs/bantuan" className="text-label font-semibold text-brand underline">
                Semua artikel bantuan
            </Link>
            {galat.Umum ? <Pemberitahuan jenis="bahaya">{galat.Umum}</Pemberitahuan> : null}
            <div className="flex flex-wrap items-center gap-2 text-keterangan text-teks-sekunder">
                <LabelStatus jenis={terbit ? 'sukses' : 'peringatan'} teks={artikel.LabelStatus} />
                <span className="font-mono break-all">payoung.id{artikel.Jalur}</span>
                {artikel.DiterbitkanPada ? <span>Terbit {FormatTanggalWaktu(artikel.DiterbitkanPada)}</span> : null}
            </div>
            {formulir.isDirty ? (
                <Pemberitahuan jenis="peringatan">
                    Ada perubahan yang belum disimpan.
                    {terbit ? ' Artikel terbit langsung berubah setelah disimpan.' : ''}
                </Pemberitahuan>
            ) : null}
            {konfirmasi === 'terbit' ? (
                <DialogKonfirmasi
                    judul={`Terbitkan ${artikel.Judul}?`}
                    labelAksi="Terbitkan"
                    varian="utama"
                    memproses={memproses}
                    saatKonfirmasi={() => Kirim('terbitkan')}
                    saatBatal={() => AturKonfirmasi(null)}
                >
                    <p>
                        Artikel tampil di Pusat Bantuan, di bantuan kontekstual back-office (bila punya halaman
                        konteks), peta situs, dan bisa ditemukan mesin pencari.
                    </p>
                </DialogKonfirmasi>
            ) : null}
            {konfirmasi === 'tarik' ? (
                <DialogKonfirmasi
                    judul={`Tarik ${artikel.Judul} ke draf?`}
                    labelAksi="Tarik ke draf"
                    memproses={memproses}
                    saatKonfirmasi={() => Kirim('tarik')}
                    saatBatal={() => AturKonfirmasi(null)}
                >
                    <p>Alamat {artikel.Jalur} akan menampilkan "halaman tidak ditemukan" sampai diterbitkan lagi.</p>
                </DialogKonfirmasi>
            ) : null}
            {konfirmasi === 'hapus' ? (
                <DialogKonfirmasi
                    judul={`Hapus artikel ${artikel.Judul}?`}
                    labelAksi="Hapus artikel"
                    memproses={memproses}
                    saatKonfirmasi={() => Kirim('', 'delete')}
                    saatBatal={() => AturKonfirmasi(null)}
                >
                    <p>Draf artikel dihapus permanen.</p>
                </DialogKonfirmasi>
            ) : null}
            <form onSubmit={Simpan} className="flex flex-col gap-4" noValidate>
                <Card className="grid gap-4 px-6 py-6 sm:grid-cols-2 rounded-panel shadow-none">
                    <h2 className="text-subjudul font-semibold text-teks-utama sm:col-span-2">Artikel</h2>
                    <BidangTeks
                        label="Judul"
                        nilai={d.Judul}
                        saatBerubah={(v) => formulir.setData('Judul', v)}
                        galat={galat.Judul}
                        maxLength={150}
                        required
                    />
                    <BidangTeks
                        label="Alamat artikel"
                        kode
                        keterangan="Huruf kecil, angka, tanda hubung. Menjadi payoung.id/pusat-bantuan/alamat."
                        nilai={d.Slug}
                        saatBerubah={(v) => formulir.setData('Slug', v.toLowerCase())}
                        galat={galat.Slug}
                        maxLength={120}
                    />
                    <BidangTeks
                        label="Kategori"
                        keterangan={
                            PilihanKategori.length > 0
                                ? `Kategori yang ada: ${PilihanKategori.join(', ')}.`
                                : 'Misalnya Mulai cepat, Penjualan, Stok, Keuangan.'
                        }
                        nilai={d.Kategori}
                        saatBerubah={(v) => formulir.setData('Kategori', v)}
                        galat={galat.Kategori}
                        maxLength={60}
                    />
                    <BidangTeks
                        label="Urutan"
                        keterangan="Angka kecil tampil lebih dulu dalam kategorinya."
                        nilai={d.Urutan}
                        saatBerubah={(v) => formulir.setData('Urutan', v.replace(/\D/g, ''))}
                        galat={galat.Urutan}
                        inputMode="numeric"
                        maxLength={4}
                    />
                    <BidangTeks
                        label="Tautan video (opsional)"
                        keterangan="https dari YouTube atau Vimeo. Tampil sebagai tautan Tonton video."
                        nilai={d.UrlVideo}
                        saatBerubah={(v) => formulir.setData('UrlVideo', v)}
                        galat={galat.UrlVideo}
                        maxLength={300}
                    />
                    <BidangTeksPanjang
                        label="Ringkasan"
                        keterangan={`${d.Ringkasan.length}/300. Tampil di daftar Pusat Bantuan & sebagai deskripsi Google.`}
                        nilai={d.Ringkasan}
                        saatBerubah={(v) => formulir.setData('Ringkasan', v)}
                        galat={galat.Ringkasan}
                        maksimal={300}
                        baris={3}
                    />
                    <div className="sm:col-span-2">
                        <BidangTeksPanjang
                            label="Isi artikel"
                            keterangan={KETERANGAN_ISI}
                            nilai={d.Isi}
                            saatBerubah={(v) => formulir.setData('Isi', v)}
                            galat={galat.Isi}
                            maksimal={60000}
                            baris={18}
                            required
                        />
                    </div>
                    <div className="sm:col-span-2">
                        <BidangTeksPanjang
                            label="Halaman konteks (opsional)"
                            keterangan="Satu jalur back-office per baris, misalnya /kelola/produk. Artikel muncul di tombol Bantuan pada halaman itu dan halaman di bawahnya. Paling banyak 10."
                            nilai={d.Konteks}
                            saatBerubah={(v) => formulir.setData('Konteks', v)}
                            galat={galat.Konteks ?? galat['Konteks.0']}
                            baris={4}
                            kode
                        />
                    </div>
                </Card>
                {Izin.Kelola ? (
                    <BilahAksiForm>
                        <Tombol type="submit" memproses={formulir.processing}>
                            {terbit ? 'Simpan & perbarui situs' : 'Simpan draf'}
                        </Tombol>
                    </BilahAksiForm>
                ) : null}
            </form>
            <Card className="flex flex-col gap-3 px-6 py-6 rounded-panel shadow-none">
                <h2 className="text-subjudul font-semibold text-teks-utama">Pratinjau isi</h2>
                {d.Isi.trim() === '' ? (
                    <p className="text-isi text-teks-sekunder">Isi artikel masih kosong.</p>
                ) : (
                    <TeksKaya teks={d.Isi} className="text-subjudul text-teks-utama" />
                )}
            </Card>
        </TataLetakPengelola>
    );
}
