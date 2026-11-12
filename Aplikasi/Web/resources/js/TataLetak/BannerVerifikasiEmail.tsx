import { Link, router } from '@inertiajs/react';
import { XIcon } from 'lucide-react';
import { useState } from 'react';

import { KelasBilahStatus, TitikBilahStatus } from '@/Komponen/Umpan/Pemberitahuan';

const KUNCI_TUTUP = 'payoung.verifikasi-email.ditutup';

function SudahDitutup(): boolean {
    try {
        return window.sessionStorage.getItem(KUNCI_TUTUP) === '1';
    } catch {
        return false;
    }
}

const kelasTautan = 'pointer-coarse:min-h-11 pointer-coarse:inline-flex pointer-coarse:items-center';

/**
 * D-271: pengingat verifikasi email sebagai bilah satu baris yang bisa ditutup (sampai sesi peramban berakhir),
 * bukan blok besar di setiap halaman. Tertutup hanya menyembunyikan di peramban ini, status verifikasi tidak berubah.
 */
export default function BannerVerifikasiEmail({ email }: { email: string }) {
    const [ditutup, AturDitutup] = useState(SudahDitutup);
    const [mengirim, AturMengirim] = useState(false);

    if (ditutup) {
        return null;
    }

    const Tutup = () => {
        try {
            window.sessionStorage.setItem(KUNCI_TUTUP, '1');
        } catch {
            // Penyimpanan peramban tidak tersedia: bilah hanya tertutup di halaman ini.
        }
        AturDitutup(true);
    };

    const KirimUlang = () =>
        router.post(
            '/verifikasi-email/kirim-ulang',
            {},
            { preserveScroll: true, onStart: () => AturMengirim(true), onFinish: () => AturMengirim(false) },
        );

    return (
        <div role="alert" data-slot="alert" data-jenis="peringatan" className={`${KelasBilahStatus()} items-center`}>
            <TitikBilahStatus jenis="peringatan" />
            <p className="min-w-0 flex-1 wrap-anywhere">
                <strong className="font-semibold">Verifikasi email Anda.</strong> Buka tautan yang kami kirim ke {email}
                .
                <span className="ml-2 inline-flex flex-wrap gap-x-3">
                    <button
                        type="button"
                        onClick={KirimUlang}
                        disabled={mengirim}
                        className={`${kelasTautan} font-semibold text-aksen underline underline-offset-2 disabled:opacity-60`}
                    >
                        {mengirim ? 'Mengirim…' : 'Kirim ulang'}
                    </button>
                    <Link href="/kelola/keamanan#email" className={kelasTautan}>
                        Ganti email
                    </Link>
                </span>
            </p>
            <button
                type="button"
                onClick={Tutup}
                aria-label="Tutup pengingat verifikasi email"
                className="-my-1 -mr-1 inline-flex size-8 shrink-0 items-center justify-center rounded-kontrol hover:bg-brand-teks/10 pointer-coarse:size-11"
            >
                <XIcon className="size-4" aria-hidden="true" />
            </button>
        </div>
    );
}
