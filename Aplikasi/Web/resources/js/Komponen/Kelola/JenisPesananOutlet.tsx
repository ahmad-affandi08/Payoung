import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import GrupCentang from '@/Komponen/Formulir/GrupCentang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import type { Pilihan } from '@/Tipe/Organisasi';

export type JenisPesananOutletData = {
    JenisPesanan: string[];
    JenisPesananBawaan: string | null;
    Otomatis: boolean;
    Pilihan: Pilihan[];
};

type Props = { alamatOutlet: string; data: JenisPesananOutletData; bolehKelola: boolean };

/**
 * v3.51: jenis pesanan yang dipilih kasir per transaksi (Makan di tempat, Bawa pulang, Antar) dan bawaannya. Mode
 * otomatis mengikuti jenis usaha outlet: FnB menawarkan makan di tempat & bawa pulang, retail tanpa pilihan.
 */
export default function JenisPesananOutlet({ alamatOutlet, data, bolehKelola }: Props) {
    const { props } = usePage<{ errors: Record<string, string> }>();
    const [otomatis, AturOtomatis] = useState(data.Otomatis);
    const [daftar, AturDaftar] = useState<string[]>(data.JenisPesanan);
    const [bawaan, AturBawaan] = useState(data.JenisPesananBawaan ?? '');
    const [memproses, AturMemproses] = useState(false);
    const label = new Map(data.Pilihan.map((p) => [p.Nilai, p.Label]));
    const opsiBawaan = data.Pilihan.filter((p) => daftar.includes(p.Nilai));

    const Simpan = (e: FormEvent) => {
        e.preventDefault();
        AturMemproses(true);
        router.post(
            `${alamatOutlet}/jenis-pesanan`,
            {
                Otomatis: otomatis,
                JenisPesanan: otomatis ? [] : daftar,
                JenisPesananBawaan: otomatis || daftar.length === 0 ? null : bawaan || null,
            },
            { preserveScroll: true, onFinish: () => AturMemproses(false) },
        );
    };

    const ringkas =
        data.JenisPesanan.length === 0
            ? 'Kasir tidak menawarkan pilihan. Semua penjualan tercatat Bawa pulang.'
            : `${data.JenisPesanan.map((j) => label.get(j) ?? j).join(', ')} | bawaan ${
                  label.get(data.JenisPesananBawaan ?? '') ?? '-'
              }${data.Otomatis ? ' (otomatis)' : ''}`;

    return (
        <Panel
            judul="Jenis pesanan di kasir"
            keterangan="Kasir memilih jenis pesanan untuk setiap transaksi. Harga per kanal, struk, dan tiket dapur mengikuti pilihan ini."
        >
            <p className="text-isi text-teks-utama">{ringkas}</p>
            {bolehKelola ? (
                <form noValidate className="flex flex-col gap-3" onSubmit={Simpan}>
                    <KotakCentang
                        label="Otomatis menurut jenis usaha outlet"
                        nilai={otomatis}
                        saatBerubah={AturOtomatis}
                    />
                    {otomatis ? null : (
                        <>
                            <GrupCentang
                                legenda="Jenis pesanan yang ditawarkan"
                                opsi={data.Pilihan.map((p) => ({ nilai: p.Nilai, label: p.Label }))}
                                terpilih={daftar}
                                saatBerubah={(baru) => {
                                    AturDaftar(baru);
                                    if (!baru.includes(bawaan)) AturBawaan(baru[0] ?? '');
                                }}
                                galat={props.errors.JenisPesanan}
                            />
                            {daftar.length > 0 ? (
                                <BidangPilihan
                                    label="Bawaan untuk transaksi baru"
                                    nilai={bawaan}
                                    opsi={opsiBawaan}
                                    saatBerubah={AturBawaan}
                                    galat={props.errors.JenisPesananBawaan}
                                    required
                                />
                            ) : null}
                        </>
                    )}
                    <div>
                        <Tombol type="submit" memproses={memproses}>
                            Simpan jenis pesanan
                        </Tombol>
                    </div>
                </form>
            ) : null}
        </Panel>
    );
}
