import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import { IzinTenant, PunyaIzinTenant } from '@/Tipe/Organisasi';
import type { KesiapanAkun } from '@/Tipe/Persediaan';

/**
 * Peringatan akun jurnal persediaan yang belum dipetakan (DesainF05a C.5 `KesiapanPeranAkun`, E). Catat stok awal
 * akan ditolak server (`PemetaanAkunBelumAda`) selama daftar ini belum kosong. Tidak dirender bila akun siap.
 * Audit kemudahan pakai #12: pemegang `akuntansi.kelola` bisa melengkapinya sekali klik dari template sektor
 * ("Perbaiki otomatis", aditif: pemetaan yang sudah diatur tidak berubah).
 */
export default function PanelKesiapanAkun({ kesiapan }: { kesiapan: KesiapanAkun }) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [memproses, AturMemproses] = useState(false);

    if (kesiapan.Siap) {
        return null;
    }

    const bolehPerbaiki = PunyaIzinTenant(props.Akses, IzinTenant.AkuntansiKelola);
    const Perbaiki = () =>
        router.post(
            '/kelola/akuntansi/pemetaan/perbaiki-otomatis',
            {},
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );

    return (
        <Pemberitahuan jenis="peringatan" judul="Pengaturan pembukuan toko belum lengkap">
            <p>Stok awal belum bisa dicatat karena akun pembukuan berikut belum diatur. Draf tetap bisa disimpan.</p>
            {kesiapan.PeranBelumDipetakan.length > 0 ? (
                <ul className="mt-1 list-disc pl-5">
                    {kesiapan.PeranBelumDipetakan.map((peran) => (
                        <li key={peran.Kunci}>{peran.Label}</li>
                    ))}
                </ul>
            ) : null}
            {bolehPerbaiki ? (
                <div className="mt-2 flex flex-col gap-1">
                    <div>
                        <Tombol onClick={Perbaiki} memproses={memproses}>
                            Perbaiki otomatis
                        </Tombol>
                    </div>
                    <p className="text-keterangan">
                        Akun yang belum ada dilengkapi dari pengaturan standar jenis usaha Anda. Pengaturan yang sudah
                        ada tidak diubah; detailnya bisa dilihat di{' '}
                        <Link href="/kelola/akuntansi/pemetaan" className="font-semibold text-brand underline">
                            Pemetaan akun
                        </Link>
                        .
                    </p>
                </div>
            ) : (
                <p className="mt-1">Minta Pemilik atau Akuntan menekan "Perbaiki otomatis" di halaman ini.</p>
            )}
        </Pemberitahuan>
    );
}
