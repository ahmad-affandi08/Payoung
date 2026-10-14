import { router, usePage } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangUang from '@/Komponen/Formulir/BidangUang';
import GrupCentang from '@/Komponen/Formulir/GrupCentang';
import KotakCentang from '@/Komponen/Formulir/KotakCentang';
import Tombol from '@/Komponen/Formulir/Tombol';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import { FormatRupiah } from '@/Pustaka/Format';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { BarcodeTimbangan, PropsPengaturanKasir } from '@/Tipe/Kasir';

const alamat = '/kelola/kasir/pengaturan';

/** Kelipatan pembulatan tunai yang umum di Indonesia (Rupiah). */
const kelipatanUmum = ['50', '100', '200', '500', '1000'];

/** "200000.00" → "200000" untuk BidangUang (tanpa float, hanya memotong sen nol). */
export function UbahKeMasukanUang(nilai: string): string {
    return nilai.replace(/\.00$/, '');
}

/** Persen dari server ("10.00", "25.50") → teks isian ("10", "25.5"); tanpa konversi ke number. */
export function UbahKeMasukanPersen(nilai: string): string {
    return nilai.includes('.') ? nilai.replace(/0+$/, '').replace(/\.$/, '') : nilai;
}

/** Isian persen: hanya angka dan satu titik/koma desimal (koma diubah ke titik), maks. 2 desimal. */
export function NormalisasiMasukanPersen(teks: string): string {
    const bersih = teks.replace(',', '.').replace(/[^\d.]/g, '');
    const [bulat = '', ...sisa] = bersih.split('.');

    return sisa.length === 0 ? bulat.slice(0, 3) : `${bulat.slice(0, 3)}.${sisa.join('').slice(0, 2)}`;
}

/**
 * Pengaturan kasir tenant: batas kas keluar tanpa persetujuan (BR-06.4), shift bersama (BR-06.2), batas diskon manual
 * kasir & penyetuju (BR-07.3), pembulatan tunai (BR-08.6), tutup shift buta & toleransi selisih kas (F-11), batas hari retur (F-09), batas retur tanpa struk per hari (K28), serta batas hari lewat jatuh tempo (F-12). Berlaku di aplikasi kasir setelah data perangkat
 * diperbarui.
 */
export default function HalamanPengaturanKasir({
    BatasKasKeluar,
    ShiftBersama,
    BatasDiskonManual,
    BatasDiskonPenyetuju,
    PembulatanTunai,
    OpsiArahPembulatan,
    TutupShiftButa,
    ToleransiSelisihKas,
    BatasHariRetur,
    BatasHariLewatJatuhTempo,
    BukaLaciPerluPin,
    BatasReturTanpaStrukHarian,
    BatasNotifikasiTransaksi,
    BarcodeTimbangan,
}: PropsPengaturanKasir) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galat = props.errors;
    const awal = {
        batas: UbahKeMasukanUang(BatasKasKeluar),
        bersama: ShiftBersama,
        diskonKasir: UbahKeMasukanPersen(BatasDiskonManual),
        diskonPenyetuju: UbahKeMasukanPersen(BatasDiskonPenyetuju),
        bulatkan: PembulatanTunai !== null,
        kelipatan: String(PembulatanTunai?.Kelipatan ?? 100),
        arah: PembulatanTunai?.Arah ?? 'Bawah',
        tutupButa: TutupShiftButa,
        toleransi: UbahKeMasukanUang(ToleransiSelisihKas),
        hariRetur: String(BatasHariRetur),
        hariLewat: String(BatasHariLewatJatuhTempo),
        laciPin: BukaLaciPerluPin,
        tanpaStruk: UbahKeMasukanUang(BatasReturTanpaStrukHarian),
        notifikasiTransaksi: UbahKeMasukanUang(BatasNotifikasiTransaksi),
    };
    const [batas, AturBatas] = useState(awal.batas);
    const [bersama, AturBersama] = useState(awal.bersama);
    const [diskonKasir, AturDiskonKasir] = useState(awal.diskonKasir);
    const [diskonPenyetuju, AturDiskonPenyetuju] = useState(awal.diskonPenyetuju);
    const [bulatkan, AturBulatkan] = useState(awal.bulatkan);
    const [kelipatan, AturKelipatan] = useState(awal.kelipatan);
    const [arah, AturArah] = useState(awal.arah);
    const [tutupButa, AturTutupButa] = useState(awal.tutupButa);
    const [toleransi, AturToleransi] = useState(awal.toleransi);
    const [hariRetur, AturHariRetur] = useState(awal.hariRetur);
    const [hariLewat, AturHariLewat] = useState(awal.hariLewat);
    const [laciPin, AturLaciPin] = useState(awal.laciPin);
    const [tanpaStruk, AturTanpaStruk] = useState(awal.tanpaStruk);
    const [notifikasiTransaksi, AturNotifikasiTransaksi] = useState(awal.notifikasiTransaksi);
    const [memproses, AturMemproses] = useState(false);
    const berubah =
        batas !== awal.batas ||
        bersama !== awal.bersama ||
        diskonKasir !== awal.diskonKasir ||
        diskonPenyetuju !== awal.diskonPenyetuju ||
        bulatkan !== awal.bulatkan ||
        tutupButa !== awal.tutupButa ||
        toleransi !== awal.toleransi ||
        hariRetur !== awal.hariRetur ||
        hariLewat !== awal.hariLewat ||
        laciPin !== awal.laciPin ||
        tanpaStruk !== awal.tanpaStruk ||
        notifikasiTransaksi !== awal.notifikasiTransaksi ||
        (bulatkan && (kelipatan !== awal.kelipatan || arah !== awal.arah));
    const opsiKelipatan = (kelipatanUmum.includes(kelipatan) ? kelipatanUmum : [...kelipatanUmum, kelipatan]).map(
        (nilai) => ({ Nilai: nilai, Label: FormatRupiah(nilai) }),
    );

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        // Bidang kosong dikirim apa adanya agar server menolaknya dengan pesan wajib diisi. Angka 0 di sini berarti
        // pengaturan paling ketat (setiap kas keluar/selisih/diskon butuh persetujuan), jadi tidak boleh terisi diam-diam.
        router.put(
            alamat,
            {
                BatasKasKeluar: batas,
                ShiftBersama: bersama,
                BatasDiskonManual: diskonKasir,
                BatasDiskonPenyetuju: diskonPenyetuju,
                PembulatanTunai: bulatkan ? { Kelipatan: Number.parseInt(kelipatan, 10), Arah: arah } : null,
                TutupShiftButa: tutupButa,
                ToleransiSelisihKas: toleransi,
                BatasHariRetur: hariRetur === '' ? null : Number.parseInt(hariRetur, 10),
                BatasHariLewatJatuhTempo: hariLewat === '' ? null : Number.parseInt(hariLewat, 10),
                BukaLaciPerluPin: laciPin,
                BatasReturTanpaStrukHarian: tanpaStruk,
                BatasNotifikasiTransaksi: notifikasiTransaksi,
            },
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );
    };

    return (
        <TataLetakAplikasi judul="Pengaturan kasir">
            <DaftarGalatServer
                galat={galat}
                kecuali={[
                    'BatasKasKeluar',
                    'ShiftBersama',
                    'BatasDiskonManual',
                    'BatasDiskonPenyetuju',
                    'PembulatanTunai.Kelipatan',
                    'PembulatanTunai.Arah',
                    'TutupShiftButa',
                    'ToleransiSelisihKas',
                    'BatasHariRetur',
                    'BatasHariLewatJatuhTempo',
                    'BukaLaciPerluPin',
                    'BatasReturTanpaStrukHarian',
                    'BatasNotifikasiTransaksi',
                ]}
            />
            <form onSubmit={Simpan} aria-label="Pengaturan kasir" className="flex flex-col gap-4" noValidate>
                <Panel
                    judul="Persetujuan kas keluar"
                    idJudul="judul-kas-keluar"
                    keterangan="Kas keluar di atas batas ini wajib disetujui supervisor dengan PIN di aplikasi kasir. Isi 0 agar setiap kas keluar butuh persetujuan."
                >
                    <BidangUang
                        label="Batas kas keluar tanpa persetujuan"
                        nilai={batas}
                        saatBerubah={AturBatas}
                        galat={galat.BatasKasKeluar}
                        required
                    />
                </Panel>
                <Panel judul="Buka laci tanpa transaksi" idJudul="judul-buka-laci">
                    <KotakCentang
                        label="Wajib PIN supervisor untuk membuka laci tanpa transaksi"
                        nilai={laciPin}
                        saatBerubah={AturLaciPin}
                    />
                    <p className="text-keterangan text-teks-sekunder">
                        Setiap buka laci tanpa transaksi selalu dicatat beserta alasan dan nama kasirnya, dan tampil di
                        detail shift. Jika dicentang, supervisor dengan izin menyetujui kas keluar harus memasukkan PIN.
                    </p>
                </Panel>
                <Panel judul="Shift bersama" idJudul="judul-shift-bersama">
                    <KotakCentang
                        label="Beberapa kasir boleh berbagi satu laci dalam satu shift"
                        nilai={bersama}
                        saatBerubah={AturBersama}
                    />
                    <p className="text-keterangan text-teks-sekunder">
                        Cocok untuk kafe kecil. Setiap transaksi dan kas tetap mencatat nama kasirnya. Jika dimatikan,
                        setiap kasir membuka shift sendiri dan hanya kasir itu atau supervisor yang bisa mencatat kas di
                        shiftnya.
                    </p>
                </Panel>
                <Panel
                    judul="Diskon manual"
                    idJudul="judul-diskon-manual"
                    keterangan="Kasir yang punya izin diskon boleh memberi diskon sampai batas kasir. Di atasnya wajib disetujui supervisor dengan PIN, sampai batas persetujuan. Hanya Pemilik yang boleh memberi diskon lebih besar."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <BidangTeks
                            label="Batas diskon kasir (%)"
                            nilai={diskonKasir}
                            saatBerubah={(teks) => AturDiskonKasir(NormalisasiMasukanPersen(teks))}
                            inputMode="decimal"
                            galat={galat.BatasDiskonManual}
                            required
                        />
                        <BidangTeks
                            label="Batas diskon dengan persetujuan (%)"
                            nilai={diskonPenyetuju}
                            saatBerubah={(teks) => AturDiskonPenyetuju(NormalisasiMasukanPersen(teks))}
                            inputMode="decimal"
                            galat={galat.BatasDiskonPenyetuju}
                            required
                        />
                    </div>
                </Panel>
                <Panel
                    judul="Pembulatan tunai"
                    idJudul="judul-pembulatan-tunai"
                    keterangan="Hanya bagian yang dibayar tunai yang dibulatkan. Selisihnya tercatat sebagai pendapatan lain."
                >
                    <KotakCentang label="Bulatkan pembayaran tunai" nilai={bulatkan} saatBerubah={AturBulatkan} />
                    {bulatkan ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <BidangPilihan
                                label="Kelipatan"
                                nilai={kelipatan}
                                opsi={opsiKelipatan}
                                saatBerubah={AturKelipatan}
                                galat={galat['PembulatanTunai.Kelipatan']}
                                required
                            />
                            <BidangPilihan
                                label="Arah pembulatan"
                                nilai={arah}
                                opsi={OpsiArahPembulatan}
                                saatBerubah={AturArah}
                                galat={galat['PembulatanTunai.Arah']}
                                required
                            />
                        </div>
                    ) : null}
                </Panel>
                <Panel
                    judul="Tutup shift"
                    idJudul="judul-tutup-shift"
                    keterangan="Selisih kas di atas toleransi wajib diberi alasan dan disetujui supervisor dengan PIN saat tutup shift. Isi 0 agar setiap selisih butuh persetujuan."
                >
                    <KotakCentang
                        label="Tutup shift buta: sembunyikan kas seharusnya sampai kasir menyimpan hitungan"
                        nilai={tutupButa}
                        saatBerubah={AturTutupButa}
                    />
                    <BidangUang
                        label="Toleransi selisih kas"
                        nilai={toleransi}
                        saatBerubah={AturToleransi}
                        galat={galat.ToleransiSelisihKas}
                        required
                    />
                </Panel>
                <Panel
                    judul="Retur penjualan"
                    idJudul="judul-retur-penjualan"
                    keterangan="Retur di aplikasi kasir hanya bisa untuk penjualan paling lama sekian hari sejak hari bisnis penjualannya. Isi 0 agar retur hanya di hari yang sama."
                >
                    <BidangTeks
                        label="Batas hari retur"
                        nilai={hariRetur}
                        saatBerubah={(teks) => AturHariRetur(teks.replace(/\D/g, '').slice(0, 3))}
                        inputMode="numeric"
                        galat={galat.BatasHariRetur}
                        required
                    />
                    <BidangUang
                        label="Batas retur tanpa struk per hari per outlet"
                        nilai={tanpaStruk}
                        saatBerubah={AturTanpaStruk}
                        galat={galat.BatasReturTanpaStrukHarian}
                        required
                    />
                    <p className="text-keterangan text-teks-sekunder">
                        Retur tanpa struk selalu butuh PIN pemilik atau pengguna berizin &quot;Menyetujui retur tanpa
                        struk&quot;, dan hanya bisa dikembalikan sebagai tukar barang atau deposit pelanggan, tidak
                        pernah uang tunai. Jika total retur tanpa struk di satu outlet hari itu melewati batas ini,
                        returnya tetap tercatat dan masuk Kotak Tindakan untuk dicek. Isi 0 agar setiap retur tanpa
                        struk dicek.
                    </p>
                </Panel>
                <Panel
                    judul="Kabar transaksi besar"
                    idJudul="judul-kabar-transaksi"
                    keterangan="Void, retur, dan penjualan berdiskon dengan nilai sama atau di atas batas ini dikabarkan lewat notifikasi ke pengguna yang boleh melihat laporan penjualan di outlet itu. Tiap pengguna bisa membisukan jenis notifikasinya sendiri. Isi 0 agar setiap kejadian dikabarkan."
                >
                    <BidangUang
                        label="Batas nilai transaksi yang dikabarkan"
                        nilai={notifikasiTransaksi}
                        saatBerubah={AturNotifikasiTransaksi}
                        galat={galat.BatasNotifikasiTransaksi}
                        required
                    />
                </Panel>
                <Panel
                    judul="Penjualan tempo"
                    idJudul="judul-penjualan-tempo"
                    keterangan="Penjualan tempo butuh PIN penyetuju bila melebihi limit kredit pelanggan atau pelanggan punya piutang yang lewat jatuh tempo lebih dari batas ini. Isi 0 agar piutang yang lewat jatuh tempo sehari pun butuh penyetuju."
                >
                    <BidangTeks
                        label="Batas hari lewat jatuh tempo"
                        nilai={hariLewat}
                        saatBerubah={(teks) => AturHariLewat(teks.replace(/\D/g, '').slice(0, 3))}
                        inputMode="numeric"
                        galat={galat.BatasHariLewatJatuhTempo}
                        required
                    />
                </Panel>
                <div className="flex flex-wrap items-center gap-3">
                    <Tombol type="submit" memproses={memproses} disabled={!berubah}>
                        Simpan pengaturan
                    </Tombol>
                    {!berubah ? <span className="text-keterangan text-teks-sekunder">Belum ada perubahan.</span> : null}
                </div>
            </form>
            <BagianBarcodeTimbangan awal={BarcodeTimbangan} galat={galat} />
        </TataLetakAplikasi>
    );
}

const AWALAN_TIMBANGAN = ['21', '22', '23', '24', '25', '26', '27', '28', '29'];

/**
 * v3.55 (§9.3): barcode timbangan EAN-13 `AA PPPPP NNNNN C` — awalan, kode produk 5 digit (barcode produk di katalog
 * = 7 digit pertama, misal 2712345), nilai 5 digit berat gram atau harga Rupiah, digit cek. Disimpan terpisah dari
 * formulir di atas.
 */
function BagianBarcodeTimbangan({ awal, galat }: { awal: BarcodeTimbangan; galat: Record<string, string> }) {
    const [aktif, AturAktif] = useState(awal.Aktif);
    const [awalan, AturAwalan] = useState<string[]>(awal.Awalan);
    const [nilai, AturNilai] = useState<string>(awal.Nilai);
    const [memproses, AturMemproses] = useState(false);

    const Simpan = (e: FormEvent) => {
        e.preventDefault();
        AturMemproses(true);
        router.put(
            '/kelola/kasir/pengaturan/barcode-timbangan',
            { Aktif: aktif, Awalan: awalan, Nilai: nilai },
            { preserveScroll: true, onFinish: () => AturMemproses(false) },
        );
    };

    return (
        <form noValidate onSubmit={Simpan} className="flex flex-col gap-4">
            <Panel
                judul="Barcode timbangan"
                keterangan="Untuk barang yang ditimbang (buah, daging, kue curah). Timbangan mencetak barcode berawalan 21–29 berisi berat atau harga; kasir cukup memindainya."
            >
                <KotakCentang label="Kasir membaca barcode timbangan" nilai={aktif} saatBerubah={AturAktif} />
                {aktif ? (
                    <>
                        <GrupCentang
                            legenda="Awalan barcode"
                            opsi={AWALAN_TIMBANGAN.map((a) => ({ nilai: a, label: a }))}
                            terpilih={awalan}
                            saatBerubah={AturAwalan}
                            galat={galat.Awalan ?? galat['Awalan.0']}
                            required
                        />
                        <BidangPilihan
                            label="Isi 5 digit nilai"
                            nilai={nilai}
                            opsi={[
                                { Nilai: 'Berat', Label: 'Berat dalam gram (12345 = 12,345 kg)' },
                                { Nilai: 'Harga', Label: 'Harga dalam Rupiah (12500 = Rp 12.500)' },
                            ]}
                            saatBerubah={AturNilai}
                            galat={galat.Nilai}
                            required
                        />
                        <p className="text-keterangan text-teks-sekunder">
                            Isi barcode produk di katalog dengan 7 digit pertama label timbangan (awalan + kode produk 5
                            digit), misal 2712345. Satuan produk yang dijual per berat harus boleh desimal.
                        </p>
                    </>
                ) : null}
            </Panel>
            <div>
                <Tombol type="submit" memproses={memproses}>
                    Simpan barcode timbangan
                </Tombol>
            </div>
        </form>
    );
}
