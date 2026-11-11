@extends('Surel.TataLetak')

@section('Judul', 'Prospek baru dari situs')
@section('Pratinjau', $Jenis.' dari '.$Nama.'. Hubungi dalam 1 hari kerja.')

@section('Isi')
    <p style="margin:0 0 20px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Halo Tim {{ config('app.name') }}, ada prospek baru dari situs pemasaran.</p>

    @include('Surel.Komponen.Rincian', ['Baris' => [
        'Jenis' => $Jenis,
        'Nama' => $Nama,
        'Nama usaha' => $NamaUsaha,
        'Jenis usaha' => $JenisUsaha,
        'Kota' => $Kota,
    ]])

    {{-- Nomor WhatsApp & email tidak dibawa di email ini: data prospek terenkripsi dan hanya dibuka di konsol. --}}
    <p style="margin:0 0 4px 0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#4f6567;">Nomor WhatsApp dan email pengunjung hanya bisa dilihat di Platform Pengelola.</p>

    @include('Surel.Komponen.Tombol', ['Url' => $Tautan, 'Label' => 'Buka data prospek'])

    <p style="margin:0; font-family:'Lato',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; line-height:24px; color:#1f3335; font-weight:bold;">Hubungi dalam 1 hari kerja agar prospek tidak dingin.</p>
@endsection
