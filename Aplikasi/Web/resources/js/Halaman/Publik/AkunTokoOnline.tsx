import { Head, router } from '@inertiajs/react';
import { ArrowLeftIcon, ReceiptTextIcon, ShoppingBagIcon, SparklesIcon } from 'lucide-react';
import { useState, type FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import KerangkaMerek from '@/Komponen/Publik/KerangkaMerek';
import DialogMasukPembeli, { type ProfilPembeli } from '@/Komponen/TokoOnline/DialogMasukPembeli';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatTanggal, FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import { KirimJson } from '@/Pustaka/PermintaanJson';

type PesananRiwayat = {
    Nomor: string;
    Outlet: string | null;
    JenisPemenuhan: string;
    Status: string;
    LabelStatus: string;
    Total: string;
    DibuatPada: string | null;
    UrlStatus: string;
};

type BelanjaRiwayat = {
    Nomor: string;
    Outlet: string | null;
    Tanggal: string;
    Status: string;
    LabelStatus: string;
    Total: string;
    UrlStruk: string;
};

type Props = {
    Slug: string;
    Toko: { Nama: string; UrlLogo?: string | null } | null;
    AkunAktif: boolean;
    Pelanggan: ProfilPembeli | null;
    Riwayat: { Pesanan: PesananRiwayat[]; Belanja: BelanjaRiwayat[] } | null;
};

const statusGagal = ['Ditolak', 'Dibatalkan', 'Kedaluwarsa', 'Void', 'Diretur'];
const statusSelesai = ['Selesai', 'Lunas'];

function AmbilJenisStatus(status: string): 'sukses' | 'bahaya' | 'netral' {
    if (statusGagal.includes(status)) return 'bahaya';
    if (statusSelesai.includes(status)) return 'sukses';
    return 'netral';
}

/**
 * F-17 bagian 3: "Akun saya" pembeli toko online. Belum masuk → ajakan masuk dengan WhatsApp. Sudah masuk → profil,
 * poin & tier, riwayat pesanan online dan belanja di kasir (satu pelanggan, satu riwayat), ubah profil, keluar.
 */
export default function AkunTokoOnline({ Slug, Toko, AkunAktif, Pelanggan, Riwayat }: Props) {
    const [dialogMasuk, AturDialogMasuk] = useState(false);
    const [profil, AturProfil] = useState(() => ({
        Nama: Pelanggan?.Nama ?? '',
        Email: Pelanggan?.Email ?? '',
        TanggalLahir: Pelanggan?.TanggalLahir ?? '',
        SetujuPemasaran: Pelanggan?.SetujuPemasaran ?? false,
    }));
    const [pesan, AturPesan] = useState<{ jenis: 'sukses' | 'bahaya'; teks: string } | null>(null);
    const [memproses, AturMemproses] = useState<'profil' | 'keluar' | null>(null);

    function MuatUlang() {
        router.reload();
    }

    async function SimpanProfil(e: FormEvent) {
        e.preventDefault();
        AturMemproses('profil');
        AturPesan(null);
        try {
            await KirimJson(
                `/${Slug}/akun/profil`,
                { ...profil, Email: profil.Email || null, TanggalLahir: profil.TanggalLahir || null },
                'PUT',
            );
            AturPesan({ jenis: 'sukses', teks: 'Profil tersimpan.' });
            MuatUlang();
        } catch (galat: unknown) {
            AturPesan({ jenis: 'bahaya', teks: galat instanceof Error ? galat.message : 'Profil belum tersimpan.' });
        } finally {
            AturMemproses(null);
        }
    }

    async function Keluar(semua: boolean) {
        AturMemproses('keluar');
        try {
            await KirimJson(`/${Slug}/akun/keluar`, { Semua: semua });
            window.location.assign(`/${Slug}`);
        } catch (galat: unknown) {
            AturPesan({ jenis: 'bahaya', teks: galat instanceof Error ? galat.message : 'Belum berhasil keluar.' });
            AturMemproses(null);
        }
    }

    return (
        <KerangkaMerek
            toko={Toko}
            lebar="3xl"
            label="Akun saya"
            judul={Pelanggan?.Nama ?? (Toko ? `Masuk ke ${Toko.Nama}` : 'Akun saya')}
            skala="situs"
            keterangan={
                <a
                    href={`/${Slug}`}
                    className="inline-flex min-h-10 items-center gap-1.5 font-semibold text-brand-gelap-teks underline"
                >
                    <ArrowLeftIcon aria-hidden="true" className="size-4" />
                    Kembali belanja di {Toko?.Nama ?? 'toko'}
                </a>
            }
        >
            <Head title={Toko ? `Akun saya | ${Toko.Nama}` : 'Akun saya'} />
            {Toko === null ? (
                <Pemberitahuan jenis="peringatan">Toko tidak ditemukan.</Pemberitahuan>
            ) : Pelanggan === null ? (
                <section className="flex flex-col gap-4 rounded-3xl bg-permukaan p-5">
                    <h2 className="text-judul-bagian-hp font-extrabold text-teks-utama">Masuk tanpa kata sandi</h2>
                    {AkunAktif ? (
                        <>
                            <p className="text-isi text-teks-sekunder">
                                Masuk dengan nomor WhatsApp untuk melihat riwayat belanja dan poin Anda. Tidak perlu
                                kata sandi.
                            </p>
                            <ul className="flex flex-col gap-2 text-isi text-teks-utama">
                                <li className="flex items-center gap-2">
                                    <ReceiptTextIcon aria-hidden="true" className="size-5 text-brand" />
                                    Semua pesanan dan belanja di satu tempat
                                </li>
                                <li className="flex items-center gap-2">
                                    <SparklesIcon aria-hidden="true" className="size-5 text-brand" />
                                    Poin dan tingkat member terlihat langsung
                                </li>
                                <li className="flex items-center gap-2">
                                    <ShoppingBagIcon aria-hidden="true" className="size-5 text-brand" />
                                    Data pesanan terisi otomatis
                                </li>
                            </ul>
                            <Tombol ukuran="besar" bentuk="pil" onClick={() => AturDialogMasuk(true)}>
                                Masuk dengan WhatsApp
                            </Tombol>
                        </>
                    ) : (
                        <p className="text-isi text-teks-sekunder">
                            Toko ini belum membuka akun pembeli. Anda tetap bisa memesan tanpa masuk.
                        </p>
                    )}
                </section>
            ) : (
                <>
                    <section aria-label="Kartu member" className="flex flex-col gap-4 rounded-3xl bg-permukaan p-5">
                        <dl className="grid grid-cols-2 gap-3">
                            <div className="flex flex-col gap-1 rounded-2xl bg-brand-lembut p-4">
                                <dt className="text-keterangan font-semibold text-teks-sekunder">Tingkat member</dt>
                                <dd className="text-judul-bagian-hp font-extrabold break-words text-teks-utama">
                                    {Pelanggan.Tier ?? 'Belum ada'}
                                </dd>
                            </div>
                            {Pelanggan.Poin !== null ? (
                                <div className="flex flex-col gap-1 rounded-2xl bg-brand-gelap p-4 text-permukaan">
                                    <dt className="text-keterangan font-semibold text-brand-gelap-teks">Poin</dt>
                                    <dd className="text-judul-bagian-hp font-extrabold tabular-nums">
                                        {Pelanggan.Poin.toLocaleString('id-ID')} poin
                                    </dd>
                                </div>
                            ) : null}
                        </dl>
                        <p className="text-isi text-teks-sekunder">
                            WhatsApp{' '}
                            <span className="font-semibold text-teks-utama tabular-nums">{Pelanggan.NoHp}</span>
                        </p>
                        <p className="text-keterangan text-teks-sekunder">
                            Poin bertambah saat pesanan dibayar di toko. Nomor WhatsApp adalah identitas akun Anda dan
                            tidak bisa diganti di sini.
                        </p>
                    </section>

                    <section
                        aria-labelledby="judul-pesanan"
                        className="flex flex-col gap-3 rounded-3xl bg-permukaan p-5"
                    >
                        <h2 id="judul-pesanan" className="text-judul-bagian-hp font-extrabold text-teks-utama">
                            Pesanan online
                        </h2>
                        {(Riwayat?.Pesanan.length ?? 0) === 0 ? (
                            <p className="text-isi text-teks-sekunder">Belum ada pesanan online.</p>
                        ) : (
                            <ul className="flex flex-col gap-2">
                                {Riwayat?.Pesanan.map((p) => (
                                    <li
                                        key={p.Nomor}
                                        className="flex flex-wrap items-center justify-between gap-2 rounded-2xl border-2 border-garis p-3"
                                    >
                                        <div className="flex min-w-0 flex-col">
                                            <a href={p.UrlStatus} className="font-mono text-isi text-brand underline">
                                                {p.Nomor}
                                            </a>
                                            <span className="text-keterangan text-teks-sekunder">
                                                {FormatTanggalWaktu(p.DibuatPada)} | {p.JenisPemenuhan}
                                                {p.Outlet ? ` | ${p.Outlet}` : ''}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <LabelStatus jenis={AmbilJenisStatus(p.Status)} teks={p.LabelStatus} />
                                            <span className="font-bold tabular-nums">{FormatRupiah(p.Total)}</span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section
                        aria-labelledby="judul-belanja"
                        className="flex flex-col gap-3 rounded-3xl bg-permukaan p-5"
                    >
                        <h2 id="judul-belanja" className="text-judul-bagian-hp font-extrabold text-teks-utama">
                            Belanja di toko
                        </h2>
                        {(Riwayat?.Belanja.length ?? 0) === 0 ? (
                            <p className="text-isi text-teks-sekunder">Belum ada belanja tercatat.</p>
                        ) : (
                            <ul className="flex flex-col gap-2">
                                {Riwayat?.Belanja.map((b) => (
                                    <li
                                        key={b.Nomor}
                                        className="flex flex-wrap items-center justify-between gap-2 rounded-2xl border-2 border-garis p-3"
                                    >
                                        <div className="flex min-w-0 flex-col">
                                            <a href={b.UrlStruk} className="font-mono text-isi text-brand underline">
                                                {b.Nomor}
                                            </a>
                                            <span className="text-keterangan text-teks-sekunder">
                                                {FormatTanggal(b.Tanggal)}
                                                {b.Outlet ? ` | ${b.Outlet}` : ''}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <LabelStatus jenis={AmbilJenisStatus(b.Status)} teks={b.LabelStatus} />
                                            <span className="font-bold tabular-nums">{FormatRupiah(b.Total)}</span>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>

                    <section className="flex flex-col gap-3 rounded-3xl bg-permukaan p-5">
                        <h2 className="text-judul-bagian-hp font-extrabold text-teks-utama">Profil</h2>
                        <form noValidate onSubmit={SimpanProfil} className="flex flex-col gap-4">
                            <BidangTeks
                                label="Nama"
                                nilai={profil.Nama}
                                saatBerubah={(v) => AturProfil((x) => ({ ...x, Nama: v }))}
                                autoComplete="name"
                                required
                            />
                            <BidangTeks
                                label="Email (opsional)"
                                nilai={profil.Email}
                                saatBerubah={(v) => AturProfil((x) => ({ ...x, Email: v }))}
                                jenis="email"
                                autoComplete="email"
                            />
                            <PemilihTanggal
                                label="Tanggal lahir (opsional)"
                                nilai={profil.TanggalLahir}
                                saatBerubah={(v) => AturProfil((x) => ({ ...x, TanggalLahir: v }))}
                                disabled={Pelanggan.TanggalLahir !== null}
                                keterangan={
                                    Pelanggan.TanggalLahir !== null
                                        ? 'Sudah tersimpan. Untuk mengubahnya, hubungi toko.'
                                        : 'Hanya bisa diisi sekali. Dipakai untuk promo ulang tahun bila toko punya.'
                                }
                            />
                            <KotakCentang
                                label="Kirimi saya info promo lewat WhatsApp."
                                nilai={profil.SetujuPemasaran}
                                saatBerubah={(v) => AturProfil((x) => ({ ...x, SetujuPemasaran: v }))}
                            />
                            {pesan ? <Pemberitahuan jenis={pesan.jenis}>{pesan.teks}</Pemberitahuan> : null}
                            <Tombol type="submit" ukuran="besar" bentuk="pil" memproses={memproses === 'profil'}>
                                Simpan profil
                            </Tombol>
                        </form>
                    </section>

                    <section className="flex flex-wrap gap-3">
                        <Tombol
                            varian="sekunder"
                            bentuk="pil"
                            memproses={memproses === 'keluar'}
                            onClick={() => void Keluar(false)}
                        >
                            Keluar
                        </Tombol>
                        <Tombol
                            varian="sekunder"
                            bentuk="pil"
                            disabled={memproses !== null}
                            onClick={() => void Keluar(true)}
                        >
                            Keluar dari semua perangkat
                        </Tombol>
                    </section>
                </>
            )}
            {dialogMasuk && Toko ? (
                <DialogMasukPembeli
                    slug={Slug}
                    namaToko={Toko.Nama}
                    saatMasuk={() => {
                        AturDialogMasuk(false);
                        MuatUlang();
                    }}
                    saatTutup={() => AturDialogMasuk(false)}
                />
            ) : null}
        </KerangkaMerek>
    );
}
