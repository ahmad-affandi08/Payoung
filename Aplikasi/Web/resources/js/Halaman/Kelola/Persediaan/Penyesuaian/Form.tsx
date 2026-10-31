import { Link, router, usePage } from '@inertiajs/react';
import { Trash2Icon } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';

import BilahAksiForm from '@/Komponen/Formulir/BilahAksiForm';
import { GalatBidang } from '@/Komponen/Formulir/BagianBidang';
import BidangOutlet from '@/Komponen/Formulir/BidangOutlet';
import BidangPilihan from '@/Komponen/Formulir/BidangPilihan';
import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import BidangTeksPanjang from '@/Komponen/Formulir/BidangTeksPanjang';
import Tombol from '@/Komponen/Formulir/Tombol';
import BidangJumlah from '@/Komponen/Katalog/BidangJumlah';
import TombolPindaiKamera from '@/Komponen/Katalog/TombolPindaiKamera';
import DaftarGalatServer from '@/Komponen/Katalog/DaftarGalatServer';
import Panel from '@/Komponen/Kelola/Panel';
import PemilihPelacakan from '@/Komponen/Persediaan/Dokumen/PemilihPelacakan';
import BidangHpp from '@/Komponen/Persediaan/BidangHpp';
import { useEntriCepat } from '@/Komponen/Persediaan/EntriCepat';
import PemilihProdukStok, { type ProdukStokTerpilih } from '@/Komponen/Persediaan/PemilihProdukStok';
import { BuatUlid } from '@/Komponen/Persediaan/UlidKlien';
import PemilihTanggal from '@/Komponen/Tanggal/PemilihTanggal';
import { Button } from '@/Komponen/Ui/button';
import { FormatHppSatuan, FormatJumlahStok, FormatLabelGudang } from '@/Pustaka/FormatPersediaan';
import { AmbilTandaDesimal, CekDesimalValid } from '@/Pustaka/HitungDesimal';
import TataLetakAplikasi from '@/TataLetak/TataLetakAplikasi';
import type { PropsBersamaAplikasi } from '@/Tipe/Aplikasi';
import type { AlasanPenyesuaian, BarisFormPenyesuaianStok, PropsFormPenyesuaianStok } from '@/Tipe/DokumenPersediaan';

const alamat = '/kelola/persediaan/penyesuaian';

// Audit kemudahan pakai #22: HPP rata-rata gudang (dari pencarian produk) mengisi harga modal saat arah Tambah stok.
type BarisForm = BarisFormPenyesuaianStok & { Kunci: string; SaldoDiGudang: string | null; HppRataRata: string | null };

let nomorKunci = 0;

/** Galat lokal satu baris penyesuaian (server memeriksa ulang). */
export function PeriksaBarisPenyesuaian(b: BarisFormPenyesuaianStok, wajibKedaluwarsa: boolean): string | null {
    if (
        b.Pelacakan !== 'Seri' &&
        (!CekDesimalValid(b.Jumlah) || AmbilTandaDesimal(b.Jumlah) <= 0 || (!b.BolehDesimal && b.Jumlah.includes('.')))
    ) {
        return 'Isi jumlah lebih dari 0.';
    }

    if (b.Arah === 'Masuk' && (b.HppSatuan === null || !CekDesimalValid(b.HppSatuan))) {
        return 'Isi harga modal per satuan untuk stok masuk.';
    }

    if (b.Pelacakan === 'Batch') {
        if (b.Arah === 'Keluar') {
            return b.UuidBatchStok ? null : 'Pilih batch.';
        }

        if ((b.NomorBatch ?? '').trim() === '') {
            return 'Isi nomor batch.';
        }

        return wajibKedaluwarsa && !b.TanggalKedaluwarsa ? 'Isi tanggal kedaluwarsa batch.' : null;
    }

    if (b.Pelacakan === 'Seri') {
        return b.Arah === 'Keluar'
            ? b.UuidNomorSeri
                ? null
                : 'Pilih nomor seri.'
            : (b.NomorSeri ?? '').trim() === ''
              ? 'Isi nomor seri.'
              : null;
    }

    return null;
}

/** F-05b: form draf penyesuaian stok (buat/ubah); pengajuan & posting dari halaman detail. */
export default function HalamanFormPenyesuaianStok({
    Mode,
    Penyesuaian,
    OpsiGudang,
    OpsiAlasan,
    HariIni,
    BatasBaris,
    WajibKedaluwarsaBatch,
}: PropsFormPenyesuaianStok) {
    const { props } = usePage<PropsBersamaAplikasi>();
    const galatServer = props.errors;
    const entri = useEntriCepat('penyesuaian');
    const [uuidBaru] = useState(() => (Penyesuaian === null ? BuatUlid() : null));
    const [gudang, AturGudang] = useState(Penyesuaian?.UuidGudang ?? '');
    const [tanggal, AturTanggal] = useState(Penyesuaian?.Tanggal ?? HariIni);
    const [alasan, AturAlasan] = useState<AlasanPenyesuaian | ''>(Penyesuaian?.KodeAlasan ?? '');
    const [keterangan, AturKeterangan] = useState(Penyesuaian?.Keterangan ?? '');
    const [daftar, AturDaftar] = useState<BarisForm[]>(() =>
        (Penyesuaian?.Baris ?? []).map((b) => {
            nomorKunci += 1;

            return { ...b, Kunci: `ps-${String(nomorKunci)}`, SaldoDiGudang: null, HppRataRata: null };
        }),
    );
    const [periksa, AturPeriksa] = useState(false);
    const [memproses, AturMemproses] = useState(false);
    // Audit kemudahan pakai #13: tombol utama menyimpan lalu langsung memproses dokumen; draf jadi pilihan kedua.
    const lanjutkan = useRef(false);
    const opsiAlasan = OpsiAlasan.find((o) => o.Nilai === alasan);
    const bolehMasuk = opsiAlasan?.BolehMasuk ?? false;
    const judul = Mode === 'Buat' ? 'Buat penyesuaian stok' : 'Ubah draf penyesuaian';
    const galatKeterangan =
        galatServer.Keterangan ??
        (periksa && opsiAlasan?.WajibKeterangan === true && keterangan.trim().length < 5
            ? 'Alasan Lainnya wajib diberi keterangan minimal 5 karakter.'
            : undefined);

    const Ubah = (kunci: string, perubahan: Partial<BarisForm>) =>
        AturDaftar((lama) => lama.map((b) => (b.Kunci === kunci ? { ...b, ...perubahan } : b)));

    const Tambah = (p: ProdukStokTerpilih) => {
        nomorKunci += 1;
        const kunci = `ps-${String(nomorKunci)}`;

        // Entri cepat: barang baru di paling atas (dekat kotak cari) dan jumlahnya langsung difokuskan.
        if (p.Pelacakan !== 'Seri') {
            entri.CatatBarisBaru(kunci);
        }

        AturDaftar((lama) => [
            {
                Kunci: kunci,
                UuidProduk: p.Uuid,
                NamaProduk: p.Nama,
                Sku: p.Sku,
                SimbolSatuan: p.SimbolSatuan,
                BolehDesimal: p.BolehDesimal,
                Pelacakan: p.Pelacakan,
                SaldoDiGudang: p.SaldoDiGudang,
                HppRataRata: p.HppRataRata,
                Arah: 'Keluar',
                Jumlah: p.Pelacakan === 'Seri' ? '1' : '',
                HppSatuan: null,
                UuidBatchStok: null,
                NomorBatch: null,
                TanggalKedaluwarsa: null,
                UuidNomorSeri: null,
                NomorSeri: null,
            },
            ...lama,
        ]);
    };

    const Simpan = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        AturPeriksa(true);

        if (
            gudang === '' ||
            alasan === '' ||
            galatKeterangan !== undefined ||
            daftar.length === 0 ||
            daftar.some(
                (b) =>
                    PeriksaBarisPenyesuaian(b, WajibKedaluwarsaBatch) !== null || (b.Arah === 'Masuk' && !bolehMasuk),
            )
        ) {
            return;
        }

        const masukan = {
            UuidGudang: gudang,
            Tanggal: tanggal,
            KodeAlasan: alasan,
            Keterangan: keterangan.trim() === '' ? null : keterangan.trim(),
            Baris: daftar.map((b) => ({
                UuidProduk: b.UuidProduk,
                Arah: b.Arah,
                Jumlah: b.Jumlah,
                HppSatuan: b.Arah === 'Masuk' ? b.HppSatuan : null,
                UuidBatchStok: b.Arah === 'Keluar' ? b.UuidBatchStok : null,
                NomorBatch: b.Arah === 'Masuk' ? b.NomorBatch : null,
                TanggalKedaluwarsa: b.Arah === 'Masuk' ? b.TanggalKedaluwarsa : null,
                UuidNomorSeri: b.Arah === 'Keluar' ? b.UuidNomorSeri : null,
                NomorSeri: b.Arah === 'Masuk' ? b.NomorSeri : null,
            })),
        };
        const opsi = { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) };

        if (Penyesuaian === null) {
            router.post(alamat, { ...masukan, Uuid: uuidBaru ?? BuatUlid(), Lanjutkan: lanjutkan.current }, opsi);
        } else {
            router.put(
                `${alamat}/${Penyesuaian.Uuid}`,
                { ...masukan, VersiDiubahPada: Penyesuaian.VersiDiubahPada, Lanjutkan: lanjutkan.current },
                opsi,
            );
        }
    };

    return (
        <TataLetakAplikasi judul={judul}>
            <DaftarGalatServer
                galat={galatServer}
                kecuali={['UuidGudang', 'Tanggal', 'KodeAlasan', 'Keterangan', 'Baris']}
            />
            <form onSubmit={Simpan} noValidate aria-label={judul} className="flex flex-col gap-4">
                <Panel
                    judul="Dokumen"
                    idJudul="judul-dokumen-penyesuaian"
                    keterangan="Stok berubah setelah penyesuaian diajukan dan dicatat, bukan saat draf disimpan."
                >
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <BidangOutlet
                            label="Lokasi stok"
                            nilai={gudang}
                            kosong="Pilih lokasi stok"
                            opsi={OpsiGudang.filter((g) => g.Aktif || g.Uuid === gudang).map((g) => ({
                                Nilai: g.Uuid,
                                Label: FormatLabelGudang(g),
                            }))}
                            saatBerubah={(nilai) => {
                                AturGudang(nilai);
                                AturDaftar((lama) =>
                                    lama.map((b) => ({
                                        ...b,
                                        SaldoDiGudang: null,
                                        HppRataRata: null,
                                        UuidBatchStok: null,
                                        UuidNomorSeri: null,
                                    })),
                                );
                            }}
                            galat={
                                galatServer.UuidGudang ?? (periksa && gudang === '' ? 'Pilih lokasi stok.' : undefined)
                            }
                        />
                        <BidangPilihan
                            label="Alasan"
                            nilai={alasan}
                            kosong="Pilih alasan"
                            opsi={OpsiAlasan.map((o) => ({ Nilai: o.Nilai, Label: o.Label }))}
                            saatBerubah={(nilai) => {
                                AturAlasan(nilai as AlasanPenyesuaian);

                                if (!(OpsiAlasan.find((o) => o.Nilai === nilai)?.BolehMasuk ?? false)) {
                                    AturDaftar((lama) => lama.map((b) => ({ ...b, Arah: 'Keluar', HppSatuan: null })));
                                }
                            }}
                            required
                            galat={
                                galatServer.KodeAlasan ??
                                (periksa && alasan === '' ? 'Pilih alasan penyesuaian.' : undefined)
                            }
                        />
                        <PemilihTanggal
                            id="tanggal-penyesuaian"
                            label="Tanggal"
                            nilai={tanggal}
                            max={HariIni}
                            required
                            saatBerubah={AturTanggal}
                            galat={galatServer.Tanggal}
                        />
                    </div>
                    <BidangTeksPanjang
                        label={opsiAlasan?.WajibKeterangan === true ? 'Keterangan' : 'Keterangan (opsional)'}
                        nilai={keterangan}
                        saatBerubah={AturKeterangan}
                        galat={galatKeterangan}
                        baris={2}
                        maksimal={500}
                        required={opsiAlasan?.WajibKeterangan === true}
                    />
                </Panel>

                <Panel
                    judul="Barang"
                    idJudul="judul-barang-penyesuaian"
                    keterangan={`${daftar.length.toLocaleString('id-ID')} dari ${BatasBaris.toLocaleString('id-ID')} baris`}
                >
                    <PemilihProdukStok
                        label="Tambah produk"
                        id={entri.idCari}
                        alihkanFokusSetelahPilih={entri.AlihkanFokusSetelahPilih}
                        uuidGudang={gudang === '' ? null : gudang}
                        saatPilih={Tambah}
                        disabled={gudang === '' || daftar.length >= BatasBaris}
                        keterangan={
                            gudang === ''
                                ? 'Pilih lokasi stok dulu.'
                                : 'Barang yang dipilih muncul paling atas. Ketik jumlahnya lalu tekan Enter untuk lanjut ke barang berikutnya. Stok keluar dinilai HPP saat dicatat. Stok masuk hanya untuk alasan Lainnya.'
                        }
                    />
                    {galatServer.Baris ? (
                        <GalatBidang>{galatServer.Baris}</GalatBidang>
                    ) : periksa && daftar.length === 0 ? (
                        <GalatBidang>Tambahkan minimal satu produk.</GalatBidang>
                    ) : null}
                    <ul className="flex flex-col divide-y divide-garis" aria-label="Barang yang disesuaikan">
                        {daftar.map((b, indeks) => {
                            const galat =
                                galatServer[`Baris.${String(indeks)}.Jumlah`] ??
                                (periksa
                                    ? (PeriksaBarisPenyesuaian(b, WajibKedaluwarsaBatch) ?? undefined)
                                    : undefined);

                            return (
                                <li key={b.Kunci} className="flex flex-col gap-2 py-3">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <span className="block font-semibold break-words text-teks-utama">
                                                {b.NamaProduk}
                                            </span>
                                            <span className="block text-keterangan text-teks-sekunder">
                                                <span className="font-mono">{b.Sku ?? 'Tanpa SKU'}</span>
                                                {b.SaldoDiGudang !== null
                                                    ? ` | stok ${FormatJumlahStok(b.SaldoDiGudang, b.SimbolSatuan)}`
                                                    : null}
                                            </span>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={`Hapus baris ${b.NamaProduk}`}
                                            onClick={() =>
                                                AturDaftar((lama) => lama.filter((x) => x.Kunci !== b.Kunci))
                                            }
                                        >
                                            <Trash2Icon aria-hidden="true" />
                                        </Button>
                                    </div>
                                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                        <BidangPilihan
                                            label={`Arah ${b.NamaProduk}`}
                                            nilai={b.Arah}
                                            opsi={[
                                                { Nilai: 'Keluar', Label: 'Kurangi stok' },
                                                ...(bolehMasuk ? [{ Nilai: 'Masuk', Label: 'Tambah stok' }] : []),
                                            ]}
                                            saatBerubah={(nilai) =>
                                                Ubah(b.Kunci, {
                                                    Arah: nilai === 'Masuk' ? 'Masuk' : 'Keluar',
                                                    HppSatuan: nilai === 'Masuk' ? b.HppRataRata : null,
                                                    UuidBatchStok: null,
                                                    UuidNomorSeri: null,
                                                    NomorBatch: null,
                                                    NomorSeri: null,
                                                })
                                            }
                                            required
                                        />
                                        {b.Pelacakan === 'Seri' ? null : (
                                            <BidangJumlah
                                                label={`Jumlah ${b.NamaProduk}`}
                                                id={entri.IdJumlah(b.Kunci)}
                                                saatEnter={entri.KembaliKeCari}
                                                nilai={b.Jumlah}
                                                saatBerubah={(jumlah) => Ubah(b.Kunci, { Jumlah: jumlah })}
                                                desimal={b.BolehDesimal ? 4 : 0}
                                                akhiran={b.SimbolSatuan}
                                                galat={galat}
                                                required
                                            />
                                        )}
                                        {b.Arah === 'Masuk' ? (
                                            <BidangHpp
                                                label={`Harga modal ${b.NamaProduk}`}
                                                nilai={b.HppSatuan ?? ''}
                                                saatBerubah={(hpp) => Ubah(b.Kunci, { HppSatuan: hpp })}
                                                simbolSatuan={b.SimbolSatuan}
                                                keterangan={
                                                    b.HppRataRata !== null
                                                        ? `Diisi dari HPP rata-rata saat ini ${FormatHppSatuan(b.HppRataRata)}. Boleh diubah.`
                                                        : undefined
                                                }
                                                required
                                            />
                                        ) : null}
                                        {b.Pelacakan !== 'Tidak' && b.Arah === 'Keluar' ? (
                                            <PemilihPelacakan
                                                nama={b.NamaProduk}
                                                pelacakan={b.Pelacakan}
                                                uuidProduk={b.UuidProduk}
                                                uuidGudang={gudang}
                                                simbolSatuan={b.SimbolSatuan}
                                                nilai={b.Pelacakan === 'Batch' ? b.UuidBatchStok : b.UuidNomorSeri}
                                                saatBerubah={(uuid, teks) =>
                                                    Ubah(
                                                        b.Kunci,
                                                        b.Pelacakan === 'Batch'
                                                            ? { UuidBatchStok: uuid, NomorBatch: teks }
                                                            : { UuidNomorSeri: uuid, NomorSeri: teks },
                                                    )
                                                }
                                            />
                                        ) : null}
                                        {b.Pelacakan === 'Batch' && b.Arah === 'Masuk' ? (
                                            <>
                                                <BidangTeks
                                                    label={`Nomor batch ${b.NamaProduk}`}
                                                    nilai={b.NomorBatch ?? ''}
                                                    kode
                                                    saatBerubah={(nilai) => Ubah(b.Kunci, { NomorBatch: nilai })}
                                                    required
                                                />
                                                <PemilihTanggal
                                                    id={`${b.Kunci}-kedaluwarsa`}
                                                    label="Kedaluwarsa"
                                                    nilai={b.TanggalKedaluwarsa ?? ''}
                                                    required={WajibKedaluwarsaBatch}
                                                    saatBerubah={(nilai) =>
                                                        Ubah(b.Kunci, { TanggalKedaluwarsa: nilai || null })
                                                    }
                                                />
                                            </>
                                        ) : null}
                                        {b.Pelacakan === 'Seri' && b.Arah === 'Masuk' ? (
                                            <div className="flex items-start gap-2">
                                                <div className="min-w-0 flex-1">
                                                    <BidangTeks
                                                        label={`Nomor seri ${b.NamaProduk}`}
                                                        nilai={b.NomorSeri ?? ''}
                                                        kode
                                                        saatBerubah={(nilai) => Ubah(b.Kunci, { NomorSeri: nilai })}
                                                        required
                                                    />
                                                </div>
                                                <div className="pt-6">
                                                    <TombolPindaiKamera
                                                        ikonSaja
                                                        jenisKode="Seri"
                                                        label={`Pindai nomor seri ${b.NamaProduk}`}
                                                        saatTerbaca={(nomor) => Ubah(b.Kunci, { NomorSeri: nomor })}
                                                    />
                                                </div>
                                            </div>
                                        ) : null}
                                    </div>
                                    {b.Pelacakan === 'Seri' && galat ? <GalatBidang>{galat}</GalatBidang> : null}
                                </li>
                            );
                        })}
                    </ul>
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
                    <Button asChild variant="outline" className="h-8 pointer-coarse:h-11">
                        <Link href={Penyesuaian === null ? alamat : `${alamat}/${Penyesuaian.Uuid}`}>Batal</Link>
                    </Button>
                </BilahAksiForm>
            </form>
        </TataLetakAplikasi>
    );
}
