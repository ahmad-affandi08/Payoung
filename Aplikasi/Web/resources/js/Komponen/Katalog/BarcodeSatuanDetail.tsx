import { router } from '@inertiajs/react';
import { useId, useState, type KeyboardEvent } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/Komponen/Ui/alert-dialog';
import { Button } from '@/Komponen/Ui/button';
import { Input } from '@/Komponen/Ui/input';

import { PeriksaBarcode } from './BantuanKatalog';
import TombolPindaiKamera from './TombolPindaiKamera';

type PropsBarcodeSatuanDetail = {
    uuidProduk: string;
    uuidSatuan: string;
    simbolSatuan: string;
    barcode: { Uuid: string; Barcode: string }[];
    /** Semua barcode produk ini (semua satuan): duplikat ditolak sebelum dikirim. */
    barcodeProduk: string[];
    bolehKelola: boolean;
};

const opsiKirim = { preserveScroll: true } as const;

/**
 * F-03 (BR-03.1): barcode satu satuan di halaman detail produk. Pengelola bisa mengetik atau memindai barcode
 * pabrik (pemindai USB mengetik lalu Enter, atau kamera), menghapusnya, atau membuat barcode internal untuk barang
 * tanpa barcode pabrik, tanpa membuka form ubah produk.
 */
export default function BarcodeSatuanDetail({
    uuidProduk,
    uuidSatuan,
    simbolSatuan,
    barcode,
    barcodeProduk,
    bolehKelola,
}: PropsBarcodeSatuanDetail) {
    const id = useId();
    const [ketikan, AturKetikan] = useState('');
    const [galat, AturGalat] = useState<string | null>(null);
    const [memproses, AturMemproses] = useState(false);
    const url = `/kelola/produk/${uuidProduk}/satuan/${uuidSatuan}`;

    const Kirim = (nilai: string) => {
        const isi = nilai.trim();

        if (isi === '' || memproses) {
            return;
        }

        const pesan = PeriksaBarcode(isi, barcodeProduk);
        AturGalat(pesan);

        if (pesan !== null) {
            return;
        }

        router.post(
            `${url}/barcode`,
            { Barcode: isi },
            {
                ...opsiKirim,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
                onSuccess: () => AturKetikan(''),
                onError: (galatServer) =>
                    AturGalat(galatServer.Barcode ?? galatServer.Umum ?? 'Barcode belum bisa disimpan.'),
            },
        );
    };

    const TekanTombol = (peristiwa: KeyboardEvent<HTMLInputElement>) => {
        if (peristiwa.key === 'Enter') {
            peristiwa.preventDefault();
            Kirim(ketikan);
        }
    };

    return (
        <div className="flex flex-col gap-2">
            {barcode.length === 0 ? (
                <span className="text-teks-sekunder">Belum ada</span>
            ) : (
                <ul className="flex flex-col gap-1" aria-label={`Barcode ${simbolSatuan}`}>
                    {barcode.map((baris) => (
                        <li key={baris.Uuid} className="flex items-center justify-between gap-2">
                            <span className="font-mono text-label break-all">{baris.Barcode}</span>
                            {bolehKelola ? (
                                <AlertDialog>
                                    <AlertDialogTrigger asChild>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="xs"
                                            className="text-destructive"
                                            aria-label={`Hapus barcode ${baris.Barcode}`}
                                        >
                                            Hapus
                                        </Button>
                                    </AlertDialogTrigger>
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle className="text-subjudul font-semibold text-teks-utama">
                                                Hapus barcode {baris.Barcode}?
                                            </AlertDialogTitle>
                                            <AlertDialogDescription className="text-isi text-teks-sekunder">
                                                Barang ini tidak lagi bisa dipindai di kasir dengan barcode tersebut.
                                                Barcode bisa ditambahkan lagi kapan saja.
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter>
                                            <AlertDialogCancel>Batal</AlertDialogCancel>
                                            <AlertDialogAction
                                                variant="destructive"
                                                onClick={() =>
                                                    router.delete(
                                                        `/kelola/produk/${uuidProduk}/barcode/${baris.Uuid}`,
                                                        opsiKirim,
                                                    )
                                                }
                                            >
                                                Ya, hapus barcode
                                            </AlertDialogAction>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}
            {bolehKelola ? (
                <div className="flex flex-col gap-2">
                    <div className="flex flex-wrap gap-2">
                        <Input
                            id={id}
                            type="text"
                            value={ketikan}
                            onChange={(peristiwa) => {
                                AturKetikan(peristiwa.target.value);
                                AturGalat(null);
                            }}
                            onKeyDown={TekanTombol}
                            disabled={memproses}
                            autoComplete="off"
                            placeholder="Ketik atau pindai barcode"
                            aria-label={`Barcode baru ${simbolSatuan}`}
                            aria-invalid={galat ? true : undefined}
                            aria-describedby={galat ? `${id}-galat` : undefined}
                            className="h-8 pointer-coarse:h-11 min-w-44 flex-1 font-mono tracking-wide"
                        />
                        <Tombol
                            varian="sekunder"
                            onClick={() => Kirim(ketikan)}
                            memproses={memproses}
                            disabled={ketikan.trim() === ''}
                        >
                            Tambah barcode
                        </Tombol>
                        <TombolPindaiKamera
                            saatTerbaca={Kirim}
                            disabled={memproses}
                            label={`Pindai kamera ${simbolSatuan}`}
                        />
                    </div>
                    <div aria-live="polite">
                        {galat ? (
                            <p id={`${id}-galat`} className="text-keterangan font-semibold text-bahaya">
                                {galat}
                            </p>
                        ) : null}
                    </div>
                    <Button
                        type="button"
                        variant="link"
                        size="sm"
                        onClick={() => router.post(`${url}/barcode-internal`, {}, opsiKirim)}
                        className="h-auto justify-start px-0"
                    >
                        Buat barcode internal {simbolSatuan}
                    </Button>
                </div>
            ) : null}
        </div>
    );
}
