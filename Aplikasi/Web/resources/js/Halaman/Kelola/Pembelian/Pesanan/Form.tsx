import { Link, router, usePage } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import TawaranDraf from '@/Komponen/Formulir/TawaranDraf';
import { useDrafLokal } from '@/Komponen/Formulir/useDrafLokal';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import {
    BuatKunciBaris,
    HitungTotalBaris,
    PeriksaBaris,
    SusunMasukanBaris,
} from '@/Komponen/Pembelian/AturanPembelian';
import TombolPemasokBaru from '@/Komponen/Pembelian/TombolPemasokBaru';
import { AlamatPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import IsianBarisPembelian from '@/Komponen/Pembelian/IsianBarisPembelian';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatRupiah } from '@/Pustaka/Format';
import { FormatLabelGudang } from '@/Pustaka/FormatPersediaan';
import { BandingkanDesimal, JumlahkanDesimal } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisIsianBebas, OpsiPemasok, PropsFormPesanan } from '@/Tipe/Pembelian';

const alamat = `${AlamatPembelian}/pesanan`;

/** F-04 fase 1: form draf pesanan pembelian (buat/ubah). Pengajuan & persetujuan dari halaman detail. */
export default function HalamanFormPesanan({
    Mode,
    Pesanan,
    OpsiPemasok: OpsiPemasokAwal,
    OpsiGudang,
    HariIni,
    BatasPersetujuanPo,
    MaksimalBaris,
}: PropsFormPesanan) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    // Pemasok yang ditambahkan lewat dialog "Tambah pemasok baru" langsung tersedia di pilihan tanpa memuat ulang halaman.
    const [pemasokBaru, AturPemasokBaru] = useState<OpsiPemasok[]>([]);
    const OpsiPemasok = [...OpsiPemasokAwal, ...pemasokBaru];
    const [pemasok, AturPemasok] = useState(Pesanan?.UuidPemasok ?? '');
    const [gudang, AturGudang] = useState(Pesanan?.UuidGudang ?? '');
    const [tanggal, AturTanggal] = useState(Pesanan?.Tanggal ?? HariIni);
    const [tiba, AturTiba] = useState(Pesanan?.PerkiraanTiba ?? '');
    const [termin, AturTermin] = useState(String(Pesanan?.TerminHari ?? ''));
    const [ongkir, AturOngkir] = useState(Pesanan?.Ongkir.replace(/\.00$/, '') ?? '');
    const [catatan, AturCatatan] = useState(Pesanan?.Catatan ?? '');
    const [baris, AturBaris] = useState<BarisIsianBebas[]>(() =>
        (Pesanan?.Baris ?? []).map((b) => ({
            ...b,
            Kunci: BuatKunciBaris(),
            NomorBatch: '',
            TanggalKedaluwarsa: '',
            NomorSeri: [],
        })),
    );
    const [periksa, AturPeriksa] = useState(false);
    const draf = useDrafLokal({
        nama: 'pesanan-pembelian',
        aktif: Pesanan === null,
        nilai: { pemasok, gudang, tiba, termin, ongkir, catatan, baris },
        kosong: (n) => n.baris.length === 0 && n.catatan.trim() === '',
    });
    const PulihkanDraf = () => {
        const isi = draf.Pulihkan();

        if (isi === null) {
            return;
        }

        AturPemasok(isi.pemasok);
        AturGudang(isi.gudang);
        AturTiba(isi.tiba);
        AturTermin(isi.termin);
        AturOngkir(isi.ongkir);
        AturCatatan(isi.catatan);
        AturBaris(isi.baris.map((b) => ({ ...b, Kunci: BuatKunciBaris() })));
    };
    const [memproses, AturMemproses] = useState(false);
    // Audit kemudahan pakai #13: tombol utama menyimpan lalu langsung memproses dokumen; draf jadi pilihan kedua.
    const lanjutkan = useRef(false);
    const judul = Mode === 'Buat' ? 'Buat pesanan pembelian' : `Ubah draf ${Pesanan?.Nomor ?? ''}`;
    const pemasokTerpilih = OpsiPemasok.find((p) => p.Uuid === pemasok);
    const totalBarang = HitungTotalBaris(baris);
    const perkiraanTotal = JumlahkanDesimal([totalBarang, ongkir === '' ? '0' : ongkir]);
    const butuhPersetujuan = BandingkanDesimal(perkiraanTotal, BatasPersetujuanPo) > 0;

    const PilihPemasok = (uuid: string) => {
        AturPemasok(uuid);
        const p = OpsiPemasok.find((o) => o.Uuid === uuid);

        if (p && termin === '') {
            AturTermin(String(p.TerminHari));
        }
    };

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturPeriksa(true);
        const adaGalat =
            pemasok === '' ||
            gudang === '' ||
            baris.length === 0 ||
            baris.some((b) => Object.keys(PeriksaBaris(b, { wajibHarga: true, pelacakan: false })).length > 0);

        if (adaGalat) {
            return;
        }

        const data = {
            UuidPemasok: pemasok,
            UuidGudang: gudang,
            Tanggal: tanggal,
            PerkiraanTiba: tiba === '' ? null : tiba,
            TerminHari: termin === '' ? null : Number(termin),
            Ongkir: ongkir === '' ? '0' : ongkir,
            Catatan: catatan === '' ? null : catatan,
            Baris: baris.map((b) => SusunMasukanBaris(b, false)),
        };
        const opsi = {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
            onSuccess: () => draf.Hapus(),
        };

        if (Mode === 'Buat' || Pesanan === null) {
            router.post(alamat, { ...data, Lanjutkan: lanjutkan.current }, opsi);
        } else {
            router.put(`${alamat}/${Pesanan.Uuid}`, { ...data, Lanjutkan: lanjutkan.current }, opsi);
        }
    };

    return (
        <TataLetakAplikasi judul={judul}>
            <DaftarGalatServer
                galat={galat}
                kecuali={['UuidPemasok', 'UuidGudang', 'Tanggal', 'PerkiraanTiba', 'TerminHari', 'Ongkir', 'Catatan']}
            />
            {draf.tawaran ? (
                <TawaranDraf disimpan={draf.tawaran.Disimpan} saatLanjutkan={PulihkanDraf} saatBuang={draf.Buang} />
            ) : null}
            <form onSubmit={Simpan} noValidate aria-label={judul} className="flex flex-col gap-4">
                <Panel
                    judul="Dokumen"
                    idJudul="judul-dokumen-pesanan"
                    keterangan="Pesanan tidak mengubah stok. Stok bertambah saat barang diterima."
                >
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <BidangOutlet
                            label="Lokasi tujuan"
                            nilai={gudang}
                            kosong="Pilih lokasi stok"
                            opsi={OpsiGudang.filter((g) => g.Aktif || g.Uuid === gudang).map((g) => ({
                                Nilai: g.Uuid,
                                Label: FormatLabelGudang(g),
                            }))}
                            saatBerubah={AturGudang}
                            galat={galat.UuidGudang ?? (periksa && gudang === '' ? 'Pilih lokasi tujuan.' : undefined)}
                        />
                        <div className="flex flex-col gap-1.5">
                            <BidangPilihan
                                label="Pemasok"
                                nilai={pemasok}
                                kosong="Pilih pemasok"
                                opsi={OpsiPemasok.filter((p) => p.Aktif || p.Uuid === pemasok).map((p) => ({
                                    Nilai: p.Uuid,
                                    Label: p.Nama,
                                    Keterangan: `${p.Kode}${p.Pkp ? ' | PKP' : ''}`,
                                }))}
                                saatBerubah={PilihPemasok}
                                required
                                galat={galat.UuidPemasok ?? (periksa && pemasok === '' ? 'Pilih pemasok.' : undefined)}
                            />
                            <TombolPemasokBaru
                                saatTersimpan={(pemasokDibuat) => {
                                    AturPemasokBaru((lama) => [...lama, pemasokDibuat]);
                                    PilihPemasok(pemasokDibuat.Uuid);
                                }}
                            />
                        </div>
                        <PemilihTanggal
                            id="tanggal-pesanan"
                            label="Tanggal pesanan"
                            nilai={tanggal}
                            required
                            saatBerubah={AturTanggal}
                            galat={galat.Tanggal}
                        />
                        <PemilihTanggal
                            id="tiba-pesanan"
                            label="Perkiraan tiba (opsional)"
                            nilai={tiba}
                            min={tanggal}
                            saatBerubah={AturTiba}
                            galat={galat.PerkiraanTiba}
                        />
                        <BidangJumlah
                            label="Termin (hari)"
                            nilai={termin}
                            saatBerubah={AturTermin}
                            desimal={0}
                            digitBulat={3}
                            akhiran="hari"
                            keterangan={
                                pemasokTerpilih
                                    ? `Bawaan pemasok: ${pemasokTerpilih.TerminHari === 0 ? 'tunai' : `${String(pemasokTerpilih.TerminHari)} hari`}. 0 = tunai.`
                                    : '0 = tunai. Kosongkan untuk memakai termin bawaan pemasok.'
                            }
                            galat={galat.TerminHari}
                        />
                        <BidangUang
                            label="Ongkos kirim (opsional)"
                            nilai={ongkir}
                            saatBerubah={AturOngkir}
                            keterangan="Dibagi ke barang sebanding nilainya saat barang diterima (ongkos kirim dan biaya impor ikut menambah modal barang)."
                            galat={galat.Ongkir}
                        />
                    </div>
                    <BidangTeksPanjang
                        label="Catatan (opsional)"
                        nilai={catatan}
                        saatBerubah={AturCatatan}
                        galat={galat.Catatan}
                        baris={2}
                        maksimal={500}
                    />
                </Panel>

                <Panel
                    judul="Barang"
                    idJudul="judul-barang-pesanan"
                    keterangan={`${baris.length.toLocaleString('id-ID')} dari ${MaksimalBaris.toLocaleString('id-ID')} baris`}
                >
                    <IsianBarisPembelian
                        judul="Barang yang dipesan"
                        baris={baris}
                        saatBerubah={AturBaris}
                        uuidGudang={gudang === '' ? null : gudang}
                        uuidPemasok={pemasok === '' ? null : pemasok}
                        pelacakan={false}
                        periksa={periksa}
                        galatServer={galat}
                        maksimal={MaksimalBaris}
                    />
                    {periksa && baris.length === 0 ? (
                        <p className="text-keterangan font-semibold text-bahaya">Tambahkan minimal satu produk.</p>
                    ) : null}
                    <dl className="ml-auto flex w-full max-w-md flex-col gap-1 border-t border-garis pt-3">
                        <div className="flex justify-between gap-3">
                            <dt className="text-teks-sekunder">Total barang</dt>
                            <dd className="tabular-nums">{FormatRupiah(totalBarang)}</dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="font-semibold">Perkiraan total (sebelum PPN)</dt>
                            <dd className="font-semibold tabular-nums">{FormatRupiah(perkiraanTotal)}</dd>
                        </div>
                    </dl>
                    {butuhPersetujuan ? (
                        <Pemberitahuan jenis="info" judul="Butuh persetujuan">
                            Total di atas {FormatRupiah(BatasPersetujuanPo)}. Setelah diajukan, pesanan menunggu
                            persetujuan pengguna lain yang berizin menyetujui pesanan pembelian.
                        </Pemberitahuan>
                    ) : null}
                </Panel>

                <BilahAksiForm>
                    <Tombol type="submit" memproses={memproses} onClick={() => (lanjutkan.current = true)}>
                        Simpan & ajukan
                    </Tombol>
                    <Tombol
                        type="submit"
                        varian="sekunder"
                        disabled={memproses}
                        onClick={() => (lanjutkan.current = false)}
                    >
                        Simpan draf
                    </Tombol>
                    <Button asChild variant="outline">
                        <Link href={Pesanan ? `${alamat}/${Pesanan.Uuid}` : alamat}>Batal</Link>
                    </Button>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
