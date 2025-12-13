<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Presensi extends Model
{
    // Nama tabel di database
    protected $table = 'presensi';

    // Kolom yang dapat diisi secara massal (mass assignable)
    protected $fillable = [
        'matakuliah_id',
        'kelas_id',
        'dosen_id',
        'judul_presensi',
        'tanggal_presensi',
        'kode_acak',
        'status_presensi', // 'active' atau 'closed'
    ];

    // Kolom yang merupakan tanggal/waktu
    protected $dates = [
        'tanggal_presensi',
    ];

    /**
     * Relasi ke Matakuliah.
     */
    public function matakuliah()
    {
        return $this->belongsTo(Matakuliah::class, 'matakuliah_id');
    }

    /**
     * Relasi ke Kelas.
     */
    public function kelas()
    {
        return $this->belongsTo(Kelas::class, 'kelas_id');
    }

    /**
     * Relasi ke Dosen yang membuka sesi presensi.
     */
    public function dosen()
    {
        return $this->belongsTo(Dosen::class, 'dosen_id');
    }

    /**
     * Relasi many-to-many ke Mahasiswa melalui tabel pivot presensi_mahasiswa.
     */
    public function mahasiswa()
    {
        return $this->belongsToMany(Mahasiswa::class, 'presensi_mahasiswa', 'presensi_id', 'mahasiswa_id')
                    ->withPivot('status_kehadiran', 'waktu_presensi')
                    ->withTimestamps();
    }
}