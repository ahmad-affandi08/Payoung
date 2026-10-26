import { Link, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import TataLetakAutentikasi from '@/TataLetak/TataLetakAutentikasi';

type PropsTerima = {
    Token: string;
    Berlaku: boolean;
    Email: string | null;
    NamaTenant: string | null;
    AkunAda: boolean;
    EmailMasuk: string | null;
};

/** Menerima undangan anggota (F-02 langkah 3, BR-00.1): buat akun baru atau tautkan akun yang sudah ada. */
export default function HalamanTerimaUndangan({ Token, Berlaku, Email, NamaTenant, AkunAda, EmailMasuk }: PropsTerima) {
    const formulir = useForm({ Nama: '', NoHp: '', KataSandi: '', KonfirmasiKataSandi: '' });
    const alamat = `/undangan/${Token}`;

    if (!Berlaku || Email === null) {
        return (
            <TataLetakAutentikasi judul="Undangan tidak berlaku">
                <div className="flex flex-col gap-3 text-isi text-teks-sekunder">
                    <p>Undangan ini sudah kedaluwarsa, dibatalkan, atau sudah dipakai.</p>
                    <p>Minta pemilik usaha mengirim undangan baru ke email Anda.</p>
                    <Link href="/masuk" className="font-semibold text-brand underline">
                        Masuk ke akun Anda
                    </Link>
                </div>
            </TataLetakAutentikasi>
        );
    }

    const judul = `Bergabung ke ${NamaTenant ?? 'usaha'}`;
    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post(alamat, { onFinish: () => formulir.reset('KataSandi', 'KonfirmasiKataSandi') });
    };

    if (EmailMasuk !== null) {
        const cocok = EmailMasuk.toLowerCase() === Email;

        return (
            <TataLetakAutentikasi judul={judul} keterangan={`Undangan untuk ${Email}.`}>
                {cocok ? (
                    <form onSubmit={Kirim} className="flex flex-col gap-4">
                        <p className="text-isi text-teks-sekunder">
                            Anda masuk sebagai {EmailMasuk}. Terima undangan untuk menambahkan {NamaTenant} ke daftar
                            usaha Anda.
                        </p>
                        <Tombol type="submit" memproses={formulir.processing}>
                            Terima undangan
                        </Tombol>
                    </form>
                ) : (
                    <div className="flex flex-col gap-4">
                        <Pemberitahuan jenis="peringatan" judul="Email berbeda">
                            Anda masuk sebagai {EmailMasuk}, sedangkan undangan ini untuk {Email}. Keluar, lalu masuk
                            dengan email undangan.
                        </Pemberitahuan>
                        <Tombol varian="sekunder" onClick={() => router.post('/keluar')}>
                            Keluar
                        </Tombol>
                    </div>
                )}
            </TataLetakAutentikasi>
        );
    }

    if (AkunAda) {
        return (
            <TataLetakAutentikasi judul={judul} keterangan={`Undangan untuk ${Email}.`}>
                <div className="flex flex-col gap-4 text-isi text-teks-sekunder">
                    <p>
                        Email ini sudah punya akun. Masuk dulu. Setelah itu Anda kembali ke halaman ini untuk menerima
                        undangan.
                    </p>
                    <Button
                        asChild
                        className="h-8 pointer-coarse:h-11 px-4 text-label font-semibold focus-visible:ring-offset-2"
                    >
                        <Link href="/masuk">Masuk untuk menerima undangan</Link>
                    </Button>
                </div>
            </TataLetakAutentikasi>
        );
    }

    return (
        <TataLetakAutentikasi judul={judul} keterangan={`Buat akun untuk ${Email}.`}>
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeks
                    label="Nama lengkap"
                    nilai={formulir.data.Nama}
                    saatBerubah={(nilai) => formulir.setData('Nama', nilai)}
                    galat={formulir.errors.Nama}
                    autoComplete="name"
                    maxLength={150}
                    autoFocus
                    required
                />
                <BidangTeks
                    label="Nomor WhatsApp (opsional)"
                    nilai={formulir.data.NoHp}
                    saatBerubah={(nilai) => formulir.setData('NoHp', nilai)}
                    galat={formulir.errors.NoHp}
                    keterangan="Misal 081234567890"
                    inputMode="tel"
                    autoComplete="tel"
                />
                <BidangTeks
                    label="Kata sandi"
                    jenis="password"
                    nilai={formulir.data.KataSandi}
                    saatBerubah={(nilai) => formulir.setData('KataSandi', nilai)}
                    galat={formulir.errors.KataSandi}
                    keterangan="Minimal 8 karakter, berisi huruf dan angka."
                    autoComplete="new-password"
                    required
                />
                <BidangTeks
                    label="Ulangi kata sandi"
                    jenis="password"
                    nilai={formulir.data.KonfirmasiKataSandi}
                    saatBerubah={(nilai) => formulir.setData('KonfirmasiKataSandi', nilai)}
                    galat={formulir.errors.KonfirmasiKataSandi}
                    autoComplete="new-password"
                    required
                />
                <Tombol type="submit" memproses={formulir.processing}>
                    Buat akun & bergabung
                </Tombol>
            </form>
        </TataLetakAutentikasi>
    );
}
