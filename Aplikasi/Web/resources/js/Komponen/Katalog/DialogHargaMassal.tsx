import { router } from '@inertiajs/react';
import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { DialogFooter } from '@/Komponen/Ui/dialog';

type ModeHarga = 'NaikPersen' | 'TurunPersen' | 'NaikNominal' | 'TurunNominal';

const OpsiMode = [
    { Nilai: 'NaikPersen', Label: 'Naikkan dengan persen (%)' },
    { Nilai: 'TurunPersen', Label: 'Turunkan dengan persen (%)' },
    { Nilai: 'NaikNominal', Label: 'Naikkan dengan nominal (Rp)' },
    { Nilai: 'TurunNominal', Label: 'Turunkan dengan nominal (Rp)' },
];

const OpsiPembulatan = [
    { Nilai: '0', Label: 'Tanpa pembulatan' },
    { Nilai: '100', Label: 'Bulatkan ke Rp 100' },
    { Nilai: '500', Label: 'Bulatkan ke Rp 500' },
    { Nilai: '1000', Label: 'Bulatkan ke Rp 1.000' },
];

type PropsDialogHargaMassal = {
    /** Uuid produk terpilih. */
    uuid: string[];
    saatTutup: () => void;
    saatSelesai: () => void;
};

/**
 * Ubah harga jual banyak produk sekaligus (F-03): naik/turun persen atau nominal, dengan pembulatan. Hanya harga dasar
 * dan harga bertingkat; harga di daftar harga khusus tidak berubah. Semua atau tidak sama sekali (server).
 */
export default function DialogHargaMassal({ uuid, saatTutup, saatSelesai }: PropsDialogHargaMassal) {
    const [mode, AturMode] = useState<ModeHarga>('NaikPersen');
    const [nilai, AturNilai] = useState('');
    const [pembulatan, AturPembulatan] = useState('0');
    const [galat, AturGalat] = useState<Record<string, string>>({});
    const [memproses, AturMemproses] = useState(false);
    const persen = mode.endsWith('Persen');

    const Kirim = () =>
        router.post(
            '/kelola/produk/harga-massal',
            { Mode: mode, Nilai: nilai, Pembulatan: Number(pembulatan), Uuid: uuid },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onError: (errors) => AturGalat(errors),
                onSuccess: () => saatSelesai(),
            },
        );

    return (
        <DialogFormulir
            judul={`Ubah harga ${uuid.length.toLocaleString('id-ID')} produk`}
            keterangan="Harga jual dan harga bertingkat berubah sekaligus. Harga di daftar harga khusus tidak ikut berubah. Setiap perubahan tercatat di riwayat harga."
            galatUmum={galat.Umum}
            saatTutup={saatTutup}
        >
            <form
                noValidate
                className="flex flex-col gap-3"
                onSubmit={(peristiwa) => {
                    peristiwa.preventDefault();
                    Kirim();
                }}
            >
                <BidangPilihan
                    label="Cara mengubah"
                    nilai={mode}
                    opsi={OpsiMode}
                    saatBerubah={(nilaiBaru) => AturMode(nilaiBaru as ModeHarga)}
                    galat={galat.Mode}
                    required
                />
                <BidangTeks
                    label={persen ? 'Besar perubahan (%)' : 'Besar perubahan (Rp)'}
                    nilai={nilai}
                    saatBerubah={AturNilai}
                    galat={galat.Nilai}
                    inputMode="decimal"
                    keterangan={persen ? 'Contoh: 10 untuk 10 persen.' : 'Contoh: 2500 untuk Rp 2.500 per satuan.'}
                    autoFocus
                    required
                />
                <BidangPilihan
                    label="Pembulatan harga baru"
                    nilai={pembulatan}
                    opsi={OpsiPembulatan}
                    saatBerubah={AturPembulatan}
                    galat={galat.Pembulatan}
                />
                {galat.Uuid ? <p className="text-keterangan font-semibold text-bahaya">{galat.Uuid}</p> : null}
                <DialogFooter className="sm:justify-start">
                    <Tombol type="submit" memproses={memproses} disabled={nilai.trim() === ''}>
                        Ubah harga
                    </Tombol>
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </form>
        </DialogFormulir>
    );
}
