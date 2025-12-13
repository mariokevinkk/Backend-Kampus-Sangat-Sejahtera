<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KuisDetailSoal extends Model
{
    protected $table = 'kuis_detail_soal';

    protected $fillable = [
        'kuis_id',
        'soal',
        'pilihan_a',
        'pilihan_b',
        'pilihan_c',
        'pilihan_d',
        'jawaban_benar',
        'nilai_bobot',
    ];

    protected $casts = [
        'kuis_id' => 'integer',
        'nilai_bobot' => 'integer',
    ];

    /**
     * Relasi: Soal milik satu Kuis.
     */
    public function kuis()
    {
        return $this->belongsTo(Kuis::class, 'kuis_id', 'id');
    }
}