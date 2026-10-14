import { Link } from '@inertiajs/react';
import { BellIcon } from 'lucide-react';

import { Button } from '@/Komponen/Ui/button';

/**
 * Lonceng notifikasi di kepala back-office (F31, PLT-08): tautan ke pusat notifikasi dengan lencana jumlah yang belum
 * dibaca (99+ bila lebih). Ukuran dan area sentuhnya sama dengan tombol cari di sebelahnya.
 */
export default function LonceNotifikasi({ belumDibaca }: { belumDibaca: number }) {
    const label = belumDibaca > 0 ? `Notifikasi, ${String(belumDibaca)} belum dibaca` : 'Notifikasi';

    return (
        <Button
            asChild
            data-tur="notifikasi"
            variant="outline"
            className="relative mr-1 size-8 min-w-0 rounded-lg border-garis bg-permukaan px-0 text-teks-sekunder after:absolute after:-inset-1.5 has-[>svg]:px-0 lg:after:hidden lg:pointer-coarse:size-11"
        >
            <Link href="/kelola/notifikasi" aria-label={label}>
                <BellIcon aria-hidden="true" />
                {belumDibaca > 0 ? (
                    <span
                        aria-hidden="true"
                        className="absolute -top-1.5 -right-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-bahaya px-1 text-keterangan leading-none font-semibold text-bahaya-teks"
                    >
                        {belumDibaca > 99 ? '99+' : String(belumDibaca)}
                    </span>
                ) : null}
            </Link>
        </Button>
    );
}
