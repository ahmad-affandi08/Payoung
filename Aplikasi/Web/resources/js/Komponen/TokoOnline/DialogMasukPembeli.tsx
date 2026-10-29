import { useEffect, useState, type FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { KirimJson } from '@/Pustaka/PermintaanJson';

export type ProfilPembeli = {
    Uuid: string;
    Nama: string;
    NoHp: string;
    Email: string | null;
    TanggalLahir: string | null;
    SetujuPemasaran: boolean;
    Tier: string | null;
    Poin: number | null;
};

export type AlamatPembeli = {
    Alamat: string;
    Kelurahan: string | null;
    Kecamatan: string | null;
    Kota: string | null;
    Provinsi: string | null;
    KodePos: string | null;
};

export type HasilMasukPembeli = { Pelanggan: ProfilPembeli; AlamatTerakhir: AlamatPembeli | null };

type Props = {
    slug: string;
    namaToko: string;
    saatMasuk: (hasil: HasilMasukPembeli) => void;
    saatTutup: () => void;
};

type Tahap = 'Nomor' | 'Kode' | 'Daftar';

/**
 * F-17 bagian 3: masuk ke toko online dengan kode WhatsApp, tanpa kata sandi. Tiga tahap dalam satu dialog supaya
 * keranjang di halaman belakang tidak hilang: nomor → kode 6 digit → (nomor baru) nama & persetujuan data.
 * Server tidak memberi tahu apakah nomor sudah terdaftar sebelum kodenya benar.
 */
export default function DialogMasukPembeli({ slug, namaToko, saatMasuk, saatTutup }: Props) {
    const [tahap, AturTahap] = useState<Tahap>('Nomor');
    const [noHp, AturNoHp] = useState('');
    const [kode, AturKode] = useState('');
    const [tokenDaftar, AturTokenDaftar] = useState('');
    const [nama, AturNama] = useState('');
    const [email, AturEmail] = useState('');
    const [setujuData, AturSetujuData] = useState(false);
    const [setujuPemasaran, AturSetujuPemasaran] = useState(false);
    const [galat, AturGalat] = useState<string | undefined>(undefined);
    const [memproses, AturMemproses] = useState(false);
    const [kirimUlangPada, AturKirimUlangPada] = useState<number | null>(null);
    const [sekarang, AturSekarang] = useState(() => Date.now());

    useEffect(() => {
        if (kirimUlangPada === null) return;
        const detak = window.setInterval(() => AturSekarang(Date.now()), 1000);
        return () => window.clearInterval(detak);
    }, [kirimUlangPada]);

    const sisaDetik = kirimUlangPada === null ? 0 : Math.max(0, Math.ceil((kirimUlangPada - sekarang) / 1000));

    async function Jalankan(kerja: () => Promise<void>) {
        AturMemproses(true);
        AturGalat(undefined);
        try {
            await kerja();
        } catch (e: unknown) {
            AturGalat(e instanceof Error ? e.message : 'Permintaan belum berhasil. Coba lagi.');
        } finally {
            AturMemproses(false);
        }
    }

    function MintaKode(e?: FormEvent) {
        e?.preventDefault();
        void Jalankan(async () => {
            const hasil = await KirimJson<{ KirimUlangPada: string }>(`/${slug}/akun/kode`, { NoHp: noHp });
            AturKirimUlangPada(Date.parse(hasil.KirimUlangPada));
            AturSekarang(Date.now());
            AturKode('');
            AturTahap('Kode');
        });
    }

    function Masuk(e: FormEvent) {
        e.preventDefault();
        void Jalankan(async () => {
            const hasil = await KirimJson<{ PerluDaftar: boolean; TokenDaftar?: string } & Partial<HasilMasukPembeli>>(
                `/${slug}/akun/masuk`,
                { NoHp: noHp, Kode: kode },
            );
            if (hasil.PerluDaftar) {
                AturTokenDaftar(hasil.TokenDaftar ?? '');
                AturTahap('Daftar');
                return;
            }
            if (hasil.Pelanggan)
                saatMasuk({ Pelanggan: hasil.Pelanggan, AlamatTerakhir: hasil.AlamatTerakhir ?? null });
        });
    }

    function Daftar(e: FormEvent) {
        e.preventDefault();
        void Jalankan(async () => {
            const hasil = await KirimJson<HasilMasukPembeli>(`/${slug}/akun/daftar`, {
                TokenDaftar: tokenDaftar,
                NoHp: noHp,
                Nama: nama,
                Email: email || null,
                SetujuDataPribadi: setujuData,
                SetujuPemasaran: setujuPemasaran,
            });
            saatMasuk({ Pelanggan: hasil.Pelanggan, AlamatTerakhir: hasil.AlamatTerakhir });
        });
    }

    const keterangan =
        tahap === 'Nomor'
            ? 'Masuk untuk mengisi data otomatis, melihat riwayat belanja, dan mengumpulkan poin. Tanpa kata sandi: kami kirim kode ke WhatsApp Anda.'
            : tahap === 'Kode'
              ? `Kode 6 digit sudah dikirim ke WhatsApp ${noHp}. Berlaku 5 menit.`
              : `Nomor terverifikasi. Lengkapi data Anda untuk menjadi pelanggan ${namaToko}.`;

    return (
        <DialogFormulir judul="Masuk dengan WhatsApp" keterangan={keterangan} galatUmum={galat} saatTutup={saatTutup}>
            {tahap === 'Nomor' ? (
                <form onSubmit={MintaKode} className="flex flex-col gap-4">
                    <BidangTeks
                        label="Nomor WhatsApp"
                        nilai={noHp}
                        saatBerubah={AturNoHp}
                        inputMode="tel"
                        autoComplete="tel"
                        autoFocus
                        required
                    />
                    <Tombol type="submit" memproses={memproses} disabled={noHp.trim().length < 9}>
                        Kirim kode
                    </Tombol>
                </form>
            ) : tahap === 'Kode' ? (
                <form onSubmit={Masuk} className="flex flex-col gap-4">
                    <BidangTeks
                        label="Kode dari WhatsApp"
                        nilai={kode}
                        saatBerubah={(v) => AturKode(v.replace(/\D/g, '').slice(0, 6))}
                        inputMode="numeric"
                        kode
                        autoComplete="one-time-code"
                        maxLength={6}
                        autoFocus
                        required
                    />
                    <Tombol type="submit" memproses={memproses} disabled={kode.length !== 6}>
                        Masuk
                    </Tombol>
                    <div className="flex flex-wrap items-center justify-between gap-2 text-keterangan text-teks-sekunder">
                        <button type="button" className="min-h-9 underline" onClick={() => AturTahap('Nomor')}>
                            Ganti nomor
                        </button>
                        <button
                            type="button"
                            className="min-h-9 underline disabled:no-underline disabled:opacity-60"
                            disabled={sisaDetik > 0 || memproses}
                            onClick={() => MintaKode()}
                        >
                            {sisaDetik > 0 ? `Kirim ulang dalam ${String(sisaDetik)} detik` : 'Kirim ulang kode'}
                        </button>
                    </div>
                </form>
            ) : (
                <form onSubmit={Daftar} className="flex flex-col gap-4">
                    <BidangTeks
                        label="Nama"
                        nilai={nama}
                        saatBerubah={AturNama}
                        autoComplete="name"
                        autoFocus
                        required
                    />
                    <BidangTeks
                        label="Email (opsional)"
                        nilai={email}
                        saatBerubah={AturEmail}
                        jenis="email"
                        autoComplete="email"
                    />
                    <KotakCentang
                        label={`Saya setuju data saya (nama, nomor WhatsApp, riwayat belanja) disimpan ${namaToko} untuk melayani pesanan dan program pelanggan.`}
                        nilai={setujuData}
                        saatBerubah={AturSetujuData}
                    />
                    <KotakCentang
                        label="Kirimi saya info promo lewat WhatsApp (opsional, bisa dimatikan kapan saja)."
                        nilai={setujuPemasaran}
                        saatBerubah={AturSetujuPemasaran}
                    />
                    <Tombol type="submit" memproses={memproses} disabled={nama.trim().length < 2 || !setujuData}>
                        Simpan & masuk
                    </Tombol>
                </form>
            )}
        </DialogFormulir>
    );
}
