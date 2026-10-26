import { Link, router, usePage } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import { BuatKunciBaris, PeriksaBaris, SusunMasukanBaris } from '@/Komponen/Pembelian/AturanPembelian';
import { AlamatPembelian } from '@/Komponen/Pembelian/BagianDokumenPembelian';
import IsianBarisPembelian from '@/Komponen/Pembelian/IsianBarisPembelian';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import { FormatLabelGudang } from '@/Pustaka/FormatPersediaan';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarisIsianBebas, PropsFormPermintaan } from '@/Tipe/Pembelian';

const alamat = `${AlamatPembelian}/permintaan`;

/** F-04 PR: form draf permintaan pembelian (buat/ubah). Harga di baris hanya perkiraan. */
export default function HalamanFormPermintaan({
    Mode,
    Permintaan,
    OpsiPemasok,
    OpsiGudang,
    HariIni,
    MaksimalBaris,
}: PropsFormPermintaan) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const [pemasok, AturPemasok] = useState(Permintaan?.UuidPemasok ?? '');
    const [gudang, AturGudang] = useState(Permintaan?.UuidGudang ?? '');
    const [tanggal, AturTanggal] = useState(Permintaan?.Tanggal ?? HariIni);
    const [dibutuhkan, AturDibutuhkan] = useState(Permintaan?.DibutuhkanPada ?? '');
    const [catatan, AturCatatan] = useState(Permintaan?.Catatan ?? '');
    const [baris, AturBaris] = useState<BarisIsianBebas[]>(() =>
        (Permintaan?.Baris ?? []).map((b) => ({
            ...b,
            Kunci: BuatKunciBaris(),
            NomorBatch: '',
            TanggalKedaluwarsa: '',
            NomorSeri: [],
        })),
    );
    const [periksa, AturPeriksa] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    const lanjutkan = useRef(false);
    const judul = Mode === 'Buat' ? 'Buat permintaan pembelian' : `Ubah draf ${Permintaan?.Nomor ?? ''}`;

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturPeriksa(true);
        const adaGalat =
            gudang === '' ||
            baris.length === 0 ||
            baris.some((b) => Object.keys(PeriksaBaris(b, { wajibHarga: false, pelacakan: false })).length > 0);

        if (adaGalat) {
            return;
        }

        const data = {
            UuidGudang: gudang,
            UuidPemasok: pemasok === '' ? null : pemasok,
            Tanggal: tanggal,
            DibutuhkanPada: dibutuhkan === '' ? null : dibutuhkan,
            Catatan: catatan === '' ? null : catatan,
            Baris: baris.map((b) => SusunMasukanBaris(b, false)),
        };
        const opsi = {
            preserveScroll: true,
            onStart: () => AturMemproses(true),
            onFinish: () => AturMemproses(false),
        };

        if (Mode === 'Buat' || Permintaan === null) {
            router.post(alamat, { ...data, Lanjutkan: lanjutkan.current }, opsi);
        } else {
            router.put(`${alamat}/${Permintaan.Uuid}`, { ...data, Lanjutkan: lanjutkan.current }, opsi);
        }
    };

    return (
        <TataLetakAplikasi judul={judul} jejak={[{ label: 'Permintaan pembelian', href: alamat }]}>
            <DaftarGalatServer
                galat={galat}
                kecuali={['UuidGudang', 'UuidPemasok', 'Tanggal', 'DibutuhkanPada', 'Catatan']}
            />
            <form onSubmit={Simpan} noValidate aria-label={judul} className="flex flex-col gap-4">
                <Panel
                    judul="Permintaan"
                    idJudul="judul-dokumen-permintaan"
                    keterangan="Permintaan tidak mengubah stok. Harga hanya perkiraan. Pemasok dan harga final ditentukan saat dijadikan pesanan pembelian."
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
                        <BidangPilihan
                            label="Pemasok usulan (opsional)"
                            nilai={pemasok}
                            kosong="Belum ditentukan"
                            opsi={[
                                { Nilai: '', Label: 'Belum ditentukan' },
                                ...OpsiPemasok.filter((p) => p.Aktif || p.Uuid === pemasok).map((p) => ({
                                    Nilai: p.Uuid,
                                    Label: p.Nama,
                                    Keterangan: p.Kode,
                                })),
                            ]}
                            saatBerubah={AturPemasok}
                            galat={galat.UuidPemasok}
                        />
                        <PemilihTanggal
                            id="tanggal-permintaan"
                            label="Tanggal permintaan"
                            nilai={tanggal}
                            required
                            saatBerubah={AturTanggal}
                            galat={galat.Tanggal}
                        />
                        <PemilihTanggal
                            id="dibutuhkan-permintaan"
                            label="Dibutuhkan pada (opsional)"
                            nilai={dibutuhkan}
                            min={tanggal}
                            saatBerubah={AturDibutuhkan}
                            galat={galat.DibutuhkanPada}
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
                    idJudul="judul-barang-permintaan"
                    keterangan={`${baris.length.toLocaleString('id-ID')} dari ${MaksimalBaris.toLocaleString('id-ID')} baris`}
                >
                    <IsianBarisPembelian
                        judul="Barang yang diminta"
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
                        <Link href={Permintaan ? `${alamat}/${Permintaan.Uuid}` : alamat}>Batal</Link>
                    </Button>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
