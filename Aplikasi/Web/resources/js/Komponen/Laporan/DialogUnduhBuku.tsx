import { router } from '@inertiajs/react';
import { useId, useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import DialogFormulir from '@/Komponen/Tindakan/DialogFormulir';
import { Checkbox } from '@/Komponen/Ui/checkbox';
import { DialogFooter } from '@/Komponen/Ui/dialog';
import { Label } from '@/Komponen/Ui/label';
import { cn } from '@/Komponen/Ui/utils';

export type KodeLembar =
    'Ringkasan' | 'Kategori' | 'PerPeriode' | 'PerOutlet' | 'Transaksi' | 'HutangPiutang' | 'LabaRugi' | 'Stok';

type OpsiLembar = {
    kode: KodeLembar;
    judul: string;
    keterangan: string;
    terkunci?: boolean;
    /** Tidak tercentang bawaan, karena bahannya dari luar uang masuk dan keluar (HPP dan persediaan). */
    bukanBawaan?: boolean;
    /** Ikut tercentang dan terkunci selama lembar ini dipilih. */
    ikutDari?: KodeLembar;
};

const KunciSimpan = 'Laporan.PemasukanPengeluaran.Lembar';
const MaksHariHarian = 62;

/** Daftar lembar yang ditawarkan. Per hari atau per bulan dan Per outlet bergantung pada rentang dan jumlah outlet. */
export function BuatDaftarLembar(hariRentang: number, tawarkanPerOutlet: boolean): OpsiLembar[] {
    const harian = hariRentang <= MaksHariHarian;

    return [
        {
            kode: 'Ringkasan',
            judul: 'Ringkasan',
            keterangan: 'Uang masuk, uang keluar, dan selisih. Selalu ikut.',
            terkunci: true,
        },
        {
            kode: 'Kategori',
            judul: 'Uang masuk dan uang keluar per kategori',
            keterangan: 'Rincian dan perbandingan dengan periode sebelumnya',
        },
        {
            kode: 'PerPeriode',
            judul: harian ? 'Per hari' : 'Per bulan',
            keterangan: harian ? 'Uang masuk dan keluar tiap tanggal' : 'Uang masuk dan keluar tiap bulan',
        },
        ...(tawarkanPerOutlet
            ? [
                  {
                      kode: 'PerOutlet' as const,
                      judul: 'Per outlet',
                      keterangan: 'Perbandingan antar outlet',
                  },
              ]
            : []),
        {
            kode: 'Transaksi',
            judul: 'Daftar transaksi per hari dan kategori',
            keterangan: 'Dasar semua rumus, jadi setiap angka bisa dilacak',
        },
        {
            kode: 'HutangPiutang',
            judul: 'Hutang dan piutang',
            keterangan: 'Hutang ke pemasok dan piutang pelanggan pada akhir periode',
        },
        {
            kode: 'LabaRugi',
            judul: 'Laba rugi',
            keterangan: 'Pendapatan dikurangi HPP dan beban. Memakai HPP, jadi lembar Stok ikut disertakan.',
            bukanBawaan: true,
        },
        {
            kode: 'Stok',
            judul: 'Nilai stok',
            keterangan: 'Nilai persediaan pada akhir periode',
            bukanBawaan: true,
            ikutDari: 'LabaRugi',
        },
    ];
}

/** Pilihan terakhir pengguna di peramban ini, atau semua lembar kecuali yang bukan bawaan bila belum pernah memilih. */
function BacaPilihan(daftar: OpsiLembar[]): KodeLembar[] {
    const semua = daftar.map((o) => o.kode);
    const bawaan = daftar.filter((o) => o.bukanBawaan !== true).map((o) => o.kode);

    try {
        const mentah = window.localStorage.getItem(KunciSimpan);

        if (mentah !== null) {
            const tersimpan: unknown = JSON.parse(mentah);

            if (Array.isArray(tersimpan)) {
                return semua.filter((kode) => kode === 'Ringkasan' || tersimpan.includes(kode));
            }
        }
    } catch {
        // Penyimpanan peramban bisa tidak tersedia (mode privat): pakai bawaan.
    }

    return bawaan;
}

function SimpanPilihan(pilihan: KodeLembar[]): void {
    try {
        window.localStorage.setItem(KunciSimpan, JSON.stringify(pilihan));
    } catch {
        // Tidak masalah bila gagal disimpan.
    }
}

/** Alamat unduhan buku kerja: disiapkan di latar belakang lewat Pusat Unduhan. */
export function BuatAlamatBuku(
    alamat: string,
    saring: { Dari: string; Sampai: string; Outlet: string },
    lembar: KodeLembar[],
): string {
    const parameter = new URLSearchParams();
    parameter.set('dari', saring.Dari);
    parameter.set('sampai', saring.Sampai);

    if (saring.Outlet !== '') {
        parameter.set('outlet', saring.Outlet);
    }

    for (const kode of lembar) {
        parameter.append('lembar[]', kode);
    }

    parameter.set('format', 'xlsx');
    parameter.set('latarbelakang', '1');

    return `${alamat}/buku?${parameter.toString()}`;
}

type PropsDialogUnduhBuku = {
    alamat: string;
    saring: { Dari: string; Sampai: string; Outlet: string };
    hariRentang: number;
    tawarkanPerOutlet: boolean;
    saatTutup: () => void;
};

/**
 * Dialog "Unduh laporan lengkap" (D-255 tahap 2): satu berkas Excel banyak lembar, pemilik memilih lembar yang dibutuhkan.
 * Pilihan terakhir diingat di peramban. Lembar yang tidak dicentang tetapi dibutuhkan rumus tetap ada di berkas dalam
 * keadaan tersembunyi, dan berkas disiapkan di Pusat Unduhan.
 */
export default function DialogUnduhBuku({
    alamat,
    saring,
    hariRentang,
    tawarkanPerOutlet,
    saatTutup,
}: PropsDialogUnduhBuku) {
    const id = useId();
    const daftar = BuatDaftarLembar(hariRentang, tawarkanPerOutlet);
    const [pilihan, AturPilihan] = useState<KodeLembar[]>(() => BacaPilihan(daftar));

    const Alihkan = (kode: KodeLembar) =>
        AturPilihan((lama) => (lama.includes(kode) ? lama.filter((k) => k !== kode) : [...lama, kode]));

    /** Tercentang karena terkunci, dipilih, atau ikut lembar lain yang dipilih (Stok ikut Laba rugi). */
    const Tercentang = (o: OpsiLembar): boolean =>
        o.terkunci === true || pilihan.includes(o.kode) || (o.ikutDari !== undefined && pilihan.includes(o.ikutDari));

    const Unduh = () => {
        const dipakai = daftar.filter(Tercentang).map((o) => o.kode);
        // Yang disimpan hanya pilihan sendiri: lembar yang cuma ikut (Stok) tidak ikut diingat.
        SimpanPilihan(daftar.filter((o) => o.terkunci === true || pilihan.includes(o.kode)).map((o) => o.kode));
        router.get(BuatAlamatBuku(alamat, saring, dipakai));
        saatTutup();
    };

    return (
        <DialogFormulir
            judul="Unduh laporan lengkap"
            keterangan="Satu berkas Excel. Pilih lembar yang Anda butuhkan."
            saatTutup={saatTutup}
        >
            <div className="flex flex-col gap-4">
                <ul className="flex flex-col gap-2">
                    {daftar.map((o, indeks) => (
                        <li
                            key={o.kode}
                            className={cn(
                                'flex items-start gap-3 rounded-panel border border-garis px-3 py-2',
                                o.terkunci && 'bg-permukaan-redup',
                            )}
                        >
                            <Checkbox
                                id={`${id}-${String(indeks)}`}
                                checked={Tercentang(o)}
                                disabled={
                                    o.terkunci === true || (o.ikutDari !== undefined && pilihan.includes(o.ikutDari))
                                }
                                onCheckedChange={() => Alihkan(o.kode)}
                                className="mt-1 border-garis-input"
                            />
                            <Label htmlFor={`${id}-${String(indeks)}`} className="flex flex-col items-start gap-0.5">
                                <span className="text-isi font-semibold text-teks-utama">{o.judul}</span>
                                <span className="text-keterangan font-normal text-teks-sekunder">{o.keterangan}</span>
                            </Label>
                        </li>
                    ))}
                </ul>
                <p className="rounded-panel bg-brand-tint px-3 py-2 text-keterangan text-teks-utama">
                    Angka di Excel berupa rumus, jadi kalau Anda mengubah kategori satu baris di lembar transaksi,
                    lembar lain ikut berubah. Lembar yang tidak dicentang tetapi dibutuhkan rumus tetap ada dan
                    disembunyikan. Berkas disiapkan di Pusat Unduhan.
                </p>
                <DialogFooter className="sm:justify-start">
                    <Tombol onClick={Unduh}>Siapkan Excel</Tombol>
                    <Tombol varian="sekunder" onClick={saatTutup}>
                        Batal
                    </Tombol>
                </DialogFooter>
            </div>
        </DialogFormulir>
    );
}
