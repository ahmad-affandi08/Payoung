import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import Tombol from '@/Komponen/Formulir/Tombol';
import { AlamatGrosir } from '@/Komponen/Grosir/BagianDokumenGrosir';
import IsianBarisGrosir, { BuatKunciBarisGrosir, type BarisIsianGrosir } from '@/Komponen/Grosir/IsianBarisGrosir';
import PemilihPelangganGrosir from '@/Komponen/Grosir/PemilihPelangganGrosir';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { PropsFormGrosir } from '@/Tipe/Grosir';

const alamat = `${AlamatGrosir}/pesanan`;
const MAKSIMAL_BARIS = 200;

/**
 * Form draf pesanan grosir (F-12, §9.7). Konfirmasi & pengiriman dilakukan dari halaman detail, karena keduanya punya
 * akibat yang perlu dilihat dulu: konfirmasi memeriksa limit kredit (BR-12.6) dan pengiriman memotong stok (BR-12.2).
 *
 * Harga tidak diisi di sini: server mengambilnya dari price engine dan menampilkannya di halaman draf.
 */
export default function HalamanFormPesananGrosir({ Isian, OpsiOutlet, OpsiGudang, HariIni }: PropsFormGrosir) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [uuidPelanggan, AturPelanggan] = useState(Isian?.UuidPelanggan ?? '');
    const [namaPelanggan, AturNamaPelanggan] = useState(Isian?.NamaPelanggan ?? '');
    const [uuidOutlet, AturOutlet] = useState(Isian?.UuidOutlet ?? '');
    const [tanggal, AturTanggal] = useState(Isian?.Tanggal ?? HariIni);
    const [tanggalKirim, AturTanggalKirim] = useState(Isian?.TanggalKirimDiminta ?? '');
    const [catatan, AturCatatan] = useState(Isian?.Catatan ?? '');
    const [baris, AturBaris] = useState<BarisIsianGrosir[]>(() =>
        (Isian?.Baris ?? []).map((b) => ({
            Kunci: BuatKunciBarisGrosir(),
            UuidProduk: b.UuidProduk,
            UuidProdukSatuan: b.UuidProdukSatuan,
            NamaProduk: b.NamaProduk,
            SimbolSatuan: b.SimbolSatuan,
            Jumlah: b.Jumlah.replace(/\.0+$/, ''),
            Diskon: b.Diskon === '0.00' ? '' : b.Diskon,
            Satuan: [{ Nilai: b.UuidProdukSatuan, Label: b.SimbolSatuan }],
        })),
    );
    const [periksa, AturPeriksa] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const ubah = Isian !== null;
    const judul = ubah ? `Ubah draf ${Isian.Nomor}` : 'Buat pesanan grosir';
    // Lokasi stok hanya dipakai untuk menampilkan saldo di pencarian produk; gudang pengirim dipilih saat surat jalan.
    const uuidGudang = OpsiGudang[0]?.Uuid ?? null;

    const Kirim = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturPeriksa(true);

        const isi = {
            UuidPelanggan: uuidPelanggan,
            UuidOutlet: uuidOutlet,
            Tanggal: tanggal,
            TanggalKirimDiminta: tanggalKirim === '' ? null : tanggalKirim,
            Catatan: catatan === '' ? null : catatan,
            Baris: baris
                .filter((b) => Number(b.Jumlah) > 0)
                .map((b) => ({
                    UuidProduk: b.UuidProduk,
                    UuidProdukSatuan: b.UuidProdukSatuan,
                    Jumlah: b.Jumlah,
                    Diskon: b.Diskon === '' ? null : b.Diskon,
                })),
        };
        const pilihan = {
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
        };

        if (ubah) {
            router.put(`${alamat}/${Isian.Uuid}`, isi, pilihan);

            return;
        }

        router.post(alamat, isi, pilihan);
    };

    const siap = uuidPelanggan !== '' && uuidOutlet !== '' && baris.some((b) => Number(b.Jumlah) > 0);

    return (
        <TataLetakAplikasi judul={judul}>
            <DaftarGalatServer galat={galat} />
            <Pemberitahuan jenis="info" judul="Harga mengikuti daftar harga">
                Harga grosir mengikuti daftar harga bertingkat & tier pelanggan. Harga dan total tampil setelah draf
                disimpan; periksa di halaman pesanan sebelum dikonfirmasi.
            </Pemberitahuan>

            <form onSubmit={Kirim} noValidate className="flex flex-col gap-4" aria-label={judul}>
                <Panel judul="Pesanan">
                    <div className="flex flex-col gap-4">
                        <div className="max-w-sm">
                            <BidangOutlet
                                label="Outlet penjual"
                                nilai={uuidOutlet}
                                opsi={OpsiOutlet.map((o) => ({ Nilai: o.Uuid, Label: `${o.Nama} (${o.Kode})` }))}
                                saatBerubah={AturOutlet}
                                galat={galat.UuidOutlet}
                            />
                        </div>
                        <PemilihPelangganGrosir
                            uuidTerpilih={uuidPelanggan}
                            namaTerpilih={namaPelanggan}
                            saatPilih={(uuid, nama) => {
                                AturPelanggan(uuid);
                                AturNamaPelanggan(nama);
                            }}
                            galat={galat.UuidPelanggan}
                        />
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <PemilihTanggal
                                label="Tanggal pesanan"
                                nilai={tanggal}
                                saatBerubah={AturTanggal}
                                galat={galat.Tanggal}
                                required
                            />
                            <PemilihTanggal
                                label="Minta dikirim"
                                nilai={tanggalKirim}
                                saatBerubah={AturTanggalKirim}
                                galat={galat.TanggalKirimDiminta}
                            />
                        </div>
                        <BidangTeksPanjang
                            label="Catatan"
                            nilai={catatan}
                            saatBerubah={AturCatatan}
                            galat={galat.Catatan}
                            maksimal={500}
                        />
                    </div>
                </Panel>

                <Panel judul="Barang">
                    <IsianBarisGrosir
                        baris={baris}
                        saatBerubah={AturBaris}
                        uuidGudang={uuidGudang}
                        periksa={periksa}
                        galatServer={galat}
                        maksimal={MAKSIMAL_BARIS}
                    />
                </Panel>

                <BilahAksiForm>
                    <Button asChild variant="outline" type="button">
                        <Link href={ubah ? `${alamat}/${Isian.Uuid}` : alamat}>Batal</Link>
                    </Button>
                    <Tombol type="submit" memproses={memproses} disabled={!siap}>
                        Simpan draf
                    </Tombol>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
