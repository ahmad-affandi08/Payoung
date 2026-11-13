import { Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import AksiHalaman from '@/Komponen/Kelola/AksiHalaman';
import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import PemilihGambarSitus from '@/Komponen/Pengelola/Situs/PemilihGambarSitus';
import TabSitus from '@/Komponen/Pengelola/Situs/TabSitus';
import TeksKaya from '@/Komponen/Situs/TeksKaya';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { Card } from '@/Komponen/Ui/card';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import TataLetakPengelola from '@/TataLetak/TataLetakPengelola';

import type { RingkasArtikelSitus } from './Daftar';

type ArtikelLengkap = RingkasArtikelSitus & {
    Ringkasan: string | null;
    Isi: string;
    NamaPenulis: string | null;
    UuidGambarSampul: string | null;
    JudulSeo: string | null;
    DeskripsiSeo: string | null;
};

const KETERANGAN_ISI =
    'Baris kosong = paragraf baru. "## " = subjudul, "- " = butir daftar, **tebal**, [teks](tautan). Tanpa HTML.';

/** Menulis & menerbitkan satu artikel blog (bagian B2). Artikel terbit langsung berubah setelah disimpan. */
export default function HalamanUbahArtikelSitus({
    Artikel: artikel,
    PilihanKategori,
    UrlArtikel,
    Izin,
}: {
    Artikel: ArtikelLengkap;
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
        NamaPenulis: artikel.NamaPenulis ?? '',
        UuidGambarSampul: artikel.UuidGambarSampul,
        JudulSeo: artikel.JudulSeo ?? '',
        DeskripsiSeo: artikel.DeskripsiSeo ?? '',
    };
    const formulir = useForm(awal);
    const d = formulir.data;
    const galat = formulir.errors as Record<string, string | undefined>;
    const [konfirmasi, AturKonfirmasi] = useState<'terbit' | 'tarik' | 'hapus' | null>(null);
    const [memproses, AturMemproses] = useState(false);
    const terbit = artikel.Status === 'Terbit';
    const alamat = `/situs/artikel/${artikel.Uuid}`;

    const Simpan = (p: FormEvent) => {
        p.preventDefault();
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
        <TataLetakPengelola judul={artikel.Judul}>
            <TabSitus />
            <Link href="/situs/artikel" className="text-label font-semibold text-brand underline">
                Semua artikel
            </Link>
            {galat.Umum ? <Pemberitahuan jenis="bahaya">{galat.Umum}</Pemberitahuan> : null}
            <div className="flex flex-wrap items-center gap-2 text-keterangan text-teks-sekunder">
                <LabelStatus jenis={terbit ? 'sukses' : 'peringatan'} teks={artikel.LabelStatus} />
                <span className="font-mono break-all">payoung.id{artikel.Jalur}</span>
                {artikel.DiterbitkanPada ? <span>Terbit {FormatTanggalWaktu(artikel.DiterbitkanPada)}</span> : null}
            </div>
            <AksiHalaman
                langkah={
                    Izin.Kelola && !terbit
                        ? [
                              {
                                  label: 'Terbitkan',
                                  saatKlik: () => AturKonfirmasi('terbit'),
                                  nonaktif: formulir.isDirty,
                              },
                          ]
                        : []
                }
                lainnya={[
                    ...(terbit ? [{ label: 'Lihat di situs', href: UrlArtikel, tabBaru: true }] : []),
                    ...(Izin.Kelola && terbit
                        ? [{ label: 'Tarik ke draf', saatKlik: () => AturKonfirmasi('tarik') }]
                        : []),
                    ...(Izin.Kelola && !terbit
                        ? [{ label: 'Hapus', saatKlik: () => AturKonfirmasi('hapus'), bahaya: true }]
                        : []),
                ]}
            />
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
                    <p>Artikel tampil di blog, peta situs, dan bisa ditemukan mesin pencari.</p>
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
                        keterangan="Huruf kecil, angka, tanda hubung. Menjadi payoung.id/blog/alamat."
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
                                : 'Misalnya Tips kasir, Keuangan, Pemasaran.'
                        }
                        nilai={d.Kategori}
                        saatBerubah={(v) => formulir.setData('Kategori', v)}
                        galat={galat.Kategori}
                        maxLength={60}
                    />
                    <BidangTeks
                        label="Nama penulis"
                        keterangan="Kosong = nama situs."
                        nilai={d.NamaPenulis}
                        saatBerubah={(v) => formulir.setData('NamaPenulis', v)}
                        galat={galat.NamaPenulis}
                        maxLength={80}
                    />
                    <PemilihGambarSitus
                        label="Gambar sampul"
                        keterangan="Tampil di daftar blog & saat tautan dibagikan. Ukuran ideal 1200×630."
                        nilai={d.UuidGambarSampul}
                        saatBerubah={(v) => formulir.setData('UuidGambarSampul', v)}
                        galat={galat.UuidGambarSampul}
                        bolehUbah={Izin.Kelola}
                    />
                    <BidangTeksPanjang
                        label="Ringkasan"
                        keterangan={`${d.Ringkasan.length}/300. Tampil di daftar blog & sebagai deskripsi Google bila kosong.`}
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
                    <BidangTeks
                        label="Judul di Google (opsional)"
                        keterangan={`${d.JudulSeo.length}/70. Kosong = judul | nama situs.`}
                        nilai={d.JudulSeo}
                        saatBerubah={(v) => formulir.setData('JudulSeo', v)}
                        galat={galat.JudulSeo}
                        maxLength={70}
                    />
                    <BidangTeks
                        label="Deskripsi di Google (opsional)"
                        keterangan={`${d.DeskripsiSeo.length}/170. Kosong = ringkasan.`}
                        nilai={d.DeskripsiSeo}
                        saatBerubah={(v) => formulir.setData('DeskripsiSeo', v)}
                        galat={galat.DeskripsiSeo}
                        maxLength={170}
                    />
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
