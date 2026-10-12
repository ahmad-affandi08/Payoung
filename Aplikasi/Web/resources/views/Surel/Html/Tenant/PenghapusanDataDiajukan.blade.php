@extends('Surel.TataLetak')

@section('Judul', 'Permintaan penghapusan data diterima')
@section('Pratinjau', 'Data {{ $NamaUsaha }} dapat dihapus paling cepat {{ $BerlakuPada }}. Anda masih bisa membatalkan.')

@section('Isi')
    <p style="margin:0 0 16px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo {{ $Nama }},</p>

    <p style="margin:0 0 16px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Kami menerima permintaan penghapusan data dan penutupan akun usaha <strong style="color:#1f3335;">{{ $NamaUsaha }}</strong>.</p>

    @include('Surel.Komponen.Rincian', ['Baris' => ['Paling cepat dilaksanakan' => $BerlakuPada]])

    <p style="margin:0 0 8px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; font-weight:bold; color:#1f3335;">Yang akan terjadi</p>

    <p style="margin:0 0 16px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Setelah masa tunggu, tim kami menghapus data pribadi (pelanggan, karyawan, pemasok, foto, kredensial, dan akun pengguna). Data transaksi dan pembukuan tetap disimpan sesuai ketentuan perpajakan, tetapi identitasnya dianonimkan. Setelah itu usaha tidak bisa dibuka kembali. Sisa masa langganan berbayar tidak dikembalikan.</p>

    <p style="margin:0 0 8px 0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:14px; line-height:20px; font-weight:bold; color:#1f3335;">Bukan Anda, atau berubah pikiran?</p>

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Batalkan permintaan'])

    <p style="margin:0; font-family:'Atkinson Hyperlegible Next','Atkinson Hyperlegible',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Jika permintaan ini bukan dari Anda, batalkan sekarang lalu segera ganti kata sandi akun Anda.</p>
@endsection

@section('CatatanKaki', 'Pertanyaan? Balas email ini atau hubungi '.$EmailDukungan.'.')
