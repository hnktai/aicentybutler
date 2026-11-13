<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HomepageAppointment extends Model
{
    protected $fillable = [
        'name', 'email', 'phone', 'title', 'appointment_date', 'appointment_time',
        'scheduled_at', 'problem', 'consent', 'zoom_url', 'zoom_passcode',
        'confirmation_sent_at', 'reminder_2d_sent_at', 'reminder_1d_sent_at', 'reminder_2h_sent_at', 'status',
    ];

    protected $casts = [
        'appointment_date' => 'date',
        'scheduled_at' => 'datetime',
        'consent' => 'boolean',
        'confirmation_sent_at' => 'datetime',
        'reminder_2d_sent_at' => 'datetime',
        'reminder_1d_sent_at' => 'datetime',
        'reminder_2h_sent_at' => 'datetime',
    ];
}
