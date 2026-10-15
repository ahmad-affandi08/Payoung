import { router } from '@inertiajs/react';
import { MonitorSmartphoneIcon } from 'lucide-react';
import { useState } from 'react';

import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import DialogKonfirmasi from '@/Komponen/Tindakan/DialogKonfirmasi';
import { Button } from '@/Komponen/Ui/button';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';

export type KiosOutletData = {
    FiturAktif: boolean;
    Aktif: boolean;
    /** Tautan rahasia layar sentuh kios; null = belum pernah dihidupkan. */
    Tautan: string | null;
    /** Tautan layar antrian untuk monitor/TV di outlet. */
    TautanAntrian: string | null;
    /** Pembayaran QRIS tersedia bila sakelar QRIS toko hidup dan gerbang pembayaran aktif. */
    QrisTersedia: boolean;
};

type Props = { alamatOutlet: string; data: KiosOutletData; bolehKelola: boolean };

/**
 * F-17 bagian 4: kios pesan sendiri di layar sentuh outlet. Menghidupkan butuh fitur paket `kanal.self-order`
 * (add-on). Tautan rahasia dibuka di tablet outlet; "Buat ulang" mematikan tautan lama (tablet hilang).
 */
export default function KiosOutlet({ alamatOutlet, data, bolehKelola }: Props) {
    const [memproses, AturMemproses] = useState<string | null>(null);
    const [konfirmasi, AturKonfirmasi] = useState(false);
    const [tersalin, AturTersalin] = useState<string | null>(null);

    const Opsi = (aksi: string) => {
        AturMemproses(aksi);

        return {
            preserveScroll: true,
            onSuccess: () => AturKonfirmasi(false),
            onFinish: () => AturMemproses(null),
        };
    };

    const Salin = async (teks: string, kunci: string) => {
        try {
            await navigator.clipboard.writeText(teks);
            AturTersalin(kunci);
        } catch {
            AturTersalin(null);
        }
    };

    return (
        <Panel
            judul="Kios pesan sendiri"
            keterangan="Layar sentuh di outlet: pelanggan memilih menu, mendapat nomor antrian, lalu membayar di kasir atau lewat QRIS."
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <MonitorSmartphoneIcon aria-hidden="true" className="size-4 text-teks-sekunder" />
                    {data.Aktif ? (
                        <LabelStatus jenis="sukses" teks="Aktif" />
                    ) : (
                        <LabelStatus jenis="netral" teks="Mati" />
                    )}
                </div>
                {bolehKelola ? (
                    data.Aktif ? (
                        <Tombol
                            varian="sekunder"
                            memproses={memproses === 'mati'}
                            onClick={() => router.post(`${alamatOutlet}/kios`, { Aktif: false }, Opsi('mati'))}
                        >
                            Matikan kios
                        </Tombol>
                    ) : (
                        <Tombol
                            memproses={memproses === 'hidup'}
                            disabled={!data.FiturAktif}
                            onClick={() => router.post(`${alamatOutlet}/kios`, { Aktif: true }, Opsi('hidup'))}
                        >
                            Hidupkan kios
                        </Tombol>
                    )
                ) : null}
            </div>
            {data.FiturAktif ? null : (
                <Pemberitahuan jenis="info" judul="Fitur Self-order belum aktif">
                    Paket usaha ini belum memuat Self-order. Tambahkan add-on di menu Langganan untuk memakai kios.
                </Pemberitahuan>
            )}
            {data.Tautan ? (
                <div className="flex flex-col gap-3">
                    <TautanRahasia
                        judul="Tautan kios (tablet pelanggan)"
                        tautan={data.Tautan}
                        tersalin={tersalin === 'kios'}
                        saatSalin={() => void Salin(data.Tautan ?? '', 'kios')}
                        petunjuk="Buka di tablet yang dipasang di outlet, lalu jadikan layar penuh. Rahasiakan tautan ini."
                    />
                    {data.TautanAntrian ? (
                        <TautanRahasia
                            judul="Layar antrian (monitor atau TV)"
                            tautan={data.TautanAntrian}
                            tersalin={tersalin === 'antrian'}
                            saatSalin={() => void Salin(data.TautanAntrian ?? '', 'antrian')}
                            petunjuk="Menampilkan nomor yang sedang disiapkan dan yang siap diambil."
                        />
                    ) : null}
                    {bolehKelola ? (
                        <div>
                            <Tombol
                                varian="sekunder"
                                disabled={memproses !== null}
                                onClick={() => AturKonfirmasi(true)}
                            >
                                Buat ulang tautan
                            </Tombol>
                        </div>
                    ) : null}
                </div>
            ) : null}
            {data.Aktif ? (
                data.QrisTersedia ? (
                    <p className="text-keterangan text-teks-sekunder">
                        Pelanggan bisa memilih bayar di kasir atau QRIS langsung di layar kios.
                    </p>
                ) : (
                    <Pemberitahuan jenis="info" judul="QRIS di kios belum tersedia">
                        Pelanggan hanya bisa memilih bayar di kasir. Hidupkan QRIS di pengaturan Toko online (butuh QRIS
                        toko aktif) agar kios juga menerima QRIS.
                    </Pemberitahuan>
                )
            ) : null}
            {konfirmasi ? (
                <DialogKonfirmasi
                    judul="Buat ulang tautan kios?"
                    labelAksi="Buat ulang tautan"
                    memproses={memproses === 'ulang'}
                    saatBatal={() => AturKonfirmasi(false)}
                    saatKonfirmasi={() => router.post(`${alamatOutlet}/kios/buat-ulang`, {}, Opsi('ulang'))}
                >
                    <p>
                        Tautan kios dan layar antrian yang lama berhenti bekerja seketika. Buka tautan baru di tablet
                        dan monitor outlet.
                    </p>
                </DialogKonfirmasi>
            ) : null}
        </Panel>
    );
}

function TautanRahasia({
    judul,
    tautan,
    tersalin,
    saatSalin,
    petunjuk,
}: {
    judul: string;
    tautan: string;
    tersalin: boolean;
    saatSalin: () => void;
    petunjuk: string;
}) {
    return (
        <section className="flex flex-col gap-2" aria-label={judul}>
            <h3 className="text-label font-semibold text-teks-utama">{judul}</h3>
            <p className="break-all rounded-panel border border-garis bg-permukaan-redup px-3 py-2 font-mono text-keterangan text-teks-utama">
                {tautan}
            </p>
            <p className="text-keterangan text-teks-sekunder">{petunjuk}</p>
            <div className="flex flex-wrap gap-2">
                <Tombol varian="sekunder" onClick={saatSalin}>
                    {tersalin ? 'Tersalin' : 'Salin tautan'}
                </Tombol>
                <Button asChild variant="outline" className="h-8 px-4 text-label font-semibold pointer-coarse:h-11">
                    <a href={tautan} target="_blank" rel="noreferrer">
                        Buka
                    </a>
                </Button>
            </div>
        </section>
    );
}
