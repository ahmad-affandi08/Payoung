import { Link, router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import PemilihPelangganGrosir from '@/Komponen/Grosir/PemilihPelangganGrosir';
import { AlamatInvoice } from '@/Komponen/Invoice/BagianInvoice';
import IsianBarisInvoice, { BuatBarisKosongInvoice } from '@/Komponen/Invoice/IsianBarisInvoice';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import { AlamatPenawaran } from '@/Komponen/Penawaran/BagianPenawaran';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import { FormatRupiah } from '@/Pustaka/Format';
import { HitungSubtotalInvoice } from '@/Pustaka/HitungInvoice';
import { TambahHariTanggal } from '@/Pustaka/TanggalPenawaran';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisIsianInvoice } from '@/Tipe/Invoice';
import type { PropsFormPenawaran } from '@/Tipe/Penawaran';

const MAKSIMAL_BARIS = 200;

let urut = 0;

function BuatKunci(): string {
    urut += 1;

    return `isian-penawaran-${String(urut)}`;
}

/**
 * Form draf penawaran (F-21 bagian 3, D-168). Baris dan pajaknya memakai isian yang sama dengan invoice, supaya penawaran
 * tidak berisi apa yang kelak ditolak invoice. Menyimpan draf tidak membuat nomor; nomor dibuat saat diterbitkan. Total dan
 * pajak dihitung server, di sini hanya pratinjau subtotal. `Isian.Uuid` null berarti salinan (penawaran baru).
 */
export default function HalamanFormPenawaran({
    Isian,
    Kebiasaan,
    MasaBerlakuBawaan,
    OpsiOutlet,
    OpsiKelompokPajak,
    HariIni,
}: PropsFormPenawaran) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const uuidUbah = Isian?.Uuid ?? null;
    const ubah = uuidUbah !== null;
    const salinan = Isian !== null && !ubah;
    const [uuidPelanggan, AturPelanggan] = useState(Isian?.UuidPelanggan ?? '');
    const [namaPelanggan, AturNamaPelanggan] = useState(Isian?.NamaPelanggan ?? '');
    const [uuidOutlet, AturOutlet] = useState(
        Isian?.UuidOutlet ?? (OpsiOutlet.length === 1 ? (OpsiOutlet[0]?.Uuid ?? '') : ''),
    );
    // Penawaran baru atau salinan mulai dari hari ini; draf yang diubah mempertahankan tanggalnya.
    const [tanggal, AturTanggal] = useState(ubah ? (Isian?.Tanggal ?? HariIni) : HariIni);
    const [berlaku, AturBerlaku] = useState(
        ubah ? (Isian?.BerlakuSampai ?? '') : TambahHariTanggal(HariIni, MasaBerlakuBawaan),
    );
    const [berlakuManual, AturBerlakuManual] = useState(ubah);
    const [termin, AturTermin] = useState(Isian === null ? '' : String(Isian.TerminHari));
    const [perihal, AturPerihal] = useState(Isian?.Perihal ?? '');
    const [referensi, AturReferensi] = useState(Isian?.NomorReferensi ?? '');
    const [inklusif, AturInklusif] = useState(Isian?.HargaTermasukPajak ?? false);
    const [catatan, AturCatatan] = useState(Isian?.Catatan ?? '');
    const [syarat, AturSyarat] = useState(Isian?.Syarat ?? Kebiasaan?.Syarat ?? '');
    const [baris, AturBaris] = useState<BarisIsianInvoice[]>(() =>
        Isian === null
            ? [BuatBarisKosongInvoice()]
            : Isian.Baris.map((b) => ({
                  ...b,
                  Kunci: BuatKunci(),
                  Jumlah: b.Jumlah.replace(/\.0+$/, ''),
                  Harga: b.Harga.replace(/\.00$/, ''),
                  Diskon: b.Diskon === '0.00' ? '' : b.Diskon.replace(/\.00$/, ''),
              })),
    );
    const [periksa, AturPeriksa] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const judul = ubah ? `Ubah draf ${Isian?.Nomor ?? 'penawaran'}` : salinan ? 'Salin penawaran' : 'Buat penawaran';
    const subtotal = HitungSubtotalInvoice(baris);

    const UbahTanggal = (nilai: string) => {
        AturTanggal(nilai);

        // Masa berlaku mengikuti tanggal selama pengguna belum mengaturnya sendiri.
        if (!berlakuManual && /^\d{4}-\d{2}-\d{2}$/.test(nilai)) {
            AturBerlaku(TambahHariTanggal(nilai, MasaBerlakuBawaan));
        }
    };

    const Kirim = (peristiwa: FormEvent, terbitkan: boolean) => {
        peristiwa.preventDefault();
        AturPeriksa(true);

        const isi = {
            UuidPelanggan: uuidPelanggan,
            UuidOutlet: uuidOutlet,
            Tanggal: tanggal,
            BerlakuSampai: berlaku,
            TerminHari: termin.trim() === '' ? null : Number(termin),
            Perihal: perihal === '' ? null : perihal,
            NomorReferensi: referensi === '' ? null : referensi,
            HargaTermasukPajak: inklusif,
            Catatan: catatan === '' ? null : catatan,
            Syarat: syarat === '' ? null : syarat,
            Lanjutkan: terbitkan,
            Baris: baris.map((b) => ({
                UuidProduk: b.UuidProduk,
                Nama: b.Nama,
                Keterangan: b.Keterangan === '' ? null : b.Keterangan,
                JenisBaris: b.JenisBaris,
                SimbolSatuan: b.SimbolSatuan === '' ? null : b.SimbolSatuan,
                Jumlah: b.Jumlah,
                Harga: b.Harga,
                Diskon: b.Diskon === '' ? null : b.Diskon,
                UuidKelompokPajak: b.UuidKelompokPajak,
                HargaTermasukPajak: b.HargaTermasukPajak,
            })),
        };
        const pilihan = {
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
        };

        if (ubah) {
            router.put(`${AlamatPenawaran}/${uuidUbah}`, isi, pilihan);

            return;
        }

        router.post(AlamatPenawaran, isi, pilihan);
    };

    const siap =
        uuidPelanggan !== '' &&
        uuidOutlet !== '' &&
        berlaku !== '' &&
        baris.length > 0 &&
        baris.every((b) => b.Nama.trim() !== '' && Number(b.Jumlah) > 0 && b.Harga !== '');

    return (
        <TataLetakAplikasi judul={judul} jejak={[{ label: 'Penawaran', href: AlamatPenawaran }]}>
            <DaftarGalatServer galat={galat} />

            <form onSubmit={(e) => Kirim(e, false)} noValidate className="flex flex-col gap-4" aria-label={judul}>
                <Panel judul="Pelanggan & masa berlaku">
                    <div className="flex flex-col gap-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <PemilihPelangganGrosir
                                uuidTerpilih={uuidPelanggan}
                                namaTerpilih={namaPelanggan}
                                alamat={`${AlamatInvoice}/pelanggan/cari`}
                                saatPilih={(uuid, nama) => {
                                    AturPelanggan(uuid);
                                    AturNamaPelanggan(nama);
                                }}
                                galat={galat.UuidPelanggan}
                            />
                            <BidangOutlet
                                label="Outlet penerbit"
                                nilai={uuidOutlet}
                                opsi={OpsiOutlet.map((o) => ({ Nilai: o.Uuid, Label: `${o.Nama} (${o.Kode})` }))}
                                saatBerubah={AturOutlet}
                                galat={galat.UuidOutlet}
                            />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <PemilihTanggal
                                label="Tanggal penawaran"
                                nilai={tanggal}
                                saatBerubah={UbahTanggal}
                                galat={galat.Tanggal}
                                required
                            />
                            <PemilihTanggal
                                label="Berlaku sampai"
                                nilai={berlaku}
                                saatBerubah={(nilai) => {
                                    AturBerlaku(nilai);
                                    AturBerlakuManual(true);
                                }}
                                galat={galat.BerlakuSampai}
                                required
                            />
                            <BidangTeks
                                label="Termin pembayaran (hari)"
                                keterangan="Dipakai invoice hasil penawaran ini. Kosong = ikut data pelanggan."
                                nilai={termin}
                                saatBerubah={(nilai) => AturTermin(nilai.replace(/\D/g, '').slice(0, 3))}
                                inputMode="numeric"
                                galat={galat.TerminHari}
                            />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <BidangTeks
                                label="Perihal"
                                keterangan="Judul yang tampil di penawaran, misalnya Desain identitas merek."
                                nilai={perihal}
                                saatBerubah={AturPerihal}
                                maxLength={150}
                                galat={galat.Perihal}
                            />
                            <BidangTeks
                                label="No. PO / referensi pelanggan"
                                nilai={referensi}
                                saatBerubah={AturReferensi}
                                maxLength={60}
                                kode
                                galat={galat.NomorReferensi}
                            />
                        </div>
                    </div>
                </Panel>

                <Panel judul="Baris penawaran">
                    <div className="flex flex-col gap-4">
                        <IsianBarisInvoice
                            baris={baris}
                            saatBerubah={AturBaris}
                            opsiKelompokPajak={OpsiKelompokPajak}
                            periksa={periksa}
                            galatServer={galat}
                            maksimal={MAKSIMAL_BARIS}
                            sembunyikanDiskon={false}
                        />
                        <KotakCentang
                            label="Harga di atas sudah termasuk pajak"
                            nilai={inklusif}
                            saatBerubah={AturInklusif}
                        />
                        <p className="text-isi text-teks-sekunder">
                            Subtotal baris{' '}
                            <span className="font-semibold text-teks-utama tabular-nums">{FormatRupiah(subtotal)}</span>
                            . Pajak dan total akhir dihitung sistem dan tampil setelah draf disimpan.
                        </p>
                    </div>
                </Panel>

                <Panel judul="Catatan & syarat">
                    <div className="grid gap-4 lg:grid-cols-2">
                        <BidangTeksPanjang
                            label="Catatan untuk pelanggan"
                            nilai={catatan}
                            saatBerubah={AturCatatan}
                            maksimal={1000}
                            galat={galat.Catatan}
                        />
                        <BidangTeksPanjang
                            label="Syarat & ketentuan"
                            nilai={syarat}
                            saatBerubah={AturSyarat}
                            maksimal={1000}
                            galat={galat.Syarat}
                        />
                    </div>
                </Panel>

                <BilahAksiForm>
                    <Tombol type="button" memproses={memproses} disabled={!siap} onClick={(e) => Kirim(e, true)}>
                        Simpan & terbitkan
                    </Tombol>
                    <Tombol type="submit" varian="sekunder" memproses={memproses} disabled={!siap}>
                        Simpan draf
                    </Tombol>
                    <Button asChild variant="outline" type="button">
                        <Link href={ubah ? `${AlamatPenawaran}/${uuidUbah}` : AlamatPenawaran}>Batal</Link>
                    </Button>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
