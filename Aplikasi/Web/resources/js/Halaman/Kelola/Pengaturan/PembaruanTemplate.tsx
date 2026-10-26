import { useState } from 'react';
import { router } from '@inertiajs/react';

import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';

export type TawaranPembaruanTemplate = {
    UuidOutlet: string;
    NamaOutlet: string;
    KodeTemplate: string;
    NamaTemplate: string;
    VersiSaatIni: number;
    VersiBaru: number;
    Perubahan: { Bagian: string; Butir: string[] }[];
};

/** Pengaturan › Pembaruan template usaha (P-03 langkah 6): lihat yang ditambahkan lalu terapkan per outlet. */
export default function HalamanPembaruanTemplate({ Tawaran }: { Tawaran: TawaranPembaruanTemplate[] }) {
    const [pilihan, AturPilihan] = useState<TawaranPembaruanTemplate | null>(null);
    const [memproses, AturMemproses] = useState(false);

    const Terapkan = () => {
        if (!pilihan) {
            return;
        }

        router.post(
            `/kelola/pengaturan/pembaruan-template/${pilihan.UuidOutlet}/terapkan`,
            {},
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => {
                    AturMemproses(false);
                    AturPilihan(null);
                },
            },
        );
    };

    return (
        <TataLetakAplikasi judul="Pembaruan template usaha">
            <div className="grid gap-4">
                <Pemberitahuan jenis="info" judul="Aman diterapkan">
                    Pembaruan hanya menambahkan yang belum Anda miliki. Akun, kategori, satuan, pajak, dan pengaturan
                    yang sudah ada atau sudah Anda ubah sendiri tidak diubah atau dihapus.
                </Pemberitahuan>
                {Tawaran.length === 0 ? (
                    <Panel judul="Semua template sudah terbaru" tingkat="h2">
                        <p className="text-isi text-teks-sekunder">
                            Saat Payoung menerbitkan versi baru template jenis usaha Anda, tawarannya muncul di sini dan
                            di Kotak Tindakan.
                        </p>
                    </Panel>
                ) : (
                    Tawaran.map((tawaran) => (
                        <Panel
                            key={tawaran.UuidOutlet}
                            judul={`${tawaran.NamaOutlet} | ${tawaran.NamaTemplate}`}
                            tingkat="h2"
                            keterangan={`Versi ${String(tawaran.VersiSaatIni)} menjadi versi ${String(tawaran.VersiBaru)}`}
                            aksi={<Tombol onClick={() => AturPilihan(tawaran)}>Terapkan pembaruan</Tombol>}
                        >
                            {tawaran.Perubahan.length === 0 ? (
                                <p className="text-isi text-teks-sekunder">
                                    Versi baru tidak menambah akun, kategori, atau fitur. Menerapkannya hanya mencatat
                                    bahwa outlet memakai versi terbaru.
                                </p>
                            ) : (
                                <ul className="grid gap-3" aria-label={`Perubahan untuk ${tawaran.NamaOutlet}`}>
                                    {tawaran.Perubahan.map((bagian) => (
                                        <li key={bagian.Bagian} className="flex flex-col gap-1">
                                            <span className="text-label font-semibold text-teks-utama">
                                                {bagian.Bagian} ({String(bagian.Butir.length)})
                                            </span>
                                            <span className="text-keterangan text-teks-sekunder">
                                                {bagian.Butir.join(' | ')}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Panel>
                    ))
                )}
            </div>
            {pilihan ? (
                <DialogKonfirmasi
                    judul={`Terapkan versi ${String(pilihan.VersiBaru)}?`}
                    labelAksi="Terapkan pembaruan"
                    varian="utama"
                    memproses={memproses}
                    saatKonfirmasi={Terapkan}
                    saatBatal={() => AturPilihan(null)}
                >
                    <p>
                        Template {pilihan.NamaTemplate} untuk {pilihan.NamaOutlet} diperbarui. Hanya data baru yang
                        ditambahkan. Data yang sudah ada tidak diubah.
                    </p>
                </DialogKonfirmasi>
            ) : null}
        </TataLetakAplikasi>
    );
}
