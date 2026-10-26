import { Link, router, usePage } from '@inertiajs/react';

import AjakanTambahBatas from '@/Komponen/Kelola/AjakanTambahBatas';
import KartuFormulir from '@/Komponen/Formulir/KartuFormulir';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import FormAksesPengguna, { type IsianAkses } from '@/Komponen/Kelola/FormAksesPengguna';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import { CekBatasPenuh, FormatBatas, type PropsBuatUndangan } from '@/Tipe/Organisasi';

const undanganKosong: IsianAkses = { Email: '', Peran: '', SemuaOutlet: false, Outlet: [] };

/** Halaman "Undang pengguna" (F-02 langkah 3, BR-02.1). Setelah terkirim, server mengarahkan ke daftar pengguna. */
export default function HalamanBuatUndangan({ Peran, Outlet, BatasPengguna }: PropsBuatUndangan) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const sayaPemilik = props.Akses?.Pemilik ?? false;
    // Peran Pemilik hanya bisa diberikan oleh Pemilik (anti-eskalasi; server tetap menegakkan).
    const peranTerlihat = Peran.filter((peran) => sayaPemilik || !peran.Pemilik);
    const isianForm = Object.keys(undanganKosong);

    return (
        <TataLetakAplikasi judul="Undang pengguna">
            <DaftarGalatServer
                galat={props.errors}
                kecuali={Object.keys(props.errors).filter((k) => isianForm.some((i) => k.startsWith(i)))}
            />
            {CekBatasPenuh(BatasPengguna) ? (
                <Pemberitahuan jenis="info" judul="Batas pengguna paket sudah tercapai">
                    Nonaktifkan pengguna yang tidak dipakai, batalkan undangan, atau{' '}
                    <AjakanTambahBatas>
                        tingkatkan paket di{' '}
                        <Link href="/kelola/langganan" className="font-semibold text-brand underline">
                            menu Langganan
                        </Link>
                    </AjakanTambahBatas>
                    .
                </Pemberitahuan>
            ) : null}
            <KartuFormulir
                keterangan={`Undangan dikirim ke email, berlaku 72 jam, dan hanya bisa dipakai sekali. Bila email itu sudah punya akun (misal di usaha lain), akunnya ditautkan. Jumlah akun terpakai: ${FormatBatas(BatasPengguna, 'pengguna')}.`}
            >
                <FormAksesPengguna
                    alamat="/kelola/pengguna/undangan"
                    metode="post"
                    denganEmail
                    awal={undanganKosong}
                    peran={peranTerlihat}
                    outlet={Outlet}
                    tombol="Kirim undangan"
                    saatBatal={() => router.visit('/kelola/tim')}
                />
            </KartuFormulir>
        </TataLetakAplikasi>
    );
}
