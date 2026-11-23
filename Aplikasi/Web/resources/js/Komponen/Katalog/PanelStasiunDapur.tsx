import { router } from '@inertiajs/react';
import { useState } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import type { StasiunDapurProduk } from '@/Tipe/Katalog';

type PropsPanelStasiunDapur = {
    uuidProduk: string;
    data: StasiunDapurProduk;
    bolehUbah: boolean;
};

/**
 * D-310: stasiun dapur atau bar khusus satu menu. Kosong = ikut stasiun kategorinya. Berlaku untuk seluruh usaha
 * dan dipakai server serta semua kasir untuk merutekan tiket. Kasir juga bisa mengaturnya dari aplikasi kasir.
 */
export default function PanelStasiunDapur({ uuidProduk, data, bolehUbah }: PropsPanelStasiunDapur) {
    const [pilihan, AturPilihan] = useState<string>(data.UuidStasiun ?? '');
    const [memproses, AturMemproses] = useState(false);
    const opsi =
        data.UuidStasiun !== null && !data.Opsi.some((o) => o.Nilai === data.UuidStasiun)
            ? [
                  ...data.Opsi,
                  { Nilai: data.UuidStasiun, Label: `${data.NamaStasiunDiarsipkan ?? 'Stasiun'} (diarsipkan)` },
              ]
            : data.Opsi;
    const ikutKategori =
        data.NamaStasiunKategori === null
            ? 'Ikut kategori (stasiun bawaan bila kategorinya belum diatur)'
            : `Ikut kategori (${data.NamaStasiunKategori})`;

    const Simpan = () =>
        router.post(
            `/kelola/produk/${uuidProduk}/stasiun-dapur`,
            { UuidStasiun: pilihan === '' ? null : pilihan },
            {
                preserveScroll: true,
                onStart: () => AturMemproses(true),
                onFinish: () => AturMemproses(false),
            },
        );

    return (
        <Panel
            judul="Stasiun dapur"
            idJudul="judul-stasiun-dapur"
            keterangan="Pilih bila menu ini dikerjakan di stasiun lain daripada kategorinya. Berlaku di semua outlet."
        >
            <div className="flex flex-col gap-3 sm:max-w-md">
                <BidangPilihan
                    label="Dikirim ke stasiun"
                    nilai={pilihan}
                    kosong={ikutKategori}
                    opsi={opsi}
                    saatBerubah={AturPilihan}
                    disabled={!bolehUbah}
                />
                {bolehUbah ? (
                    <div>
                        <Tombol
                            varian="sekunder"
                            memproses={memproses}
                            disabled={pilihan === (data.UuidStasiun ?? '')}
                            onClick={Simpan}
                        >
                            Simpan stasiun
                        </Tombol>
                    </div>
                ) : null}
            </div>
        </Panel>
    );
}
