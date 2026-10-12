import { Link } from '@inertiajs/react';
import { useEffect, useRef, type FormEvent, type ReactNode } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import Tombol from '@/Komponen/Formulir/Tombol';
import { Button } from '@/Komponen/Ui/button';
import { Card } from '@/Komponen/Ui/card';

import { TahanBilaBarcodeTertunda } from '@/Komponen/Katalog/BantuanKatalog';

import type { KunciLangkah } from './LangkahProduk';
import PenunjukLangkah from './PenunjukLangkah';
import type { KontrolWizardProduk } from './useAlurWizardProduk';

type PropsWizardProduk = {
    kontrol: KontrolWizardProduk;
    /** Isi tiap langkah; hanya isi langkah aktif yang dipasang. */
    panel: Record<KunciLangkah, ReactNode>;
    /** Langkah yang memuat isian bermasalah. */
    bergalat: KunciLangkah[];
    /** Ringkasan galat langkah aktif (aria-live) dan galat server yang tidak terikat isian. */
    pesanGalat: ReactNode;
    /** Dipanggil hanya dari langkah terakhir (Tinjau). */
    saatSimpan: () => void;
    memproses: boolean;
    /** Pengguna boleh menyimpan produk (izin `produk.kelola`). */
    bolehSimpan: boolean;
    /** Simpan ditahan, misal batas produk paket tercapai. */
    simpanDitahan?: boolean;
    tautanBatal: string;
};

/** Fokuskan isian pertama yang ditandai tidak valid (atau pesan galat tanpa isian) di dalam langkah aktif. */
function FokusGalatLangkah(wadah: HTMLElement | null): void {
    wadah?.querySelector<HTMLElement>('[aria-invalid="true"], [data-galat-langkah]')?.focus();
}

/**
 * Cangkang wizard "Tambah produk" (D-128): penunjuk langkah, isi satu langkah, dan bilah aksi bawah.
 * Satu `<form>`: Enter di isian = Lanjut, dan hanya langkah terakhir (Tinjau) yang menyimpan. Fokus pindah ke
 * judul langkah saat berpindah, atau ke isian bermasalah saat langkah ditolak.
 */
export default function WizardProduk({
    kontrol,
    panel,
    bergalat,
    pesanGalat,
    saatSimpan,
    memproses,
    bolehSimpan,
    simpanDitahan = false,
    tautanBatal,
}: PropsWizardProduk) {
    const wadahLangkah = useRef<HTMLElement>(null);
    const judulLangkah = useRef<HTMLHeadingElement>(null);
    const sudahPindah = useRef(false);
    const { langkah, aktif, indeksAktif, nomorFokus } = kontrol;
    const langkahIni = langkah[indeksAktif];
    const terakhir = indeksAktif === langkah.length - 1;

    // Urutan efek penting: fokus judul dulu, lalu fokus isian bermasalah (menang bila keduanya terjadi sekaligus).
    useEffect(() => {
        if (sudahPindah.current) {
            judulLangkah.current?.focus();
        }

        sudahPindah.current = true;
    }, [aktif]);

    useEffect(() => {
        if (nomorFokus > 0) {
            FokusGalatLangkah(wadahLangkah.current);
        }
    }, [nomorFokus]);

    // Barcode yang diketik tetapi belum ditambahkan tidak boleh hilang diam-diam saat pindah langkah atau menyimpan.
    const Tahan = () => TahanBilaBarcodeTertunda(wadahLangkah.current);

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();

        if (Tahan()) {
            return;
        }

        if (terakhir) {
            saatSimpan();
        } else {
            kontrol.Maju();
        }
    };

    return (
        <form onSubmit={Kirim} noValidate className="flex flex-col gap-4">
            <PenunjukLangkah
                langkah={langkah}
                aktif={aktif}
                cekTerbuka={kontrol.CekTerbuka}
                bergalat={bergalat}
                saatPilih={(kunci) => (Tahan() ? false : kontrol.Lompat(kunci))}
            />
            {pesanGalat}
            <Card className="gap-0 rounded-panel p-4 shadow-none">
                <section ref={wadahLangkah} aria-labelledby="judul-langkah-produk" className="flex flex-col gap-4">
                    <div className="flex flex-col gap-1">
                        <h2
                            id="judul-langkah-produk"
                            ref={judulLangkah}
                            tabIndex={-1}
                            className="text-subjudul font-semibold text-teks-utama outline-none"
                        >
                            {langkahIni?.Label}
                        </h2>
                        <p className="text-isi text-teks-sekunder">{langkahIni?.Keterangan}</p>
                    </div>
                    {panel[aktif]}
                </section>
            </Card>
            <BilahAksiForm>
                {indeksAktif > 0 ? (
                    <Tombol varian="sekunder" onClick={() => (Tahan() ? undefined : kontrol.Mundur())}>
                        Kembali
                    </Tombol>
                ) : null}
                {terakhir ? (
                    bolehSimpan ? (
                        <Tombol type="submit" memproses={memproses} disabled={simpanDitahan}>
                            Simpan produk
                        </Tombol>
                    ) : null
                ) : (
                    <Tombol type="submit">Lanjut</Tombol>
                )}
                {!terakhir && kontrol.CekTerjangkau('Tinjau') ? (
                    <Tombol varian="sekunder" onClick={() => (Tahan() ? false : kontrol.Lompat('Tinjau'))}>
                        Kembali ke tinjauan
                    </Tombol>
                ) : null}
                <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                    <Link href={tautanBatal}>Batal</Link>
                </Button>
            </BilahAksiForm>
        </form>
    );
}
