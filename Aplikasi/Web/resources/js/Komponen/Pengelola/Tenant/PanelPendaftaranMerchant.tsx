import { Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

import BidangTeks from '@/Komponen/Formulir/BidangTeks';
import Tombol from '@/Komponen/Formulir/Tombol';
import Panel from '@/Komponen/Kelola/Panel';
import LabelStatus from '@/Komponen/Umpan/LabelStatus';
import Pemberitahuan from '@/Komponen/Umpan/Pemberitahuan';
import { FormatTanggalWaktu } from '@/Pustaka/FormatWaktu';
import type { Tampilan360 } from '@/Tipe/TenantPengelola';

const jenisStatus = {
    Draf: 'netral',
    Dikirim: 'peringatan',
    Ditinjau: 'peringatan',
    Aktif: 'sukses',
    Ditolak: 'bahaya',
    Gagal: 'bahaya',
} as const;

type PropsPanel = {
    uuidTenant: string;
    data: Tampilan360['PendaftaranMerchant'];
    /** Izin `integrasi.kelola`: segarkan status memakai kredensial Partner platform, dan mengisi penampung QRIS. */
    bolehKelola: boolean;
};

/**
 * Pendaftaran merchant pembayaran tenant di DOKU Partner API (KYB). Pengelola melihat status, ID bisnis/brand, dan
 * jejak waktunya; NIK dan rekening hanya tersamar, foto KTP sudah dihapus dari server, shared key tidak ditampilkan.
 * Setelah disetujui, aktivasi QRIS per Brand dilakukan manual di DOKU Dashboard, lalu merchantId/terminalId yang
 * dihasilkan DOKU dicatat di dua kolom penampung (belum dipakai sistem).
 */
export default function PanelPendaftaranMerchant({ uuidTenant, data, bolehKelola }: PropsPanel) {
    const [memproses, AturMemproses] = useState(false);
    const pendaftaran = data.Pendaftaran;
    const formulir = useForm({
        IdPedagangQris: pendaftaran?.IdPedagangQris ?? '',
        IdTerminalQris: pendaftaran?.IdTerminalQris ?? '',
    });
    const galat = formulir.errors as Record<string, string | undefined>;

    const Segarkan = () => {
        router.post(
            `/tenant/${uuidTenant}/pendaftaran-merchant/segarkan`,
            {},
            { preserveScroll: true, onStart: () => AturMemproses(true), onFinish: () => AturMemproses(false) },
        );
    };

    const SimpanPenampung = (peristiwa: FormEvent) => {
        peristiwa.preventDefault();
        formulir.put(`/tenant/${uuidTenant}/pendaftaran-merchant/penampung-qris`, { preserveScroll: true });
    };

    const baris: [string, string | null][] = pendaftaran
        ? [
              ['Nama pemilik', pendaftaran.NamaPemilik],
              ['Nama usaha', pendaftaran.NamaUsaha],
              ['NIK', pendaftaran.NikTersamar],
              ['Rekening', pendaftaran.RekeningTersamar],
              ['ID bisnis DOKU', pendaftaran.IdBisnisDoku],
              ['ID brand DOKU', pendaftaran.IdBrandDoku],
              ['Status di DOKU', pendaftaran.StatusDoku],
              ['Dikirim', pendaftaran.DikirimPada ? FormatTanggalWaktu(pendaftaran.DikirimPada) : null],
              ['Disetujui', pendaftaran.DisetujuiPada ? FormatTanggalWaktu(pendaftaran.DisetujuiPada) : null],
              ['Terakhir diperiksa', pendaftaran.DiperiksaPada ? FormatTanggalWaktu(pendaftaran.DiperiksaPada) : null],
              [
                  'Callback KYB terakhir',
                  pendaftaran.CallbackDiterimaPada ? FormatTanggalWaktu(pendaftaran.CallbackDiterimaPada) : null,
              ],
          ]
        : [];

    return (
        <Panel
            judul="Pendaftaran merchant pembayaran (DOKU)"
            keterangan="Tenant mendaftar sendiri dengan foto KTP dan rekening; Payoung meneruskannya ke DOKU Partner API (KYB)."
        >
            {pendaftaran === null ? (
                <p className="text-isi text-teks-sekunder">Tenant ini belum mengirim pendaftaran merchant.</p>
            ) : (
                <>
                    <div className="flex flex-wrap items-center gap-2">
                        <LabelStatus jenis={jenisStatus[pendaftaran.Status]} teks={pendaftaran.LabelStatus} />
                    </div>
                    <dl className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-x-3 gap-y-2 text-isi">
                        {baris
                            .filter(([, nilai]) => nilai !== null && nilai !== '')
                            .map(([label, nilai]) => (
                                <div key={label} className="contents">
                                    <dt className="text-teks-sekunder">{label}</dt>
                                    <dd className="break-all text-teks-utama">{nilai}</dd>
                                </div>
                            ))}
                    </dl>
                    {pendaftaran.AlasanPenolakan ? (
                        <Pemberitahuan jenis="bahaya" judul="Alasan penolakan">
                            {pendaftaran.AlasanPenolakan}
                        </Pemberitahuan>
                    ) : null}
                    {pendaftaran.PesanGalat ? (
                        <Pemberitahuan jenis="peringatan">{pendaftaran.PesanGalat}</Pemberitahuan>
                    ) : null}
                    {pendaftaran.Status === 'Aktif' ? (
                        <Pemberitahuan jenis="info" judul="Langkah berikutnya">
                            Aktifkan layanan QRIS untuk Brand ini di DOKU Dashboard (Pengaturan, Akun, Layanan, Tambah
                            Layanan, QRIS di bagian QR Payment, Aktivasi): isi Nama Pendek Brand dan MCC. Setelah aktif,
                            DOKU mengisi merchantId dan terminalId otomatis. Catat keduanya di kolom di bawah.
                        </Pemberitahuan>
                    ) : null}
                </>
            )}

            {!data.PartnerAktif ? (
                <Pemberitahuan jenis="peringatan">
                    Akun DOKU Partner belum diisi. Isi Brand ID dan Secret key di{' '}
                    <Link href="/integrasi" className="font-semibold underline">
                        menu Integrasi
                    </Link>{' '}
                    dulu.
                </Pemberitahuan>
            ) : null}

            <div className="flex flex-wrap items-center gap-2">
                <Tombol
                    varian="sekunder"
                    memproses={memproses}
                    disabled={!pendaftaran?.BisaDisegarkan || !data.PartnerAktif || !bolehKelola}
                    onClick={Segarkan}
                >
                    Segarkan status
                </Tombol>
                {!bolehKelola ? (
                    <span className="text-keterangan text-teks-sekunder">
                        Hanya peran dengan izin kelola integrasi (Teknis, Super Admin) yang bisa menyegarkan.
                    </span>
                ) : null}
            </div>

            {pendaftaran !== null ? (
                <form
                    onSubmit={SimpanPenampung}
                    className="grid gap-3 border-t border-garis pt-3 sm:grid-cols-2"
                    noValidate
                >
                    <p className="text-keterangan text-teks-sekunder sm:col-span-2">
                        ID pedagang dan terminal QRIS diisi manual sampai DOKU menjawab cara membacanya otomatis. Hanya
                        penampung: belum dipakai di mana pun. Menyimpan butuh kode 2FA baru.
                    </p>
                    <BidangTeks
                        label="ID pedagang QRIS (merchantId)"
                        nilai={formulir.data.IdPedagangQris}
                        saatBerubah={(nilai) => formulir.setData('IdPedagangQris', nilai)}
                        galat={galat.IdPedagangQris}
                        disabled={!bolehKelola}
                        kode
                    />
                    <BidangTeks
                        label="ID terminal QRIS (terminalId)"
                        nilai={formulir.data.IdTerminalQris}
                        saatBerubah={(nilai) => formulir.setData('IdTerminalQris', nilai)}
                        galat={galat.IdTerminalQris}
                        disabled={!bolehKelola}
                        kode
                    />
                    <div className="sm:col-span-2">
                        <Tombol type="submit" varian="sekunder" memproses={formulir.processing} disabled={!bolehKelola}>
                            Simpan ID QRIS
                        </Tombol>
                    </div>
                </form>
            ) : null}
        </Panel>
    );
}
