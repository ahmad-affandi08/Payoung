import { Link, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import TombolGoogle, { PemisahAtau } from '@/Komponen/Formulir/TombolGoogle';
import Tombol from '@/Komponen/Formulir/Tombol';
import TataLetakAutentikasi from '@/TataLetak/TataLetakAutentikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';

/** Masuk back-office tenant. Masuk dengan Google (D-57) menggantikan verifikasi dua langkah. */
export default function HalamanMasuk({ MasukGoogle = false }: { MasukGoogle?: boolean }) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const formulir = useForm({ Email: '', KataSandi: '', Ingat: false });

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.post('/masuk', { onFinish: () => formulir.reset('KataSandi') });
    };

    return (
        <TataLetakAutentikasi judul="Masuk" keterangan="Selamat datang kembali. Lanjutkan mengelola toko Anda.">
            <form onSubmit={Kirim} className="flex flex-col gap-4" noValidate>
                <BidangTeks
                    label="Email"
                    jenis="email"
                    autoComplete="email"
                    nilai={formulir.data.Email}
                    saatBerubah={(nilai) => formulir.setData('Email', nilai)}
                    galat={formulir.errors.Email}
                    required
                />
                <BidangTeks
                    label="Kata sandi"
                    jenis="password"
                    autoComplete="current-password"
                    nilai={formulir.data.KataSandi}
                    saatBerubah={(nilai) => formulir.setData('KataSandi', nilai)}
                    galat={formulir.errors.KataSandi}
                    required
                />
                <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                    <KotakCentang
                        label="Ingat saya di perangkat ini"
                        nilai={formulir.data.Ingat}
                        saatBerubah={(nilai) => formulir.setData('Ingat', nilai)}
                    />
                    <Link href="/lupa-kata-sandi" className="text-label font-semibold text-brand underline">
                        Lupa kata sandi?
                    </Link>
                </div>
                <Tombol type="submit" memproses={formulir.processing}>
                    Masuk
                </Tombol>
                {MasukGoogle ? (
                    <>
                        <PemisahAtau />
                        <TombolGoogle href="/masuk/google">Masuk dengan Google</TombolGoogle>
                    </>
                ) : null}
                {/* D-35: edisi Lisensi tanpa pendaftaran publik; akun dibuat Owner dari menu Pengguna. */}
                {props.Edisi === 'Lisensi' ? null : (
                    <p className="text-keterangan text-teks-sekunder">
                        Belum punya akun?{' '}
                        <Link href="/daftar" className="font-semibold text-brand underline">
                            Daftar gratis
                        </Link>
                    </p>
                )}
            </form>
        </TataLetakAutentikasi>
    );
}
