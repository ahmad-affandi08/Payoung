import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import GrupCentang from '@/Komponen/Formulir/GrupCentang';
import KartuFormulir from '@/Komponen/Formulir/KartuFormulir';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import PesanFiturPaketSesi from '@/Komponen/Pelanggan/PesanFiturPaketSesi';
import Tombol from '@/Komponen/Formulir/Tombol';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsFormulirPaketSesi } from '@/Tipe/Katalog';

const alamat = '/kelola/paket-sesi';

type Isian = {
    UuidProduk: string;
    JumlahSesi: string;
    MasaBerlakuHari: string;
    SemuaProdukJasa: boolean;
    ProdukBerlaku: string[];
    Aktif: boolean;
};

const bidangForm = ['UuidProduk', 'JumlahSesi', 'MasaBerlakuHari', 'SemuaProdukJasa', 'ProdukBerlaku', 'Aktif'];

/**
 * F-16d bagian 2: tambah/ubah paket sesi. Produk paket = produk Jasa (harga jualnya = harga paket); layanan yang bisa
 * ditukar = produk Jasa lain, atau semua layanan. Perubahan berlaku untuk penjualan berikutnya.
 */
export default function HalamanFormulirPaketSesi({ Paket, PilihanJasa, FiturAktif }: PropsFormulirPaketSesi) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [isian, AturIsian] = useState<Isian>({
        UuidProduk: Paket?.UuidProduk ?? '',
        JumlahSesi: Paket ? String(Paket.JumlahSesi) : '10',
        MasaBerlakuHari: Paket?.MasaBerlakuHari === null || Paket === null ? '' : String(Paket.MasaBerlakuHari),
        SemuaProdukJasa: Paket?.SemuaProdukJasa ?? false,
        ProdukBerlaku: Paket?.ProdukBerlaku ?? [],
        Aktif: Paket?.Aktif ?? true,
    });
    const [memproses, AturMemproses] = useState(false);
    const Ubah = (ubah: Partial<Isian>) => AturIsian({ ...isian, ...ubah });

    const opsiPaket = PilihanJasa.filter((p) => !p.SudahPaket || p.Nilai === Paket?.UuidProduk).map((p) => ({
        Nilai: p.Nilai,
        Label: p.Label,
    }));
    const opsiLayanan = PilihanJasa.filter((p) => p.Nilai !== isian.UuidProduk).map((p) => ({
        nilai: p.Nilai,
        label: p.Label,
    }));

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        const data = {
            UuidProduk: isian.UuidProduk,
            JumlahSesi: isian.JumlahSesi === '' ? null : Number(isian.JumlahSesi),
            MasaBerlakuHari: isian.MasaBerlakuHari === '' ? null : Number(isian.MasaBerlakuHari),
            SemuaProdukJasa: isian.SemuaProdukJasa,
            ProdukBerlaku: isian.SemuaProdukJasa ? [] : isian.ProdukBerlaku,
            Aktif: isian.Aktif,
        };
        const opsi = {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
        };

        if (Paket === null) {
            router.post(alamat, data, opsi);
        } else {
            router.put(`${alamat}/${Paket.Uuid}`, data, opsi);
        }
    };

    return (
        <TataLetakAplikasi judul={Paket === null ? 'Tambah paket sesi' : `Ubah paket ${Paket.NamaProduk}`}>
            {FiturAktif ? null : <PesanFiturPaketSesi />}
            <DaftarGalatServer
                galat={galat}
                kecuali={Object.keys(galat).filter((k) => bidangForm.includes(k.split('.')[0] ?? k))}
            />
            <KartuFormulir keterangan="Buat dulu produk Jasa untuk paketnya, misal Paket Creambath 10x. Pelanggan wajib dipilih saat dijual.">
                <form onSubmit={Simpan} className="flex flex-col gap-4" aria-label="Formulir paket sesi" noValidate>
                    <BidangPilihan
                        label="Produk paket (Jasa)"
                        nilai={isian.UuidProduk}
                        opsi={opsiPaket}
                        saatBerubah={(nilai) =>
                            Ubah({ UuidProduk: nilai, ProdukBerlaku: isian.ProdukBerlaku.filter((u) => u !== nilai) })
                        }
                        galat={galat.UuidProduk}
                        required
                    />
                    <BidangJumlah
                        label="Jumlah sesi per paket"
                        nilai={isian.JumlahSesi}
                        saatBerubah={(nilai) => Ubah({ JumlahSesi: nilai })}
                        desimal={0}
                        digitBulat={4}
                        akhiran="sesi"
                        galat={galat.JumlahSesi}
                        required
                    />
                    <BidangJumlah
                        label="Masa berlaku"
                        nilai={isian.MasaBerlakuHari}
                        saatBerubah={(nilai) => Ubah({ MasaBerlakuHari: nilai })}
                        desimal={0}
                        digitBulat={4}
                        akhiran="hari"
                        keterangan="Dihitung dari tanggal beli. Kosongkan bila tanpa batas. Sisa sesi yang lewat masa berlaku hangus otomatis."
                        galat={galat.MasaBerlakuHari}
                    />
                    <KotakCentang
                        label="Bisa ditukar untuk semua layanan (semua produk Jasa)"
                        nilai={isian.SemuaProdukJasa}
                        saatBerubah={(nilai) => Ubah({ SemuaProdukJasa: nilai })}
                    />
                    {isian.SemuaProdukJasa ? null : (
                        <GrupCentang
                            legenda="Layanan yang bisa ditukar dengan sesi"
                            opsi={opsiLayanan}
                            terpilih={isian.ProdukBerlaku}
                            saatBerubah={(terpilih) => Ubah({ ProdukBerlaku: terpilih })}
                            galat={galat.ProdukBerlaku}
                            required
                        />
                    )}
                    <KotakCentang
                        label="Aktif (bisa dijual di kasir)"
                        nilai={isian.Aktif}
                        saatBerubah={(nilai) => Ubah({ Aktif: nilai })}
                    />
                    <BilahAksiForm>
                        <Tombol type="button" varian="sekunder" onClick={() => router.visit(alamat)}>
                            Batal
                        </Tombol>
                        <Tombol type="submit" memproses={memproses} disabled={!FiturAktif}>
                            Simpan paket sesi
                        </Tombol>
                    </BilahAksiForm>
                </form>
            </KartuFormulir>
        </TataLetakAplikasi>
    );
}
