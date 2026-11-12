import { useState, type FormEvent, type ReactNode } from 'react';

import type { AksiTambahPilihan } from '@/Komponen/Formulir/BarisTambahPilihan';
import { GalatBidang } from '@/Komponen/Formulir/BagianBidang';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import { AlamatPemasok } from '@/Komponen/Pembelian/FormPemasok';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Komponen/Ui/dialog';
import { KirimJson } from '@/Pustaka/PermintaanJson';
import { SaranKodePemasok } from '@/Pustaka/SaranKode';
import type { OpsiPemasok } from '@/Tipe/Pembelian';

/**
 * Tambah pemasok dari dalam pilihan pemasok pada formulir pembelian (D-273): baris "Tambah pemasok baru" di dasar
 * daftar dropdown membuka dialog kecil (nama, kode, HP, termin) yang menyimpan lewat `POST /kelola/pembelian/pemasok/cepat`,
 * jadi pengguna tidak perlu meninggalkan formulir yang sudah terisi. Data lengkap (alamat, rekening, NPWP) bisa
 * dilengkapi kemudian di halaman Pemasok. Dialog harus di luar popover pemilih, jadi pemakai memasang `aksiTambah`
 * ke pemilihnya dan merender `dialog` di dekatnya.
 */
export function usePemasokBaru(saatTersimpan: (pemasok: OpsiPemasok) => void): {
    aksiTambah: AksiTambahPilihan;
    dialog: ReactNode;
} {
    const [terbuka, AturTerbuka] = useState(false);

    return {
        aksiTambah: { label: 'Tambah pemasok baru', saatKlik: () => AturTerbuka(true) },
        dialog: (
            <DialogPemasokBaru terbuka={terbuka} saatTutup={() => AturTerbuka(false)} saatTersimpan={saatTersimpan} />
        ),
    };
}

function DialogPemasokBaru({
    terbuka,
    saatTutup,
    saatTersimpan,
}: {
    terbuka: boolean;
    saatTutup: () => void;
    saatTersimpan: (pemasok: OpsiPemasok) => void;
}) {
    const [nama, AturNama] = useState('');
    const [kode, AturKode] = useState('');
    const [kodeDiketik, AturKodeDiketik] = useState(false);
    const [noHp, AturNoHp] = useState('');
    const [termin, AturTermin] = useState('0');
    const [memproses, AturMemproses] = useState(false);
    const [galat, AturGalat] = useState<string | null>(null);

    const Tutup = () => {
        saatTutup();
        AturNama('');
        AturKode('');
        AturKodeDiketik(false);
        AturNoHp('');
        AturTermin('0');
        AturGalat(null);
    };

    const Simpan = async (peristiwa: FormEvent) => {
        // Dialog dirender di luar DOM formulir induk, tetapi peristiwa React tetap naik ke formulir itu.
        peristiwa.preventDefault();
        peristiwa.stopPropagation();

        if (nama.trim() === '' || kode.trim() === '') {
            AturGalat('Isi nama dan kode pemasok.');

            return;
        }

        AturMemproses(true);
        AturGalat(null);

        try {
            const hasil = await KirimJson<{ Data: OpsiPemasok }>(`${AlamatPemasok}/cepat`, {
                Kode: kode.trim(),
                Nama: nama.trim(),
                NoHp: noHp.trim() === '' ? null : noHp.trim(),
                Pkp: false,
                TerminHari: termin === '' ? 0 : Number(termin),
            });

            saatTersimpan(hasil.Data);
            Tutup();
        } catch (galatSimpan) {
            AturGalat(galatSimpan instanceof Error ? galatSimpan.message : 'Pemasok belum tersimpan. Coba lagi.');
        } finally {
            AturMemproses(false);
        }
    };

    return (
        <Dialog open={terbuka} onOpenChange={(buka) => (buka ? undefined : Tutup())}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={(peristiwa) => void Simpan(peristiwa)} noValidate className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle className="text-judul font-semibold text-teks-utama">Tambah pemasok</DialogTitle>
                        <DialogDescription className="text-isi text-teks-sekunder">
                            Cukup data pokoknya. Alamat, rekening, dan NPWP bisa dilengkapi nanti di halaman Pemasok.
                        </DialogDescription>
                    </DialogHeader>
                    <BidangTeks
                        label="Nama pemasok"
                        nilai={nama}
                        saatBerubah={(nilai) => {
                            AturNama(nilai);

                            if (!kodeDiketik) {
                                AturKode(SaranKodePemasok(nilai));
                            }
                        }}
                        maxLength={150}
                        required
                        autoFocus
                    />
                    <BidangTeks
                        label="Kode pemasok"
                        nilai={kode}
                        saatBerubah={(nilai) => {
                            AturKodeDiketik(true);
                            AturKode(nilai);
                        }}
                        keterangan="Disarankan dari nama. Boleh diganti."
                        maxLength={30}
                        kode
                        required
                    />
                    <BidangTeks
                        label="Nomor HP (opsional)"
                        nilai={noHp}
                        saatBerubah={AturNoHp}
                        inputMode="tel"
                        maxLength={30}
                    />
                    <BidangJumlah
                        label="Termin bayar (hari)"
                        nilai={termin}
                        saatBerubah={AturTermin}
                        desimal={0}
                        digitBulat={3}
                        akhiran="hari"
                        keterangan="0 berarti bayar tunai."
                    />
                    {galat ? <GalatBidang>{galat}</GalatBidang> : null}
                    <DialogFooter>
                        <Tombol type="button" varian="sekunder" onClick={Tutup}>
                            Batal
                        </Tombol>
                        <Tombol type="submit" memproses={memproses}>
                            Simpan pemasok
                        </Tombol>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
