<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NilaiKuis extends Model
{
    protected $table = 'nilai_kuis';

    protected $fillable = [
        'kuis_id',
        'mahasiswa_id',
        'nilai',
        'waktu_mulai_pengerjaan',
        'waktu_selesai_pengerjaan',
        'status_pengerjaan',
    ];

    protected $casts = [
        'kuis_id' => 'integer',
        'mahasiswa_id' => 'integer',
        'nilai' => 'float',
    ];

    /**
     * Relasi: NilaiKuis milik satu Kuis.
     */
    public function kuis()
    {
        return $this->belongsTo(Kuis::class, 'kuis_id', 'id');
    }

    /**
     * Relasi: NilaiKuis milik satu Mahasiswa.
     */
    public function mahasiswa()
    {
        return $this->belongsTo(Mahasiswa::class, 'mahasiswa_id', 'id');
    }

    /**
     * Relasi: NilaiKuis memiliki banyak jawaban (KuisJawabanMhs).
     */
    public function jawaban()
    {
        return $this->hasMany(KuisJawabanMhs::class, 'nilai_kuis_id', 'id');
    }
}