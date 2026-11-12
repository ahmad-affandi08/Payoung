import { usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import { GalatBidang } from '@/Komponen/Formulir/BagianBidang';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import { AlamatPelanggan } from '@/Komponen/Pelanggan/FormulirPelanggan';
import { Button } from '@/Komponen/Ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Komponen/Ui/dialog';
import { KirimJson } from '@/Pustaka/PermintaanJson';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import { IzinTenant, PunyaIzinTenant } from '@/Tipe/Organisasi';

/** Bentuk pilihan pelanggan yang dipakai semua pemilih pelanggan back-office. */
export type PelangganBaru = {
    Uuid: string;
    Nama: string;
    NoHp: string | null;
    LimitKredit: string | null;
    SisaPiutang?: string | null;
    TerminHari?: number;
};

/**
 * Tombol "Pelanggan belum ada? Tambah pelanggan baru" di bawah setiap pemilih pelanggan (D-272). Membuka dialog kecil
 * (nama dan nomor HP) dan menyimpannya lewat `POST /kelola/pelanggan/cepat`, jadi pengguna tidak perlu meninggalkan
 * formulir yang sudah terisi. Data lengkap bisa dilengkapi kemudian di halaman Pelanggan. Tidak tampil bagi pengguna
 * tanpa izin `pelanggan.kelola`, karena server akan menolaknya.
 */
export default function TombolPelangganBaru({ saatTersimpan }: { saatTersimpan: (pelanggan: PelangganBaru) => void }) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const [terbuka, AturTerbuka] = useState(false);
    const [nama, AturNama] = useState('');
    const [noHp, AturNoHp] = useState('');
    const [memproses, AturMemproses] = useState(false);
    const [galat, AturGalat] = useState<string | null>(null);

    if (!PunyaIzinTenant(props.Akses, IzinTenant.PelangganKelola)) {
        return null;
    }

    const Tutup = () => {
        AturTerbuka(false);
        AturNama('');
        AturNoHp('');
        AturGalat(null);
    };

    const Simpan = async (peristiwa: FormEvent) => {
        // Dialog dirender di luar DOM formulir induk, tetapi peristiwa React tetap naik ke formulir itu.
        peristiwa.preventDefault();
        peristiwa.stopPropagation();

        if (nama.trim() === '' || noHp.trim() === '') {
            AturGalat('Isi nama dan nomor HP pelanggan.');

            return;
        }

        AturMemproses(true);
        AturGalat(null);

        try {
            const hasil = await KirimJson<{ Data: PelangganBaru }>(`${AlamatPelanggan}/cepat`, {
                Nama: nama.trim(),
                NoHp: noHp.trim(),
                SetujuPemasaran: false,
            });

            saatTersimpan(hasil.Data);
            Tutup();
        } catch (galatSimpan) {
            AturGalat(galatSimpan instanceof Error ? galatSimpan.message : 'Pelanggan belum tersimpan. Coba lagi.');
        } finally {
            AturMemproses(false);
        }
    };

    return (
        <>
            <Button
                type="button"
                variant="link"
                className="h-auto self-start p-0 text-label font-semibold"
                onClick={() => AturTerbuka(true)}
            >
                Pelanggan belum ada? Tambah pelanggan baru
            </Button>
            <Dialog open={terbuka} onOpenChange={(buka) => (buka ? undefined : Tutup())}>
                <DialogContent className="sm:max-w-md">
                    <form onSubmit={(peristiwa) => void Simpan(peristiwa)} noValidate className="flex flex-col gap-4">
                        <DialogHeader>
                            <DialogTitle className="text-judul font-semibold text-teks-utama">
                                Tambah pelanggan
                            </DialogTitle>
                            <DialogDescription className="text-isi text-teks-sekunder">
                                Cukup nama dan nomor HP. Alamat, email, NPWP, dan limit kredit bisa dilengkapi nanti di
                                halaman Pelanggan.
                            </DialogDescription>
                        </DialogHeader>
                        <BidangTeks
                            label="Nama pelanggan"
                            nilai={nama}
                            saatBerubah={AturNama}
                            maxLength={150}
                            required
                            autoFocus
                        />
                        <BidangTeks
                            label="Nomor HP"
                            nilai={noHp}
                            saatBerubah={AturNoHp}
                            inputMode="tel"
                            maxLength={30}
                            required
                        />
                        {galat ? <GalatBidang>{galat}</GalatBidang> : null}
                        <DialogFooter>
                            <Tombol type="button" varian="sekunder" onClick={Tutup}>
                                Batal
                            </Tombol>
                            <Tombol type="submit" memproses={memproses}>
                                Simpan pelanggan
                            </Tombol>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
