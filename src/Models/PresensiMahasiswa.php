<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

// Model ini hanya diperlukan jika Anda ingin query langsung ke tabel pivot.
class PresensiMahasiswa extends Pivot
{
    // Nama tabel di database
    protected $table = 'presensi_mahasiswa';

    // Kolom yang dapat diisi secara massal
    protected $fillable = [
        'presensi_id',
        'mahasiswa_id',
        'waktu_presensi',
        'status_kehadiran',
    ];

    // Kolom yang merupakan tanggal/waktu
    protected $dates = [
        'waktu_presensi',
    ];

    /**
     * Relasi ke sesi Presensi.
     */
    public function presensi()
    {
        return $this->belongsTo(Presensi::class, 'presensi_id');
    }

    /**
     * Relasi ke Mahasiswa.
     */
    public function mahasiswa()
    {
        return $this->belongsTo(Mahasiswa::class, 'mahasiswa_id');
    }
}